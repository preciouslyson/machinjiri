<?php

namespace Mlangeni\Machinjiri\Testing\Traits;

trait WithoutMiddleware
{
    protected function disableMiddleware(): void
    {
        $this->bind('middleware.dispatcher', function () {
            return new class {
                public function handle($request, $next) { return $next($request); }
            };
        });
    }

    protected function setUpWithoutMiddleware(): void
    {
        $this->disableMiddleware();
    }
}