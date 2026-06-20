<?php

namespace Mlangeni\Machinjiri\Core\Routing;

use Mlangeni\Machinjiri\Core\Routing\Contracts\RouteCollectionInterface;
use Mlangeni\Machinjiri\Core\Routing\Contracts\RouteInterface;

class RouteCollection implements RouteCollectionInterface
{
    protected array $routes = [];
    protected array $namedRoutes = [];

    public function add(RouteInterface $route): void
    {
        $this->routes[] = $route;
        if ($name = $route->getName()) {
            $this->addNamedRoute($name, $route);
        }
    }

    public function addNamedRoute(string $name, RouteInterface $route): void
    {
        $this->namedRoutes[$name] = $route;
    }

    public function get(int $index): ?RouteInterface
    {
        return $this->routes[$index] ?? null;
    }

    public function all(): array
    {
        return $this->routes;
    }

    public function getByName(string $name): ?RouteInterface
    {
        return $this->namedRoutes[$name] ?? null;
    }

    public function hasNamed(string $name): bool
    {
        return isset($this->namedRoutes[$name]);
    }

    public function last(): ?RouteInterface
    {
        if (empty($this->routes)) {
            return null;
        }
        return $this->routes[array_key_last($this->routes)];
    }
}