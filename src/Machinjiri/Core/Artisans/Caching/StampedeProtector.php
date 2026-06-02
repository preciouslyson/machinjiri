<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Caching;

use Mlangeni\Machinjiri\Core\Artisans\Caching\Contracts\CacheStore;

class StampedeProtector
{
    protected array $locks = [];
    protected Metrics\CacheMetrics $metrics;

    public function __construct(Metrics\CacheMetrics $metrics)
    {
        $this->metrics = $metrics;
    }

    public function remember(CacheStore $store, string $key, callable $callback, int $ttl): mixed
    {
        $value = $store->get($key);
        if ($value !== null) {
            return $value;
        }
        // acquire lock for this key
        $lockKey = "lock:{$key}";
        if ($this->acquireLock($lockKey)) {
            try {
                $value = $callback();
                $store->set($key, $value, $ttl);
                $this->releaseLock($lockKey);
                return $value;
            } catch (\Throwable $e) {
                $this->releaseLock($lockKey);
                throw $e;
            }
        } else {
            // wait for first thread to populate cache
            $wait = 0;
            while ($wait < 5) {
                usleep(50000); // 50ms
                $value = $store->get($key);
                if ($value !== null) {
                    return $value;
                }
                $wait++;
            }
            // fallback: compute anyway
            return $callback();
        }
    }

    protected function acquireLock(string $key): bool
    {
        // Simple memory lock for demo; production use Redis atomic setnx
        if (isset($this->locks[$key])) {
            return false;
        }
        $this->locks[$key] = true;
        return true;
    }

    protected function releaseLock(string $key): void
    {
        unset($this->locks[$key]);
    }
}