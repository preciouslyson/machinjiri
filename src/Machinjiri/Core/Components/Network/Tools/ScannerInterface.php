<?php

namespace Mlangeni\Machinjiri\Core\Components\Network\Tools;

interface ScannerInterface
{
    public function setTimeout(int $seconds): self;
    public function setMaxPorts(int $max): self;
    public function ping(string $host, ?int $timeout = null): bool;
    public function pingScan(string $subnet): array;
    public function pingScanAsync(string $subnet, callable $callback): void;
    public function portScan(string $host, int $startPort = 1, int $endPort = 1024, ?int $timeout = null): array;
    public function resolveHostname(string $hostname): ?string;
    public function dnsLookup(string $domain, int $type = DNS_A): array;
}