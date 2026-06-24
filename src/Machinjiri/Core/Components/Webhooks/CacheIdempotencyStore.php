<?php

namespace Mlangeni\Machinjiri\Core\Components\Webhooks;

use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;

class CacheIdempotencyStore implements IdempotencyStore
{
    private CacheManager $cache;
    private string $prefix;

    public function __construct(CacheManager $cache, string $prefix = 'webhook_idempotent:')
    {
        $this->cache = $cache;
        $this->prefix = $prefix;
    }

    public function lock(string $key, int $ttl = 3600): bool
    {
        $store = $this->cache->store();
        $lockKey = $this->prefix . $key . ':lock';
        // Use add() which only sets if not exists (atomic)
        return $store->add($lockKey, true, $ttl);
    }

    public function markDone(string $key): void
    {
        $store = $this->cache->store();
        $doneKey = $this->prefix . $key . ':done';
        $store->set($doneKey, true, 86400); // keep for 24 hours
        // Release lock
        $lockKey = $this->prefix . $key . ':lock';
        $store->delete($lockKey);
    }

    public function isDone(string $key): bool
    {
        $store = $this->cache->store();
        $doneKey = $this->prefix . $key . ':done';
        return $store->get($doneKey, false) === true;
    }
}