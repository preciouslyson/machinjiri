<?php

namespace Mlangeni\Machinjiri\Core\Kernel\Routing;

use Mlangeni\Machinjiri\Core\Kernel\Http\RequestInterface;
use Mlangeni\Machinjiri\Core\Kernel\Http\ResponseInterface;

/**
 * RouterInterface defines the contract for HTTP routing
 * 
 * All router implementations must follow this contract to ensure
 * consistent route registration, matching, and dispatching.
 */
interface RouterInterface
{
    /**
     * Register a GET route
     * 
     * @param string $path Route path
     * @param callable|string $handler Route handler
     * @param string|null $name Route name
     * @return self Fluent interface
     */
    public function get(string $path, $handler, ?string $name = null): self;

    /**
     * Register a POST route
     * 
     * @param string $path Route path
     * @param callable|string $handler Route handler
     * @param string|null $name Route name
     * @return self Fluent interface
     */
    public function post(string $path, $handler, ?string $name = null): self;

    /**
     * Register a PUT route
     * 
     * @param string $path Route path
     * @param callable|string $handler Route handler
     * @param string|null $name Route name
     * @return self Fluent interface
     */
    public function put(string $path, $handler, ?string $name = null): self;

    /**
     * Register a DELETE route
     * 
     * @param string $path Route path
     * @param callable|string $handler Route handler
     * @param string|null $name Route name
     * @return self Fluent interface
     */
    public function delete(string $path, $handler, ?string $name = null): self;

    /**
     * Register a PATCH route
     * 
     * @param string $path Route path
     * @param callable|string $handler Route handler
     * @param string|null $name Route name
     * @return self Fluent interface
     */
    public function patch(string $path, $handler, ?string $name = null): self;

    /**
     * Register a route for multiple HTTP methods
     * 
     * @param array $methods HTTP methods (GET, POST, etc.)
     * @param string $path Route path
     * @param callable|string $handler Route handler
     * @param string|null $name Route name
     * @return self Fluent interface
     */
    public function match(array $methods, string $path, $handler, ?string $name = null): self;

    /**
     * Register a route for all HTTP methods
     * 
     * @param string $path Route path
     * @param callable|string $handler Route handler
     * @param string|null $name Route name
     * @return self Fluent interface
     */
    public function any(string $path, $handler, ?string $name = null): self;

    /**
     * Create a route group with common prefix and middleware
     * 
     * @param array $options Group options (prefix, middleware, etc.)
     * @param callable $callback Callback to register routes in group
     * @return void
     */
    public function group(array $options, callable $callback): void;

    /**
     * Match request to route
     * 
     * @param RequestInterface $request The HTTP request
     * @return array|null Route match data or null if no match
     */
    public function match(RequestInterface $request): ?array;

    /**
     * Dispatch request to matched route
     * 
     * @param RequestInterface $request The HTTP request
     * @param ResponseInterface $response The HTTP response
     * @return ResponseInterface The response
     */
    public function dispatch(RequestInterface $request, ResponseInterface $response): ResponseInterface;

    /**
     * Get all registered routes
     * 
     * @return array All routes
     */
    public function getRoutes(): array;

    /**
     * Get a route by name
     * 
     * @param string $name Route name
     * @return array|null Route data or null if not found
     */
    public function getRoute(string $name): ?array;

    /**
     * Generate URL for named route
     * 
     * @param string $name Route name
     * @param array $params Route parameters
     * @return string Generated URL
     */
    public function url(string $name, array $params = []): string;

    /**
     * Register middleware
     * 
     * @param string $name Middleware name
     * @param callable $handler Middleware handler
     * @return self Fluent interface
     */
    public function middleware(string $name, callable $handler): self;

    /**
     * Get registered middleware
     * 
     * @return array Middleware
     */
    public function getMiddleware(): array;

    /**
     * Load routes from cache file
     * 
     * @return bool True if loaded from cache
     */
    public function loadFromCache(): bool;

    /**
     * Cache routes to file
     * 
     * @return bool True if cached successfully
     */
    public function cacheRoutes(): bool;

    /**
     * Clear route cache
     * 
     * @return void
     */
    public function clearCache(): void;
}
