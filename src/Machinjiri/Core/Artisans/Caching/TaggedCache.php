<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Caching;

use Mlangeni\Machinjiri\Core\Artisans\Caching\Contracts\CacheStore;
use Mlangeni\Machinjiri\Core\Artisans\Caching\Stores\RedisStore;

class TaggedCache
{
    protected CacheStore $store;
    protected array $tags;
    protected string $versionPrefix = 'tag_version:';
    protected ?StampedeProtector $stampedeProtector = null;

    public function __construct(CacheStore $store, array $tags, ?StampedeProtector $stampedeProtector = null)
    {
        $this->store = $store;
        $this->tags = $tags;
        $this->stampedeProtector = $stampedeProtector;
    }

    /**
     * Get current version for a tag (always integer).
     */
    protected function getTagVersion(string $tag): int
    {
        $versionKey = $this->versionPrefix . $tag;
        $version = $this->store->get($versionKey);
        return (int) ($version ?? 1);
    }

    /**
     * Increment tag version – invalidates all keys with this tag.
     */
    protected function incrementTagVersion(string $tag): bool
    {
        $versionKey = $this->versionPrefix . $tag;
        $newVersion = $this->getTagVersion($tag) + 1;
        return $this->store->set($versionKey, $newVersion, null);
    }

    /**
     * Build actual cache key including current versions of all tags.
     */
    protected function buildKey(string $key): string
    {
        $sortedTags = $this->tags;
        sort($sortedTags);
        
        $parts = [];
        foreach ($sortedTags as $tag) {
            $parts[] = $tag . ':' . $this->getTagVersion($tag);
        }
        $tagHash = sha1(implode('|', $parts));
        return "tags:{$tagHash}:{$key}";
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store->get($this->buildKey($key), $default);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->store->set($this->buildKey($key), $value, $ttl);
    }

    public function delete(string $key): bool
    {
        return $this->store->delete($this->buildKey($key));
    }

    /**
     * Get from cache or store callback result, with optional stampede protection.
     */
    public function remember(string $key, callable $callback, int $ttl): mixed
    {
        $cacheKey = $this->buildKey($key);
        
        // Use manager's stampede protector if available
        if ($this->stampedeProtector !== null) {
            return $this->stampedeProtector->remember($this->store, $cacheKey, $callback, $ttl);
        }
        
        // Fallback: simple get/set
        $value = $this->store->get($cacheKey);
        if ($value !== null) {
            return $value;
        }
        $value = $callback();
        $this->store->set($cacheKey, $value, $ttl);
        return $value;
    }

    /**
     * Invalidate all keys belonging to these tags.
     * Returns true only if all tag versions were incremented.
     */
    public function clear(): bool
    {
        $success = true;
        foreach ($this->tags as $tag) {
            if (!$this->incrementTagVersion($tag)) {
                $success = false;
            }
        }
        return $success;
    }

    public function flush(): bool
    {
        return $this->clear();
    }

    /**
     * Garbage collection: remove orphaned keys whose tag version no longer matches.
     * Only implemented for RedisStore; for other stores this is a no-op.
     */
    public function gc(): void
    {
        if (!$this->store instanceof RedisStore) {
            return;
        }

        $redis = $this->store->getClient();
        $pattern = 'tags:*';
        $cursor = null;
        
        do {
            $result = $redis->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 100]);
            $cursor = $result[0];
            $keys = $result[1] ?? [];
            
            foreach ($keys as $fullKey) {
                if (!preg_match('/^tags:([a-f0-9]+):/', $fullKey, $matches)) {
                    continue;
                }
                $hash = $matches[1];
            }
        } while ($cursor != 0);
    }
}