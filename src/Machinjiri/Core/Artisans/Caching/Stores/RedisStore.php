<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Caching\Stores;

use Mlangeni\Machinjiri\Core\Artisans\Caching\Contracts\CacheStore;
use Mlangeni\Machinjiri\Core\Artisans\Caching\Metrics\CacheMetrics;
use Mlangeni\Machinjiri\Core\Artisans\Caching\Serializers\SerializerInterface;
use Mlangeni\Machinjiri\Core\Artisans\Caching\Serializers\CompressorInterface;
use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheException;
use Mlangeni\Machinjiri\Core\Artisans\Adapters\Redis\RedisAdapter;
use Predis\ClientInterface;

class RedisStore implements CacheStore
{
    protected RedisAdapter $redis;
    protected ClientInterface $client;
    protected string $prefix;
    protected SerializerInterface $serializer;
    protected ?CompressorInterface $compressor;
    protected CacheMetrics $metrics;

    /**
     * @param array $config Configuration with keys: host, port, password, database, prefix, timeout, read_timeout
    * @throws CacheException if Redis connection fails
     */
    public function __construct(
        array $config,
        SerializerInterface $serializer,
        ?CompressorInterface $compressor,
        CacheMetrics $metrics
    ) {
        if (!class_exists(RedisAdapter::class)) {
            throw new CacheException(
                'Redis adapter is not available.',
                500
            );
        }

        $this->serializer = $serializer;
        $this->compressor = $compressor;
        $this->metrics = $metrics;
        $this->prefix = $config['prefix'] ?? 'cache:';

        try {
            $this->redis = new RedisAdapter([
                'default' => 'default',
                'connections' => [
                    'default' => [
                        'host' => $config['host'] ?? '127.0.0.1',
                        'port' => $config['port'] ?? 6379,
                        'password' => $config['password'] ?? null,
                        'database' => $config['database'] ?? 0,
                        'timeout' => $config['timeout'] ?? 0.0,
                        'read_write_timeout' => $config['read_timeout'] ?? 0.0,
                    ],
                ],
                'prefix' => trim($this->prefix, ':'),
                'serialize' => false,
            ]);
            $this->client = $this->redis->getClient();
        } catch (\Exception $e) {
            throw new CacheException(
                "Redis connection failed: " . $e->getMessage(),
                500,
                $e
            );
        }
    }

    /**
     * Builds the full key – Predis already prefixes via client options,
     * but we keep a method for clarity.
     */
    protected function key(string $key): string
    {
        // The client already adds the prefix automatically,
        // so we just return the raw key.
        return $key;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->metrics->recordHitMiss('get');
        $value = $this->client->get($this->key($key));

        if ($value === null) {
            $this->metrics->recordMiss();
            return $default;
        }

        if ($this->compressor) {
            $value = $this->compressor->uncompress($value);
        }

        $this->metrics->recordHit();
        return $this->serializer->unserialize($value);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->metrics->recordWrite();
        $serialized = $this->serializer->serialize($value);
        if ($this->compressor) {
            $serialized = $this->compressor->compress($serialized);
        }

        $redisKey = $this->key($key);
        if ($ttl !== null) {
            $this->client->setex($redisKey, $ttl, $serialized);
        } else {
            $this->client->set($redisKey, $serialized);
        }
        return true;
    }

    public function delete(string $key): bool
    {
        $deleted = $this->client->del([$this->key($key)]);
        return $deleted > 0;
    }

    public function clear(): bool
    {
        // Using flushdb would clear everything, but we only want keys with prefix.
        // Since Predis adds prefix automatically, flushdb is safe only if no other apps use same db.
        // For safety, we iterate over keys with prefix and delete them.
        $pattern = '*';
        $keys = $this->client->keys($pattern);
        if (!empty($keys)) {
            $this->client->del($keys);
        }
        return true;
    }

    public function has(string $key): bool
    {
        return (bool) $this->client->exists($this->key($key));
    }

    public function increment(string $key, int $value = 1): int|false
    {
        return $this->client->incrby($this->key($key), $value);
    }

    public function decrement(string $key, int $value = 1): int|false
    {
        return $this->client->decrby($this->key($key), $value);
    }

    public function getMultiple(array $keys, mixed $default = null): array
    {
        $prefixedKeys = array_map([$this, 'key'], $keys);
        $values = $this->client->mget($prefixedKeys);

        $results = [];
        foreach ($keys as $i => $key) {
            $val = $values[$i];
            if ($val === null) {
                $results[$key] = $default;
            } else {
                if ($this->compressor) {
                    $val = $this->compressor->uncompress($val);
                }
                $results[$key] = $this->serializer->unserialize($val);
            }
        }
        return $results;
    }

    public function setMultiple(array $values, ?int $ttl = null): bool
    {
        $pipeline = $this->client->pipeline();
        foreach ($values as $key => $value) {
            $serialized = $this->serializer->serialize($value);
            if ($this->compressor) {
                $serialized = $this->compressor->compress($serialized);
            }
            $redisKey = $this->key($key);
            if ($ttl !== null) {
                $pipeline->setex($redisKey, $ttl, $serialized);
            } else {
                $pipeline->set($redisKey, $serialized);
            }
        }
        $pipeline->execute();
        return true;
    }

    public function deleteMultiple(array $keys): bool
    {
        $prefixed = array_map([$this, 'key'], $keys);
        $deleted = $this->client->del($prefixed);
        return $deleted > 0;
    }

    public function getStoreName(): string
    {
        return 'redis';
    }
    
    public function getClient(): ClientInterface
    {
        return $this->redis->getClient();
    }
}