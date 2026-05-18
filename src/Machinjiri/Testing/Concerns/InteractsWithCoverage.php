<?php

namespace Mlangeni\Machinjiri\Testing\Concerns;

trait InteractsWithCoverage
{
    protected function startCoverage(): void
    {
        if (function_exists('xdebug_start_code_coverage')) {
            xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);
        }
    }

    protected function stopCoverage(): array
    {
        if (function_exists('xdebug_get_code_coverage')) {
            return xdebug_get_code_coverage();
        }
        return [];
    }
}