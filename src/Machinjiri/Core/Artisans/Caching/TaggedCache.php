<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Caching;

use Mlangeni\Machinjiri\Core\Artisans\Caching\Contracts\CacheStore;

class TaggedCache
{
    protected CacheStore $store;
    protected array $tags;
    protected string $tagPrefix = 'tag:';

    public function __construct(CacheStore $store, array $tags)
    {
        $this->store = $store;
        $this->tags = $tags;
    }

    protected function buildKey(string $key): string
    {
        $tagHash = sha1(implode('|', $this->tags));
        return "tags:{$tagHash}:{$key}";
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store->get($this->buildKey($key), $default);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->store->set($this->buildKey($key), $value, $ttl);
        // Also store reverse index for tag invalidation
        foreach ($this->tags as $tag) {
            $tagKey = $this->tagPrefix . $tag;
            $this->store->set(
                $tagKey . ':' . $key,
                null,
                $ttl
            ); // just to mark membership
        }
        return true;
    }

    public function flush(): bool
    {
        $keysToDelete = [];
        foreach ($this->tags as $tag) {
            $tagKey = $this->tagPrefix . $tag;
            // In real impl you'd need to scan; simplified
        }
        return $this->store->clear(); // simplified
    }
}