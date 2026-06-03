<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Caching;

class CacheItem
{
    public mixed $value;
    public ?int $expiresAt = null;
    public int $hitCount = 0;
    public int $lastAccess = 0;

    public function __construct(mixed $value, ?int $ttl = null)
    {
        $this->value = $value;
        if ($ttl !== null) {
            $this->expiresAt = time() + $ttl;
        }
        $this->lastAccess = time();
    }

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && time() > $this->expiresAt;
    }

    public function hit(): void
    {
        $this->hitCount++;
        $this->lastAccess = time();
    }
}