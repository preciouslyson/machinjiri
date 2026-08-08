<?php

namespace Mlangeni\Machinjiri\Core\Transport\SMS\Circuit;

use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;

class CircuitBreaker
{
    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';

    private CacheManager $cache;
    private string $keyPrefix;
    private int $failureThreshold;
    private int $timeoutSeconds;

    public function __construct(CacheManager $cache, string $transportName, int $failureThreshold = 5, int $timeoutSeconds = 60)
    {
        $this->cache = $cache;
        $this->keyPrefix = "circuit_{$transportName}";
        $this->failureThreshold = $failureThreshold;
        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function isAvailable(): bool
    {
        $state = $this->cache->get("{$this->keyPrefix}_state", self::STATE_CLOSED);
        if ($state === self::STATE_OPEN) {
            $openedAt = (int) $this->cache->get("{$this->keyPrefix}_opened_at", 0);
            if (time() - $openedAt > $this->timeoutSeconds) {
                $this->setState(self::STATE_HALF_OPEN);
                return true;
            }
            return false;
        }
        return true;
    }

    public function recordSuccess(): void
    {
        $this->cache->delete("{$this->keyPrefix}_failures");
        $this->setState(self::STATE_CLOSED);
    }

    public function recordFailure(): void
    {
        $failures = $this->cache->increment("{$this->keyPrefix}_failures");
        if ($failures >= $this->failureThreshold) {
            $this->setState(self::STATE_OPEN);
            $this->cache->set("{$this->keyPrefix}_opened_at", time(), $this->timeoutSeconds);
        }
    }

    private function setState(string $state): void
    {
        $this->cache->set("{$this->keyPrefix}_state", $state);
    }
}