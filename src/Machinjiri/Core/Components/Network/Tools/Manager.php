<?php

namespace Mlangeni\Machinjiri\Core\Components\Network\Tools;

use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;
use Mlangeni\Machinjiri\Core\Artisans\Contracts\JobDispatcherInterface;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Logging\LoggerFactory;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Exceptions\NetworkException;

/**
 * Central management facade for network operations.
 */
class Manager
{
    protected Logger $logger;
    protected ScannerInterface $scanner;
    protected MonitorInterface $monitor;
    protected ?EventListener $eventListener;
    protected CacheManager $cache;
    protected ?JobDispatcherInterface $dispatcher;
    protected NetworkConfig $config;

    public const PROCESS = "network";

    public function __construct(
        ScannerInterface $scanner,
        MonitorInterface $monitor,
        CacheManager $cache,
        NetworkConfig $config,
        ?JobDispatcherInterface $dispatcher = null,
        ?EventListener $eventListener = null
    ) {
        $this->logger = LoggerFactory::system('network_manager', self::PROCESS);
        $this->scanner = $scanner;
        $this->monitor = $monitor;
        $this->cache = $cache;
        $this->config = $config;
        $this->dispatcher = $dispatcher;
        $this->eventListener = $eventListener ?? new EventListener($this->logger);
    }

    public function getScanner(): ScannerInterface
    {
        return $this->scanner;
    }

    public function getMonitor(): MonitorInterface
    {
        return $this->monitor;
    }

    /**
     * List all local network interfaces with caching.
     */
    public function getLocalInterfaces(): array
    {
        $cacheKey = 'local_interfaces';
        $ttl = $this->config->get('cache_ttl', 300);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return json_decode($cached, true);
        }

        $this->logger->info("Fetching local network interfaces.");
        $interfaces = [];

        if (function_exists('net_get_interfaces')) {
            $ifaces = net_get_interfaces();
            foreach ($ifaces as $name => $info) {
                $ip = $info['ip'] ?? '';
                $mac = $info['mac'] ?? '';
                $netmask = $info['netmask'] ?? '';
                $isLoopback = $name === 'lo' || $name === 'lo0' || strpos($ip, '127.') === 0;
                $isUp = isset($info['up']) ? (bool)$info['up'] : true;

                $interfaces[] = new Network($name, $ip, $mac, $netmask, $isLoopback, $isUp);
            }
        } else {
            // Fallback: parse `ip addr` (Linux)
            $output = shell_exec('ip -4 addr show 2>/dev/null');
            if ($output) {
                $lines = explode("\n", $output);
                $current = null;
                foreach ($lines as $line) {
                    if (preg_match('/^\d+:\s+([^:]+):/', $line, $matches)) {
                        $current = $matches[1];
                        continue;
                    }
                    if ($current !== null && preg_match('/\s+inet\s+(\d+\.\d+\.\d+\.\d+)\/(\d+)/', $line, $matches)) {
                        $ip = $matches[1];
                        $prefix = $matches[2];
                        $netmask = long2ip(-1 << (32 - $prefix));
                        $isLoopback = $current === 'lo';
                        $interfaces[] = new Network($current, $ip, '', $netmask, $isLoopback, true);
                        $current = null;
                    }
                }
            }
        }

        $this->logger->debug("Found " . count($interfaces) . " local interfaces.");
        $this->cache->set($cacheKey, json_encode($interfaces), $ttl);
        return $interfaces;
    }

    /**
     * Ping a host with caching and event.
     */
    public function ping(string $host, int $timeout = 2): bool
    {
        $result = $this->scanner->ping($host, $timeout);
        $this->logger->info("Ping {$host}: " . ($result ? 'success' : 'failure'));
        if ($this->eventListener) {
            $this->eventListener->trigger('network.ping', ['host' => $host, 'reachable' => $result]);
        }
        return $result;
    }

    /**
     * Scan for active hosts on a subnet (synchronous).
     */
    public function scanSubnet(string $subnet): array
    {
        $result = $this->scanner->pingScan($subnet);
        if ($this->eventListener) {
            $this->eventListener->trigger('network.scan.completed', ['subnet' => $subnet, 'hosts' => $result]);
        }
        return $result;
    }

    /**
     * Scan subnet asynchronously via queue (if dispatcher available).
     */
    public function scanSubnetAsync(string $subnet, ?callable $callback = null): void
    {
        if ($this->dispatcher) {
            // Create a ScanSubnetJob (assuming class exists)
            $job = new \Mlangeni\Machinjiri\App\Jobs\ScanSubnetJob($this->scanner, $subnet, $callback);
            $this->dispatcher->dispatch($job);
        } else {
            // Fallback to synchronous
            $result = $this->scanSubnet($subnet);
            if ($callback) {
                $callback($result);
            }
        }
    }

    public function scanPorts(string $host, int $startPort = 1, int $endPort = 1024): array
    {
        return $this->scanner->portScan($host, $startPort, $endPort);
    }

    public function resolve(string $hostname): ?string
    {
        return $this->scanner->resolveHostname($hostname);
    }

    public function dnsLookup(string $domain, int $type = DNS_A): array
    {
        return $this->scanner->dnsLookup($domain, $type);
    }

    public function monitorHost(string $host, ?string $alias = null): self
    {
        $this->monitor->addHost($host, $alias);
        return $this;
    }

    public function getMonitorStatus(): array
    {
        return $this->monitor->getLastResults();
    }

    public function checkMonitor(): array
    {
        return $this->monitor->check();
    }

    public function getMonitorHistory(string $alias, int $limit = 10): array
    {
        return $this->monitor->getHistory($alias, $limit);
    }
}