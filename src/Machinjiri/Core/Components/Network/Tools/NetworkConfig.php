<?php

namespace Mlangeni\Machinjiri\Core\Components\Network\Tools;

/**
 * Configuration container for the network component.
 */
class NetworkConfig
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->defaults(), $config);
    }

    private function defaults(): array
    {
        return [
            'ping_timeout' => 2,
            'scan_timeout' => 5,
            'max_ports' => 1024,
            'retry_attempts' => 2,
            'retry_delay' => 1, // seconds
            'concurrent_pings' => 20,
            'cache_ttl' => 300, // seconds
            'monitor_interval' => 60,
            'monitor_history_limit' => 100,
            'default_subnet' => '192.168.1.0/24',
            'use_async_scan' => false,
        ];
    }

    public function get(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->config;
    }

    public function set(string $key, $value): void
    {
        $this->config[$key] = $value;
    }
}