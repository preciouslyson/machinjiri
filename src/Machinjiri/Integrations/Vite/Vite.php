<?php

namespace Mlangeni\Machinjiri\Integrations\Vite;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;

class Vite
{
    protected Container $app;
    protected string $devServerUrl = 'http://localhost:5173';
    protected string $manifestPath = 'build/manifest.json';
    protected string $buildDirectory = 'build';
    protected array $entries = [];
    protected bool $isHot = false;
    protected ?array $manifest = null;
    protected string $indexFile = 'index.html';
    protected ?string $indexContent = null;
    protected ?int $indexLastModified = null;
    protected array $excludedPaths = ['/api/', '/assets/', '/storage/', '/vendor/', 'favicon.ico'];
    protected array $securityHeaders = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'X-XSS-Protection' => '1; mode=block',
    ];
    protected bool $sendSecurityHeaders = true;
    protected int $assetCacheMaxAge = 31536000;
    protected bool $enableCompression = true;
    protected array $config = [];
    protected bool $registerFallbackRoute = false;
    protected Logger $logger;

    public function __construct(Container $app, ?Logger $logger = null)
    {
        $this->app = $app;
        $this->logger = $logger ?? new Logger('vite');
        $this->loadConfiguration();

        $this->isHot = $this->app->isDevelopment() && $this->isDevServerRunning();
    }

    protected function loadConfiguration(): void
    {
        $configFile = $this->app->config . 'vite.php';
        $this->config = file_exists($configFile) ? require $configFile : [];

        $this->devServerUrl = rtrim($this->config['dev_server_url'] ?? $this->devServerUrl, '/');
        $this->manifestPath = $this->config['manifest_path'] ?? $this->manifestPath;
        $this->buildDirectory = rtrim($this->config['build_directory'] ?? $this->buildDirectory, '/');
        $this->indexFile = $this->config['index_file'] ?? $this->indexFile;
        $this->excludedPaths = $this->config['excluded_paths'] ?? $this->excludedPaths;
        $this->sendSecurityHeaders = $this->config['security_headers'] ?? $this->sendSecurityHeaders;
        $this->assetCacheMaxAge = $this->config['cache_control_max_age'] ?? $this->assetCacheMaxAge;
        $this->enableCompression = $this->config['enable_compression'] ?? $this->enableCompression;
        $this->registerFallbackRoute = $this->config['register_fallback_route'] ?? false;
    }

    public function entry(string|array $entries): self
    {
        $this->entries = array_merge($this->entries, (array) $entries);
        return $this;
    }

    public function setDevServerUrl(string $url): self
    {
        $this->devServerUrl = rtrim($url, '/');
        return $this;
    }

    public function setBuildDirectory(string $directory): self
    {
        $this->buildDirectory = rtrim($directory, '/');
        return $this;
    }

    public function setManifestPath(string $path): self
    {
        $this->manifestPath = $path;
        return $this;
    }

    public function tags(): string
    {
        if (empty($this->entries)) return '';
        if ($this->isHot) return $this->generateHotTags();
        return $this->generateBuildTags();
    }

    protected function generateHotTags(): string
    {
        $tags = [sprintf('<script type="module" src="%s/@vite/client"></script>', $this->devServerUrl)];
        foreach ($this->entries as $entry) {
            $tags[] = sprintf('<script type="module" src="%s/%s"></script>', $this->devServerUrl, ltrim($entry, '/'));
        }
        return implode("\n", $tags);
    }

    protected function generateBuildTags(): string
    {
        $this->loadManifest();
        $tags = [];
        $publicPath = Container::getBaseUrl() . '/' . $this->buildDirectory;

        foreach ($this->entries as $entry) {
            $entry = ltrim($entry, '/');
            if (!isset($this->manifest[$entry])) {
                throw new MachinjiriException("Vite entry not found in manifest: {$entry}", 50001);
            }
            $chunk = $this->manifest[$entry];
            if (isset($chunk['css'])) {
                foreach ($chunk['css'] as $cssFile) {
                    $tags[] = sprintf('<link rel="stylesheet" href="%s/%s">', $publicPath, $cssFile);
                }
            }
            if (isset($chunk['file'])) {
                $tags[] = sprintf('<script type="module" src="%s/%s"></script>', $publicPath, $chunk['file']);
            }
            if (isset($chunk['imports'])) {
                foreach ($chunk['imports'] as $import) {
                    if (isset($this->manifest[$import])) {
                        $tags[] = sprintf('<link rel="modulepreload" href="%s/%s">', $publicPath, $this->manifest[$import]['file']);
                    }
                }
            }
        }
        return implode("\n", $tags);
    }

    protected function loadManifest(): void
    {
        if ($this->manifest !== null) return;
        $manifestFullPath = $this->app->getPublicPath() . '/' . $this->manifestPath;
        if (!file_exists($manifestFullPath)) {
            throw new MachinjiriException("Vite manifest not found at: {$manifestFullPath}", 50002);
        }
        $content = file_get_contents($manifestFullPath);
        $this->manifest = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new MachinjiriException("Invalid Vite manifest JSON: " . json_last_error_msg(), 50003);
        }
    }

    protected function isDevServerRunning(): bool
    {
        $hotFile = $this->app->getPublicPath() . '/hot';
        if (file_exists($hotFile)) {
            $hotUrl = trim(file_get_contents($hotFile));
            if (filter_var($hotUrl, FILTER_VALIDATE_URL)) {
                $this->devServerUrl = rtrim($hotUrl, '/');
                return true;
            }
        }
        $host = parse_url($this->devServerUrl, PHP_URL_HOST) ?: 'localhost';
        $port = parse_url($this->devServerUrl, PHP_URL_PORT) ?: 5173;
        $handle = @fsockopen($host, $port, $errno, $errstr, 0.5);
        if ($handle) {
            fclose($handle);
            return true;
        }
        return false;
    }

    public function asset(string $path): string
    {
        if ($this->isHot) return $this->devServerUrl . '/' . ltrim($path, '/');
        $this->loadManifest();
        $path = ltrim($path, '/');
        if (isset($this->manifest[$path])) {
            return Container::getBaseUrl() . '/' . $this->buildDirectory . '/' . $this->manifest[$path]['file'];
        }
        return Container::getBaseUrl() . '/' . $this->buildDirectory . '/' . $path;
    }

    public function isHot(): bool
    {
        return $this->isHot;
    }

    public function setHot(bool $hot): self
    {
        $this->isHot = $hot;
        return $this;
    }

    public function shouldRegisterFallbackRoute(): bool
    {
        return $this->registerFallbackRoute;
    }

    protected function getPublicBuildPath(): string
    {
        return $this->app->getPublicPath() . '/' . $this->buildDirectory;
    }

    protected function isSafePath(string $filePath, string $basePath): bool
    {
        $realPath = realpath($filePath);
        $realBase = realpath($basePath);
        return $realPath !== false && $realBase !== false && strpos($realPath, $realBase) === 0;
    }

    protected function applySecurityHeaders(HttpResponse $response): void
    {
        if (!$this->sendSecurityHeaders) return;
        foreach ($this->securityHeaders as $name => $value) {
            $response->setHeader($name, $value);
        }
    }

    protected function renderMaintenancePage(HttpResponse $response): void
    {
        $response->setStatusCode(503);
        $response->setHeader('Retry-After', '3600');
        $response->setBody('<!DOCTYPE html><html><head><title>Maintenance</title></head><body><h1>503 Service Unavailable</h1><p>Vite application is being updated. Please try again later.</p></body></html>');
        $this->applySecurityHeaders($response);
        $response->send();
    }

    public function serve(HttpRequest $request, HttpResponse $response): ?HttpResponse
    {
        $uri = $request->getPath();
        foreach ($this->excludedPaths as $excluded) {
            if (str_starts_with($uri, $excluded) || $uri === $excluded) {
                return null;
            }
        }

        $buildPath = $this->getPublicBuildPath();
        $filePath = $buildPath . $uri;

        if (!$this->isSafePath($filePath, $buildPath)) {
            $this->logger->warning('Vite path traversal attempt', ['uri' => $uri]);
            $response->setStatusCode(403)->send();
            return $response;
        }

        if (file_exists($filePath) && is_file($filePath)) {
            return $this->serveStaticFile($filePath, $request, $response);
        }

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
        return $response;
    }

    protected function serveIndexFile(string $buildPath, HttpRequest $request, HttpResponse $response): HttpResponse
    {
        $indexPath = $buildPath . '/' . $this->indexFile;
        if (!file_exists($indexPath)) {
            $this->logger->critical('Vite index file missing', ['path' => $indexPath]);
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
        return $response;
    }

    public function proxyToDevServer(HttpRequest $request, HttpResponse $response): ?HttpResponse
    {
        if (!$this->isHot) return null;
        if ($this->app->getEnvironment() === 'production') {
            throw new MachinjiriException('Vite dev server proxy is not allowed in production', 60011);
        }

        $uri = $request->getUri();
        $targetUrl = $this->devServerUrl . $uri;

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
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Vite proxy failed', ['target' => $targetUrl, 'error' => $e->getMessage()]);
            $response->setStatusCode(502)->setBody('Bad Gateway: Unable to reach Vite dev server.')->send();
            return $response;
        }
    }
}