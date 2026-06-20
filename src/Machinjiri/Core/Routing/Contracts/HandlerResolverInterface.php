<?php

namespace Mlangeni\Machinjiri\Core\Routing\Contracts;

use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;

interface HandlerResolverInterface
{
    public function execute(mixed $handler, array $params, HttpRequest $request, HttpResponse $response): mixed;
}