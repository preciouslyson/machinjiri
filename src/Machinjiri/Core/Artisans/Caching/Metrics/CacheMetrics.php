<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Caching\Metrics;

class CacheMetrics
{
    protected int $hits = 0;
    protected int $misses = 0;
    protected int $writes = 0;
    protected array $operationLatency = [];

    public function recordHit(): void
    {
        $this->hits++;
    }

    public function recordMiss(): void
    {
        $this->misses++;
    }

    public function recordWrite(): void
    {
        $this->writes++;
    }

    public function recordHitMiss(string $operation): void
    {
        // start time could be stored, but simplified
    }

    public function getHitRate(): float
    {
        $total = $this->hits + $this->misses;
        return $total === 0 ? 0 : $this->hits / $total;
    }

    public function getStats(): array
    {
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'writes' => $this->writes,
            'hit_rate' => $this->getHitRate()
        ];
    }

    public function reset(): void
    {
        $this->hits = $this->misses = $this->writes = 0;
    }
}