<?php

namespace Mlangeni\Machinjiri\Core\Routing\Contracts;

use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;

interface CorsManagerInterface
{
    public function applyHeaders(array $config): void;
    public function handlePreflight(?array $routeConfig): bool;
}