<?php
namespace Mlangeni\Machinjiri\Core\Transport\SMS\Idempotency;

use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;

class IdempotencyStore
{
    private CacheManager $cache;
    private int $ttl;

    public function __construct(CacheManager $cache, int $ttl = 60)
    {
        $this->cache = $cache;
        $this->ttl = $ttl;
    }

    public function isProcessed(string $key): bool
    {
        return $this->cache->has("sms_{$key}") !== null;
    }

    public function markProcessed(string $key): void
    {
        $this->cache->set("sms_{$key}", true, $this->ttl);
    }

    /**
     * Atomic lock – returns true if lock acquired.
     */
    public function lock(string $key, int $lockTtl = 30): bool
    {
        return $this->cache->set("lock_sms_{$key}", true, $lockTtl);
    }

    public function unlock(string $key): void
    {
        $this->cache->delete("lock_sms_{$key}");
    }
}