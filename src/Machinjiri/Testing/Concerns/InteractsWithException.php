<?php

namespace Mlangeni\Machinjiri\Testing\Concerns;

trait InteractsWithException
{
    protected function expectException(string $exceptionClass): void
    {
        $this->expectException($exceptionClass);
    }

    protected function expectExceptionMessage(string $message): void
    {
        $this->expectExceptionMessage($message);
    }

    protected function expectExceptionCode(int $code): void
    {
        $this->expectExceptionCode($code);
    }
}