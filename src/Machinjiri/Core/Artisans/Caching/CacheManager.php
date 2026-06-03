<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Caching;

use Mlangeni\Machinjiri\Core\Artisans\Caching\Contracts\CacheStore;
use Mlangeni\Machinjiri\Core\Artisans\Caching\Stores\ArrayStore;
use Mlangeni\Machinjiri\Core\Artisans\Caching\Stores\FileStore;
use Mlangeni\Machinjiri\Core\Artisans\Caching\Stores\RedisStore;
use Mlangeni\Machinjiri\Core\Artisans\Caching\Serializers\JsonSerializer;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\FileSystem\FileSystem;
use Mlangeni\Machinjiri\Core\FileSystem\Adapters\LocalAdapter;

class CacheManager
{
    protected array $stores = [];
    protected array $config;
    protected ?CacheStore $defaultStore = null;
    protected Metrics\CacheMetrics $metrics;
    protected ?StampedeProtector $stampedeProtector = null;

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->metrics = new Metrics\CacheMetrics();
        $this->stampedeProtector = new StampedeProtector($this->metrics);
    }

    protected function getDefaultConfig(): array
    {
        return [
            'default' => 'array',
            'stores' => [
                'array' => [
                    'driver' => 'array',
                    'max_items' => 1000,
                    'eviction' => 'lru'
                ],
                'file' => [
                    'driver' => 'file',
                    'path' => null,
                    'max_files' => 5000,
                    'file_perm' => 0644
                ],
                'redis' => [
                    'driver' => 'redis',
                    'host' => '127.0.0.1',
                    'port' => 6379,
                    'password' => null,
                    'database' => 0,
                    'prefix' => 'cache:'
                ]
            ],
            'serializer' => 'json',
            'compression' => false,
            'compression_algorithm' => 'zlib', // zlib, snappy, lz4
            'prefix' => 'app',
            'default_ttl' => 3600, // seconds
            'stampede_protection' => true,
            'circuit_breaker' => [
                'enabled' => false,
                'failures_threshold' => 5,
                'timeout' => 30
            ]
        ];
    }

    public function store(?string $name = null): CacheStore
    {
        $name = $name ?? $this->config['default'];
        if (!isset($this->stores[$name])) {
            $this->stores[$name] = $this->resolveStore($name);
        }
        return $this->stores[$name];
    }

    protected function resolveStore(string $name): CacheStore
    {
        $storeConfig = $this->config['stores'][$name] ?? null;
        if (!$storeConfig) {
            throw new MachinjiriException("Cache store [{$name}] not configured", 500);
        }

        $driver = $storeConfig['driver'];
        $serializer = $this->getSerializer();
        $compressor = $this->getCompressor();
        $basePath = $storeConfig['path'] ?? $this->getDefaultFilePath();
        // Ensure the 'app' subdirectory exists
        $cacheDir = rtrim($basePath, '/') . '/app';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        $adapter = new LocalAdapter($cacheDir);
        $filesystem = new FileSystem($adapter);

        return match($driver) {
            'array' => new ArrayStore(
                $storeConfig['max_items'] ?? 1000,
                $storeConfig['eviction'] ?? 'lru',
                $serializer,
                $compressor,
                $this->metrics
            ),
            'file' => new FileStore(
                $filesystem,
                $storeConfig['max_files'] ?? 5000,
                $serializer,
                $compressor,
                $this->metrics
            ),
            'redis' => new RedisStore(
                $storeConfig,
                $serializer,
                $compressor,
                $this->metrics
            ),
            default => throw new MachinjiriException("Unsupported cache driver [{$driver}]", 500),
        };
    }

    protected function getSerializer(): Serializers\SerializerInterface
    {
        $type = $this->config['serializer'] ?? 'json';
        return match($type) {
            'json' => new JsonSerializer(),
            default => new JsonSerializer(),
        };
    }

    protected function getCompressor(): ?Serializers\CompressorInterface
    {
        if (!$this->config['compression']) {
            return null;
        }
        $algo = $this->config['compression_algorithm'] ?? 'zlib';
        return new Serializers\Compressor($algo);
    }

    protected function getDefaultFilePath(): string
    {
        $container = Container::getInstance();
        $cachePath = $container->getCachePath();
        if (!is_dir($cachePath)) {
            mkdir($cachePath, 0755, true);
        }
        return $cachePath;
    }

    public function getMetrics(): Metrics\CacheMetrics
    {
        return $this->metrics;
    }

    public function getStampedeProtector(): StampedeProtector
    {
        return $this->stampedeProtector;
    }

    // Shortcut methods
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store()->get($key, $default);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->store()->set($key, $value, $ttl ?? $this->config['default_ttl']);
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $ttl = $ttl ?? $this->config['default_ttl'];
        $store = $this->store();

        if ($this->config['stampede_protection'] && $this->stampedeProtector) {
            return $this->stampedeProtector->remember($store, $key, $callback, $ttl);
        }

        $value = $store->get($key);
        if ($value !== null) {
            return $value;
        }
        $value = $callback();
        $store->set($key, $value, $ttl);
        return $value;
    }

    public function delete(string $key): bool
    {
        return $this->store()->delete($key);
    }

    public function clear(): bool
    {
        return $this->store()->clear();
    }

    public function tags(array $names): TaggedCache
    {
        return new TaggedCache($this->store(), $names);
    }
}