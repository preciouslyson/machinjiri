<?php

namespace Mlangeni\Machinjiri\Core\Routing\Contracts;

use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;

interface MiddlewareDispatcherInterface
{
    public function dispatch(array $middlewares, callable $core, array $params): void;
}