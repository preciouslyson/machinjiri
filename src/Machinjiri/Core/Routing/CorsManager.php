<?php

namespace Mlangeni\Machinjiri\Core\Routing;

use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;
use Mlangeni\Machinjiri\Core\Routing\Contracts\CorsManagerInterface;

class CorsManager implements CorsManagerInterface
{
    public function __construct(
        protected HttpRequest $request,
        protected HttpResponse $response
    ) {}

    public function applyHeaders(array $config): void
    {
        $origin = $this->request->getHeader('Origin') ?? '*';
        $allowedOrigins = $config['allowed_origins'] ?? ['*'];

        if (in_array('*', $allowedOrigins) || in_array($origin, $allowedOrigins)) {
            $this->response
                ->setHeader('Access-Control-Allow-Origin', $origin)
                ->setHeader('Access-Control-Allow-Methods', implode(', ', $config['allowed_methods'] ?? ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS']))
                ->setHeader('Access-Control-Allow-Headers', implode(', ', $config['allowed_headers'] ?? ['*']))
                ->setHeader('Access-Control-Max-Age', $config['max_age'] ?? 86400);
        }
    }

    public function handlePreflight(?array $routeConfig): bool
    {
        if ($this->request->getMethod() !== 'OPTIONS') {
            return false;
        }

        if ($routeConfig) {
            $this->applyHeaders($routeConfig);
            $this->response->setStatusCode(204)->send();
            return true;
        }

        return false;
    }
}