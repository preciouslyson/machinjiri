<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Process;

interface QueueDriverInterface
{
    public function push(array $payload): string;
    public function later(int $delay, array $payload): string;
    public function delete(string $jobId): void;
    public function fail(array $payload): void;
    public function retry(string $jobId, array $payload): void;
    public function getFailed(string $jobId): ?array;
}