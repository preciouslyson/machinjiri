<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Caching\Stores;

use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheItem;
use Mlangeni\Machinjiri\Core\Artisans\Caching\Contracts\CacheStore;
use Mlangeni\Machinjiri\Core\Artisans\Caching\Metrics\CacheMetrics;
use Mlangeni\Machinjiri\Core\Artisans\Caching\Serializers\SerializerInterface;
use Mlangeni\Machinjiri\Core\Artisans\Caching\Serializers\CompressorInterface;

class ArrayStore implements CacheStore
{
    protected array $items = [];
    protected array $lru = []; // ordered list for LRU
    protected int $maxItems;
    protected string $evictionPolicy;
    protected SerializerInterface $serializer;
    protected ?CompressorInterface $compressor;
    protected CacheMetrics $metrics;

    public function __construct(
        int $maxItems,
        string $evictionPolicy,
        SerializerInterface $serializer,
        ?CompressorInterface $compressor,
        CacheMetrics $metrics
    ) {
        $this->maxItems = $maxItems;
        $this->evictionPolicy = $evictionPolicy;
        $this->serializer = $serializer;
        $this->compressor = $compressor;
        $this->metrics = $metrics;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->metrics->recordHitMiss('get');
        if (!isset($this->items[$key])) {
            $this->metrics->recordMiss();
            return $default;
        }
        $item = $this->items[$key];
        if ($item->isExpired()) {
            $this->delete($key);
            $this->metrics->recordMiss();
            return $default;
        }
        $item->hit();
        $this->updateLRU($key);
        $this->metrics->recordHit();
        return $item->value;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->metrics->recordWrite();
        $this->evictIfNeeded();
        $this->items[$key] = new CacheItem($value, $ttl);
        $this->updateLRU($key);
        return true;
    }

    public function delete(string $key): bool
    {
        if (isset($this->items[$key])) {
            unset($this->items[$key]);
            $this->removeFromLRU($key);
        }
        return true;
    }

    public function clear(): bool
    {
        $this->items = [];
        $this->lru = [];
        return true;
    }

    public function has(string $key): bool
    {
        return isset($this->items[$key]) && !$this->items[$key]->isExpired();
    }

    public function increment(string $key, int $value = 1): int|false
    {
        $current = $this->get($key, 0);
        if (!is_numeric($current)) return false;
        $new = $current + $value;
        $this->set($key, $new);
        return $new;
    }

    public function decrement(string $key, int $value = 1): int|false
    {
        return $this->increment($key, -$value);
    }

    public function getMultiple(array $keys, mixed $default = null): array
    {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key, $default);
        }
        return $results;
    }

    public function setMultiple(array $values, ?int $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return true;
    }

    public function deleteMultiple(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    protected function evictIfNeeded(): void
    {
        if (count($this->items) < $this->maxItems) {
            return;
        }
        if ($this->evictionPolicy === 'lru') {
            $this->evictLRU();
        } elseif ($this->evictionPolicy === 'lfu') {
            $this->evictLFU();
        }
    }

    protected function evictLRU(): void
    {
        if (empty($this->lru)) return;
        $keyToEvict = array_shift($this->lru);
        unset($this->items[$keyToEvict]);
    }

    protected function evictLFU(): void
    {
        $minHits = PHP_INT_MAX;
        $keyToEvict = null;
        foreach ($this->items as $key => $item) {
            if ($item->hitCount < $minHits) {
                $minHits = $item->hitCount;
                $keyToEvict = $key;
            }
        }
        if ($keyToEvict) {
            unset($this->items[$keyToEvict]);
            $this->removeFromLRU($keyToEvict);
        }
    }

    protected function updateLRU(string $key): void
    {
        $this->removeFromLRU($key);
        $this->lru[] = $key;
    }

    protected function removeFromLRU(string $key): void
    {
        $index = array_search($key, $this->lru, true);
        if ($index !== false) {
            array_splice($this->lru, $index, 1);
        }
    }

    public function getStoreName(): string
    {
        return 'array';
    }
}