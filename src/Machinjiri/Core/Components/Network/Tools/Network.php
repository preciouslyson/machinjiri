<?php

namespace Mlangeni\Machinjiri\Core\Components\Network\Tools;

/**
 * Represents a network interface with properties such as name, IP, MAC, etc.
 */
class Network implements NetworkInterface
{
    protected string $name;
    protected string $ip;
    protected string $mac;
    protected string $netmask;
    protected bool $isLoopback;
    protected bool $isUp;

    public function __construct(
        string $name,
        string $ip,
        string $mac = '',
        string $netmask = '',
        bool $isLoopback = false,
        bool $isUp = true
    ) {
        $this->name = $name;
        $this->ip = $ip;
        $this->mac = $mac;
        $this->netmask = $netmask;
        $this->isLoopback = $isLoopback;
        $this->isUp = $isUp;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function getMac(): string
    {
        return $this->mac;
    }

    public function getNetmask(): string
    {
        return $this->netmask;
    }

    public function isLoopback(): bool
    {
        return $this->isLoopback;
    }

    public function isUp(): bool
    {
        return $this->isUp;
    }

    public function getNetworkAddress(): string
    {
        return $this->ip . '/' . $this->getCidrFromNetmask();
    }

    protected function getCidrFromNetmask(): int
    {
        if (empty($this->netmask)) {
            return 0;
        }
        $bits = 0;
        $mask = ip2long($this->netmask);
        while ($mask & 0x80000000) {
            $bits++;
            $mask <<= 1;
        }
        return $bits;
    }

    public function toArray(): array
    {
        return [
            'name'       => $this->name,
            'ip'         => $this->ip,
            'mac'        => $this->mac,
            'netmask'    => $this->netmask,
            'network'    => $this->getNetworkAddress(),
            'is_loopback' => $this->isLoopback,
            'is_up'      => $this->isUp,
        ];
    }
}