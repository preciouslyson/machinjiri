<?php

namespace Mlangeni\Machinjiri\Testing\Concerns;

use Mlangeni\Machinjiri\Core\Machinjiri;

trait InteractsWithApplication
{
    protected function setUpApplication(): void
    {
        // Already implemented in TestCase – override as needed
    }

    protected function refreshApplication(): void
    {
        $this->tearDown();
        $this->setUp();
    }

    public function app(): Machinjiri
    {
        return $this->app;
    }
}