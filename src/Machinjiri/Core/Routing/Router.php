<?php

namespace Mlangeni\Machinjiri\Core\Routing;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;

class Router
{
    protected static ?self $instance = null;
    protected array $routes = [];
    protected array $namedRoutes = [];
    protected string $basePath;
    protected string $documentRoot;
    protected array $groupStack = [];
    protected array $middleware = [];
    protected array $rateLimiters = [];
    protected ?string $cacheFile = null;
    private HttpRequest $httpRequest;
    private HttpResponse $httpResponse;
    protected ?int $lastRouteIndex = null;

    private function __construct()
    {
        $this->httpRequest = HttpRequest::createFromGlobals();
        $this->httpResponse = new HttpResponse();
        $this->cacheFile = Container::$appBasePath . "/../storage/cache/routes.cache";
        // Set document root
        $this->documentRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
        // Auto-detect base path based on document root and current script
        $this->basePath = $this->autoDetectBasePath();
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Reset instance (mainly for testing)
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    // Static HTTP method shortcuts
    public static function get(string $pattern, mixed $handler, ?string $name = null, array $options = []): self
    {
        return self::getInstance()->instanceGet($pattern, $handler, $name, $options);
    }

    public static function post(string $pattern, mixed $handler, ?string $name = null, array $options = []): self
    {
        return self::getInstance()->instancePost($pattern, $handler, $name, $options);
    }

    public static function put(string $pattern, mixed $handler, ?string $name = null, array $options = []): self
    {
        return self::getInstance()->instancePut($pattern, $handler, $name, $options);
    }

    public static function delete(string $pattern, mixed $handler, ?string $name = null, array $options = []): self
    {
        return self::getInstance()->instanceDelete($pattern, $handler, $name, $options);
    }

    public static function any(string $pattern, mixed $handler, ?string $name = null, array $options = []): self
    {
        return self::getInstance()->instanceAny($pattern, $handler, $name, $options);
    }

    // Static AJAX-only route
    public static function ajax(string $pattern, mixed $handler, ?string $name = null, array $options = []): self
    {
        return self::getInstance()->instanceAjax($pattern, $handler, $name, $options);
    }

    // Static non-AJAX route
    public static function traditional(string $pattern, mixed $handler, ?string $name = null, array $options = []): self
    {
        return self::getInstance()->instanceTraditional($pattern, $handler, $name, $options);
    }

    // Static group method
    public static function group(array $attributes, callable $callback): self
    {
        return self::getInstance()->instanceGroup($attributes, $callback);
    }

    // Static middleware method
    public static function middleware(mixed $middleware, ?callable $callback = null): self
    {
        return self::getInstance()->instanceMiddleware($middleware, $callback);
    }

    // Static CORS method
    public static function cors(array $config = []): self
    {
        $instance = self::getInstance();
        
        if (func_num_args() > 1) {
            $callback = func_get_arg(1);
            return $instance->instanceCors($config, $callback);
        }
        
        return $instance->instanceCors($config);
    }

    // Instance versions of the methods (keep original instance methods)
    public function instanceGet(string $pattern, mixed $handler, ?string $name = null, array $options = []): self
    {
        return $this->map(['GET'], $pattern, $handler, $name, $options);
    }

    public function instancePost(string $pattern, mixed $handler, ?string $name = null, array $options = []): self
    {
        return $this->map(['POST'], $pattern, $handler, $name, $options);
    }

    public function instancePut(string $pattern, mixed $handler, ?string $name = null, array $options = []): self
    {
        return $this->map(['PUT'], $pattern, $handler, $name, $options);
    }

    public function instanceDelete(string $pattern, mixed $handler, ?string $name = null, array $options = []): self
    {
        return $this->map(['DELETE'], $pattern, $handler, $name, $options);
    }

    public function instanceAny(string $pattern, mixed $handler, ?string $name = null, array $options = []): self
    {
        return $this->map(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'], $pattern, $handler, $name, $options);
    }

    public function instanceAjax(string $pattern, mixed $handler, ?string $name = null, array $options = []): self
    {
        $options['ajax_only'] = true;
        return $this->map(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], $pattern, $handler, $name, $options);
    }

    public function instanceTraditional(string $pattern, mixed $handler, ?string $name = null, array $options = []): self
    {
        $options['no_ajax'] = true;
        return $this->map(['GET', 'POST'], $pattern, $handler, $name, $options);
    }

    public function instanceGroup(array $attributes, callable $callback): self
    {
        $this->groupStack[] = $attributes;
        $callback($this);
        array_pop($this->groupStack);
        return $this;
    }

    public function instanceMiddleware(mixed $middleware, ?callable $callback = null): self
    {
        if ($callback) {
            return $this->instanceGroup(['middleware' => $middleware], $callback);
        }

        $this->middleware[] = $middleware;
        return $this;
    }

    public function instanceCors(array $config, ?callable $callback = null): self
    {
        $defaults = [
            'allowed_origins' => ['*'],
            'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
            'allowed_headers' => ['*'],
            'max_age' => 86400
        ];

        if ($callback) {
            return $this->instanceGroup(['cors' => array_merge($defaults, $config)], $callback);
        }

        $this->group(['cors' => array_merge($defaults, $config)], function() {});
        return $this;
    }

    /**
     * Static method to get the base URL
     */
    public static function baseUrl(): string
    {
        return self::getInstance()->getBaseUrl();
    }

    /**
     * Static method to generate URL for a named route
     */
    public static function route(string $name, array $params = []): string
    {
        return self::getInstance()->url($name, $params);
    }

    /**
     * Static method to generate absolute URL for a named route
     */
    public static function absoluteRoute(string $name, array $params = []): string
    {
        return self::getInstance()->absoluteUrl($name, $params);
    }

    /**
     * Static dispatch method
     */
    public static function dispatch(): void
    {
        self::getInstance()->instanceDispatch();
    }

    public function instanceDispatch(): void
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

        // Set appropriate content type for AJAX requests
        if ($this->isAjaxRequest() || $this->expectsJson()) {
            $this->httpResponse->setHeader('Content-Type', 'application/json');
        }

        // Apply middleware stack
        $this->applyMiddleware($route['middleware'], function ($params) use ($route) {
            $this->executeHandler($route['handler'], $params);
        }, $route['params']);
    }

    /**
     * Auto-detect the base path by comparing document root with current script path
     */
    protected function autoDetectBasePath(): string
    {
        // If base path is already set in container, use it
        $containerBase = Container::getRoutingBase();
        if ($containerBase) {
            return rtrim($containerBase, '/');
        }

        // Get the current script directory
        $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? '';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        
        if (!$scriptFilename || !$scriptName) {
            return '';
        }

        // Get the directory of the current script
        $scriptDir = dirname($scriptFilename);
        
        // Calculate the base path by finding the difference between script directory and document root
        if (strpos($scriptDir, $this->documentRoot) === 0) {
            // Script is inside document root
            $relativePath = substr($scriptDir, strlen($this->documentRoot));
            $basePath = rtrim($relativePath, '/');
            
            // Also consider the script name for index.php scenarios
            if (basename($scriptName) !== 'index.php') {
                $basePath = dirname($scriptName);
                $basePath = $basePath === '/' ? '' : rtrim($basePath, '/');
            }
            
            return $basePath;
        }
        
        // Fallback: use the directory of SCRIPT_NAME
        $basePath = dirname($scriptName);
        return $basePath === '/' ? '' : rtrim($basePath, '/');
    }

    /**
     * Get the full base URL including protocol and host
     */
    public function getBaseUrl(): string
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $basePath = $this->basePath;
        
        return $protocol . '://' . $host . $basePath;
    }

    /**
     * Get the document root directory
     */
    public function getDocumentRoot(): string
    {
        return $this->documentRoot;
    }

    // Update the map method to handle document root routing
    public function map(array|string $methods, string $pattern, mixed $handler, ?string $name = null, array $options = []): self
    {
        $pattern = $this->applyGroupPrefix($pattern);
        
        if ($this->basePath && strpos($pattern, $this->basePath) !== 0) {
            $pattern = $this->basePath . '/' . ltrim($pattern, '/');
        }
        
        $pattern = rtrim($pattern, '/') ?: '/';

        $route = [
            'methods' => array_map('strtoupper', (array)$methods),
            'pattern' => $pattern,
            'handler' => $handler,
            'name' => $name,
            'regex' => $this->compilePattern($pattern, $options['where'] ?? []),
            'middleware' => array_merge($this->getGroupMiddleware(), $options['middleware'] ?? []),
            'cors' => $options['cors'] ?? null,
            'rate_limit' => $options['rate_limit'] ?? null,
            'ajax_only' => $options['ajax_only'] ?? false,
            'no_ajax' => $options['no_ajax'] ?? false,
            'constraints' => $options['where'] ?? []
        ];

        $this->routes[] = $route;
        $this->lastRouteIndex = count($this->routes) - 1;

        if ($name) {
            $this->namedRoutes[$name] = $route;
        }

        if ($route['cors'] && !in_array('OPTIONS', $route['methods'])) {
            $this->addCorsRoute($route);
        }

        return $this;
    }

    // Update the match method to properly handle request URI with document root
    public function match(): ?array
    {
        $requestMethod = $this->httpRequest->getMethod();
        $requestUri = parse_url($this->httpRequest->getUri(), PHP_URL_PATH);

        // Normalize the request URI
        $requestUri = $this->normalizeRequestUri($requestUri);

        // Remove base path from request URI if present
        if ($this->basePath && strpos($requestUri, $this->basePath) === 0) {
            $requestUri = substr($requestUri, strlen($this->basePath));
        }

        $requestUri = rtrim($requestUri, '/') ?: '/';

        // Try to load from cache
        if ($this->cacheFile) {
          if (file_exists($this->cacheFile))
          {
              $cachedRoutes = require $this->cacheFile;
              if (isset($cachedRoutes[$requestMethod][$requestUri])) {
                  return $cachedRoutes[$requestMethod][$requestUri];
              } 
          }
        }

        foreach ($this->routes as $route) {
            // Check HTTP method
            if (!in_array($requestMethod, $route['methods']) && !in_array('ANY', $route['methods'])) {
                continue;
            }

            // Check AJAX restrictions
            if ($route['ajax_only'] && !$this->isAjaxRequest()) {
                continue;
            }

            if ($route['no_ajax'] && $this->isAjaxRequest()) {
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
                    'rate_limit' => $route['rate_limit'],
                    'ajax_only' => $route['ajax_only'] ?? false
                ];
            }
        }

        return null;
    }

    /**
     * Normalize the request URI by handling various server configurations
     */
    protected function normalizeRequestUri(string $requestUri): string
    {
        // Handle cases where request URI includes the full path or relative path
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        
        // If request URI starts with script directory and we're in a subdirectory
        if ($scriptDir !== '/' && strpos($requestUri, $scriptDir) === 0) {
            $requestUri = substr($requestUri, strlen($scriptDir));
        }
        
        // Ensure request URI is properly formatted
        $requestUri = '/' . ltrim($requestUri, '/');
        
        return $requestUri;
    }

    // Update URL generation to include base path properly
    public function url(string $name, array $params = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new MachinjiriException("Route '{$name}' not found");
        }

        $route = $this->namedRoutes[$name]['pattern'];

        foreach ($params as $key => $value) {
            $route = str_replace('{' . $key . '}', urlencode((string)$value), $route);
        }

        // Ensure the URL includes the base path if we're in a subdirectory
        if ($this->basePath && strpos($route, $this->basePath) !== 0) {
            $route = $this->basePath . $route;
        }

        return $route;
    }

    /**
     * Get absolute URL for a named route (includes protocol and host)
     */
    public function absoluteUrl(string $name, array $params = []): string
    {
        return $this->getBaseUrl() . $this->url($name, $params);
    }

    // Check if current request is AJAX
    public function isAjaxRequest(): bool
    {
        return $this->httpRequest->isAjax();
    }

    // Check if current request expects JSON response
    /*public function expectsJson(): bool
    {
        return $this->httpRequest->expectsJson();
    }*/
    public function expectsJson(): bool
    {
        $accept = $this->httpRequest->getHeader('Accept') ?? '';
        return str_contains($accept, 'application/json');
    }

    // Set base path for all routes
    public function setBasePath(string $basePath): self
    {
        $this->basePath = rtrim($basePath, '/');
        return $this;
    }

    // AJAX-only route group
    public function ajaxGroup(callable $callback): self
    {
        return $this->group(['ajax_only' => true], $callback);
    }

    // Traditional (non-AJAX) route group
    public function traditionalGroup(callable $callback): self
    {
        return $this->group(['no_ajax' => true], $callback);
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

    // Cache routes to file
    public function cacheRoutes(): void
    {
        if (!$this->cacheFile) {
            throw new MachinjiriException('Cache file not specified');
        }

        $cacheData = [];
        foreach ($this->routes as $route) {
            foreach ($route['methods'] as $method) {
                $cacheData[$method][$route['pattern']] = [
                    'handler' => $route['handler'],
                    'params' => [],
                    'middleware' => $route['middleware'],
                    'ajax_only' => $route['ajax_only'] ?? false
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
        // Create the final handler
        $handler = $core;
        
        // Wrap middleware in reverse order (last middleware wraps the core, first middleware wraps everything)
        for ($i = count($middlewares) - 1; $i >= 0; $i--) {
            $current = $middlewares[$i];
            
            // Resolve middleware string to class instance
            if (is_string($current)) {
                $middlewareClass = "Mlangeni\\Machinjiri\\App\\Middleware\\$current";
                if (!class_exists($middlewareClass)) {
                    throw new MachinjiriException("Middleware class '$middlewareClass' not found");
                }
                $current = [new $middlewareClass(), 'handle'];
            }
            
            $handler = function ($params) use ($current, $handler) {
                return $current($this->httpRequest, $this->httpResponse, $handler, $params);
            };
        }
        // Execute the middleware stack
        $handler($params);
    }

    protected function executeHandler(mixed $handler, array $params): void
    {
        if (is_callable($handler)) {
            $result = call_user_func_array($handler, array_merge([$this->httpRequest, $this->httpResponse], $params));
            
            // If handler returns a value and response hasn't been sent, set it as body
            if ($result !== null && !$this->httpResponse->isSent()) {
                $this->handleHandlerResult($result);
            }
        } elseif (is_string($handler) && str_contains($handler, '@')) {
            [$controller, $method] = explode('@', $handler, 2);
            $controllerClass = "Mlangeni\\Machinjiri\\App\\Controllers\\$controller";
            
            if (!class_exists($controllerClass)) {
                throw new MachinjiriException("Controller class '$controllerClass' not found");
            }
            
            $controllerInstance = new $controllerClass();
            
            if (!method_exists($controllerInstance, $method)) {
                throw new MachinjiriException("Method '$method' not found in controller '$controllerClass'");
            }
            
            $result = call_user_func_array(
                [$controllerInstance, $method],
                array_merge([$this->httpRequest, $this->httpResponse], $params)
            );
            
            // If controller returns a value and response hasn't been sent, set it as body
            if ($result !== null && !$this->httpResponse->isSent()) {
                $this->handleHandlerResult($result);
            }
        } else {
            throw new MachinjiriException("Invalid route handler");
        }
    }

    protected function handleHandlerResult(mixed $result): void
    {
        // AJAX or JSON expectation → always send JSON
        if ($this->isAjaxRequest() || $this->expectsJson()) {
            $this->httpResponse->setJsonBody(
                is_array($result) || is_object($result) ? $result : ['data' => $result]
            );
            $this->httpResponse->send();
            return;
        }
    
        if (is_string($result)) {
            $this->httpResponse->setBody($result);
        } elseif ($result === null) {
            if (!$this->httpResponse->isSent()) {
                $this->httpResponse->setStatusCode(204);
            }
        } else {
            throw new MachinjiriException(
                'Returning an array or object in a traditional (non‑AJAX) route is not allowed. ' .
                'Use $this->httpResponse->setJsonBody() or mark the route as AJAX.'
            );
        }
    
        if (!$this->httpResponse->isSent()) {
            $this->httpResponse->send();
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
            if (isset($group['ajax_only'])) {
                // Add AJAX validation middleware if not already present
                if (!in_array('ValidateAjax', $middleware)) {
                    $middleware[] = 'ValidateAjax';
                }
            }
        }
        return $middleware;
    }

    protected function applyGroupPrefix(string $pattern): string
    {
        $prefix = '';
        foreach ($this->groupStack as $group) {
            $prefix .= $group['prefix'] ?? '';
            // Apply AJAX restrictions to group
            if (isset($group['ajax_only'])) {
                // This will be handled in the route matching process
            }
            if (isset($group['no_ajax'])) {
                // This will be handled in the route matching process
            }
        }
        return $prefix . $pattern;
    }

    protected function sendNotFound(): void
    {
        if ($this->httpResponse->isSent()) {
          return;
        }
        if ($this->isAjaxRequest() || $this->expectsJson()) {
            $this->httpResponse
                ->setStatusCode(404)
                ->setJsonBody([
                    'error' => 'Not Found',
                    'message' => 'The requested resource was not found',
                    'code' => 404,
                    'timestamp' => time()
                ])
                ->send();
        } else {
            $this->httpResponse
                ->setStatusCode(404)
                ->setBody($this->notFoundTemplate())
                ->send();
        }
    }

    protected function sendRateLimitExceeded(): void
    {
        if ($this->isAjaxRequest() || $this->expectsJson()) {
            $this->httpResponse
                ->setStatusCode(429)
                ->setHeader('Retry-After', '60')
                ->setJsonBody([
                    'error' => 'Rate Limit Exceeded',
                    'message' => 'Too many requests. Please try again later.',
                    'code' => 429,
                    'retry_after' => 60,
                    'timestamp' => time()
                ])
                ->send();
        } else {
            $this->httpResponse
                ->setStatusCode(429)
                ->setHeader('Retry-After', '60')
                ->setBody('Rate limit exceeded. Please try again later.')
                ->send();
        }
    }
    
    protected function notFoundTemplate(): string
    {
      $requestUri = htmlspecialchars($this->httpRequest->getServerParam('REQUEST_URI') ?? '/', ENT_QUOTES, 'UTF-8');
      $appName = env('APP_NAME') ?? "Machinjiri";
      
      return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 Not Found - {$appName}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #FCF7F0;
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: #2E2C2A;
        }

        .error-container {
            max-width: 650px;
            width: 100%;
            background: #FFFFFFDD;
            backdrop-filter: blur(2px);
            border-radius: 12px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.02);
            border: 1px solid #F2E5D8;
            overflow: hidden;
        }

        .error-header {
            padding: 30px 30px 0 30px;
            border-bottom: 1px solid #F2E5D8;
        }

        .error-code {
            font-size: 72px;
            font-weight: 700;
            color: #E68A5E;
            letter-spacing: -1px;
            line-height: 1;
            margin-bottom: 10px;
        }

        .error-status {
            font-size: 16px;
            font-weight: 500;
            color: #C4633A;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 20px;
        }

        .error-body {
            padding: 30px;
        }

        .error-title {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 12px;
            color: #2E2C2A;
        }

        .error-message {
            font-size: 16px;
            line-height: 1.5;
            color: #2E2C2A;
            margin-bottom: 25px;
            background: #FDE8E8;
            padding: 15px 20px;
            border-radius: 8px;
            border-left: 3px solid #F5C6C6;
        }

        .requested-path {
            background: rgba(242, 229, 216, 0.5);
            padding: 12px 16px;
            border-radius: 8px;
            font-family: 'SF Mono', 'Menlo', monospace;
            font-size: 14px;
            word-break: break-all;
            margin-bottom: 25px;
            border: 1px solid #F2E5D8;
        }

        .requested-path strong {
            color: #E68A5E;
            font-weight: 600;
        }

        .error-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }

        .error-actions a, .error-actions button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 40px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            font-family: inherit;
        }

        .btn-primary {
            background: #E68A5E;
            color: white;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }

        .btn-primary:hover {
            background: #C4633A;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: transparent;
            color: #2E2C2A;
            border: 1px solid #F2E5D8;
        }

        .btn-secondary:hover {
            background: #F2E5D8;
            border-color: #E68A5E;
        }

        .error-footer {
            padding: 20px 30px;
            background: rgba(242, 229, 216, 0.3);
            border-top: 1px solid #F2E5D8;
            font-size: 13px;
            color: #2E2C2A;
            text-align: center;
        }

        .error-footer span {
            color: #E68A5E;
        }

        @media (max-width: 550px) {
            .error-header, .error-body, .error-footer {
                padding-left: 20px;
                padding-right: 20px;
            }
            .error-code {
                font-size: 54px;
            }
            .error-title {
                font-size: 20px;
            }
            .error-actions a, .error-actions button {
                padding: 8px 16px;
                font-size: 13px;
            }
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-header">
            <div class="error-code">404</div>
        </div>
        <div class="error-body">
            <h1 class="error-title">The page you are looking for could not be found.</h1>
            <div class="error-message">
                The server returned a 404 error for the requested resource.
            </div>
            <div class="requested-path">
                <strong>Requested URL:</strong> <code>{$requestUri}</code>
            </div>
            <div class="error-actions">
                <a href="javascript:history.back()" class="btn-secondary">← Go Back</a>
                <a href="javascript:location.reload()" class="btn-secondary">⟳ Reload Page</a>
                <a href="/" class="btn-primary">🏠 Return Home</a>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
}
    /**
     * Add regex constraint(s) to the most recently defined route.
     *
     * @param string|array $param  Parameter name or array of [param => regex]
     * @param string|null $regex   Regex pattern (required if $param is a string)
     * @return $this
     * @throws MachinjiriException if no route has been defined yet
     */
    public function where(string|array $param, ?string $regex = null): self
    {
        if ($this->lastRouteIndex === null) {
            throw new MachinjiriException('No route defined to apply constraints to.');
        }

        $route = &$this->routes[$this->lastRouteIndex];

        if (is_array($param)) {
            foreach ($param as $p => $r) {
                $route['constraints'][$p] = $r;
            }
        } else {
            $route['constraints'][$param] = $regex;
        }

        // Recompile pattern with updated constraints
        $route['regex'] = $this->compilePattern($route['pattern'], $route['constraints']);

        // If the route has a name, update the named route reference as well
        if ($route['name'] && isset($this->namedRoutes[$route['name']])) {
            $this->namedRoutes[$route['name']]['regex'] = $route['regex'];
            $this->namedRoutes[$route['name']]['constraints'] = $route['constraints'];
        }

        return $this;
    }
}