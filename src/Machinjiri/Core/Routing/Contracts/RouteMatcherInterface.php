<?php

namespace Mlangeni\Machinjiri\Core\Routing\Contracts;

use Mlangeni\Machinjiri\Core\Http\HttpRequest;

interface RouteMatcherInterface
{
    public function match(RouteCollectionInterface $collection, HttpRequest $request): ?array;
}    