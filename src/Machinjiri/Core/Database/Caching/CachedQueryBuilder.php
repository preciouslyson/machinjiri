<?php

namespace Mlangeni\Machinjiri\Core\Database\Caching;

use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;
use Mlangeni\Machinjiri\Core\Database\QueryBuilder;
use Mlangeni\Machinjiri\Core\Artisans\Caching\Metrics\CacheMetrics;

class CachedQueryBuilder
{
    protected QueryBuilder $builder;
    protected CacheManager $cache;
    protected CacheMetrics $metrics;
    protected int $defaultTtl = 3600; // 1 hour

    // Per‑instance cache control flags
    protected bool $cacheEnabled = true;
    protected ?int $cacheTtl = null;
    protected array $cacheTags = [];
    protected ?string $cacheStrategy = null;

    public function __construct(QueryBuilder $builder, CacheManager $cache)
    {
        $this->builder = $builder;
        $this->cache = $cache;
        $this->metrics = $cache->getMetrics();
    }
    
    // -------------------------------------------------------------------------
    // Public API for cache control
    // -------------------------------------------------------------------------

    public function withoutCache(): self
    {
        $clone = clone $this;
        $clone->cacheEnabled = false;
        return $clone;
    }

    public function withCache(?int $ttl = null, array $tags = []): self
    {
        $clone = clone $this;
        $clone->cacheEnabled = true;
        $clone->cacheTtl = $ttl;
        $clone->cacheTags = array_merge($clone->cacheTags, $tags);
        return $clone;
    }

    public function withStrategy(string $strategy): self
    {
        $clone = clone $this;
        $clone->cacheStrategy = $strategy;
        return $clone;
    }

    /**
     * Delegate all method calls to the underlying builder,
     * but cache the result of terminal methods (get, first, count, etc.)
     */
    public function __call(string $name, array $arguments)
    {
        // Terminal methods that can be cached
        $terminal = ['get', 'first', 'count', 'sum', 'avg', 'min', 'max'];
        $writing = ['insert', 'update', 'delete', 'replace', 'truncate'];

        if (in_array($name, $writing)) {
            return $this->handleWrite($name, $arguments);
        }

        if (in_array($name, $terminal) && $this->cacheEnabled) {
            return $this->cacheResult($name, $arguments);
        }

        // Proxy the call
        $result = $this->builder->$name(...$arguments);

        // If the builder is immutable (returns new instance), wrap it again
        if ($result instanceof QueryBuilder) {
            $decorated = clone $this;
            $decorated->builder = $result;
            return $decorated;
        }

        return $result;
    }
    
    protected function handleWrite(string $method, array $arguments)
    {
        // Extract affected tables from the current query state
        $sql = $this->builder->toSql();
        $tables = $this->extractTableNames($sql);

        $result = $this->builder->$method(...$arguments);

        // Invalidate cache tags for all affected tables
        foreach ($tables as $table) {
            $this->cache->tags([$table])->clear();
            $this->metrics->incrementCacheInvalidation($table);
        }

        return $result;
    }

    protected function cacheResult(string $method, array $arguments)
    {
        // Clone builder to freeze current state
        $builderClone = clone $this->builder;

        $sql = $builderClone->compileSelect();
        $bindings = $builderClone->getBindings();

        // Determine effective TTL
        $ttl = $this->determineTtl($sql);

        // Generate efficient cache key
        $cacheKey = $this->generateCacheKey($sql, $bindings);

        // Extract table names for tagging
        $tables = $this->extractTableNames($sql);
        $effectiveTags = array_merge($this->cacheTags, $tables);

        $start = microtime(true);
        $cached = $this->cache->tags($effectiveTags)->get($cacheKey);
        $this->metrics->recordKeyGenerationTime(microtime(true) - $start);

        if ($cached !== null) {
            $this->metrics->incrementCacheHit();
            return $cached;
        }

        $this->metrics->incrementCacheMiss();

        // Use CacheManager's remember with stampede protection
        $result = $this->cache->remember(
            $cacheKey,
            function () use ($builderClone, $method, $arguments) {
                return $builderClone->$method(...$arguments);
            },
            $ttl,
            $effectiveTags  // Pass tags to CacheManager (requires extending remember)
        );

        // Store size metric
        $this->metrics->recordResultSize(strlen(serialize($result)));

        return $result;
    }
    
    protected function determineTtl(string $sql): int
    {
        if ($this->cacheTtl !== null) {
            return $this->cacheTtl;
        }

        // Strategy-based TTL (configurable via external mapping)
        if ($this->cacheStrategy) {
            $ttl = $this->cache->getStrategyTtl($this->cacheStrategy);
            if ($ttl !== null) {
                return $ttl;
            }
        }

        // Table‑based strategy (e.g., from config/cache_queries.php)
        $tables = $this->extractTableNames($sql);
        foreach ($tables as $table) {
            $strategy = $this->cache->getTableStrategy($table);
            if ($strategy) {
                return $this->cache->getStrategyTtl($strategy) ?? $this->defaultTtl;
            }
        }

        return $this->defaultTtl;
    }

    protected function generateCacheKey(string $sql, array $bindings): string
    {
        static $sqlHashes = [];
        if (!isset($sqlHashes[$sql])) {
            $sqlHashes[$sql] = md5($sql);
        }

        // Normalise bindings for consistent hashing
        $normalized = array_map(function ($v) {
            if (is_array($v)) {
                sort($v);
                return md5(serialize($v));
            }
            return (string) $v;
        }, $bindings);

        return 'dbq:' . $sqlHashes[$sql] . ':' . md5(implode('|', $normalized));
    }
    
    protected function extractTableNames(string $sql): array
    {
        preg_match_all('/\b(?:FROM|JOIN|UPDATE|INTO)\s+([`"\']?)([a-zA-Z0-9_]+)\1/iu', $sql, $matches);
        $tables = array_unique($matches[2] ?? []);
        return array_values(array_filter($tables));
    }

    // Also expose raw builder methods if needed
    public function getBuilder(): QueryBuilder
    {
        return $this->builder;
    }
}