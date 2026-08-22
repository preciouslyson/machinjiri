<?php

namespace Mlangeni\Machinjiri\Core\Exceptions\ErrorHandler;

class ErrorContext
{
    private static array $context = [];

    public static function addContext(array $context): void
    {
        self::$context = array_merge(self::$context, $context);
    }

    public static function clearContext(): void
    {
        self::$context = [];
    }

    public static function getContext(): array
    {
        return self::$context;
    }
}