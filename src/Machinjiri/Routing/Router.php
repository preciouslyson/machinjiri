<?php

namespace Mlangeni\Machinjiri\Core\Routing;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;
use Exception;

class Router
{
    protected $routes = [];
    protected $namedRoutes = [];
    protected $basePath = '/Machinjiri/public';
    protected $groupStack = [];
    protected $middleware = [];
    protected $rateLimiters = [];
    protected $cacheFile = null;
    private $httpRequest;
    private $httpResponse;

    public function __construct()
    {
        $this->httpRequest = HttpRequest::createFromGlobals();
        $this->httpResponse = new HttpResponse();
        $this->cacheFile = __DIR__ . "/../../../storage/cache/routes";
    }

    // Set base path for all routes
    public function setBasePath($basePath): self
    {
        $this->basePath = rtrim($basePath, '/');
        return $this;
    }

    // Add route with multiple HTTP methods
    public function map($methods, $pattern, $handler, $name = null, $options = []): self
    {
        $pattern = $this->applyGroupPrefix($pattern);
        $pattern = $this->basePath . '/' . ltrim($pattern, '/');
        $pattern = rtrim($pattern, '/') ?: '/';

        $route = [
            'methods' => array_map('strtoupper', (array)$methods),
            'pattern' => $pattern,
            'handler' => $handler,
            'name' => $name,
            'regex' => $this->compilePattern($pattern, $options['where'] ?? []),
            'middleware' => array_merge($this->getGroupMiddleware(), $options['middleware'] ?? []),
            'cors' => $options['cors'] ?? null,
            'rate_limit' => $options['rate_limit'] ?? null
        ];

        $this->routes[] = $route;

        if ($name) {
            $this->namedRoutes[$name] = $route;
        }

        // Add automatic OPTIONS route for CORS
        if ($route['cors'] && !in_array('OPTIONS', $route['methods'])) {
            $this->addCorsRoute($route);
        }

        return $this;
    }

    // HTTP method shortcuts
    public function get($pattern, $handler, $name = null, $options = []): self
    {
        return $this->map(['GET'], $pattern, $handler, $name, $options);
    }

    public function post($pattern, $handler, $name = null, $options = []): self
    {
        return $this->map(['POST'], $pattern, $handler, $name, $options);
    }

    public function put($pattern, $handler, $name = null, $options = []): self
    {
        return $this->map(['PUT'], $pattern, $handler, $name, $options);
    }

    public function delete($pattern, $handler, $name = null, $options = []): self
    {
        return $this->map(['DELETE'], $pattern, $handler, $name, $options);
    }

    public function any($pattern, $handler, $name = null, $options = []): self
    {
        return $this->map(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'], $pattern, $handler, $name, $options);
    }

    // Group routes with common attributes
    public function group(array $attributes, callable $callback): self
    {
        $this->groupStack[] = $attributes;
        $callback($this);
        array_pop($this->groupStack);
        return $this;
    }

    // Add middleware
    public function middleware($middleware, callable $callback = null): self
    {
        if ($callback) {
            return $this->group(['middleware' => $middleware], $callback);
        }

        $this->middleware[] = $middleware;
        return $this;
    }

    // Add rate limiter definition
    public function rateLimiter(string $name, int $maxRequests, int $period): self
    {
        $this->rateLimiters[$name] = [
            'max_requests' => $maxRequests,
            'period' => $period,
            'storage' => []
        ];
        return $this;
    }

    // Enable CORS for routes
    public function cors(array $config = []): self
    {
        $defaults = [
            'allowed_origins' => ['*'],
            'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
            'allowed_headers' => ['*'],
            'max_age' => 86400
        ];

        $this->group(['cors' => array_merge($defaults, $config)], func_get_args()[1] ?? null);
        return $this;
    }

    // Generate URL from named route
    public function url(string $name, array $params = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new Exception("Route '{$name}' not found");
        }

        $route = $this->namedRoutes[$name]['pattern'];

        foreach ($params as $key => $value) {
            $route = str_replace('{' . $key . '}', urlencode($value), $route);
        }

        return $route;
    }

    // Match current request to a route
    public function match(): ?array
    {
        $requestMethod = $this->httpRequest->getMethod();
        $requestUri = parse_url($this->httpRequest->getUri(), PHP_URL_PATH);

        // Remove base path from request URI
        if ($this->basePath && strpos($requestUri, $this->basePath) === 0) {
            $requestUri = substr($requestUri, strlen($this->basePath));
        }

        $requestUri = rtrim($requestUri, '/') ?: '/';

        // Try to load from cache
        if ($this->cacheFile && file_exists($this->cacheFile)) {
            $cachedRoutes = require $this->cacheFile;
            if (isset($cachedRoutes[$requestMethod][$requestUri])) {
                return $cachedRoutes[$requestMethod][$requestUri];
            }
        }

        foreach ($this->routes as $route) {
            // Check HTTP method
            if (!in_array($requestMethod, $route['methods']) && !in_array('ANY', $route['methods'])) {
                continue;
            }

            // Check pattern match
            if (preg_match($route['regex'], $requestUri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                return [
                    'handler' => $route['handler'],
                    'params' => $params,
                    'middleware' => $route['middleware'],
                    'cors' => $route['cors'],
                    'rate_limit' => $route['rate_limit']
                ];
            }
        }

        return null;
    }

    // Dispatch matched route
    public function dispatch(): void
    {
        // Handle CORS preflight requests
        if ($this->httpRequest->getMethod() === 'OPTIONS') {
            $this->handleCorsPreflight();
            return;
        }

        $route = $this->match();

        if (!$route) {
            $this->sendNotFound();
            return;
        }

        // Apply rate limiting
        if ($route['rate_limit'] && !$this->handleRateLimit($route['rate_limit'])) {
            $this->sendRateLimitExceeded();
            return;
        }

        // Apply CORS headers if configured
        if ($route['cors']) {
            $this->applyCorsHeaders($route['cors']);
        }

        // Apply middleware stack
        $this->applyMiddleware($route['middleware'], function ($params) use ($route) {
            $this->executeHandler($route['handler'], $params);
        }, $route['params']);
    }

    // Cache routes to file
    public function cacheRoutes(): void
    {
        if (!$this->cacheFile) {
            throw new Exception('Cache file not specified');
        }

        $cacheData = [];
        foreach ($this->routes as $route) {
            foreach ($route['methods'] as $method) {
                $cacheData[$method][$route['pattern']] = [
                    'handler' => $route['handler'],
                    'params' => [],
                    'middleware' => $route['middleware']
                ];
            }
        }

        file_put_contents(
            $this->cacheFile,
            '<?php return ' . var_export($cacheData, true) . ';'
        );
    }

    protected function addCorsRoute(array $route): void
    {
        $this->map(
            ['OPTIONS'],
            $route['pattern'],
            function () use ($route) {
                $this->applyCorsHeaders($route['cors']);
                $this->httpResponse->setStatusCode(204)->send();
            },
            null,
            ['cors' => false] // Don't add CORS again
        );
    }

    protected function applyCorsHeaders(array $config): void
    {
        $origin = $this->httpRequest->getHeader('Origin') ?? '*';

        if (in_array('*', $config['allowed_origins']) || in_array($origin, $config['allowed_origins'])) {
            $this->httpResponse
                ->setHeader('Access-Control-Allow-Origin', $origin)
                ->setHeader('Access-Control-Allow-Methods', implode(', ', $config['allowed_methods']))
                ->setHeader('Access-Control-Allow-Headers', implode(', ', $config['allowed_headers']))
                ->setHeader('Access-Control-Max-Age', $config['max_age']);
        }
    }

    protected function handleCorsPreflight(): void
    {
        $route = $this->match();

        if ($route && $route['cors']) {
            $this->applyCorsHeaders($route['cors']);
            $this->httpResponse->setStatusCode(204)->send();
        } else {
            $this->sendNotFound();
        }
    }

    protected function handleRateLimit(string $limiterName): bool
    {
        if (!isset($this->rateLimiters[$limiterName])) {
            return true;
        }

        $limiter = &$this->rateLimiters[$limiterName];
        $clientId = $this->httpRequest->getServerParam('REMOTE_ADDR', 'unknown');

        $currentTime = time();
        $windowStart = $currentTime - $limiter['period'];

        // Cleanup old requests
        $limiter['storage'][$clientId] = array_filter(
            $limiter['storage'][$clientId] ?? [],
            fn ($time) => $time > $windowStart
        );

        if (count($limiter['storage'][$clientId]) >= $limiter['max_requests']) {
            return false;
        }

        $limiter['storage'][$clientId][] = $currentTime;
        return true;
    }

    protected function applyMiddleware(array $middlewares, callable $core, array $params): void
    {
        $runner = function ($params) use ($middlewares, $core, &$runner) {
            if (empty($middlewares)) {
                return $core($params);
            }

            $current = array_shift($middlewares);
            $next = function ($params) use ($runner, $middlewares) {
                return $runner($params);
            };

            return $current($this->httpRequest, $this->httpResponse, $next, $params);
        };

        $runner($params);
    }

    protected function executeHandler($handler, array $params): void
    {
        if (is_callable($handler)) {
            $result = call_user_func_array($handler, array_merge([$this->httpRequest, $this->httpResponse], $params));
            
            // If handler returns a value and response hasn't been sent, set it as body
            if ($result !== null && !$this->httpResponse->isSent()) {
                if (is_array($result) || is_object($result)) {
                    $this->httpResponse->setJsonBody($result);
                } else {
                    $this->httpResponse->setBody((string)$result);
                }
                $this->httpResponse->send();
            }
        } elseif (is_string($handler) && strpos($handler, '@') !== false) {
            [$controller, $method] = explode('@', $handler, 2);
            $controllerClass = "Mlangeni\\Machinjiri\\App\\Controllers\\$controller";
            
            if (!class_exists($controllerClass)) {
                throw new Exception("Controller class '$controllerClass' not found");
            }
            
            $controllerInstance = new $controllerClass();
            
            if (!method_exists($controllerInstance, $method)) {
                throw new Exception("Method '$method' not found in controller '$controllerClass'");
            }
            
            $result = call_user_func_array(
                [$controllerInstance, $method],
                array_merge([$this->httpRequest, $this->httpResponse], $params)
            );
            
            // If controller returns a value and response hasn't been sent, set it as body
            if ($result !== null && !$this->httpResponse->isSent()) {
                if (is_array($result) || is_object($result)) {
                    $this->httpResponse->setJsonBody($result);
                } else {
                    $this->httpResponse->setBody((string)$result);
                }
                $this->httpResponse->send();
            }
        } else {
            throw new Exception("Invalid route handler");
        }
    }

    protected function compilePattern(string $pattern, array $constraints = []): string
    {
        $regex = preg_quote($pattern, '#');

        // Replace placeholders with custom regex or default
        $regex = preg_replace_callback(
            '/\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\}/',
            function ($matches) use ($constraints) {
                $name = $matches[1];
                $pattern = $constraints[$name] ?? '[^/]+';
                return '(?P<' . $name . '>' . $pattern . ')';
            },
            $regex
        );

        return '#^' . $regex . '$#i';
    }

    protected function getGroupMiddleware(): array
    {
        $middleware = [];
        foreach ($this->groupStack as $group) {
            if (isset($group['middleware'])) {
                $middleware = array_merge($middleware, (array)$group['middleware']);
            }
        }
        return $middleware;
    }

    protected function applyGroupPrefix(string $pattern): string
    {
        $prefix = '';
        foreach ($this->groupStack as $group) {
            $prefix .= $group['prefix'] ?? '';
        }
        return $prefix . $pattern;
    }

    protected function sendNotFound(): void
    {
        $this->httpResponse
            ->setStatusCode(404)
            ->setBody('404 Not Found')
            ->send();
    }

    protected function sendRateLimitExceeded(): void
    {
        $this->httpResponse
            ->setStatusCode(429)
            ->setHeader('Retry-After', '60')
            ->setBody('Rate limit exceeded. Please try again later.')
            ->send();
    }
}