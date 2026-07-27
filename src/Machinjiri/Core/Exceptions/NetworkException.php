<?php

namespace Mlangeni\Machinjiri\Core\Exceptions;

/**
 * Exception specific to network operations.
 */
class NetworkException extends MachinjiriException
{
    public function __construct(
        string $message = "",
        int $code = 0,
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous, $context, 'network');
    }

    public static function connectionFailed(string $host, int $port, string $reason = ''): self
    {
        $message = "Connection to {$host}:{$port} failed" . ($reason ? ": {$reason}" : '');
        return new self($message, 500, null, ['host' => $host, 'port' => $port]);
    }

    public static function timeout(string $host, int $timeout): self
    {
        return new self("Connection to {$host} timed out after {$timeout}s", 408, null, ['host' => $host, 'timeout' => $timeout]);
    }

    public static function invalidConfiguration(string $configKey, string $value): self
    {
        return new self("Invalid network configuration for '{$configKey}': {$value}", 400, null, ['key' => $configKey, 'value' => $value]);
    }

    public static function subnetInvalid(string $subnet): self
    {
        return new self("Invalid subnet format: {$subnet}", 400, null, ['subnet' => $subnet]);
    }

    public static function hostUnreachable(string $host): self
    {
        return new self("Host {$host} is unreachable", 503, null, ['host' => $host]);
    }

    public static function dnsFailed(string $domain): self
    {
        return new self("DNS lookup failed for {$domain}", 500, null, ['domain' => $domain]);
    }
}