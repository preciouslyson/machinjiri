<?php

namespace Mlangeni\Machinjiri\Integrations\Vite;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Artisans\Logging\LoggerFactory;

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
    
    // New debugging properties
    protected bool $debugMode = false;
    protected array $debugLog = [];

    public function __construct(Container $app, ?Logger $logger = null)
    {
        $this->app = $app;
        $this->logger = $logger ?? LoggerFactory::system("vite", "integration", false);
        $this->loadConfiguration();
        
        $this->debugMode = $app->isDevelopment();
        $this->isHot = $this->app->isDevelopment() && $this->isDevServerRunning();
        
        // Force manifest reload if in production
        if (!$this->isHot) {
            $this->manifest = null;
        }
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
        $this->sendSecurityHeaders = $this->config['security_headers_enabled'] ?? $this->sendSecurityHeaders;
        $this->assetCacheMaxAge = $this->config['cache_control_max_age'] ?? $this->assetCacheMaxAge;
        $this->enableCompression = $this->config['enable_compression'] ?? $this->enableCompression;
        $this->registerFallbackRoute = $this->config['register_fallback_route'] ?? false;
        $this->debugMode = $this->config['debug_mode'] ?? $this->debugMode;
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
        try {
            $this->loadManifest();
        } catch (MachinjiriException $e) {
            // If manifest loading fails, return a fallback
            if ($this->debugMode) {
                return $this->generateFallbackTags();
            }
            throw $e;
        }
        
        $tags = [];
        $publicPath = Container::getBaseUrl() . '/' . $this->buildDirectory;
        
        // Ensure the public path is not empty
        $basePath = $publicPath ?: '/' . $this->buildDirectory;

        foreach ($this->entries as $entry) {
            $entry = ltrim($entry, '/');
            
            // Try to find the entry in the manifest
            $entryKey = $this->findEntryInManifest($entry);
            
            if (!$entryKey || !isset($this->manifest[$entryKey])) {
                // If entry not found, try to load it directly
                $tags[] = sprintf('<script type="module" src="%s/%s"></script>', $basePath, $entry);
                continue;
            }
            
            $chunk = $this->manifest[$entryKey];
            
            // Add CSS files
            if (isset($chunk['css'])) {
                foreach ($chunk['css'] as $cssFile) {
                    $tags[] = sprintf('<link rel="stylesheet" href="%s/%s" crossorigin="anonymous">', $basePath, $cssFile);
                }
            }
            
            // Add main JS file
            if (isset($chunk['file'])) {
                $tags[] = sprintf('<script type="module" src="%s/%s" crossorigin="anonymous"></script>', $basePath, $chunk['file']);
            }
            
            // Add preloads for imports
            if (isset($chunk['imports'])) {
                foreach ($chunk['imports'] as $import) {
                    if (isset($this->manifest[$import])) {
                        $importFile = $this->manifest[$import]['file'];
                        $tags[] = sprintf('<link rel="modulepreload" href="%s/%s" crossorigin="anonymous">', $basePath, $importFile);
                    }
                }
            }
        }
        
        return implode("\n", $tags);
    }

    private function findEntryInManifest(string $entry): ?string
    {
        // Direct match
        if (isset($this->manifest[$entry])) {
            return $entry;
        }
        
        // Try to find by filename without extension
        $baseName = pathinfo($entry, PATHINFO_FILENAME);
        foreach ($this->manifest as $key => $value) {
            if (strpos($key, $baseName) !== false) {
                return $key;
            }
        }
        
        // Try to find by file name in the manifest values
        foreach ($this->manifest as $key => $value) {
            if (isset($value['file']) && strpos($value['file'], $baseName) !== false) {
                return $key;
            }
        }
        
        return null;
    }

    private function generateFallbackTags(): string
    {
        $basePath = Container::getBaseUrl() . '/' . $this->buildDirectory;
        $tags = [];
        
        foreach ($this->entries as $entry) {
            $entry = ltrim($entry, '/');
            $tags[] = sprintf('<script type="module" src="%s/%s"></script>', $basePath, $entry);
        }
        
        return implode("\n", $tags);
    }

    protected function loadManifest(): void
    {
        if ($this->manifest !== null) return;
        
        // Try multiple possible paths for the manifest
        $possiblePaths = [
            $this->app->getPublicPath() . '/' . $this->manifestPath,
            $this->app->getPublicPath() . '/build/manifest.json',
            $this->app->getPublicPath() . '/' . $this->buildDirectory . '/manifest.json',
            getcwd() . '/public/' . $this->manifestPath,
            getcwd() . '/public/build/manifest.json',
            getcwd() . '/public/' . $this->buildDirectory . '/manifest.json',
        ];
        
        $manifestFullPath = null;
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $manifestFullPath = $path;
                if ($this->debugMode) {
                    $this->logDebug("Found manifest at: {$path}");
                }
                break;
            }
        }
        
        if (!$manifestFullPath) {
            if ($this->debugMode) {
                $this->logDebug("Manifest not found in any of the expected locations");
            }
            throw new MachinjiriException("Vite manifest not found", 50002);
        }
        
        $content = file_get_contents($manifestFullPath);
        $this->manifest = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new MachinjiriException("Invalid Vite manifest JSON: " . json_last_error_msg(), 50003);
        }
        
        if ($this->debugMode) {
            $this->logDebug("Manifest loaded with " . count($this->manifest) . " entries");
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

    public function getBuildDirectory(): string
    {
        return $this->buildDirectory;
    }

    public function isCompressionEnabled(): bool
    {
        return $this->enableCompression;
    }

    protected function getPublicBuildPath(): string
    {
        return $this->app->routing . $this->buildDirectory;
    }

    /**
     * Enhanced path safety check with debugging
     */
    protected function isSafePath(string $filePath, string $basePath): bool
    {
        // Debug logging
        if ($this->debugMode) {
            $this->debugLog[] = [
                'method' => 'isSafePath',
                'filePath' => $filePath,
                'basePath' => $basePath,
                'timestamp' => microtime(true)
            ];
        }

        // Normalize paths for Windows compatibility
        $filePath = $this->normalizePath($filePath);
        $basePath = $this->normalizePath($basePath);

        // Check if file exists and is within base path
        if (!file_exists($filePath)) {
            if ($this->debugMode) {
                $this->logDebug("File does not exist: {$filePath}");
            }
            return false;
        }

        // Try realpath first (best practice)
        $realPath = realpath($filePath);
        $realBase = realpath($basePath);

        if ($realPath !== false && $realBase !== false) {
            $isSafe = strpos($realPath, $realBase) === 0;
            if ($this->debugMode && !$isSafe) {
                $this->logDebug("Path traversal detected: {$realPath} is not within {$realBase}");
            }
            return $isSafe;
        }

        // Fallback for systems where realpath fails (Windows edge cases)
        // Use string comparison with normalized paths
        $normalizedFilePath = $this->normalizePath($filePath);
        $normalizedBasePath = $this->normalizePath($basePath);
        
        $isSafe = strpos($normalizedFilePath, $normalizedBasePath) === 0;
        
        if ($this->debugMode && !$isSafe) {
            $this->logDebug("Path traversal detected (fallback): {$normalizedFilePath} is not within {$normalizedBasePath}");
        }
        
        return $isSafe;
    }

    /**
     * Normalize paths for cross-platform compatibility
     */
    protected function normalizePath(string $path): string
    {
        // Convert backslashes to forward slashes for Windows
        $path = str_replace('\\', '/', $path);
        
        // Remove multiple slashes
        $path = preg_replace('#/+#', '/', $path);
        
        // Remove trailing slash
        return rtrim($path, '/');
    }

    protected function applySecurityHeaders(HttpResponse $response): void
    {
        if (!$this->sendSecurityHeaders) return;
        foreach ($this->securityHeaders as $name => $value) {
            $response->setHeader($name, $value);
        }
    }

    protected function rewriteIndexHtml(string $content): string
    {
        $baseUrl = Container::getBaseUrl();
        $baseUrl = rtrim($baseUrl, '/');
        $buildBase = $baseUrl . '/' . ltrim($this->buildDirectory, '/');

        $devBase = rtrim($this->devServerUrl, '/');

        $rewriteAttribute = function (array $matches) use ($devBase, $buildBase): string {
            $attributeValue = $matches[2];
            
            // Skip external, data URIs, etc.
            if ($attributeValue === '' || 
                preg_match('/^(https?:)?\/\//', $attributeValue) || 
                str_starts_with($attributeValue, 'data:') || 
                str_starts_with($attributeValue, 'mailto:') || 
                str_starts_with($attributeValue, '#')) {
                return $matches[0];
            }

            // For hot reload, use dev server
            if ($this->isHot) {
                $resolvedValue = $devBase . '/' . ltrim($attributeValue, '/');
            } else {
                // For production, use the build directory
                // Remove any existing build directory prefix to avoid duplication
                $cleanPath = ltrim($attributeValue, '/');
                if (strpos($cleanPath, $this->buildDirectory . '/') === 0) {
                    $cleanPath = substr($cleanPath, strlen($this->buildDirectory . '/'));
                }
                $resolvedValue = $buildBase . '/' . $cleanPath;
            }

            return $matches[1] . $resolvedValue . $matches[3];
        };

        // Rewrite script tags
        $rewritten = preg_replace_callback(
            '/(<script\b[^>]*\bsrc=["\'])([^"\']+)(["\'][^>]*>)/i',
            $rewriteAttribute,
            $content
        );

        if ($rewritten === null) {
            return $content;
        }

        // Rewrite link tags
        $rewritten = preg_replace_callback(
            '/(<link\b[^>]*\bhref=["\'])([^"\']+)(["\'][^>]*>)/i',
            $rewriteAttribute,
            $rewritten
        );

        // Rewrite img tags
        $rewritten = preg_replace_callback(
            '/(<img\b[^>]*\bsrc=["\'])([^"\']+)(["\'][^>]*>)/i',
            $rewriteAttribute,
            $rewritten
        );

        return $rewritten ?? $content;
    }

    protected function renderMaintenancePage(HttpResponse $response): string
    {
        $response->setStatusCode(503);
        $response->setHeader('Retry-After', '3600');
        $body = "<!DOCTYPE html><html><head><title>Maintenance</title></head><body><h1>503 Service Unavailable</h1><p>Vite application is being updated. Please try again later.</p></body></html>";
        $response->setBody($body);
        $this->applySecurityHeaders($response);
        $response->send();
        return $body;
    }

    public function serve(HttpRequest $request, HttpResponse $response): HttpResponse|string|null
    {
        $uri = $request->getPath();
        
        if ($this->debugMode) {
            $this->logDebug("Vite serve called with URI: {$uri}");
            $this->logDebug("Build directory: {$this->buildDirectory}");
        }

        // Check excluded paths
        foreach ($this->excludedPaths as $excluded) {
            if (str_starts_with($uri, $excluded) || $uri === $excluded) {
                if ($this->debugMode) {
                    $this->logDebug("URI {$uri} is excluded");
                }
                return null;
            }
        }

        // If it's a request for the build directory, serve static files
        if (strpos($uri, '/' . $this->buildDirectory) === 0) {
            $filePath = $this->resolveFilePath($uri);
            if ($filePath && file_exists($filePath) && is_file($filePath)) {
                if ($this->debugMode) {
                    $this->logDebug("Serving static file: {$filePath}");
                }
                return $this->serveStaticFile($filePath, $request, $response);
            }
            
            // If file doesn't exist but it's a static file extension, try to find it
            if ($this->isStaticFileRequest($uri)) {
                // Try to find the file in the build directory
                $publicPath = $this->app->getPublicPath();
                $buildPath = $publicPath . '/' . $this->buildDirectory;
                $relativePath = substr($uri, strlen('/' . $this->buildDirectory . '/'));
                $filePath = $buildPath . '/' . $relativePath;
                
                if (file_exists($filePath) && is_file($filePath)) {
                    return $this->serveStaticFile($filePath, $request, $response);
                }
            }
        }

        // For all other requests, serve index.html (SPA routing)
        $publicPath = $this->app->getPublicPath();
        $indexPath = $publicPath . '/' . $this->buildDirectory . '/index.html';
        
        if ($this->debugMode) {
            $this->logDebug("Looking for index at: {$indexPath}");
        }

        if (!file_exists($indexPath)) {
            // Try alternative paths
            $altPaths = [
                $publicPath . '/build/index.html',
                getcwd() . '/public/' . $this->buildDirectory . '/index.html',
                getcwd() . '/public/build/index.html',
            ];
            
            foreach ($altPaths as $altPath) {
                if (file_exists($altPath)) {
                    $indexPath = $altPath;
                    break;
                }
            }
            
            if (!file_exists($indexPath)) {
                $this->logger->critical('Vite index file missing', ['path' => $indexPath]);
                $response->setStatusCode(404);
                $response->setBody('<!DOCTYPE html><html><head><title>404</title></head><body><h1>404 - Page Not Found</h1><p>The application has not been built yet. Please run "php artisan vite:build".</p></body></html>');
                $response->send();
                return null;
            }
        }

        // Cache the index content
        if ($this->indexContent === null || filemtime($indexPath) !== $this->indexLastModified) {
            $this->indexContent = file_get_contents($indexPath);
            $this->indexLastModified = filemtime($indexPath);
        }
        
        // Ensure asset paths are correct
        $body = $this->rewriteIndexHtml($this->indexContent);
        
        $response->setHeader('Content-Type', 'text/html');
        $response->setHeader('Cache-Control', 'no-cache, must-revalidate');
        $response->setBody($body);
        $this->applySecurityHeaders($response);
        $response->send();
        
        return $body;
    }

    protected function resolveFilePath(string $uri): ?string
    {
        // Remove query string
        $uri = strtok($uri, '?');
        
        // Get the public build path
        $publicPath = $this->app->getPublicPath();
        $buildPath = $publicPath . '/' . $this->buildDirectory;
        
        if (!is_dir($buildPath)) {
            // Try alternative build path
            $altBuildPath = getcwd() . '/public/' . $this->buildDirectory;
            if (is_dir($altBuildPath)) {
                $buildPath = $altBuildPath;
            } else {
                $altBuildPath = getcwd() . '/public/build';
                if (is_dir($altBuildPath)) {
                    $buildPath = $altBuildPath;
                }
            }
        }
        
        // Normalize the URI
        $uri = '/' . ltrim($uri, '/');
        
        // If the URI already contains the build directory, extract the relative path
        if (strpos($uri, '/' . $this->buildDirectory . '/') === 0) {
            $relativePath = substr($uri, strlen('/' . $this->buildDirectory . '/'));
            $filePath = $buildPath . '/' . $relativePath;
        } else {
            // Otherwise, try to find the file in the build directory
            $filePath = $buildPath . $uri;
        }
        
        // Normalize the path
        $filePath = $this->normalizePath($filePath);
        
        // Security check
        if (!$this->isSafePath($filePath, $buildPath)) {
            $this->logger->warning('Path traversal attempt detected', [
                'uri' => $uri,
                'path' => $filePath
            ]);
            return null;
        }
        
        return $filePath;
    }

    /**
     * Check if the request is for a static file
     */
    protected function isStaticFileRequest(string $uri): bool
    {
        // Static file extensions
        $extensions = ['js', 'css', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'ico', 'webp', 'woff', 'woff2', 'ttf', 'eot', 'map', 'json', 'txt', 'xml', 'html'];
        
        $extension = pathinfo($uri, PATHINFO_EXTENSION);
        if (empty($extension)) {
            return false;
        }
        
        return in_array(strtolower($extension), $extensions);
    }

    protected function serveStaticFile(string $filePath, HttpRequest $request, HttpResponse $response): HttpResponse
    {
        if ($this->debugMode) {
            $this->logDebug("Serving static file: {$filePath}");
        }

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

    protected function serveIndexFile(HttpRequest $request, HttpResponse $response): ?string
    {
        $publicPath = $this->app->getPublicPath();
        $indexPath = $publicPath . '/' . $this->buildDirectory . '/' . $this->indexFile;
        
        if ($this->debugMode) {
            $this->logDebug("Serving index file: {$indexPath}");
            $this->logDebug("Index exists: " . (file_exists($indexPath) ? 'yes' : 'no'));
        }

        if (!file_exists($indexPath)) {
            $this->logger->critical('Vite index file missing', ['path' => $indexPath]);
            $response->setStatusCode(404);
            $response->setBody('<!DOCTYPE html><html><head><title>404</title></head><body><h1>404 - Page Not Found</h1><p>The application has not been built yet. Please run "php artisan vite:build".</p></body></html>');
            $response->send();
            return null;
        }

        // Cache the index content
        if ($this->indexContent === null || filemtime($indexPath) !== $this->indexLastModified) {
            $this->indexContent = file_get_contents($indexPath);
            $this->indexLastModified = filemtime($indexPath);
        }
        
        // Ensure asset paths are correct
        $body = $this->rewriteIndexHtml($this->indexContent);
        
        $response->setHeader('Content-Type', 'text/html');
        $response->setHeader('Cache-Control', 'no-cache, must-revalidate');
        $response->setBody($body);
        $this->applySecurityHeaders($response);
        $response->send();
        
        return $body;
    }

    /**
     * Enhanced proxy with better error handling
     */
    public function proxyToDevServer(HttpRequest $request, HttpResponse $response): ?string
    {
        if (!$this->isHot) return null;
        if ($this->app->getEnvironment() === 'production') {
            throw new MachinjiriException('Vite dev server proxy is not allowed in production', 60011);
        }

        $uri = $request->getPath();
        
        // Check for static files in dev server
        if ($this->isStaticFileRequest($uri)) {
            // Let the dev server handle it
        }

        $targetUrl = $this->devServerUrl . $uri;
        
        if ($this->debugMode) {
            $this->logDebug("Proxying to dev server: {$targetUrl}");
        }

        try {
            $client = $request->getClient();
            $proxyResponse = $client->toHttpResponse($client->get($targetUrl, $request->getHeaders()));

            foreach ($proxyResponse->getHeaders() as $name => $value) {
                if (!in_array(strtolower($name), ['transfer-encoding', 'connection'])) {
                    $response->setHeader($name, $value);
                }
            }
            $this->applySecurityHeaders($response);
            return $proxyResponse->getBody();
        } catch (\Exception $e) {
            $this->logger->error('Vite proxy failed', ['target' => $targetUrl, 'error' => $e->getMessage()]);
            $response->setStatusCode(502)->setBody('Bad Gateway: Unable to reach Vite dev server.')->send();
            return null;
        }
    }

    /**
     * Debug logging helper
     */
    protected function logDebug(string $message): void
    {
        if ($this->debugMode && $this->logger) {
            $this->logger->debug($message, ['component' => 'vite']);
        }
        
        // Also write to a debug file for easier troubleshooting
        if ($this->debugMode) {
            $logFile = $this->app->storage . 'logs/vite_debug.log';
            $logEntry = date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL;
            file_put_contents($logFile, $logEntry, FILE_APPEND);
        }
    }

    /**
     * Get debug log
     */
    public function getDebugLog(): array
    {
        return $this->debugLog;
    }

    /**
     * Clear debug log
     */
    public function clearDebugLog(): void
    {
        $this->debugLog = [];
    }
}