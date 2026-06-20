<?php

namespace Mlangeni\Machinjiri\Core\Routing;

use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;
use Mlangeni\Machinjiri\Core\Routing\Contracts\RateLimiterInterface;

class RateLimiter implements RateLimiterInterface
{
    public function __construct(
        protected CacheManager $cache,
        protected array $limiters = []
    ) {}

    public function attempt(string $limiterName, string $clientId, ?int $maxRequests = null, ?int $period = null): bool
    {
        // Allow override, otherwise use config
        if ($maxRequests === null || $period === null) {
            $config = $this->limiters[$limiterName] ?? null;
            if (!$config) {
                return true; // no limiter defined, allow
            }
            $maxRequests = $config['max_requests'];
            $period = $config['period'];
        }

        $key = "rate_limit:{$limiterName}:{$clientId}";
        $store = $this->cache->store();
        $current = $store->get($key);

        if ($current === null) {
            $store->set($key, 1, $period);
            return true;
        }
        if ($current < $maxRequests) {
            $store->set($key, $current + 1, $period);
            return true;
        }
        return false;
    }
}