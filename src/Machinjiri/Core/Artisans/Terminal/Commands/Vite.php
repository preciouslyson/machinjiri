<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Integrations\Vite\Vite as ViteIntegration;

trait viteHelpers
{

    private function fixAssetPaths(string $content, string $buildDir): string
    {
        // Fix asset paths in HTML to use the correct base URL
        $content = preg_replace_callback(
            '/(<(?:script|link|img)\b[^>]*\b(?:src|href)=["\'])([^"\']+)(["\'][^>]*>)/i',
            function ($matches) use ($buildDir) {
                $src = $matches[2];
                
                // Skip external URLs, data URIs, etc.
                if (preg_match('/^(https?:)?\/\//', $src) || 
                    str_starts_with($src, 'data:') || 
                    str_starts_with($src, 'mailto:') || 
                    str_starts_with($src, '#')) {
                    return $matches[0];
                }
                
                // If the path already starts with /build/, keep it
                if (strpos($src, '/' . $buildDir . '/') === 0) {
                    return $matches[0];
                }
                
                // If it's a relative path starting with ./, remove the ./
                if (strpos($src, './') === 0) {
                    $src = substr($src, 2);
                }
                
                // If it starts with /, remove it to avoid double slashes
                $cleanSrc = ltrim($src, '/');
                
                // Don't add build directory for external or absolute paths
                if (strpos($cleanSrc, 'http') === 0 || strpos($cleanSrc, '//') === 0) {
                    return $matches[0];
                }
                
                return $matches[1] . '/' . $buildDir . '/' . $cleanSrc . $matches[3];
            },
            $content
        );
        
        return $content ?? $content;
    }

    private function checkNodeEnvironment(SymfonyStyle $ss, ?string $packageManager): bool
    {
        $pm = $packageManager ?? 'npm';
        $process = new Process([$pm, '--version']);
        $process->run();
        if (!$process->isSuccessful()) {
            $ss->error("{$pm} is not installed or not in PATH.");
            return false;
        }
        $process = new Process(['node', '--version']);
        $process->run();
        if (!$process->isSuccessful()) {
            $ss->error("Node.js is not installed.");
            return false;
        }
        return true;
    }

    private function detectPackageManager(string $appDir): string
    {
        if (file_exists($appDir . '/yarn.lock')) return 'yarn';
        if (file_exists($appDir . '/pnpm-lock.yaml')) return 'pnpm';
        if (file_exists($appDir . '/bun.lockb')) return 'bun';
        return 'npm';
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function rewriteEntryScript(string $filePath, string $devUrl, ?string $buildUrl = null): void
    {
        if (!file_exists($filePath)) return;

        $content = file_get_contents($filePath);
        if ($content === false) return;

        // Determine the base URL for assets
        $baseUrl = $buildUrl ?? $devUrl;
        $baseUrl = rtrim($baseUrl, '/');

        // More comprehensive rewriting for both script and link tags
        $rewritten = preg_replace_callback(
            '/(<(?:script|link|img)\b[^>]*\b(?:src|href)=["\'])([^"\']+)(["\'][^>]*>)/i',
            function (array $matches) use ($baseUrl): string {
                $src = $matches[2];
                
                // Skip external URLs, data URIs, etc.
                if (preg_match('/^(https?:)?\/\//', $src) || 
                    str_starts_with($src, 'data:') || 
                    str_starts_with($src, 'mailto:') || 
                    str_starts_with($src, '#')) {
                    return $matches[0];
                }

                // For production builds, handle the path correctly
                // If the path already starts with /, remove it to avoid double slashes
                $cleanSrc = ltrim($src, '/');
                $resolvedSrc = $baseUrl . '/' . $cleanSrc;

                return $matches[1] . $resolvedSrc . $matches[3];
            },
            $content
        );

        if ($rewritten !== null && $rewritten !== $content) {
            file_put_contents($filePath, $rewritten);
        }
    }

    private function copyDirectoryContents(string $sourceDir, string $targetDir): void
    {
        if (!is_dir($sourceDir)) return;
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $sourceDir = rtrim($sourceDir, DIRECTORY_SEPARATOR);
        $targetDir = rtrim($targetDir, DIRECTORY_SEPARATOR);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($sourceDir));
            $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
            $targetPath = $targetDir . DIRECTORY_SEPARATOR . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } elseif ($item->isFile()) {
                $targetDirPath = dirname($targetPath);
                if (!is_dir($targetDirPath)) {
                    mkdir($targetDirPath, 0755, true);
                }
                copy($item->getPathname(), $targetPath);
            }
        }
    }
    
    private function detectEntryPoints(string $appDir): array
    {
        $possibleEntries = [
            'src/main.jsx',
            'src/main.js',
            'src/main.tsx',
            'src/main.ts',
            'index.html',
            'src/app.jsx',
            'src/app.js',
            'src/app.tsx',
            'src/app.ts',
            'src/App.jsx',
            'src/App.js',
            'src/App.tsx',
            'src/App.ts'
        ];
        
        $entries = [];
        foreach ($possibleEntries as $entry) {
            $fullPath = $appDir . DIRECTORY_SEPARATOR . $entry;
            if (file_exists($fullPath)) {
                $entries[] = $entry;
            }
        }
        
        return $entries;
    }

    private function findViteEntryPoint(string $appDir): ?string
    {
        // Check if there's a vite.config.js/js/ts file
        $configFiles = ['vite.config.js', 'vite.config.ts', 'vite.config.mjs'];
        $configPath = null;
        
        foreach ($configFiles as $config) {
            $path = $appDir . DIRECTORY_SEPARATOR . $config;
            if (file_exists($path)) {
                $configPath = $path;
                break;
            }
        }
        
        if ($configPath) {
            // Read the config file to find the entry point
            $content = file_get_contents($configPath);
            
            // Look for input or entry configuration
            if (preg_match('/input\s*:\s*\{\s*([^}]+)\}/s', $content, $matches)) {
                // Parse the input object
                $entries = [];
                preg_match_all('/["\']([^"\']+)["\']\s*:\s*["\']([^"\']+)["\']/', $matches[1], $entryMatches);
                if (!empty($entryMatches[1])) {
                    // Return the first entry
                    return $entryMatches[2][0];
                }
            }
            
            if (preg_match('/input\s*:\s*["\']([^"\']+)["\']/', $content, $matches)) {
                return $matches[1];
            }
        }
        
        // If no config entry found, try to detect from common locations
        $commonEntries = [
            'index.html',
            'src/main.jsx',
            'src/main.js',
            'src/main.tsx',
            'src/main.ts',
            'src/App.jsx',
            'src/App.js',
            'src/App.tsx',
            'src/App.ts'
        ];
        
        foreach ($commonEntries as $entry) {
            if (file_exists($appDir . DIRECTORY_SEPARATOR . $entry)) {
                return $entry;
            }
        }
        
        return null;
    }
}

/**
 * Vite Integration Commands
 */
class Vite
{
    /**
     * Get all Vite commands.
     *
     * @return array<Command>
     */
    public static function getCommands(): array
    {
        return [
            new class extends Command {
                use CommandHelper, viteHelpers;

                public function __construct()
                {
                    parent::__construct('vite:init');
                    $this->setDescription('Initialize Vite integration in the current project');
                    $this->setHelp(<<<HELP
This command will:
- Create the Vite integration configuration file (config/vite.php)
- Generate the ViteServiceProvider (app/Providers/ViteServiceProvider.php)
- Register the provider in config/providers.php
- Optionally create a basic Vite project scaffold in the specified directory
HELP
                    );
                }

                protected function configure(): void
                {
                    $this->addOption('app-dir', null, InputOption::VALUE_OPTIONAL, 'Directory where Vite frontend source will be created (relative to project root)', 'resources/frontend');
                    $this->addOption('build-dir', null, InputOption::VALUE_OPTIONAL, 'Build output directory (relative to public)', 'build');
                    $this->addOption('template', null, InputOption::VALUE_OPTIONAL, 'Vite template (vanilla, vue, react, preact, lit, svelte)', 'vanilla');
                    $this->addOption('package-manager', null, InputOption::VALUE_OPTIONAL, 'Package manager (npm, yarn, pnpm, bun)', 'npm');
                    $this->addOption('skip-install', null, InputOption::VALUE_NONE, 'Skip npm install after scaffolding');
                    $this->addOption('skip-provider', null, InputOption::VALUE_NONE, 'Skip generating the service provider');
                    $this->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing configuration and scaffold');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Vite Integration Initializer', function (SymfonyStyle $ss) use ($input) {
                        // Check Node.js environment
                        if (!$this->checkNodeEnvironment($ss, $input->getOption('package-manager'))) {
                            return Command::FAILURE;
                        }

                        $created = [];

                        // 1. Create configuration file (merge if exists)
                        $configPath = $this->createViteConfig($this->artisanContainer(), $input);
                        if ($configPath) {
                            $created[] = 'Configuration: ' . $configPath;
                        } elseif (!$input->getOption('force')) {
                            $ss->warning('Configuration already exists. Use --force to overwrite.');
                        }

                        // 2. Create service provider
                        if (!$input->getOption('skip-provider')) {
                            $providerPath = $this->createViteServiceProvider($this->artisanContainer(), $input);
                            if ($providerPath) {
                                $created[] = 'Service Provider: ' . $providerPath;
                            }
                        }

                        // 3. Register provider in config/providers.php (idempotent)
                        $this->registerProviderInConfig($this->artisanContainer());

                        // 4. Create Vite project scaffold
                        $appDir = $input->getOption('app-dir') ?: 'resources/' . getenv("APP_NAME", "frontend");
                        $template = $input->getOption('template') ?: 'vanilla';
                        $packageManager = $input->getOption('package-manager') ?: 'npm';
                        $skipInstall = $input->getOption('skip-install');

                        $result = $this->createViteScaffold($ss, $appDir, $template, $packageManager, $skipInstall, $input->getOption('force'));
                        if ($result) {
                            $created[] = "Vite frontend scaffold created in: {$appDir} (template: {$template})";
                            // Update build path in config
                            $this->updateBuildPathInConfig($this->artisanContainer(), $appDir, $input->getOption('build-dir') ?: 'build');
                        }

                        if (empty($created)) {
                            $ss->warning('Nothing was created.');
                            return Command::SUCCESS;
                        }

                        $ss->success('Vite integration initialized successfully!');
                        $ss->section('Created items');
                        $ss->listing($created);
                        $ss->note('You may need to run "composer dump-autoload" if you added new classes.');
                        $ss->note('Don\'t forget to run "php artisan vite:install" to install npm dependencies.');

                        return Command::SUCCESS;
                    });
                }

                

                private function createViteConfig(Container $container, InputInterface $input): ?string
                {
                    $configDir = $container->config;
                    if (!is_dir($configDir)) {
                        mkdir($configDir, 0755, true);
                    }
                    $configFile = $configDir . 'vite.php';
                    if (file_exists($configFile) && !$input->getOption('force')) {
                        return null;
                    }

                    $buildDir = $input->getOption('build-dir') ?: 'build';
                    $useDevServer = $container->getEnvironment() === 'development' ? 'true' : 'false';
                    $autoDetect = $container->getEnvironment() === 'development' ? 'true' : 'false';

                    $template = <<<PHP
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Vite Development Server
    |--------------------------------------------------------------------------
    */
    'dev_server_url' => env('VITE_DEV_SERVER_URL', 'http://localhost:5173'),
    
    /*
    |--------------------------------------------------------------------------
    | Build Configuration
    |--------------------------------------------------------------------------
    */
    'manifest_path' => env('VITE_MANIFEST_PATH', 'build/manifest.json'),
    'build_directory' => env('VITE_BUILD_DIRECTORY', 'build'),
    'index_file' => 'index.html',
    
    /*
    |--------------------------------------------------------------------------
    | Excluded Paths (These will bypass Vite handling)
    |--------------------------------------------------------------------------
    */
    'excluded_paths' => [
        '/api/',
        '/assets/',
        '/storage/',
        '/vendor/',
        'favicon.ico',
        'robots.txt',
        '/health',
        '/metrics',
        '/_debugbar',
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Security Headers
    |--------------------------------------------------------------------------
    */
    'security_headers' => [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'X-XSS-Protection' => '1; mode=block',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'geolocation=(), microphone=(), camera=(), payment=(), usb=()',
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self' https:;",
    ],
    'security_headers_enabled' => true,
    
    /*
    |--------------------------------------------------------------------------
    | Cache & Performance
    |--------------------------------------------------------------------------
    */
    'cache_control_max_age' => 31536000, // 1 year
    'enable_compression' => true,
    'enable_caching' => env('VITE_ENABLE_CACHING', true),
    'cache_ttl' => 86400, // 24 hours for manifest cache
    
    /*
    |--------------------------------------------------------------------------
    | Routing & Fallback
    |--------------------------------------------------------------------------
    */
    'register_fallback_route' => true,
    'spa_mode' => true, // Enable SPA mode for client-side routing
    
    /*
    |--------------------------------------------------------------------------
    | Environment Detection
    |--------------------------------------------------------------------------
    */
    'use_dev_server' => env('APP_ENV') === 'development',
    'auto_detect_dev_server' => env('APP_ENV') === 'development',
    
    /*
    |--------------------------------------------------------------------------
    | Error Handling
    |--------------------------------------------------------------------------
    */
    'error_reporting' => env('APP_ENV') === 'production' ? false : true,
    'show_error_details' => env('APP_ENV') === 'production' ? false : true,
    'error_logging' => true,
    
    /*
    |--------------------------------------------------------------------------
    | Performance Optimization
    |--------------------------------------------------------------------------
    */
    'preload_assets' => true,
    'preconnect_domains' => [
        // Add CDN domains here
        // 'https://cdn.jsdelivr.net',
    ],
    'dns_prefetch_domains' => [
        // Add domains for DNS prefetch
        // 'https://fonts.googleapis.com',
    ],
];
PHP;
                    file_put_contents($configFile, $template);
                    return $configFile;
                }

                private function createViteServiceProvider(Container $container, InputInterface $input): ?string
                {
                    $providersDir = $container->app . 'Providers/';
                    if (!is_dir($providersDir)) {
                        mkdir($providersDir, 0755, true);
                    }

                    $providerFile = $providersDir . 'ViteServiceProvider.php';
                    if (file_exists($providerFile) && !$input->getOption('force')) {
                        return null;
                    }

                    $template = <<<'PHP'
<?php

namespace Mlangeni\Machinjiri\App\Providers;

use Mlangeni\Machinjiri\Core\Providers\ServiceProvider;
use Mlangeni\Machinjiri\Integrations\Vite\Vite;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Routing\Router;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Http\HttpRequest;

class ViteServiceProvider extends ServiceProvider
{
    protected ?Vite $vite = null;
    protected ?Logger $logger = null;

    public function register(): void
    {
        $this->app->singleton(Vite::class, function ($app) {
            $logger = $app->bound(Logger::class) ? $app->make(Logger::class) : null;
            return new Vite($app, $logger);
        });
    }

    public function boot(): void
    {
        $this->vite = $this->app->make(Vite::class);
        $this->logger = $this->app->bound(Logger::class) ? $this->app->make(Logger::class) : null;

        // Register routes with proper priority
        $this->registerViteRoutes();
    }

    protected function registerViteRoutes(): void
    {
        // Register asset routes first (higher priority)
        $this->registerAssetRoutes();

        // Register the main Vite route handler with fallback support
        if ($this->vite->isHot()) {
            $this->registerDevServerRoutes();
        } else {
            $this->registerProductionRoutes();
        }
    }

    protected function registerAssetRoutes(): void
    {
        $buildDir = $this->vite->getBuildDirectory();
        
        // Static asset serving for build directory
        Router::get("/{$buildDir}/{path}", function ($request, $response, $path) {
            return $this->handleAssetRequest($request, $response, $path);
        }, 'vite.assets')->where(['path' => '.*']);

        // Handle manifest file specifically
        Router::get("/{$buildDir}/manifest.json", function ($request, $response) {
            return $this->handleManifestRequest($request, $response);
        }, 'vite.manifest');
    }

    protected function registerDevServerRoutes(): void
    {
        // In development, proxy everything to Vite dev server
        Router::any('/{any}', function ($request, $response, $any) {
            return $this->handleDevServerRequest($request, $response);
        }, 'vite.dev')->where(['any' => '.*']);
    }

    protected function registerProductionRoutes(): void
    {
        // In production, serve the built files
        Router::any('/{any}', function ($request, $response, $any) {
            return $this->handleProductionRequest($request, $response);
        }, 'vite.prod')->where(['any' => '.*']);
    }

    protected function registerFallbackRoute(): void
    {
        // This will be used when no other route matches
        Router::fallback(function ($request, $response) {
            return $this->handleFallbackRequest($request, $response);
        });
    }

    protected function handleAssetRequest(HttpRequest $request, HttpResponse $response, string $path): HttpResponse
    {
        try {
            $buildDir = $this->vite->getBuildDirectory();
            $filePath = $this->app->getPublicPath() . '/' . $buildDir . '/' . $path;
            
            // Security check
            if (!$this->isPathSafe($filePath)) {
                return $this->errorResponse($response, 403, 'Access denied');
            }

            if (!file_exists($filePath) || !is_file($filePath)) {
                return $this->errorResponse($response, 404, 'Asset not found');
            }

            // Serve the file with proper headers
            $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
            $response->setHeader('Content-Type', $mimeType);
            
            // Cache control for production
            $response->setHeader('Cache-Control', 'public, max-age=31536000, immutable');
            
            // ETag support
            $etag = md5_file($filePath);
            $response->setHeader('ETag', $etag);
            
            if ($request->getHeader('If-None-Match') === $etag) {
                $response->setStatusCode(304);
                $response->send();
                return $response;
            }

            // Serve compressed version if available and accepted
            $content = $this->getCompressedContent($filePath, $request);
            $response->setBody($content);
            $response->send();
            return $response;

        } catch (\Exception $e) {
            $this->logError('Asset request failed', ['path' => $path, 'error' => $e->getMessage()]);
            return $this->errorResponse($response, 500, 'Failed to serve asset');
        }
    }

    protected function handleManifestRequest($request, $response): HttpResponse
    {
        try {
            $buildDir = $this->vite->getBuildDirectory();
            $manifestPath = $this->app->getPublicPath() . '/' . $buildDir . '/manifest.json';
            
            if (!file_exists($manifestPath)) {
                return $this->errorResponse($response, 404, 'Manifest not found');
            }

            $content = file_get_contents($manifestPath);
            $response->setHeader('Content-Type', 'application/json');
            $response->setHeader('Cache-Control', 'public, max-age=3600');
            $response->setBody($content);
            $response->send();
            return $response;

        } catch (\Exception $e) {
            $this->logError('Manifest request failed', ['error' => $e->getMessage()]);
            return $this->errorResponse($response, 500, 'Failed to serve manifest');
        }
    }

    protected function handleDevServerRequest($request, $response): HttpResponse
    {
        try {
            // Check if we should handle this request or let it pass through
            if ($this->shouldSkipViteHandling($request)) {
                return $response;
            }

            $result = $this->vite->proxyToDevServer($request, $response);
            
            if ($result instanceof HttpResponse) {
                return $result;
            }

            // If the proxy returned a string body, ensure it's properly sent
            if (is_string($result)) {
                $response->setBody($result);
                $response->send();
                return $response;
            }

            return $response;

        } catch (MachinjiriException $e) {
            $this->logError('Vite dev server proxy failed', ['error' => $e->getMessage()]);
            return $this->errorResponse($response, 502, 'Vite dev server unavailable');
        } catch (\Exception $e) {
            $this->logError('Vite dev server error', ['error' => $e->getMessage()]);
            return $this->errorResponse($response, 500, 'Vite dev server error');
        }
    }

    protected function handleProductionRequest(HttpRequest $request, HttpResponse $response): HttpResponse
    {
        try {
            // Check if we should handle this request
            if ($this->shouldSkipViteHandling($request)) {
                return $response;
            }

            // Check if the request is for a static file
            $uri = $request->getPath();
            if ($this->isStaticFileRequest($uri)) {
                $result = $this->vite->serve($request, $response);
                if ($result instanceof HttpResponse) {
                    return $result;
                }
                if (is_string($result)) {
                    $response->setBody($result);
                    $response->send();
                    return $response;
                }
            }

            // For all other requests, serve the Vite app
            $result = $this->vite->serve($request, $response);
            
            if ($result instanceof HttpResponse) {
                return $result;
            }

            if (is_string($result)) {
                $response->setBody($result);
                $response->send();
                return $response;
            }

            // If we got null, the Vite integration didn't handle it
            // Try to serve index.html directly
            $buildDir = $this->vite->getBuildDirectory();
            $indexPath = $this->app->getPublicPath() . '/' . $buildDir . '/index.html';
            
            if (file_exists($indexPath)) {
                $content = file_get_contents($indexPath);
                $response->setHeader('Content-Type', 'text/html');
                $response->setHeader('Cache-Control', 'no-cache, must-revalidate');
                $response->setBody($content);
                $response->send();
                return $response;
            }

            return $this->errorResponse($response, 404, 'Application not found');

        } catch (MachinjiriException $e) {
            $this->logError('Vite production serve failed', ['error' => $e->getMessage()]);
            
            if ($this->app->getEnvironment() === 'production') {
                return $this->errorResponse($response, 500, 'Application error');
            }
            
            return $this->errorResponse($response, 500, "Vite error: " . $e->getMessage());
        } catch (\Exception $e) {
            $this->logError('Vite production error', ['error' => $e->getMessage()]);
            return $this->errorResponse($response, 500, 'Application error');
        }
    }

    protected function handleFallbackRequest($request, $response): HttpResponse
    {
        try {
            // In production, try to serve the index.html for SPA routing
            if (!$this->vite->isHot()) {
                $buildDir = $this->vite->getBuildDirectory();
                $indexPath = $this->app->getPublicPath() . '/' . $buildDir . '/index.html';
                
                if (file_exists($indexPath)) {
                    $content = file_get_contents($indexPath);
                    
                    // Rewrite asset paths in the HTML
                    $baseUrl = $this->getBaseUrl();
                    $content = $this->rewriteHtmlAssets($content, $baseUrl);
                    
                    $response->setHeader('Content-Type', 'text/html');
                    $response->setHeader('Cache-Control', 'no-cache, must-revalidate');
                    $response->setBody($content);
                    $response->send();
                    return $response;
                }
            }

            // If we can't serve the Vite app, return a 404
            return $this->errorResponse($response, 404, 'Page not found');

        } catch (\Exception $e) {
            $this->logError('Fallback request failed', ['error' => $e->getMessage()]);
            return $this->errorResponse($response, 500, 'Failed to serve application');
        }
    }

    protected function shouldSkipViteHandling($request): bool
    {
        $uri = $request->getPath();
        
        // Skip API routes
        if (str_starts_with($uri, '/api/')) {
            return true;
        }
        
        // Skip static asset routes (but only if they're not in the build directory)
        if ($this->isStaticFileRequest($uri) && !str_starts_with($uri, '/' . $this->vite->getBuildDirectory())) {
            return true;
        }
        
        return false;
    }

    protected function handleMaintenanceMode($response): HttpResponse
    {
        if (file_exists($this->app->storage . 'framework/maintenance')) {
            $this->vite->renderMaintenancePage($response);
            return $response;
        }
        return $response;
    }

    protected function getCompressedContent(string $filePath, $request): string
    {
        if (!$this->vite->isCompressionEnabled()) {
            return file_get_contents($filePath);
        }

        $acceptEncoding = $request->getHeader('Accept-Encoding');
        
        // Check for brotli
        if (strpos($acceptEncoding, 'br') !== false && file_exists($filePath . '.br')) {
            $content = file_get_contents($filePath . '.br');
            $this->httpResponse->setHeader('Content-Encoding', 'br');
            return $content;
        }
        
        // Check for gzip
        if (strpos($acceptEncoding, 'gzip') !== false && file_exists($filePath . '.gz')) {
            $content = file_get_contents($filePath . '.gz');
            $this->httpResponse->setHeader('Content-Encoding', 'gzip');
            return $content;
        }
        
        return file_get_contents($filePath);
    }

    protected function rewriteHtmlAssets(string $content, string $baseUrl): string
    {
        $buildDir = $this->vite->getBuildDirectory();
        
        // Rewrite asset paths in HTML
        $content = preg_replace_callback(
            '/(<(?:script|link)\b[^>]*\b(?:src|href)=["\'])(?!https?:\/\/)([^"\']+)(["\'][^>]*>)/i',
            function ($matches) use ($baseUrl, $buildDir) {
                $path = ltrim($matches[2], '/');
                
                // Skip external or data URIs
                if (preg_match('/^(https?:)?\/\//', $path) || 
                    str_starts_with($path, 'data:') || 
                    str_starts_with($path, '#')) {
                    return $matches[0];
                }
                
                // Ensure the path includes the build directory if needed
                if (!str_starts_with($path, $buildDir) && 
                    !str_starts_with($path, 'http') && 
                    !str_starts_with($path, 'data:')) {
                    $path = $buildDir . '/' . $path;
                }
                
                return $matches[1] . $baseUrl . '/' . ltrim($path, '/') . $matches[3];
            },
            $content
        );
        
        return $content ?? $content;
    }

    protected function getBaseUrl(): string
    {
        $baseUrl = Container::getBaseUrl();
        return rtrim($baseUrl, '/');
    }

    protected function isPathSafe(string $path): bool
    {
        $realPath = realpath($path);
        if ($realPath === false) {
            return false;
        }
        
        $publicPath = realpath($this->app->getPublicPath());
        return str_starts_with($realPath, $publicPath);
    }

    protected function errorResponse(HttpResponse $response, int $code, string $message): HttpResponse
    {
        $response->setStatusCode($code);
        
        // Check if we should return JSON
        $request = $this->app->make(HttpRequest::class);
        if ($request->isAjax() || str_contains($request->getHeader('Accept') ?? '', 'application/json')) {
            $response->setHeader('Content-Type', 'application/json');
            $response->setJsonBody([
                'error' => true,
                'code' => $code,
                'message' => $message,
                'timestamp' => time()
            ]);
        } else {
            // Return HTML error page
            $html = $this->renderErrorPage($code, $message);
            $response->setHeader('Content-Type', 'text/html');
            $response->setBody($html);
        }
        
        $response->send();
        return $response;
    }

    protected function renderErrorPage(int $code, string $message): string
    {
        $errorFile = $this->app->config . 'errors/' . $code . '.php';
        
        if (file_exists($errorFile)) {
            ob_start();
            extract(['message' => $message, 'code' => $code]);
            include $errorFile;
            return ob_get_clean();
        }
        
        // Default error page
        $environment = $this->app->getEnvironment();
        $showDetails = $environment !== 'production';
        
        $html = '<!DOCTYPE html>';
        $html .= '<html lang="en">';
        $html .= '<head>';
        $html .= '<meta charset="UTF-8">';
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
        $html .= '<title>Error ' . $code . '</title>';
        $html .= '<style>';
        $html .= 'body { font-family: Arial, sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; background: #f5f5f5; }';
        $html .= '.error-container { text-align: center; padding: 2rem; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 600px; }';
        $html .= 'h1 { color: #333; margin-bottom: 0.5rem; }';
        $html .= '.error-code { font-size: 3rem; color: #e74c3c; margin: 1rem 0; }';
        $html .= '.error-message { color: #666; margin-bottom: 2rem; }';
        $html .= '.error-details { background: #f8f9fa; padding: 1rem; border-radius: 4px; text-align: left; font-size: 0.9rem; }';
        $html .= 'a { color: #3498db; text-decoration: none; }';
        $html .= 'a:hover { text-decoration: underline; }';
        $html .= '</style>';
        $html .= '</head>';
        $html .= '<body>';
        $html .= '<div class="error-container">';
        $html .= '<h1>Something went wrong</h1>';
        $html .= '<div class="error-code">' . $code . '</div>';
        $html .= '<div class="error-message">' . htmlspecialchars($message) . '</div>';
        
        if ($showDetails && $this->logger) {
            $html .= '<div class="error-details">';
            $html .= '<strong>Environment:</strong> ' . htmlspecialchars($environment) . '<br>';
            $html .= '<strong>Debug Info:</strong> Check application logs for more details.';
            $html .= '</div>';
        }
        
        $html .= '<br><a href="/">Go to Homepage</a>';
        $html .= '</div>';
        $html .= '</body>';
        $html .= '</html>';
        
        return $html;
    }

    protected function logError(string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->error($message, $context);
        }
    }

    protected function isStaticFileRequest(string $uri): bool
    {
        $extensions = ['js', 'css', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'ico', 'webp', 'woff', 'woff2', 'ttf', 'eot', 'map', 'json', 'txt', 'xml'];
        $extension = pathinfo($uri, PATHINFO_EXTENSION);
        return !empty($extension) && in_array(strtolower($extension), $extensions);
    }
}
PHP;
                    file_put_contents($providerFile, $template);
                    return $providerFile;
                }

                private function registerProviderInConfig(Container $container): void
                {
                    $providersConfigFile = $container->config . 'providers.php';
                    if (!file_exists($providersConfigFile)) {
                        $default = "<?php\nreturn ['providers' => [], 'deferred' => []];\n";
                        file_put_contents($providersConfigFile, $default);
                    }
                    $config = require $providersConfigFile;
                    $providerClass = 'Mlangeni\\Machinjiri\\App\\Providers\\ViteServiceProvider';
                    if (!in_array($providerClass, $config['providers'] ?? [])) {
                        $config['providers'][] = $providerClass;
                        $content = "<?php\nreturn " . var_export($config, true) . ";\n";
                        file_put_contents($providersConfigFile, $content);
                    }
                }

                private function createViteScaffold(SymfonyStyle $ss, string $appDir, string $template, string $packageManager, bool $skipInstall, bool $force): bool
                {
                    $fullPath = getcwd() . DIRECTORY_SEPARATOR . $appDir;
                    if (is_dir($fullPath)) {
                        if (!$force) {
                            $ss->warning("Directory {$appDir} already exists. Use --force to overwrite.");
                            return false;
                        }
                        $this->deleteDirectory($fullPath);
                    }

                    $tempDir = $fullPath . '_tmp';
                    $this->deleteDirectory($tempDir); // clean previous temp

                    $ss->text("Creating Vite project in temporary location...");
                    $createCmd = ['npm', 'create', 'vite@latest', basename($tempDir), '--', '--template', $template];
                    $process = new Process($createCmd, dirname($tempDir));
                    $process->setTimeout(300);
                    $process->run(function ($type, $buffer) use ($ss) {
                        $ss->write($buffer);
                    });
                    if (!$process->isSuccessful()) {
                        $ss->error("Scaffolding failed: " . $process->getErrorOutput());
                        $this->deleteDirectory($tempDir);
                        return false;
                    }

                    // Move temp to final location
                    rename($tempDir, $fullPath);

                    // Update package.json scripts
                    $packageJsonPath = $fullPath . DIRECTORY_SEPARATOR . 'package.json';
                    if (file_exists($packageJsonPath)) {
                        $package = json_decode(file_get_contents($packageJsonPath), true);
                        $package['scripts']['build'] = 'vite build --outDir ../../public/build';
                        $package['scripts']['dev'] = 'vite --port 5173';
                        file_put_contents($packageJsonPath, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    }

                    $entryIndexPath = $fullPath . DIRECTORY_SEPARATOR . 'index.html';
                    if (file_exists($entryIndexPath)) {
                        $this->rewriteEntryScript($entryIndexPath, 'http://localhost:5173');
                    }

                    // Create/override vite.config.js - Improved version
                    $viteConfig = <<<JS
import { defineConfig } from 'vite';
import path from 'path';

export default defineConfig({
    server: {
        port: 5173,
        strictPort: true,
    },
    build: {
        outDir: path.resolve(__dirname, '../../public/build'),
        emptyOutDir: true,
        manifest: true,
        rollupOptions: {
            // Auto-detect entry points
            input: {
                main: path.resolve(__dirname, 'index.html'),
            },
        },
    },
    // Ensure base path is set correctly for production
    base: '/build/',
});
JS;
                    file_put_contents($fullPath . DIRECTORY_SEPARATOR . 'vite.config.js', $viteConfig);

                    if (!$skipInstall) {
                        $ss->text("Installing dependencies with {$packageManager}...");
                        $installProcess = new Process([$packageManager, 'install'], $fullPath);
                        $installProcess->setTimeout(600);
                        $installProcess->run(function ($type, $buffer) use ($ss) {
                            $ss->write($buffer);
                        });
                        if (!$installProcess->isSuccessful()) {
                            $ss->error("Dependency installation failed.");
                            return false;
                        }
                    }

                    return true;
                }

                private function updateBuildPathInConfig(Container $container, string $appDir, string $buildDir): void
                {
                    $configFile = $container->config . 'vite.php';
                    if (!file_exists($configFile)) return;
                    $existing = require $configFile;
                    $existing['build_directory'] = $buildDir;
                    $existing['manifest_path'] = $buildDir . '/manifest.json';
                    $content = "<?php\nreturn " . var_export($existing, true) . ";\n";
                    file_put_contents($configFile, $content);
                }
            },

            new class extends Command {
                use CommandHelper, viteHelpers;

                public function __construct()
                {
                    parent::__construct('vite:install');
                    $this->setDescription('Install npm dependencies for the Vite frontend');
                }

                protected function configure(): void
                {
                    $this->addOption('app-dir', null, InputOption::VALUE_OPTIONAL, 'Vite frontend directory', 'resources/frontend');
                    $this->addOption('package-manager', null, InputOption::VALUE_OPTIONAL, 'Package manager (npm, yarn, pnpm, bun)');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Install Vite Dependencies', function (SymfonyStyle $ss) use ($input) {
                        $appDir = $input->getOption('app-dir');
                        $packageManager = $input->getOption('package-manager') ?: $this->detectPackageManager($appDir);
                        if (!$this->checkNodeEnvironment($ss, $packageManager)) {
                            return Command::FAILURE;
                        }
                        $ss->text("Running {$packageManager} install...");
                        $process = new Process([$packageManager, 'install'], $appDir);
                        $process->setTimeout(600);
                        $process->run(function ($type, $buffer) use ($ss) {
                            $ss->write($buffer);
                        });
                        if ($process->isSuccessful()) {
                            $ss->success('Dependencies installed.');
                            return Command::SUCCESS;
                        }
                        $ss->error('Installation failed: ' . $process->getErrorOutput());
                        return Command::FAILURE;
                    });
                }

            },

            new class extends Command {
                use CommandHelper, viteHelpers;

                public function __construct()
                {
                    parent::__construct('vite:install-package');
                    $this->setDescription('Install one or more frontend packages into the Vite project');
                }

                protected function configure(): void
                {
                    $this->addArgument('packages', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Package(s) to install (space separated)');
                    $this->addOption('app-dir', null, InputOption::VALUE_OPTIONAL, 'Vite frontend directory', 'resources/frontend');
                    $this->addOption('package-manager', null, InputOption::VALUE_OPTIONAL, 'Package manager (npm, yarn, pnpm, bun)');
                    $this->addOption('dev', null, InputOption::VALUE_NONE, 'Install package(s) as dev dependencies');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Install Vite Package', function (SymfonyStyle $ss) use ($input) {
                        $appDir = $input->getOption('app-dir');
                        $packageManager = $input->getOption('package-manager') ?: $this->detectPackageManager($appDir);
                        if (!$this->checkNodeEnvironment($ss, $packageManager)) {
                            return Command::FAILURE;
                        }

                        $packages = $input->getArgument('packages');
                        if (empty($packages)) {
                            $ss->error('No package names were provided.');
                            return Command::FAILURE;
                        }

                        $dev = $input->getOption('dev');
                        switch ($packageManager) {
                            case 'yarn':
                                $command = ['yarn', 'add'];
                                if ($dev) {
                                    $command[] = '--dev';
                                }
                                break;
                            case 'pnpm':
                                $command = ['pnpm', 'add'];
                                if ($dev) {
                                    $command[] = '--save-dev';
                                }
                                break;
                            case 'bun':
                                $command = ['bun', 'add'];
                                if ($dev) {
                                    $command[] = '--dev';
                                }
                                break;
                            case 'npm':
                            default:
                                $command = ['npm', 'install'];
                                if ($dev) {
                                    $command[] = '--save-dev';
                                }
                                break;
                        }

                        $command = array_merge($command, $packages);
                        $ss->text('Running: ' . implode(' ', $command));
                        $process = new Process($command, $appDir);
                        $process->setTimeout(600);
                        $process->run(function ($type, $buffer) use ($ss) {
                            $ss->write($buffer);
                        });

                        if ($process->isSuccessful()) {
                            $ss->success('Package(s) installed successfully.');
                            return Command::SUCCESS;
                        }

                        $ss->error('Package installation failed: ' . $process->getErrorOutput());
                        return Command::FAILURE;
                    });
                }

            },

            new class extends Command {
                use CommandHelper, viteHelpers;

                public function __construct()
                {
                    parent::__construct('vite:build');
                    $this->setDescription('Build the Vite frontend for production');
                }

                protected function configure(): void
                {
                    $this->addOption('app-dir', null, InputOption::VALUE_OPTIONAL, 'Vite frontend directory', 'resources/frontend');
                    $this->addOption('build-dir', null, InputOption::VALUE_OPTIONAL, 'Build output directory (relative to public)', 'build');
                    $this->addOption('watch', 'w', InputOption::VALUE_NONE, 'Watch for changes and rebuild');
                    $this->addOption('env', null, InputOption::VALUE_OPTIONAL, 'Environment (production, staging, etc.)');
                    $this->addOption('package-manager', null, InputOption::VALUE_OPTIONAL);
                    $this->addOption('force', null, InputOption::VALUE_NONE, 'Force build even in production (for debugging)');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Vite Build', function (SymfonyStyle $ss) use ($input) {
                        $container = $this->artisanContainer();
                        if ($container->getEnvironment() === 'production' && !$input->getOption('force')) {
                            $ss->error("vite:build is not allowed in production environment. Use --force to override.");
                            return Command::FAILURE;
                        }

                        $appDir = $input->getOption('app-dir');
                        $buildDir = $input->getOption('build-dir') ?: 'build';
                        $packageManager = $input->getOption('package-manager') ?: $this->detectPackageManager($appDir);
                        
                        if (!$this->checkNodeEnvironment($ss, $packageManager)) {
                            return Command::FAILURE;
                        }

                        // Detect entry point for the build
                        $entryPoint = $this->findViteEntryPoint($appDir);
                        if ($entryPoint) {
                            $ss->text("Using entry point: {$entryPoint}");
                        } else {
                            $ss->warning("No entry point detected, using default (index.html)");
                            $entryPoint = 'index.html';
                        }

                        // Clean build directory first
                        $publicBuildDir = getcwd() . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . $buildDir;
                        if (is_dir($publicBuildDir)) {
                            $this->deleteDirectory($publicBuildDir);
                        }
                        mkdir($publicBuildDir, 0755, true);

                        // Create or update vite.config.js with correct build settings
                        $this->createOptimizedViteConfig($appDir, $buildDir, $entryPoint, $ss);

                        $args = ['run', 'build'];
                        if ($input->getOption('watch')) {
                            $args[] = '--watch';
                        }
                        
                        $ss->text("Building with {$packageManager}...");
                        if (!file_exists($appDir . DIRECTORY_SEPARATOR . 'package.json')) {
                            $ss->error('package.json not found in ' . $appDir);
                            return Command::FAILURE;
                        }

                        // Check if vite is installed
                        $checkVite = new Process([$packageManager, 'list', 'vite'], $appDir);
                        $checkVite->run();
                        if (strpos($checkVite->getOutput(), 'vite') === false) {
                            $ss->warning('Vite not found in dependencies. Installing...');
                            $installVite = new Process([$packageManager, 'install', 'vite', '--save-dev'], $appDir);
                            $installVite->setTimeout(300);
                            $installVite->run();
                            if (!$installVite->isSuccessful()) {
                                $ss->error('Failed to install Vite');
                                return Command::FAILURE;
                            }
                        }

                        // Run the build
                        $args = ['run', 'build'];
                        if ($input->getOption('watch')) {
                            $args[] = '--watch';
                        }

                        // Set environment variables for build
                        $env = $input->getOption('env') ?: 'production';
                        putenv("NODE_ENV={$env}");
                        putenv("VITE_APP_ENV={$env}");

                        $process = new Process([$packageManager, ...$args], $appDir);
                        $process->setTimeout(null);
                        $process->setEnv(array_merge($process->getEnv(), [
                            'NODE_ENV' => $env,
                            'VITE_APP_ENV' => $env
                        ]));

                        $ss->text("Running: " . $process->getCommandLine());

                        $process->run(function ($type, $buffer) use ($ss) {
                            $ss->write($buffer);
                        });

                        if ($process->isSuccessful()) {
                            // Process the build output
                            $result = $this->processBuildOutput($appDir, $publicBuildDir, $buildDir, $entryPoint, $ss);
                            
                            if ($result) {
                                $ss->success('Build completed successfully!');
                                $ss->note("Build output directory: public/{$buildDir}");
                                return Command::SUCCESS;
                            } else {
                                $ss->error('Build processing failed.');
                                return Command::FAILURE;
                            }
                        }

                        $ss->error('Build failed: ' . $process->getErrorOutput());
                        return Command::FAILURE;
                    });
                }

                private function createOptimizedViteConfig(string $appDir, string $buildDir, string $entryPoint, SymfonyStyle $ss): void
                {
                    $configPath = $appDir . DIRECTORY_SEPARATOR . 'vite.config.js';
                    
                    // Ensure the build directory path is absolute and correct
                    $publicPath = getcwd() . DIRECTORY_SEPARATOR . 'public';
                    $outDir = $publicPath . DIRECTORY_SEPARATOR . $buildDir;
                    
                    // Create the public/build directory if it doesn't exist
                    if (!is_dir($outDir)) {
                        mkdir($outDir, 0755, true);
                    }
                    
                    // Determine the correct entry path
                    $entryPath = $appDir . DIRECTORY_SEPARATOR . $entryPoint;
                    if (!file_exists($entryPath)) {
                        // Try alternative entry paths
                        $alternatives = [
                            'index.html',
                            'src/main.jsx',
                            'src/main.js',
                            'src/main.tsx',
                            'src/main.ts',
                            'src/App.jsx',
                            'src/App.js',
                            'src/App.tsx',
                            'src/App.ts'
                        ];
                        
                        foreach ($alternatives as $alt) {
                            if (file_exists($appDir . DIRECTORY_SEPARATOR . $alt)) {
                                $entryPoint = $alt;
                                break;
                            }
                        }
                    }
                    
                    $entryName = pathinfo($entryPoint, PATHINFO_FILENAME);
                    
                    // CRITICAL: Use the correct path format for the input
                    $entryFullPath = $appDir . DIRECTORY_SEPARATOR . $entryPoint;
                    
                    $viteConfig = <<<JS
import { defineConfig } from 'vite';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

export default defineConfig({
    server: {
        port: 5173,
        strictPort: true,
        host: true,
    },
    build: {
        outDir: path.resolve(__dirname, '../../public/{$buildDir}'),
        emptyOutDir: true,
        manifest: true,
        rollupOptions: {
            input: {
                {$entryName}: path.resolve(__dirname, '{$entryPoint}'),
            },
        },
        sourcemap: true,
        minify: 'terser',
        chunkSizeWarningLimit: 1000,
        // Ensure manifest is generated
        manifest: 'manifest.json',
    },
    base: '/{$buildDir}/',
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './src'),
        },
    },
});
JS;

                    file_put_contents($configPath, $viteConfig);
                    $ss->text("Vite config created at: {$configPath}");
                    $ss->text("Entry point: {$entryPoint}");
                    $ss->text("Build output directory: {$outDir}");
                }

                private function processBuildOutput(string $appDir, string $publicBuildDir, string $buildDir, string $entryPoint, SymfonyStyle $ss): bool
                {
                    $ss->text("Processing build output in: {$publicBuildDir}");
                    
                    // Check if build directory has content
                    if (!is_dir($publicBuildDir)) {
                        $ss->error("Build directory does not exist: {$publicBuildDir}");
                        return false;
                    }
                    
                    $files = scandir($publicBuildDir);
                    $visibleFiles = array_diff($files, ['.', '..']);
                    $ss->text("Files in build directory: " . implode(', ', $visibleFiles));
                    
                    if (count($visibleFiles) <= 0) {
                        $ss->error('Build directory is empty. Build may have failed.');
                        return false;
                    }

                    // Process manifest.json
                    $manifestPath = $publicBuildDir . DIRECTORY_SEPARATOR . 'manifest.json';
                    $cssFiles = [];
                    $jsFiles = [];
                    $foundEntry = false;
                    
                    // If manifest doesn't exist, try to generate it from the build output
                    if (!file_exists($manifestPath)) {
                        $ss->warning("Manifest not found at: {$manifestPath}");
                        $ss->text("Attempting to generate manifest from build output...");
                        
                        // Generate manifest from the files in the build directory
                        $manifest = $this->generateManifestFromBuild($publicBuildDir, $buildDir, $entryPoint);
                        
                        if ($manifest) {
                            file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT));
                            $ss->text("Manifest generated successfully.");
                        } else {
                            $ss->warning("Could not generate manifest. Will use direct file loading.");
                        }
                    }
                    
                    // Load manifest if it exists now
                    if (file_exists($manifestPath)) {
                        $ss->text("Manifest found at: {$manifestPath}");
                        $manifest = json_decode(file_get_contents($manifestPath), true);
                        
                        if ($manifest) {
                            $ss->text("Manifest entries: " . implode(', ', array_keys($manifest)));
                            
                            // Try to find the entry point
                            $entryKey = null;
                            
                            // Check for the entry point directly
                            if (isset($manifest[$entryPoint])) {
                                $entryKey = $entryPoint;
                            } else {
                                // Try to find by filename
                                $baseName = pathinfo($entryPoint, PATHINFO_FILENAME);
                                foreach ($manifest as $key => $value) {
                                    if (strpos($key, $baseName) !== false || 
                                        (isset($value['file']) && strpos($value['file'], $baseName) !== false)) {
                                        $entryKey = $key;
                                        break;
                                    }
                                }
                            }
                            
                            // If still not found, look for index.html or main
                            if (!$entryKey) {
                                foreach ($manifest as $key => $value) {
                                    if (strpos($key, 'index.html') !== false || 
                                        strpos($key, 'main') !== false || 
                                        strpos($key, 'app') !== false) {
                                        $entryKey = $key;
                                        break;
                                    }
                                }
                            }
                            
                            // If still not found, use the first entry
                            if (!$entryKey && !empty($manifest)) {
                                $entryKey = array_key_first($manifest);
                            }
                            
                            if ($entryKey && isset($manifest[$entryKey])) {
                                $foundEntry = true;
                                $entryData = $manifest[$entryKey];
                                
                                // Collect CSS files
                                if (isset($entryData['css'])) {
                                    $cssFiles = $entryData['css'];
                                }
                                
                                // Collect JS files
                                if (isset($entryData['file'])) {
                                    $jsFiles[] = $entryData['file'];
                                }
                                
                                if (isset($entryData['imports'])) {
                                    foreach ($entryData['imports'] as $import) {
                                        if (isset($manifest[$import]) && isset($manifest[$import]['file'])) {
                                            $jsFiles[] = $manifest[$import]['file'];
                                        }
                                    }
                                }
                                
                                $ss->text("Entry found: {$entryKey}");
                                $ss->text("CSS files: " . implode(', ', $cssFiles));
                                $ss->text("JS files: " . implode(', ', $jsFiles));
                            }
                        }
                    }

                    // If no entry found, try to detect files directly
                    if (!$foundEntry) {
                        $ss->text("No entry found in manifest, detecting files directly...");
                        
                        // Find all JS and CSS files in the build directory
                        $iterator = new \RecursiveIteratorIterator(
                            new \RecursiveDirectoryIterator($publicBuildDir, \RecursiveDirectoryIterator::SKIP_DOTS)
                        );
                        
                        foreach ($iterator as $file) {
                            if ($file->isFile()) {
                                $extension = $file->getExtension();
                                $relativePath = str_replace($publicBuildDir . DIRECTORY_SEPARATOR, '', $file->getPathname());
                                
                                if ($extension === 'js' && strpos($relativePath, 'assets/') === false) {
                                    $jsFiles[] = $relativePath;
                                } elseif ($extension === 'css') {
                                    $cssFiles[] = $relativePath;
                                }
                            }
                        }
                        
                        $ss->text("Detected JS files: " . implode(', ', $jsFiles));
                        $ss->text("Detected CSS files: " . implode(', ', $cssFiles));
                    }

                    // Process index.html if it exists
                    $indexPath = $publicBuildDir . DIRECTORY_SEPARATOR . 'index.html';
                    if (file_exists($indexPath)) {
                        $ss->text('Processing index.html...');
                        $content = file_get_contents($indexPath);
                        
                        // If we have CSS files and they're not in the HTML, inject them
                        if (!empty($cssFiles)) {
                            $cssLinks = '';
                            foreach ($cssFiles as $cssFile) {
                                if (strpos($content, $cssFile) === false) {
                                    $cssLinks .= sprintf('<link rel="stylesheet" href="/%s/%s" crossorigin="anonymous">', $buildDir, $cssFile) . "\n";
                                }
                            }
                            if ($cssLinks) {
                                $content = str_replace('</head>', $cssLinks . "</head>", $content);
                                $ss->text("Injected CSS links into index.html");
                            }
                        }
                        
                        // If we have JS files and they're not in the HTML, inject them
                        if (!empty($jsFiles)) {
                            $jsScripts = '';
                            foreach ($jsFiles as $jsFile) {
                                if (strpos($content, $jsFile) === false) {
                                    $jsScripts .= sprintf('<script type="module" src="/%s/%s" crossorigin="anonymous"></script>', $buildDir, $jsFile) . "\n";
                                }
                            }
                            if ($jsScripts) {
                                $content = str_replace('</body>', $jsScripts . "</body>", $content);
                                $ss->text("Injected JS scripts into index.html");
                            }
                        }
                        
                        // Fix asset paths
                        $content = $this->fixAssetPaths($content, $buildDir);
                        file_put_contents($indexPath, $content);
                        $ss->text('index.html processed successfully.');
                    } else {
                        $ss->warning('No index.html found in build output.');
                        
                        // If we have assets, create a fallback index.html
                        if (!empty($jsFiles) || !empty($cssFiles)) {
                            $this->createFallbackIndexHtml($publicBuildDir, $buildDir, $cssFiles, $jsFiles, $ss);
                        }
                    }

                    return true;
                }

                private function generateManifestFromBuild(string $buildDir, string $buildPath, string $entryPoint): ?array
                {
                    $manifest = [];
                    
                    // Find all JS and CSS files
                    $jsFiles = [];
                    $cssFiles = [];
                    $htmlFiles = [];
                    
                    $iterator = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($buildDir, \RecursiveDirectoryIterator::SKIP_DOTS)
                    );
                    
                    foreach ($iterator as $file) {
                        if ($file->isFile()) {
                            $extension = $file->getExtension();
                            $relativePath = str_replace($buildDir . DIRECTORY_SEPARATOR, '', $file->getPathname());
                            
                            if ($extension === 'js') {
                                $jsFiles[] = $relativePath;
                            } elseif ($extension === 'css') {
                                $cssFiles[] = $relativePath;
                            } elseif ($extension === 'html') {
                                $htmlFiles[] = $relativePath;
                            }
                        }
                    }
                    
                    // Build manifest entry
                    $entryName = pathinfo($entryPoint, PATHINFO_FILENAME);
                    $manifest[$entryPoint] = [
                        'file' => $jsFiles[0] ?? '',
                        'css' => $cssFiles,
                        'imports' => [],
                        'isEntry' => true,
                    ];
                    
                    // Add other JS files as imports
                    if (count($jsFiles) > 1) {
                        foreach (array_slice($jsFiles, 1) as $jsFile) {
                            $manifest[$jsFile] = [
                                'file' => $jsFile,
                                'imports' => [],
                            ];
                        }
                    }
                    
                    return $manifest;
                }

                private function fixAssetPaths(string $content, string $buildDir): string
                {
                    // Fix asset paths in HTML
                    $content = preg_replace_callback(
                        '/(<(?:script|link|img)\b[^>]*\b(?:src|href)=["\'])([^"\']+)(["\'][^>]*>)/i',
                        function ($matches) use ($buildDir) {
                            $src = $matches[2];
                            
                            // Skip external URLs
                            if (preg_match('/^(https?:)?\/\//', $src) || 
                                str_starts_with($src, 'data:') || 
                                str_starts_with($src, 'mailto:') || 
                                str_starts_with($src, '#')) {
                                return $matches[0];
                            }
                            
                            // Skip if the path already has the build directory
                            if (strpos($src, "/{$buildDir}/") === 0) {
                                return $matches[0];
                            }
                            
                            // Fix the path
                            $cleanSrc = ltrim($src, '/');
                            return $matches[1] . "/{$buildDir}/" . $cleanSrc . $matches[3];
                        },
                        $content
                    );
                    
                    return $content ?? $content;
                }

                private function createFallbackIndexHtml(string $publicBuildDir, string $buildDir, array $cssFiles, array $jsFiles, SymfonyStyle $ss): void
                {
                    $ss->text('Creating fallback index.html...');
                    
                    $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application</title>';
    
    // Add CSS files
    foreach ($cssFiles as $cssFile) {
        $html .= sprintf('<link rel="stylesheet" href="/%s/%s" crossorigin="anonymous">', $buildDir, $cssFile) . "\n";
    }
    
    $html .= '</head>
<body>
    <div id="app"></div>';
    
    // Add JS files
    foreach ($jsFiles as $jsFile) {
        $html .= sprintf('<script type="module" src="/%s/%s" crossorigin="anonymous"></script>', $buildDir, $jsFile) . "\n";
    }
    
    $html .= '</body>
</html>';
                    
                    file_put_contents($publicBuildDir . DIRECTORY_SEPARATOR . 'index.html', $html);
                    $ss->text('Fallback index.html created.');
                }

                private function ensureAssetsCopied(string $appDir, string $publicBuildDir, SymfonyStyle $ss): void
                {
                    // Copy any assets from the source directory
                    $srcDir = $appDir . DIRECTORY_SEPARATOR . 'src';
                    $publicAssetsDir = $srcDir . DIRECTORY_SEPARATOR . 'assets';
                    
                    if (is_dir($publicAssetsDir)) {
                        $targetAssetsDir = $publicBuildDir . DIRECTORY_SEPARATOR . 'assets';
                        if (!is_dir($targetAssetsDir)) {
                            mkdir($targetAssetsDir, 0755, true);
                        }
                        
                        $this->copyDirectoryContents($publicAssetsDir, $targetAssetsDir);
                        $ss->text('Assets copied to build directory.');
                    }
                }
            },

            new class extends Command {
                use CommandHelper, viteHelpers;

                public function __construct()
                {
                    parent::__construct('vite:dev');
                    $this->setDescription('Run Vite development server (detached)');
                }

                protected function configure(): void
                {
                    $this->addOption('app-dir', null, InputOption::VALUE_OPTIONAL, 'Vite frontend directory', 'resources/frontend');
                    $this->addOption('port', null, InputOption::VALUE_OPTIONAL, 'Port for Vite dev server', '5173');
                    $this->addOption('package-manager', null, InputOption::VALUE_OPTIONAL);
                    $this->addOption('force', null, InputOption::VALUE_NONE, 'Allow in production (emergency only)');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Vite Dev Server', function (SymfonyStyle $ss) use ($input) {
                        $container = $this->artisanContainer();
                        if ($container->getEnvironment() === 'production' && !$input->getOption('force')) {
                            $ss->error("vite:dev is not allowed in production. Use --force only in emergency.");
                            return Command::FAILURE;
                        }

                        $appDir = $input->getOption('app-dir');
                        $packageManager = $input->getOption('package-manager') ?: $this->detectPackageManager($appDir);
                        if (!$this->checkNodeEnvironment($ss, $packageManager)) {
                            return Command::FAILURE;
                        }

                        $port = $input->getOption('port');
                        $ss->note("Starting Vite dev server on port {$port}. Logs will be written to storage/logs/vite-dev.log");
                        $logFile = getcwd() . '/storage/logs/vite-dev.log';
                        $process = new Process([$packageManager, 'run', 'dev', '--', '--port', $port], $appDir);
                        $process->setTimeout(null);
                        $process->start();
                        file_put_contents($logFile, "Vite dev server started at " . date('Y-m-d H:i:s') . "\n");
                        $ss->success("Vite dev server running (PID: {$process->getPid()}). Logs: {$logFile}");
                        $ss->warning("Press Ctrl+C to stop the server.");
                        $process->wait(function ($type, $buffer) use ($ss, $logFile) {
                            file_put_contents($logFile, $buffer, FILE_APPEND);
                        });
                        return Command::SUCCESS;
                    });
                }

            },

            new class extends Command {
                use CommandHelper, viteHelpers;

                public function __construct()
                {
                    parent::__construct('vite:clear-cache');
                    $this->setDescription('Clear Vite build cache and artifacts');
                }

                protected function configure(): void
                {
                    $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Clear Vite Cache', function (SymfonyStyle $ss) use ($input) {
                        $publicBuild = getcwd() . '/public/build';
                        $viteCache = getcwd() . '/resources/frontend/node_modules/.vite';

                        $paths = [];
                        if (is_dir($publicBuild)) $paths[] = $publicBuild;
                        if (is_dir($viteCache)) $paths[] = $viteCache;

                        if (empty($paths)) {
                            $ss->comment('No cache or build directories found.');
                            return Command::SUCCESS;
                        }

                        if (!$input->getOption('force') && !$ss->confirm('This will permanently delete build output and Vite cache. Continue?', false)) {
                            $ss->comment('Aborted.');
                            return Command::SUCCESS;
                        }

                        foreach ($paths as $path) {
                            $this->deleteDirectory($path);
                            $ss->success("Cleared: {$path}");
                        }

                        return Command::SUCCESS;
                    });
                }
            },

            new class extends Command {
                use CommandHelper, viteHelpers;

                public function __construct()
                {
                    parent::__construct('vite:make:component');
                    $this->setDescription('Generate a frontend component (Vue/React) inside the Vite project');
                }

                protected function configure(): void
                {
                    $this->addArgument('name', InputArgument::REQUIRED, 'Component name (e.g., Button, HelloWorld)');
                    $this->addOption('app-dir', null, InputOption::VALUE_OPTIONAL, 'Vite frontend directory', 'resources/frontend');
                    $this->addOption('framework', null, InputOption::VALUE_OPTIONAL, 'Framework (vue, react)', 'vue');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Generate Frontend Component', function (SymfonyStyle $ss) use ($input) {
                        $name = $input->getArgument('name');
                        $appDir = $input->getOption('app-dir');
                        $framework = $input->getOption('framework');

                        $srcDir = getcwd() . '/' . $appDir . '/src/components';
                        if (!is_dir($srcDir)) {
                            mkdir($srcDir, 0755, true);
                        }

                        $extension = $framework === 'vue' ? '.vue' : '.jsx';
                        $componentFile = $srcDir . '/' . $name . $extension;
                        if (file_exists($componentFile)) {
                            $ss->error("Component already exists: {$componentFile}");
                            return Command::FAILURE;
                        }

                        if ($framework === 'vue') {
                            $content = "<template>\n  <div class=\"" . strtolower($name) . "\">\n    <h1>{$name} Component</h1>\n  </div>\n</template>\n\n<script>\nexport default {\n  name: '{$name}',\n};\n</script>\n\n<style scoped>\n/* Add your styles here */\n</style>\n";
                        } else {
                            $content = "import React from 'react';\n\nconst {$name} = () => {\n  return (\n    <div className=\"" . strtolower($name) . "\">\n      <h1>{$name} Component</h1>\n    </div>\n  );\n};\n\nexport default {$name};\n";
                        }

                        file_put_contents($componentFile, $content);
                        $ss->success("Component created: {$componentFile}");
                        return Command::SUCCESS;
                    });
                }
            },

            new class extends Command {
                use CommandHelper, viteHelpers;

                public function __construct()
                {
                    parent::__construct('vite:make:page');
                    $this->setDescription('Generate a frontend page component (routed)');
                }

                protected function configure(): void
                {
                    $this->addArgument('name', InputArgument::REQUIRED, 'Page name (e.g., Home, About)');
                    $this->addOption('app-dir', null, InputOption::VALUE_OPTIONAL, 'Vite frontend directory', 'resources/frontend');
                    $this->addOption('framework', null, InputOption::VALUE_OPTIONAL, 'Framework (vue, react)', 'vue');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Generate Frontend Page', function (SymfonyStyle $ss) use ($input) {
                        $name = $input->getArgument('name');
                        $appDir = $input->getOption('app-dir');
                        $framework = $input->getOption('framework');

                        $pagesDir = getcwd() . '/' . $appDir . '/src/pages';
                        if (!is_dir($pagesDir)) {
                            mkdir($pagesDir, 0755, true);
                        }

                        $extension = $framework === 'vue' ? '.vue' : '.jsx';
                        $pageFile = $pagesDir . '/' . $name . $extension;
                        if (file_exists($pageFile)) {
                            $ss->error("Page already exists: {$pageFile}");
                            return Command::FAILURE;
                        }

                        if ($framework === 'vue') {
                            $content = "<template>\n  <div>\n    <h1>{$name} Page</h1>\n  </div>\n</template>\n\n<script>\nexport default {\n  name: '{$name}',\n};\n</script>\n";
                        } else {
                            $content = "import React from 'react';\n\nconst {$name} = () => {\n  return <h1>{$name} Page</h1>;\n};\n\nexport default {$name};\n";
                        }

                        file_put_contents($pageFile, $content);
                        $ss->success("Page created: {$pageFile}");
                        return Command::SUCCESS;
                    });
                }
            },

            new class extends Command {
                use CommandHelper, viteHelpers;

                public function __construct()
                {
                    parent::__construct('vite:version');
                    $this->setDescription('Show installed Vite version and integration info');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Vite Version', function (SymfonyStyle $ss) {
                        $viteVersion = trim(exec('npx vite --version 2>&1'));
                        $ss->table(['Component', 'Version'], [
                            ['Vite CLI', $viteVersion ?: 'not installed'],
                            ['Machinjiri Vite Integration', '1.0.0'],
                        ]);
                        return Command::SUCCESS;
                    });
                }
            }
        ];
    }
}