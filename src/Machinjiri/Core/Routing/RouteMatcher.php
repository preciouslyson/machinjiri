<?php

namespace Mlangeni\Machinjiri\Core\Routing;

use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Routing\Contracts\RouteCollectionInterface;
use Mlangeni\Machinjiri\Core\Routing\Contracts\RouteMatcherInterface;

class RouteMatcher implements RouteMatcherInterface
{
    protected string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    public function match(RouteCollectionInterface $collection, HttpRequest $request): ?array
    {
        $method = $request->getMethod();
        $uri = $this->normalizeUri($request->getUri());

        foreach ($collection->all() as $route) {
            if (!in_array($method, $route->getMethods()) && !in_array('ANY', $route->getMethods())) {
                continue;
            }

            if ($route->isAjaxOnly() && !$request->isAjax()) {
                continue;
            }

            if ($route->isNoAjax() && $request->isAjax()) {
                continue;
            }

            if (preg_match($route->getRegex(), $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                return [
                    'route' => $route,
                    'params' => $params
                ];
            }
        }

        return null;
    }

    protected function normalizeUri(string $uri): string
    {
        $uri = parse_url($uri, PHP_URL_PATH);
        $uri = '/' . ltrim($uri, '/');

        if ($this->basePath && strpos($uri, $this->basePath) === 0) {
            $uri = substr($uri, strlen($this->basePath));
        }

        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        if ($scriptDir !== '/' && strpos($uri, $scriptDir) === 0) {
            $uri = substr($uri, strlen($scriptDir));
        }

        return rtrim($uri, '/') ?: '/';
    }
}