<?php

namespace Mlangeni\Machinjiri\Core\Exceptions;

class InvalidUuidStringException extends UuidException
{
    public function __construct(string $uuidString, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct(
            "Invalid UUID string format: {$uuidString}",
            $code,
            $previous,
            ['uuid_string' => $uuidString],
            'validation'
        );
    }
}