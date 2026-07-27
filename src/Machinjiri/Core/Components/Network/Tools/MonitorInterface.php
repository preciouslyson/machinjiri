<?php

namespace Mlangeni\Machinjiri\Core\Components\Network\Tools;

interface MonitorInterface
{
    public function addHost(string $host, ?string $alias = null): self;
    public function removeHost(string $identifier): self;
    public function check(int $timeout = 2): array;
    public function watch(int $interval = 60, int $count = 0, ?callable $callback = null): void;
    public function getLastResults(): array;
    public function getSummary(): array;
    public function getHistory(string $alias, int $limit = 10): array;
}