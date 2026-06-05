<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Caching\Metrics;

class CacheMetrics
{
    protected int $hits = 0;
    protected int $misses = 0;
    protected int $writes = 0;
    protected array $operationLatency = [];
    protected array $resultSizes = [];

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

    public function reset(): void
    {
        $this->hits = $this->misses = $this->writes = 0;
    }
    
    /**
     * Increment cache hit counter.
     */
    public function incrementCacheHit(): void
    {
        $this->recordHit();
    }
    
    /**
     * Increment cache miss counter.
     */
    public function incrementCacheMiss(): void
    {
        $this->recordMiss();
    }
    
    /**
     * Increment cache invalidation counter for a specific table.
     *
     * @param string $table
     */
    public function incrementCacheInvalidation(string $table): void
    {
        // Store per-table invalidation counts
        if (!isset($this->invalidationCounts)) {
            $this->invalidationCounts = [];
        }
        $this->invalidationCounts[$table] = ($this->invalidationCounts[$table] ?? 0) + 1;
        $this->writes++; // optional: count as a write operation
    }
    
    /**
     * Record time taken to generate a cache key.
     *
     * @param float $duration Time in seconds
     */
    public function recordKeyGenerationTime(float $duration): void
    {
        if (!isset($this->operationLatency['key_generation'])) {
            $this->operationLatency['key_generation'] = [];
        }
        $this->operationLatency['key_generation'][] = $duration;
    }
    
    /**
     * Record the size of a cached result.
     *
     * @param int $size Size in bytes
     */
    public function recordResultSize(int $size): void
    {
        if (!isset($this->resultSizes)) {
            $this->resultSizes = [];
        }
        $this->resultSizes[] = $size;
    }
    
    // Optional: extend getStats() to include the new metrics
    public function getStats(): array
    {
        $keyGenLatency = $this->operationLatency['key_generation'] ?? [];
        $avgKeyGen = !empty($keyGenLatency) ? array_sum($keyGenLatency) / count($keyGenLatency) : 0;
        
        $avgResultSize = !empty($this->resultSizes) ? array_sum($this->resultSizes) / count($this->resultSizes) : 0;
        
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'writes' => $this->writes,
            'hit_rate' => $this->getHitRate(),
            'avg_key_generation_time' => $avgKeyGen,
            'avg_result_size_bytes' => $avgResultSize,
            'invalidations_by_table' => $this->invalidationCounts ?? [],
        ];
    }
    
}