<?php

namespace Mlangeni\Machinjiri\Core\Components\Network\Tools;

use Mlangeni\Machinjiri\Core\Http\HttpClient;
use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;
use Mlangeni\Machinjiri\Core\Exceptions\NetworkException;
use Mlangeni\Machinjiri\Core\Artisans\Logging\LoggerFactory;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Components\Network\Tools\NetworkConfig;

/**
 * Scans network for hosts, open ports, etc. with caching and concurrency.
 */
class Scanner implements ScannerInterface
{
    protected Logger $logger;
    protected EventListener $events;
    protected CacheManager $cache;
    protected NetworkConfig $config;
    protected HttpClient $httpClient;
    protected int $timeout = 2;
    protected int $maxPorts = 1024;
    protected int $retryAttempts = 2;
    protected int $retryDelay = 1;

    public function __construct(
        CacheManager $cache,
        NetworkConfig $config,
        HttpClient $httpClient,
        ?EventListener $events = null
    ) {
        $this->cache = $cache;
        $this->config = $config;
        $this->httpClient = $httpClient;
        $this->events = $events ?? new EventListener(LoggerFactory::system('network_scanner', Manager::PROCESS));
        $this->logger = LoggerFactory::system('network_scanner', Manager::PROCESS);
        $this->timeout = $config->get('ping_timeout', 2);
        $this->maxPorts = $config->get('max_ports', 1024);
        $this->retryAttempts = $config->get('retry_attempts', 2);
        $this->retryDelay = $config->get('retry_delay', 1);

        // Configure HttpClient with default timeout
        $this->httpClient->setTimeout($this->timeout);
    }

    public function setTimeout(int $seconds): self
    {
        $this->timeout = $seconds;
        return $this;
    }

    public function setMaxPorts(int $max): self
    {
        $this->maxPorts = $max;
        return $this;
    }

    /**
     * Ping a host with retry, using ICMP first, then HTTP fallback.
     */
    public function ping(string $host, ?int $timeout = null): bool
    {
        $timeout = $timeout ?? $this->timeout;
        $cacheKey = "ping_{$host}_{$timeout}";
        $ttl = $this->config->get('cache_ttl', 300);

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return (bool) $cached;
        }

        $reachable = $this->doPing($host, $timeout);

        // If ICMP fails, try HTTP (port 80) as a fallback
        if (!$reachable) {
            $reachable = $this->httpPing($host, $timeout);
        }

        $this->cache->set($cacheKey, $reachable ? 1 : 0, $ttl);
        $this->logger->debug("Ping {$host}: " . ($reachable ? 'success' : 'failure'));
        return $reachable;
    }

    /**
     * ICMP ping using system command.
     */
    protected function doPing(string $host, int $timeout): bool
    {
        if (function_exists('exec')) {
            $command = 'ping -c 1 -W ' . (int)$timeout . ' ' . escapeshellarg($host) . ' 2>&1';
            exec($command, $output, $exitCode);
            if ($exitCode === 0) {
                return true;
            }
        }

        // Fallback: TCP connect to port 80 or 443
        $ip = gethostbyname($host);
        if ($ip === $host) {
            return false;
        }
        $fp = @fsockopen($host, 80, $errno, $errstr, $timeout);
        if ($fp) {
            fclose($fp);
            return true;
        }
        $fp = @fsockopen($host, 443, $errno, $errstr, $timeout);
        if ($fp) {
            fclose($fp);
            return true;
        }
        return false;
    }

    /**
     * HTTP ping using HttpClient (GET request to root).
     */
    protected function httpPing(string $host, int $timeout): bool
    {
        try {
            // Temporarily set timeout for this request
            $this->httpClient->setTimeout($timeout);
            $result = $this->httpClient->get("http://{$host}/", []);
            if (isset($result['http_code']) && $result['http_code'] > 0 && $result['http_code'] < 500) {
                // Any response (including 4xx) indicates the host is reachable
                return true;
            }
            // Also try HTTPS
            $result = $this->httpClient->get("https://{$host}/", []);
            return isset($result['http_code']) && $result['http_code'] > 0 && $result['http_code'] < 500;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Ping scan a subnet (sequential).
     */
    public function pingScan(string $subnet): array
    {
        $this->logger->info("Starting ping scan on subnet: {$subnet}");
        $hosts = $this->getHostsFromSubnet($subnet);
        $active = [];

        // Use chunked concurrent ping if enabled
        $concurrent = $this->config->get('concurrent_pings', 20);
        if ($concurrent > 1 && extension_loaded('curl')) {
            $active = $this->pingScanConcurrent($hosts, $concurrent);
        } else {
            foreach ($hosts as $ip) {
                if ($this->ping($ip)) {
                    $active[] = $ip;
                }
            }
        }

        $this->logger->info("Ping scan completed. Found " . count($active) . " active hosts.");
        $this->events->trigger('network.scan.completed', ['subnet' => $subnet, 'hosts' => $active]);
        return $active;
    }

    /**
     * Concurrent ping using curl_multi.
     */
    protected function pingScanConcurrent(array $hosts, int $concurrency): array
    {
        $active = [];
        $chunks = array_chunk($hosts, $concurrency);
        foreach ($chunks as $chunk) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ($chunk as $ip) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, "http://{$ip}");
                curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->timeout);
                curl_setopt($ch, CURLOPT_NOBODY, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
                curl_multi_add_handle($mh, $ch);
                $handles[] = $ch;
            }

            do {
                $status = curl_multi_exec($mh, $activeConnections);
                if ($activeConnections > 0) {
                    curl_multi_select($mh);
                }
            } while ($activeConnections > 0);

            foreach ($handles as $ch) {
                $info = curl_getinfo($ch);
                if ($info['http_code'] > 0 || curl_errno($ch) === CURLE_OK) {
                    $active[] = $info['url'] ? parse_url($info['url'], PHP_URL_HOST) : '';
                }
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);
        }
        return $active;
    }

    /**
     * Async ping scan – dispatches a job.
     */
    public function pingScanAsync(string $subnet, callable $callback): void
    {
        // Assume we have a job dispatcher injected (we'll add via constructor later)
        // For now, we'll simulate using the event system.
        $this->events->trigger('network.scan.async', ['subnet' => $subnet, 'callback' => $callback]);
        // In a real implementation, we would dispatch a ScanSubnetJob.
    }

    /**
     * Port scan using fsockopen, with optional HTTP client for web ports.
     */
    public function portScan(string $host, int $startPort = 1, int $endPort = 1024, ?int $timeout = null): array
    {
        $timeout = $timeout ?? $this->timeout;
        if ($endPort > $this->maxPorts) {
            $endPort = $this->maxPorts;
            $this->logger->warning("Port scan limited to {$this->maxPorts} ports.");
        }

        $this->logger->info("Port scanning {$host} from {$startPort} to {$endPort}");
        $openPorts = [];

        for ($port = $startPort; $port <= $endPort; $port++) {
            // For HTTP/HTTPS, use HttpClient for deeper check
            if ($port === 80 || $port === 443) {
                $isOpen = $this->httpPortCheck($host, $port, $timeout);
            } else {
                $isOpen = $this->tcpConnectCheck($host, $port, $timeout);
            }

            if ($isOpen) {
                $service = $this->getServiceName($port);
                $openPorts[] = [
                    'port'    => $port,
                    'service' => $service,
                ];
                $this->logger->debug("Open port found: {$port} ({$service}) on {$host}");
            }
        }

        $this->logger->info("Port scan completed. Found " . count($openPorts) . " open ports.");
        return $openPorts;
    }

    /**
     * TCP connect check using fsockopen.
     */
    protected function tcpConnectCheck(string $host, int $port, int $timeout): bool
    {
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($fp) {
            fclose($fp);
            return true;
        }
        return false;
    }

    public function resolveHostname(string $hostname): ?string
    {
        $cacheKey = "dns_resolve_{$hostname}";
        $ttl = $this->config->get('cache_ttl', 300);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached ?: null;
        }

        $ip = gethostbyname($hostname);
        if ($ip !== $hostname) {
            $this->cache->set($cacheKey, $ip, $ttl);
            return $ip;
        }
        $this->cache->set($cacheKey, '', $ttl);
        return null;
    }

    public function dnsLookup(string $domain, int $type = DNS_A): array
    {
        $cacheKey = "dns_lookup_{$domain}_{$type}";
        $ttl = $this->config->get('cache_ttl', 300);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return json_decode($cached, true) ?: [];
        }

        $result = dns_get_record($domain, $type);
        if ($result === false) {
            $this->logger->warning("DNS lookup failed for {$domain}");
            throw NetworkException::dnsFailed($domain);
        }
        $this->cache->set($cacheKey, json_encode($result), $ttl);
        return $result;
    }

    protected function getHostsFromSubnet(string $subnet): array
    {
        [$base, $prefix] = explode('/', $subnet);
        $ipLong = ip2long($base);
        if ($ipLong === false) {
            throw NetworkException::subnetInvalid($subnet);
        }
        $mask = 0xFFFFFFFF << (32 - (int)$prefix);
        $network = $ipLong & $mask;
        $broadcast = $network | ~$mask;
        $hosts = [];
        for ($i = $network + 1; $i < $broadcast; $i++) {
            $hosts[] = long2ip($i);
        }
        return $hosts;
    }

    protected function getServiceName(int $port): string
    {
        $wellKnown = [
            21 => 'FTP', 22 => 'SSH', 23 => 'Telnet', 25 => 'SMTP',
            53 => 'DNS', 80 => 'HTTP', 110 => 'POP3', 143 => 'IMAP',
            443 => 'HTTPS', 3306 => 'MySQL', 5432 => 'PostgreSQL',
        ];
        return $wellKnown[$port] ?? 'unknown';
    }

    protected function httpPortCheck(string $host, int $port, int $timeout): bool
    {
        try {
            $this->httpClient->setTimeout($timeout);
            $protocol = ($port === 443) ? 'https' : 'http';
            // Use HEAD to avoid downloading content
            $result = $this->httpClient->head("{$protocol}://{$host}:{$port}/");
            // Any successful response (status code > 0) indicates the port is open and responding
            return isset($result['http_code']) && $result['http_code'] > 0;
        } catch (\Exception $e) {
            return false;
        }
    }
}