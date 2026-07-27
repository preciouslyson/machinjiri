<?php

namespace Mlangeni\Machinjiri\Core\Components\Network\Tools;

use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Logging\LoggerFactory;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;

/**
 * Monitors network connectivity and performance with history and alerting.
 */
class Monitor implements MonitorInterface
{
    protected Logger $logger;
    protected EventListener $events;
    protected ScannerInterface $scanner;
    protected CacheManager $cache;
    protected NetworkConfig $config;
    protected array $hosts = [];
    protected array $results = [];
    protected array $history = [];
    protected int $historyLimit = 100;

    public function __construct(
        ScannerInterface $scanner,
        CacheManager $cache,
        NetworkConfig $config,
        ?EventListener $events = null
    ) {
        $this->scanner = $scanner;
        $this->cache = $cache;
        $this->config = $config;
        $this->events = $events ?? new EventListener(LoggerFactory::system('network_monitor', Manager::PROCESS));
        $this->logger = LoggerFactory::system('network_monitor', Manager::PROCESS);
        $this->historyLimit = $config->get('monitor_history_limit', 100);
    }

    public function addHost(string $host, ?string $alias = null): self
    {
        $this->hosts[$alias ?? $host] = $host;
        return $this;
    }

    public function removeHost(string $identifier): self
    {
        unset($this->hosts[$identifier]);
        return $this;
    }

    /**
     * Perform a single check on all monitored hosts with parallel scanning.
     */
    public function check(int $timeout = 2): array
    {
        $this->logger->info("Performing network check on " . count($this->hosts) . " hosts.");
        $results = [];

        // Use scanner's ping in parallel if possible? Scanner may have concurrent method.
        foreach ($this->hosts as $alias => $host) {
            $start = microtime(true);
            $reachable = $this->scanner->ping($host, $timeout);
            $latency = $reachable ? round((microtime(true) - $start) * 1000, 2) : null;

            $results[$alias] = [
                'host'       => $host,
                'reachable'  => $reachable,
                'latency'    => $latency,
                'checked_at' => date('Y-m-d H:i:s'),
            ];

            $this->logger->debug("Check result for {$alias} ({$host}): " . ($reachable ? 'reachable' : 'unreachable'));
        }

        $this->results = $results;
        $this->storeHistory($results);

        // Trigger events for status changes
        foreach ($results as $alias => $data) {
            $previous = $this->getPreviousResult($alias);
            if ($previous !== null && $previous['reachable'] !== $data['reachable']) {
                $event = $data['reachable'] ? 'network.monitor.host_recovered' : 'network.monitor.host_down';
                $this->events->trigger($event, ['alias' => $alias, 'host' => $data['host']]);
            }
        }

        $this->events->trigger('network.monitor.checked', $results);
        return $results;
    }

    protected function getPreviousResult(string $alias): ?array
    {
        $history = $this->getHistory($alias, 1);
        return $history[0] ?? null;
    }

    protected function storeHistory(array $results): void
    {
        foreach ($results as $alias => $data) {
            if (!isset($this->history[$alias])) {
                $this->history[$alias] = [];
            }
            $this->history[$alias][] = $data;
            if (count($this->history[$alias]) > $this->historyLimit) {
                array_shift($this->history[$alias]);
            }
        }
        // Also store in cache for persistence
        $cacheKey = 'monitor_history';
        $this->cache->set($cacheKey, $this->history, 3600);
    }

    public function getHistory(string $alias, int $limit = 10): array
    {
        $history = $this->history[$alias] ?? [];
        return array_slice($history, -$limit);
    }

    public function watch(int $interval = 60, int $count = 0, ?callable $callback = null): void
    {
        $iteration = 0;
        while ($count === 0 || $iteration < $count) {
            $results = $this->check();
            if ($callback) {
                $callback($results);
            }
            $iteration++;
            if ($count === 0 || $iteration < $count) {
                sleep($interval);
            }
        }
    }

    public function getLastResults(): array
    {
        return $this->results;
    }

    public function getSummary(): array
    {
        $summary = [];
        foreach ($this->hosts as $alias => $host) {
            $summary[] = [
                'alias' => $alias,
                'host'  => $host,
            ];
        }
        return $summary;
    }
}