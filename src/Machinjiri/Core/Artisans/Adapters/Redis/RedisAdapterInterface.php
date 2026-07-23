<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Adapters\Redis;

use Predis\ClientInterface;

interface RedisAdapterInterface
{
    public function get(string $key);
    public function set(string $key, $value, ?int $ttl = null): bool;
    public function delete(string ...$keys): int;
    public function exists(string $key): bool;
    public function increment(string $key, int $by = 1): int;
    public function decrement(string $key, int $by = 1): int;
    public function expire(string $key, int $seconds): bool;
    public function persist(string $key): bool;
    public function ttl(string $key): ?int;
    public function ping(): bool;
    public function pipeline(callable $callback): array;
    public function transaction(callable $callback): array;
    public function getClient(): ClientInterface;
    public function disconnect(): void;
}