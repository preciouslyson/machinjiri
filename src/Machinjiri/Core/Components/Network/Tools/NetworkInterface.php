<?php

namespace Mlangeni\Machinjiri\Core\Components\Network\Tools;

interface NetworkInterface
{
    public function getName(): string;
    public function getIp(): string;
    public function getMac(): string;
    public function getNetmask(): string;
    public function isLoopback(): bool;
    public function isUp(): bool;
    public function getNetworkAddress(): string;
    public function toArray(): array;
}