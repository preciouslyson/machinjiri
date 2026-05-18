<?php

namespace Mlangeni\Machinjiri\Integrations\Angular;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;
use Mlangeni\Machinjiri\Core\Authentication\Session;
use Mlangeni\Machinjiri\Core\Authentication\Cookie;
use Mlangeni\Machinjiri\Core\Security\Tokens\CSRFToken;
use Mlangeni\Machinjiri\Core\Security\Encryption\Bangwe;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;

class Angular
{
    protected Container $app;
    protected string $buildPath = 'angular';
    protected string $indexFile = 'index.html';
    protected string $devServerUrl = 'http://localhost:4200';
    protected bool $useDevServer = false;
    protected array $excludedPaths = [
        '/api/', '/assets/', '/storage/', '/vendor/',
        'favicon.ico', 'robots.txt',
    ];
    protected ?string $indexContent = null;
    protected ?int $indexLastModified = null;
    protected ?CSRFToken $csrfToken = null;
    protected ?Bangwe $bangwe = null;
    protected Logger $logger;
    protected ?EventListener $eventListener = null;
    protected array $securityHeaders = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'X-XSS-Protection' => '1; mode=block',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];
    protected bool $sendSecurityHeaders = true;
    protected int $assetCacheMaxAge = 31536000;
    protected bool $enableCompression = true;
    protected array $config = [];
    protected bool $registerFallbackRoute = false;

    public function __construct(Container $app, ?EventListener $eventListener = null, ?Logger $logger = null)
    {
        $this->app = $app;
        $this->loadConfiguration();

        $this->logger = $logger ?? new Logger('angular');
        $this->eventListener = $eventListener ?? new EventListener(new Logger('angular_events'));

        if ($this->app->isDevelopment() && ($this->config['auto_detect_dev_server'] ?? true)) {
            $this->useDevServer = $this->isDevServerRunning();
        } else {
            $this->useDevServer = $this->config['use_dev_server'] ?? false;
        }

        if ($this->app->bound(CSRFToken::class)) {
            $this->csrfToken = $this->app->make(CSRFToken::class);
        } else {
            $session = $this->app->bound(Session::class) ? $this->app->make(Session::class) : new Session();
            $cookie = $this->app->bound(Cookie::class) ? $this->app->make(Cookie::class) : new Cookie();
            $this->csrfToken = new CSRFToken($session, $cookie);
        }

        if ($this->app->bound(Bangwe::class)) {
            $this->bangwe = $this->app->make(Bangwe::class);
        }

        $this->eventListener->trigger('angular.init', [
            'use_dev_server' => $this->useDevServer,
            'build_path' => $this->buildPath,
        ]);
    }

    protected function loadConfiguration(): void
    {
        $configFile = $this->app->config . 'angular.php';
        $this->config = file_exists($configFile) ? require $configFile : [];

        $this->buildPath = rtrim($this->config['build_path'] ?? $this->buildPath, '/');
        $this->indexFile = $this->config['index_file'] ?? $this->indexFile;
        $this->devServerUrl = rtrim($this->config['dev_server_url'] ?? $this->devServerUrl, '/');
        $this->excludedPaths = $this->config['excluded_paths'] ?? $this->excludedPaths;
        $this->sendSecurityHeaders = $this->config['security_headers'] ?? $this->sendSecurityHeaders;
        $this->assetCacheMaxAge = $this->config['cache_control_max_age'] ?? $this->assetCacheMaxAge;
        $this->enableCompression = $this->config['enable_compression'] ?? $this->enableCompression;
        $this->registerFallbackRoute = $this->config['register_fallback_route'] ?? false;
    }

    protected function isDevServerRunning(): bool
    {
        $host = parse_url($this->devServerUrl, PHP_URL_HOST) ?: 'localhost';
        $port = parse_url($this->devServerUrl, PHP_URL_PORT) ?: 4200;
        $handle = @fsockopen($host, $port, $errno, $errstr, 0.5);
        if ($handle) {
            fclose($handle);
            return true;
        }
        return false;
    }

    protected function getPublicBuildPath(): string
    {
        return $this->app->getPublicPath() . '/' . $this->buildPath;
    }

    public function getBaseUrl(): string
    {
        if ($this->useDevServer) {
            return $this->devServerUrl;
        }
        return Container::getBaseUrl() . '/' . $this->buildPath;
    }

    public function tags(bool $asString = true): string
    {
        $this->eventListener->trigger('angular.tags.generating', ['use_dev_server' => $this->useDevServer]);

        if ($this->useDevServer) {
            $tags = $this->generateDevTags();
        } else {
            $tags = $this->generateProdTags();
        }

        if ($this->config['csrf_meta_tag'] ?? true) {
            $csrfTag = sprintf('<meta name="csrf-token" content="%s">', htmlspecialchars($this->getCsrfToken()));
            $tags = $csrfTag . "\n" . $tags;
        }

        $this->eventListener->trigger('angular.tags.generated', ['tags_length' => strlen($tags)]);

        if (!$asString) echo $tags;
        return $tags;
    }

    protected function generateDevTags(): string
    {
        return sprintf('<script type="module" src="%s/@vite/client"></script>', $this->devServerUrl);
    }

    protected function generateProdTags(): string
    {
        $indexPath = $this->getPublicBuildPath() . '/' . $this->indexFile;
        if (!file_exists($indexPath)) {
            $this->logger->error('Angular index file missing', ['path' => $indexPath]);
            throw new MachinjiriException("Angular index file not found at: {$indexPath}", 60001);
        }

        $content = file_get_contents($indexPath);
        $tags = [];
        $pattern = '/<(script|link)(?:\s+[^>]*)?\s+[^>]*?(?:src|href)=["\']([^"\']+)["\'][^>]*>(?:<\/\1>)?/i';
        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $tag = $match[0];
            $tag = preg_replace_callback(
                '/(?:src|href)=["\']([^"\']+)["\']/',
                fn($m) => str_replace($m[1], $this->getBaseUrl() . '/' . ltrim($m[1], '/'), $m[0]),
                $tag
            );
            $tags[] = $tag;
        }

        return implode("\n", array_unique($tags));
    }

    public function asset(string $path): string
    {
        if ($this->useDevServer) return $this->devServerUrl . '/' . ltrim($path, '/');
        return $this->getBaseUrl() . '/' . ltrim($path, '/');
    }

    public function serve(HttpRequest $request, HttpResponse $response): ?HttpResponse
    {
        $uri = $request->getPath();
        $this->eventListener->trigger('angular.serve.start', ['uri' => $uri]);

        foreach ($this->excludedPaths as $excluded) {
            if (str_starts_with($uri, $excluded) || $uri === $excluded) {
                $this->eventListener->trigger('angular.serve.excluded', ['uri' => $uri, 'pattern' => $excluded]);
                return null;
            }
        }

        $buildPath = $this->getPublicBuildPath();
        $filePath = $buildPath . $uri;

        if (!$this->isSafePath($filePath, $buildPath)) {
            $this->logger->warning('Attempted path traversal', ['uri' => $uri]);
            $this->eventListener->trigger('angular.serve.traversal_attempt', ['uri' => $uri]);
            $response->setStatusCode(403)->send();
            return $response;
        }

        if (file_exists($filePath) && is_file($filePath)) {
            $this->eventListener->trigger('angular.serve.static_file', ['path' => $filePath]);
            return $this->serveStaticFile($filePath, $request, $response);
        }

        $this->eventListener->trigger('angular.serve.fallback', ['uri' => $uri]);
        return $this->serveIndexFile($buildPath, $request, $response);
    }

    protected function serveStaticFile(string $filePath, HttpRequest $request, HttpResponse $response): HttpResponse
    {
        $mime = mime_content_type($filePath) ?: 'application/octet-stream';
        $response->setHeader('Content-Type', $mime);

        $etag = md5_file($filePath);
        $response->setHeader('ETag', $etag);
        $response->setHeader('Cache-Control', "public, max-age={$this->assetCacheMaxAge}");

        if ($request->getHeader('If-None-Match') === $etag) {
            $response->setStatusCode(304)->send();
            $this->eventListener->trigger('angular.serve.not_modified', ['path' => $filePath]);
            return $response;
        }

        $content = file_get_contents($filePath);
        if ($this->enableCompression) {
            $acceptEncoding = $request->getHeader('Accept-Encoding');
            if (strpos($acceptEncoding, 'br') !== false && file_exists($filePath . '.br')) {
                $content = file_get_contents($filePath . '.br');
                $response->setHeader('Content-Encoding', 'br');
            } elseif (strpos($acceptEncoding, 'gzip') !== false && file_exists($filePath . '.gz')) {
                $content = file_get_contents($filePath . '.gz');
                $response->setHeader('Content-Encoding', 'gzip');
            }
        }

        $response->setBody($content);
        $this->applySecurityHeaders($response);
        $response->send();
        $this->eventListener->trigger('angular.serve.static_sent', ['path' => $filePath, 'size' => strlen($content)]);
        return $response;
    }

    protected function serveIndexFile(string $buildPath, HttpRequest $request, HttpResponse $response): HttpResponse
    {
        $indexPath = $buildPath . '/' . $this->indexFile;
        if (!file_exists($indexPath)) {
            $this->logger->critical('Angular index file missing', ['path' => $indexPath]);
            $this->eventListener->trigger('angular.serve.index_missing', ['path' => $indexPath]);
            $this->renderMaintenancePage($response);
            return $response;
        }

        if ($this->indexContent === null || filemtime($indexPath) !== $this->indexLastModified) {
            $this->indexContent = file_get_contents($indexPath);
            $this->indexLastModified = filemtime($indexPath);
        }

        $response->setHeader('Content-Type', 'text/html');
        $response->setHeader('Cache-Control', 'no-cache, must-revalidate');
        $response->setBody($this->indexContent);
        $this->applySecurityHeaders($response);
        $response->send();
        $this->eventListener->trigger('angular.serve.index_sent', ['size' => strlen($this->indexContent)]);
        return $response;
    }

    protected function applySecurityHeaders(HttpResponse $response): void
    {
        if (!$this->sendSecurityHeaders) return;
        foreach ($this->securityHeaders as $name => $value) {
            $response->setHeader($name, $value);
        }
    }

    protected function isSafePath(string $filePath, string $basePath): bool
    {
        $realPath = realpath($filePath);
        $realBase = realpath($basePath);
        return $realPath !== false && $realBase !== false && strpos($realPath, $realBase) === 0;
    }

    protected function renderMaintenancePage(HttpResponse $response): void
    {
        $response->setStatusCode(503);
        $response->setHeader('Retry-After', '3600');
        $response->setBody('<!DOCTYPE html><html><head><title>Maintenance</title></head><body><h1>503 Service Unavailable</h1><p>Application is being updated. Please try again later.</p></body></html>');
        $this->applySecurityHeaders($response);
        $response->send();
    }

    public function proxyToDevServer(HttpRequest $request, HttpResponse $response): ?HttpResponse
    {
        if (!$this->useDevServer) return null;
        if ($this->app->getEnvironment() === 'production') {
            throw new MachinjiriException('Dev server proxy is not allowed in production', 60010);
        }

        $uri = $request->getUri();
        $targetUrl = $this->devServerUrl . $uri;
        $this->eventListener->trigger('angular.proxy.start', ['target' => $targetUrl]);

        try {
            $client = $request->getClient();
            $proxyResponse = $client->get($targetUrl, [], $request->getHeaders());
            $response->setStatusCode($proxyResponse->getStatusCode())
                     ->setBody($proxyResponse->getBody());
            foreach ($proxyResponse->getHeaders() as $name => $value) {
                if (!in_array(strtolower($name), ['transfer-encoding', 'connection'])) {
                    $response->setHeader($name, $value);
                }
            }
            $this->applySecurityHeaders($response);
            $response->send();
            $this->eventListener->trigger('angular.proxy.success', ['target' => $targetUrl, 'status' => $proxyResponse->getStatusCode()]);
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Angular proxy failed', ['target' => $targetUrl, 'error' => $e->getMessage()]);
            $this->eventListener->trigger('angular.proxy.error', ['target' => $targetUrl, 'error' => $e->getMessage()]);
            $response->setStatusCode(502)->setBody('Bad Gateway: Unable to reach Angular dev server.')->send();
            return $response;
        }
    }

    public function isHot(): bool
    {
        return $this->useDevServer;
    }

    public function getCsrfToken(): string
    {
        $token = $this->csrfToken->getToken();
        if ($this->bangwe) {
            return $this->bangwe->encryptToJson($token);
        }
        return $token;
    }

    public function shouldRegisterFallbackRoute(): bool
    {
        return $this->registerFallbackRoute;
    }

    public function getEventListener(): EventListener
    {
        return $this->eventListener;
    }

    public function getLogger(): Logger
    {
        return $this->logger;
    }
}