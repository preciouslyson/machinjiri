<?php

namespace Mlangeni\Machinjiri\Testing;

trait Assertions
{
    protected function assertInstanceOf(string $expected, $actual): void
    {
        \PHPUnit\Framework\Assert::assertInstanceOf($expected, $actual);
    }

    protected function assertTrue($condition, string $message = ''): void
    {
        \PHPUnit\Framework\Assert::assertTrue($condition, $message);
    }

    protected function assertFalse($condition, string $message = ''): void
    {
        \PHPUnit\Framework\Assert::assertFalse($condition, $message);
    }

    protected function assertCount(int $expected, array $haystack, string $message = ''): void
    {
        \PHPUnit\Framework\Assert::assertCount($expected, $haystack, $message);
    }

    protected function assertEmpty($actual, string $message = ''): void
    {
        \PHPUnit\Framework\Assert::assertEmpty($actual, $message);
    }
}