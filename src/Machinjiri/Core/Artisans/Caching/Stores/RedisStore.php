<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Caching\Stores;

use Mlangeni\Machinjiri\Core\Artisans\Caching\Contracts\CacheStore;
use Mlangeni\Machinjiri\Core\Artisans\Caching\Metrics\CacheMetrics;
use Mlangeni\Machinjiri\Core\Artisans\Caching\Serializers\SerializerInterface;
use Mlangeni\Machinjiri\Core\Artisans\Caching\Serializers\CompressorInterface;
use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheException;
use Predis\Client as PredisClient;

class RedisStore implements CacheStore
{
    protected PredisClient $redis;
    protected string $prefix;
    protected SerializerInterface $serializer;
    protected ?CompressorInterface $compressor;
    protected CacheMetrics $metrics;

    /**
     * @param array $config Configuration with keys: host, port, password, database, prefix, timeout, read_timeout
     * @throws CacheException if Predis is not installed or connection fails
     */
    public function __construct(
        array $config,
        SerializerInterface $serializer,
        ?CompressorInterface $compressor,
        CacheMetrics $metrics
    ) {
        if (!class_exists(PredisClient::class)) {
            throw new CacheException(
                'Predis client not installed. Please run: composer require predis/predis',
                500
            );
        }

        $this->serializer = $serializer;
        $this->compressor = $compressor;
        $this->metrics = $metrics;
        $this->prefix = $config['prefix'] ?? 'cache:';

        $parameters = [
            'scheme' => $config['scheme'] ?? 'tcp',
            'host'   => $config['host'] ?? '127.0.0.1',
            'port'   => $config['port'] ?? 6379,
            'database' => $config['database'] ?? 0,
            'timeout'  => $config['timeout'] ?? 0.0,
            'read_write_timeout' => $config['read_timeout'] ?? 0.0,
        ];

        if (!empty($config['password'])) {
            $parameters['password'] = $config['password'];
        }

        $options = [
            'prefix' => $this->prefix, // Predis supports prefixing directly
        ];

        try {
            $this->redis = new PredisClient($parameters, $options);
            $this->redis->connect();
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
        $value = $this->redis->get($this->key($key));

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
            $this->redis->setex($redisKey, $ttl, $serialized);
        } else {
            $this->redis->set($redisKey, $serialized);
        }
        return true;
    }

    public function delete(string $key): bool
    {
        $deleted = $this->redis->del([$this->key($key)]);
        return $deleted > 0;
    }

    public function clear(): bool
    {
        // Using flushdb would clear everything, but we only want keys with prefix.
        // Since Predis adds prefix automatically, flushdb is safe only if no other apps use same db.
        // For safety, we iterate over keys with prefix and delete them.
        $pattern = $this->prefix . '*';
        $keys = $this->redis->keys($pattern);
        if (!empty($keys)) {
            $this->redis->del($keys);
        }
        return true;
    }

    public function has(string $key): bool
    {
        return (bool) $this->redis->exists($this->key($key));
    }

    public function increment(string $key, int $value = 1): int|false
    {
        return $this->redis->incrby($this->key($key), $value);
    }

    public function decrement(string $key, int $value = 1): int|false
    {
        return $this->redis->decrby($this->key($key), $value);
    }

    public function getMultiple(array $keys, mixed $default = null): array
    {
        $prefixedKeys = array_map([$this, 'key'], $keys);
        $values = $this->redis->mget($prefixedKeys);

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
        $pipeline = $this->redis->pipeline();
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
        $deleted = $this->redis->del($prefixed);
        return $deleted > 0;
    }

    public function getStoreName(): string
    {
        return 'redis';
    }
}