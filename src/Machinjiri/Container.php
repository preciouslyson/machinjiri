<?php

namespace Mlangeni\Machinjiri\Core;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Routing\DotEnv;
use Mlangeni\Machinjiri\Core\Artisans\Helpers\EnvFileWriter;
use Mlangeni\Machinjiri\Core\Artisans\Helpers\Mkutumula;

class Container
{
    public static $appBasePath;
    protected $storage;
    protected $routing;
    protected $routes;
    protected $database;
    protected $resources;
    protected $app;
    protected $config;
    protected $unitTesting;
    public static $terminalBase = "./";
    
    protected function __construct(string $appBasePath)
    {
        self::$appBasePath = rtrim($appBasePath, DIRECTORY_SEPARATOR);
    }
    
    protected function initialize(): void
    {
        $this->validateBasePath();
        $this->bootstrapEnvironment();
        $this->setupPaths();
        $this->createDirectories();
        $this->createAppDirectories();
    }
    
    protected function validateBasePath(): void
    {
        if (!is_dir(self::$appBasePath)) {
            throw new MachinjiriException("Specify Application Base");
        }
    }
    
    protected function bootstrapEnvironment(): void
    {
        if (!is_file($this->getRootPath() . ".env")) {
            $this->bootstrapEnv();
        }
    }
    
    protected function setupPaths(): void
    {
        $root = $this->getRootPath();
        $this->routes = $root . "routes/";
        $this->resources = $root . "resources/";
        $this->database = $root . "database/";
        $this->storage = $root . "storage/";
        $this->routing = $root . "public/";
        $this->app = $root . "app/";
        $this->config = $root . "config/";
        $this->unitTesting = $root . "tests/Unit";
    }
    
    protected function createDirectories(): void
    {
        $directories = [
            $this->routes,
            $this->resources,
            $this->database,
            $this->storage,
            $this->app,
            $this->unitTesting
        ];
        
        array_walk($directories, function ($directory) {
            $this->createDirectory($directory);
        });
        
        if (!is_dir($this->app)) {
            $this->createAppDirectories();
        }
    }
    
    protected function createDirectory(string $path, int $permissions = 0777): bool
    {
        return !is_dir($path) ? @mkdir($path, $permissions, true) : true;
    }
    
    protected function getRootPath(): string
    {
        return self::$appBasePath . "/../";
    }
    
    public function getConfigurations(): array
    {
        $configDir = $this->config;
        $appConfig = $configDir . "app.php";
        $databaseConfig = $configDir . "database.php";
        
        $this->validateConfigurationFiles($appConfig, $databaseConfig);
        
        return [
            'app' => $this->loadAppConfiguration($appConfig),
            'database' => $this->loadDatabaseConfiguration($databaseConfig)
        ];
    }
    
    protected function validateConfigurationFiles(string $appConfig, string $databaseConfig): void
    {
        $envVars = $this->dotEnv();
        
        if ((!is_file($appConfig) || !is_readable($appConfig)) && !$envVars) {
            throw new MachinjiriException(
                "App configuration error. Due to empty or unreadable environment file or no app configuration script in config folder.",
                10110
            );
        }
        
        if ((!is_file($databaseConfig) || !is_readable($databaseConfig)) && !$envVars) {
            throw new MachinjiriException(
                "Database configuration error. Due to empty or unreadable environment file or no database configuration script in config folder.",
                10111
            );
        }
    }
    
    protected function loadAppConfiguration(string $configPath): array
    {
        $config = is_file($configPath) ? include $configPath : [];
        $envVars = $this->dotEnv();
        
        if (!is_array($config) && $envVars) {
            return [
                "app_name" => $envVars["APP_NAME"] ?? '',
                "app_version" => $envVars["APP_VERSION"] ?? '',
                "app_key" => $envVars["APP_DEBUG"] ?? '',
                "app_env" => $envVars["APP_ENV"] ?? '',
                "app_url" => $envVars["APP_URL"] ?? '',
            ];
        }
        
        return $config;
    }
    
    protected function loadDatabaseConfiguration(string $configPath): array
    {
        $config = is_file($configPath) ? include $configPath : [];
        $envVars = $this->dotEnv();
        
        if ((!is_array($config) || empty($config['driver'])) && $envVars) {
            return [
                "driver" => $envVars["DB_CONNECTION"] ?? '',
                "host" => $envVars["DB_HOST"] ?? '',
                "username" => $envVars["DB_USERNAME"] ?? '',
                "password" => $envVars["DB_PASSWORD"] ?? '',
                "database" => $envVars["DB_DATABASE"] ?? '',
                "port" => $envVars["DB_PORT"] ?? '',
                "path" => $envVars["DB_PATH"] ?? '',
                "dsn" => $envVars["DB_DSN"] ?? ''
            ];
        }
        
        return $config;
    }
    
    public function dotEnv(): ?array
    {
        $dotEnv = new DotEnv($this->getRootPath());
        $dotEnv->load();
        $variables = $dotEnv->getVariables();
        
        return count($variables) > 0 ? $variables : null;
    }
    
    protected function loadRoutes(): void
    {
        $this->createRouteFiles();
        require $this->routes . "web.php";
    }
    
    protected function createRouteFiles(): void
    {
        $routesDir = $this->routes;
        $this->createDirectory($routesDir);
        
        $webRoute = $routesDir . "web.php";
        
        if (!is_file($webRoute)) {
            $this->createWebRouteFile($webRoute);
        }
        
    }
    
    public static function getSystemTempDir () : string {
      return sys_get_temp_dir();
    }
    
    protected function createWebRouteFile(string $path): void
    {
        $template = <<<'EOT'
<?php
require __DIR__ . "/../vendor/autoload.php";
use Mlangeni\Machinjiri\Core\Routing\Router;
$router = new Router();

// declare your routes here...
// example routes
$router->get('/', 'HomeController@index');

// dispatching all routes
$router->dispatch();
EOT;
        
        file_put_contents($path, $template);
    }
    
    /**
     * Get routing base path for the application
     * This method now properly detects the base path based on document root and script location
     */
    public static function getRoutingBase(): string
    {
        // Check if we have a custom base path in environment
        $envVars = (new self(self::$appBasePath))->dotEnv();
        if ($envVars && isset($envVars['APP_BASE_PATH'])) {
            return rtrim($envVars['APP_BASE_PATH'], '/');
        }
        
        $documentRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
        $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? '';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        
        if (!$scriptFilename || !$scriptName) {
            return '';
        }

        // Get the directory of the current script
        $scriptDir = dirname($scriptFilename);
        
        // Calculate the base path by finding the difference between script directory and document root
        if (strpos($scriptDir, $documentRoot) === 0) {
            // Script is inside document root
            $relativePath = substr($scriptDir, strlen($documentRoot));
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
    public static function getBaseUrl(): string
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $basePath = self::getRoutingBase();
        
        return $protocol . '://' . $host . $basePath;
    }
    
    /**
     * Get document root directory
     */
    public static function getDocumentRoot(): string
    {
        return rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
    }
    
    public function getLoggingBase(): string
    {
        return $this->storage . 'logs/';
    }
    
    protected function createAppDirectories(): void
    {
        $appDirs = [
            $this->app . "Controllers/",
            $this->app . "Middleware/",
            $this->app . "Model/",
            $this->app . "Jobs/"
        ];
        
        array_walk($appDirs, function ($dir) {
            $this->createDirectory($dir);
        });
    }
    
    protected function bootstrapEnv(): bool
    {
        return (new EnvFileWriter())->create($this->getRootPath() . ".env");
    }
    
    protected function boot(): void
    {
        $this->createHomeController();
        $this->createViewDirectories();
        $this->createStorageDirectories();
        $this->createWelcomeView();
        $this->createHtaccess();
        $this->createArtisan();
        $this->createPhpUnitConfiguration();
    }
    
    protected function createHomeController(): void
    {
        (new Mkutumula())->create("HomeController");
    }
    
    protected function createViewDirectories(): void
    {
        $viewDirs = [
            $this->resources . 'views/',
            $this->resources . 'views/layouts/',
            $this->resources . 'views/partials/'
        ];
        
        array_walk($viewDirs, function ($dir) {
            $this->createDirectory($dir);
        });
    }
    
    protected function createStorageDirectories(): void
    {
        $storageDirs = [
            $this->storage . 'session/',
            $this->storage . 'cache/',
            $this->storage . 'logs/',
            $this->storage . 'cookies/',
            $this->storage . 'cache/routes/' // Add routes cache directory
        ];
        
        array_walk($storageDirs, function ($dir) {
            $this->createDirectory($dir);
        });
    }
    
    protected function createWelcomeView(): void
    {
        $welcomeView = $this->resources . 'views/welcome.mg.php';
        
        if (!is_file($welcomeView)) {
            $template = $this->getWelcomeTemplate();
            file_put_contents($welcomeView, $template);
        }
    }
    
    protected function getWelcomeTemplate(): string
    {
        $baseUrl = self::getBaseUrl();
        
        return <<<EOT
<?php use Mlangeni\Machinjiri\Core\Views\View; ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Machinjiri</title>
  <style>
    body {
      margin: 0;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      background-color: #f8f9fa;
      color: #343a40;
    }
    header {
      background-color: #dc3545;
      color: white;
      padding: 2rem;
      text-align: center;
    }
    main {
      padding: 2rem;
      text-align: center;
    }
    .btn {
      background-color: #dc3545;
      color: white;
      padding: 0.75rem 1.5rem;
      margin: 0.5rem;
      border: none;
      border-radius: 4px;
      font-size: 1rem;
      cursor: pointer;
      transition: background-color 0.3s ease;
      text-decoration: none;
      display: inline-block;
    }
    .btn:hover {
      background-color: #c82333;
    }
    .info-box {
      background: white;
      padding: 1.5rem;
      margin: 2rem auto;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
      max-width: 600px;
      text-align: left;
    }
    .info-box h3 {
      color: #dc3545;
      margin-top: 0;
    }
    .info-item {
      margin: 0.5rem 0;
      padding: 0.5rem;
      background: #f8f9fa;
      border-radius: 4px;
    }
    .social-buttons {
      margin-top: 2rem;
    }
    .social-buttons a {
      margin: 0 0.5rem;
      text-decoration: none;
      font-size: 1.5rem;
      color: #dc3545;
      transition: color 0.3s ease;
    }
    .social-buttons a:hover {
      color: #c82333;
    }
    footer {
      margin-top: 3rem;
      text-align: center;
      font-size: 0.9rem;
      color: #6c757d;
    }
  </style>
  
</head>
<body>
  <header>
    <h1>Machinjiri</h1>
    <p>Installation completed successfully</p>
  </header>
  <main>
    <p>Your development environment is ready to go.</p>
    
    <div class="info-box">
      <h3>Routing Information</h3>
      <div class="info-item">
        <strong>Base URL:</strong> {$baseUrl}
      </div>
      <div class="info-item">
        <strong>Document Root:</strong> {self::getDocumentRoot()}
      </div>
      <div class="info-item">
        <strong>Routing Base:</strong> {self::getRoutingBase()}
      </div>
    </div>
    
    <a href="/docs" class="btn">📖 Read Docs</a>
    <a href="{$baseUrl}/test-route" class="btn">🧪 Test Routing</a>
  </main>
  <footer>
    &copy; 2024 - <?php print date('Y') ;?> Your Framework. All rights reserved.
  </footer>
</body>
</html>
EOT;
    }
    
    protected function createHtaccess(): void
    {
        $htaccessPath = self::$appBasePath . "/.htaccess";
        
        if (!is_file($htaccessPath)) {
            $rules = $this->getHtaccessRules();
            file_put_contents($htaccessPath, $rules);
        }
        
        // Also create a public/.htaccess for better routing
        $publicHtaccess = $this->routing . ".htaccess";
        if (!is_file($publicHtaccess)) {
            $publicRules = $this->getPublicHtaccessRules();
            file_put_contents($publicHtaccess, $publicRules);
        }
    }
    
    protected function getHtaccessRules(): string
    {
        return <<<'EOT'
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA]

# --------------------
# Security Enhancements
# --------------------
# Prevent directory listing
Options -Indexes

# Block access to sensitive files
<FilesMatch "\.(env|htaccess|git|gitignore|DS_Store|composer\.json|composer\.lock|config\.php)$">
    Require all denied
</FilesMatch>

# Disable server signature
ServerSignature Off

# Protect .htaccess file
<Files .htaccess>
    Require all denied
</Files>

# --------------------
# Performance Optimizations
# --------------------
# Enable browser caching
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpg "access plus 1 month"
    ExpiresByType image/jpeg "access plus 1 month"
    ExpiresByType image/gif "access plus 1 month"
    ExpiresByType image/png "access plus 1 month"
    ExpiresByType text/css "access plus 1 week"
    ExpiresByType application/javascript "access plus 1 week"
    ExpiresByType text/html "access plus 1 hour"
</IfModule>

# Enable compression
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css application/javascript
</IfModule>

# Remove ETag header
FileETag None
Header unset ETag
EOT;
    }
    
    /**
     * Get enhanced .htaccess rules for public directory
     */
    protected function getPublicHtaccessRules(): string
    {
        $basePath = self::getRoutingBase();
        
        return <<<EOT
RewriteEngine On

# Handle base path for subdirectory installations
RewriteBase {$basePath}

# Redirect to remove trailing slash (optional)
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_URI} (.+)/$
RewriteRule ^ %1 [L,R=301]

# Handle front controller
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [L]

# Security headers
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options nosniff
    Header always set X-Frame-Options DENY
    Header always set X-XSS-Protection "1; mode=block"
</IfModule>

# Cache control for static assets
<FilesMatch "\.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$">
    <IfModule mod_expires.c>
        ExpiresActive on
        ExpiresDefault "access plus 1 month"
    </IfModule>
    <IfModule mod_headers.c>
        Header set Cache-Control "public, immutable"
    </IfModule>
</FilesMatch>
EOT;
    }
    
    protected function createArtisan () : void {
      $artisan = $this->getRootPath() . "/artisan";
      
      if (!is_file($artisan)) {
        $template = $this->getArtisanTemplate();
        file_put_contents($artisan, $template);
      }
    }
    
    protected function createPhpUnitConfiguration () : void {
      $phpunit = $this->getRootPath() . "/phpunit.xml";
      
      if (!is_file($phpunit)) {
        $template = $this->phpUnitConfigurationTemplate();
        file_put_contents($phpunit, $template);
      }
    }
    
    protected function getArtisanTemplate (): string {
      return <<<'EOT'
#!/usr/bin/env php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Mlangeni\Machinjiri\Core\Artisans\Terminal\Terminal;

$application = new Terminal();
$application->run();
EOT;
    }
    
    protected function phpUnitConfigurationTemplate () : string {
      return <<<EOT
<?xml version="1.0" encoding="UTF-8" ?>
<phpunit colors="true" bootstrap="vendor/autoload.php">
  <testsuites>
    <testsuite name="Unit Tests">
      <directory>tests/Unit</directory>
    </testsuite>
  </testsuites>
</phpunit>
EOT;
    }
    
    /**
     * Get the application storage path
     */
    public function getStoragePath(): string
    {
        return $this->storage;
    }
    
    /**
     * Get the application cache path
     */
    public function getCachePath(): string
    {
        return $this->storage . 'cache/';
    }
    
    /**
     * Get the routes cache path
     */
    public function getRoutesCachePath(): string
    {
        return $this->storage . 'cache/routes/';
    }
}