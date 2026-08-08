<?php

namespace Mlangeni\Machinjiri\Core\Transport\SMS\RateLimit;

use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;

class RateLimiter
{
    private CacheManager $cache;
    private string $key;
    private int $capacity;
    private int $refillRate; // tokens per second
    private int $refillInterval; // seconds

    public function __construct(CacheManager $cache, string $identifier, int $capacity, int $refillRate)
    {
        $this->cache = $cache;
        $this->key = "rate_limit_{$identifier}";
        $this->capacity = $capacity;
        $this->refillRate = $refillRate;
        $this->refillInterval = 1; // refill every second
    }

    public function allow(): bool
    {
        $bucket = $this->cache->get($this->key, ['tokens' => $this->capacity, 'last_refill' => time()]);
        $now = time();
        $elapsed = $now - $bucket['last_refill'];
        $tokensToAdd = floor($elapsed * $this->refillRate / $this->refillInterval);
        $bucket['tokens'] = min($this->capacity, $bucket['tokens'] + $tokensToAdd);
        $bucket['last_refill'] = $now;

        if ($bucket['tokens'] >= 1) {
            $bucket['tokens']--;
            $this->cache->set($this->key, $bucket, 3600);
            return true;
        }
        $this->cache->set($this->key, $bucket, 3600);
        return false;
    }
}