<?php

namespace Mlangeni\Machinjiri\Core\Exceptions;

class InvalidVersionException extends UuidException
{
    public function __construct(string $version, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct(
            "Unsupported UUID version: {$version}. Supported versions: 1, 3, 4.",
            $code,
            $previous,
            ['version' => $version],
            'validation'
        );
    }
}