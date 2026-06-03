<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Caching\Stores;

use Mlangeni\Machinjiri\Core\Artisans\Caching\Contracts\CacheStore;
use Mlangeni\Machinjiri\Core\Artisans\Caching\Metrics\CacheMetrics;
use Mlangeni\Machinjiri\Core\Artisans\Caching\Serializers\SerializerInterface;
use Mlangeni\Machinjiri\Core\Artisans\Caching\Serializers\CompressorInterface;
use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheException;
use Mlangeni\Machinjiri\Core\FileSystem\Filesystem;
use Mlangeni\Machinjiri\Core\FileSystem\Adapters\LocalAdapter;

class FileStore implements CacheStore
{
    protected Filesystem $filesystem;
    protected string $directory = 'store';
    protected int $maxFiles;
    protected SerializerInterface $serializer;
    protected ?CompressorInterface $compressor;
    protected CacheMetrics $metrics;

    /**
     * @param Filesystem $filesystem Already configured with the cache root directory
     * @param int $maxFiles Maximum number of cache files allowed (soft limit)
     * @param SerializerInterface $serializer
     * @param CompressorInterface|null $compressor
     * @param CacheMetrics $metrics
     */
    public function __construct(
        Filesystem $filesystem,
        int $maxFiles,
        SerializerInterface $serializer,
        ?CompressorInterface $compressor,
        CacheMetrics $metrics
    ) {
        $this->filesystem = $filesystem;

        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0755);
        }

        $this->maxFiles = $maxFiles;
        $this->serializer = $serializer;
        $this->compressor = $compressor;
        $this->metrics = $metrics;
    }

    /**
     * Build file path for a given cache key.
     * Uses a sha1 hash to avoid filesystem issues and keep paths flat.
     */
    protected function path(string $key): string
    {
        return $this->directory . '/' . sha1($key) . '.cache';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->metrics->recordHitMiss('get');
        $path = $this->path($key);

        if (!$this->filesystem->exists($path)) {
            $this->metrics->recordMiss();
            return $default;
        }

        try {
            $data = $this->filesystem->read($path);
            $payload = unserialize($data);
            if (!$payload || !isset($payload['expires'], $payload['value'])) {
                $this->delete($key);
                $this->metrics->recordMiss();
                return $default;
            }

            if ($payload['expires'] !== null && time() > $payload['expires']) {
                $this->delete($key);
                $this->metrics->recordMiss();
                return $default;
            }

            $value = $payload['value'];
            if ($this->compressor) {
                $value = $this->compressor->uncompress($value);
            }
            $value = $this->serializer->unserialize($value);
            $this->metrics->recordHit();
            return $value;
        } catch (\Exception $e) {
            // Log error but treat as miss
            $this->metrics->recordMiss();
            return $default;
        }
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->metrics->recordWrite();
        $this->evictIfNeeded();

        $serialized = $this->serializer->serialize($value);
        if ($this->compressor) {
            $serialized = $this->compressor->compress($serialized);
        }

        $payload = [
            'expires' => $ttl ? time() + $ttl : null,
            'value' => $serialized
        ];
        $data = serialize($payload);
        $path = $this->path($key);

        try {
            return $this->filesystem->write($path, $data);
        } catch (\Exception $e) {
            throw new CacheException("Failed to write cache file: {$path}", 500, $e);
        }
    }

    public function delete(string $key): bool
    {
        $path = $this->path($key);
        if ($this->filesystem->exists($path)) {
            return $this->filesystem->delete($path);
        }
        return true;
    }

    public function clear(): bool
    {
        // List all cache files in directory (non‑recursive)
        $contents = $this->filesystem->listContents('', false);
        $deleted = true;
        foreach ($contents as $item) {
            if ($item['type'] === 'file' && str_ends_with($item['path'], '.cache')) {
                if (!$this->filesystem->delete($item['path'])) {
                    $deleted = false;
                }
            }
        }
        return $deleted;
    }

    public function has(string $key): bool
    {
        $path = $this->path($key);
        if (!$this->filesystem->exists($path)) {
            return false;
        }
        try {
            $data = $this->filesystem->read($path);
            $payload = unserialize($data);
            if (!$payload || !isset($payload['expires'])) {
                return false;
            }
            return !($payload['expires'] !== null && time() > $payload['expires']);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function increment(string $key, int $value = 1): int|false
    {
        $current = $this->get($key, 0);
        if (!is_numeric($current)) {
            return false;
        }
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
        $success = true;
        foreach ($values as $key => $value) {
            if (!$this->set($key, $value, $ttl)) {
                $success = false;
            }
        }
        return $success;
    }

    public function deleteMultiple(array $keys): bool
    {
        $success = true;
        foreach ($keys as $key) {
            if (!$this->delete($key)) {
                $success = false;
            }
        }
        return $success;
    }

    /**
     * Evict oldest cache files if exceeding maxFiles limit.
     */
    protected function evictIfNeeded(): void
    {
        $contents = $this->filesystem->listContents('', false);
        $cacheFiles = array_filter($contents, function ($item) {
            return $item['type'] === 'file' && str_ends_with($item['path'], '.cache');
        });

        if (count($cacheFiles) <= $this->maxFiles) {
            return;
        }

        // Sort by last modified ascending (oldest first)
        usort($cacheFiles, function ($a, $b) {
            return $a['lastModified'] <=> $b['lastModified'];
        });

        $toDelete = array_slice($cacheFiles, $this->maxFiles);
        foreach ($toDelete as $file) {
            $this->filesystem->delete($file['path']);
        }
    }

    public function getStoreName(): string
    {
        return 'file';
    }
}