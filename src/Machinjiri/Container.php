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
    }
    
    protected function createDirectories(): void
    {
        $directories = [
            $this->routes,
            $this->resources,
            $this->database,
            $this->storage,
            $this->app,
            $this->config
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
                "name" => $envVars["APP_NAME"] ?? '',
                "version" => $envVars["APP_VERSION"] ?? '',
                "key" => $envVars["APP_DEBUG"] ?? '',
                "env" => $envVars["APP_ENV"] ?? '',
                "url" => $envVars["APP_URL"] ?? '',
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
        require $this->routes . "api.php";
    }
    
    protected function createRouteFiles(): void
    {
        $routesDir = $this->routes;
        $this->createDirectory($routesDir);
        
        $webRoute = $routesDir . "web.php";
        $apiRoute = $routesDir . "api.php";
        
        if (!is_file($webRoute)) {
            $this->createWebRouteFile($webRoute);
        }
        
        if (!is_file($apiRoute)) {
            @fopen($apiRoute, "w");
        }
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
    
    public static function getRoutingBase(): string
    {
        $pathParts = explode(DIRECTORY_SEPARATOR, rtrim(self::$appBasePath, DIRECTORY_SEPARATOR));
        $appName = ucfirst($pathParts[count($pathParts) - 2] ?? 'public/');
        return DIRECTORY_SEPARATOR . $appName . "/public";
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
            $this->app . "Model/"
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
            $this->storage . 'cookies/'
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
        return <<<'EOT'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Machinjiri - PHP Framework</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        :root {
            --primary: #dc3545;
            --primary-dark: #b02a37;
            --primary-light: #e9717c;
            --secondary: #343a40;
            --light: #f8f9fa;
            --dark: #212529;
            --gray: #6c757d;
            --success: #28a745;
        }
        
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            min-height: 100vh;
        }
        
        /* Navbar */
        .navbar {
            background-color: var(--primary);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .navbar-brand {
            font-weight: 700;
            font-size: 1.8rem;
        }
        
        .navbar-nav .nav-link {
            color: rgba(255, 255, 255, 0.9) !important;
            font-weight: 500;
            transition: all 0.3s ease;
            position: relative;
            padding: 0.5rem 0;
            margin: 0 0.8rem;
        }
        
        .navbar-nav .nav-link:after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background: white;
            transition: width 0.3s ease;
        }
        
        .navbar-nav .nav-link:hover:after {
            width: 100%;
        }
        
        /* Hero Section */
        .hero {
            padding: 6rem 0;
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><rect width="100" height="100" fill="%23f8f9fa"/><path d="M0 0L100 100" stroke="%23e9ecef" stroke-width="2"/></svg>');
        }
        
        .btn-primary {
            background-color: var(--primary);
            border-color: var(--primary);
            padding: 0.8rem 2rem;
            font-weight: 600;
            border-radius: 50px;
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.25);
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
            border-color: var(--primary-dark);
            transform: translateY(-3px);
            box-shadow: 0 6px 16px rgba(220, 53, 69, 0.35);
        }
        
        .btn-outline-primary {
            color: var(--primary);
            border-color: var(--primary);
            padding: 0.8rem 2rem;
            font-weight: 600;
            border-radius: 50px;
        }
        
        .btn-outline-primary:hover {
            background-color: var(--primary);
            border-color: var(--primary);
            transform: translateY(-3px);
        }
        
        /* Features Section */
        .feature-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            text-align: center;
            height: 100%;
            border-top: 4px solid var(--primary);
        }
        
        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.12);
        }
        
        .feature-icon {
            font-size: 2.5rem;
            color: var(--primary);
            margin-bottom: 1.5rem;
            background: rgba(220, 53, 69, 0.1);
            width: 80px;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            margin: 0 auto 1.5rem;
        }
        
        /* CTA Section */
        .cta {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 5rem 0;
        }
        
        .cta .btn-light {
            padding: 0.8rem 2rem;
            font-weight: 600;
            border-radius: 50px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        
        .cta .btn-light:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
        }
        
        /* Footer */
        footer {
            background-color: var(--secondary);
            color: white;
            padding: 3rem 0 2rem;
        }
        
        .footer-links h3 {
            position: relative;
            padding-bottom: 0.5rem;
            margin-bottom: 1.5rem;
        }
        
        .footer-links h3:after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 40px;
            height: 2px;
            background-color: var(--primary);
        }
        
        .footer-links a {
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .footer-links a:hover {
            color: var(--primary-light);
            padding-left: 5px;
        }
        
        .social-links a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
            border-radius: 50%;
            transition: all 0.3s ease;
            margin-right: 0.5rem;
        }
        
        .social-links a:hover {
            background-color: var(--primary);
            transform: translateY(-3px);
        }
        
        .copyright {
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        /* Animations */
        .feature-card, .btn {
            transition: all 0.3s ease;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark sticky-top">
        <div class="container">
            <a class="navbar-brand" href="#">
                Machinjiri
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="#">Docs</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#">Community</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#">GitHub</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8 text-center">
                    <h1 class="display-4 fw-bold mb-4 text-danger">Welcome to Machinjiri</h1>
                    <p class="lead text-muted mb-5">Machinjiri is a modular PHP framework designed for developers who demand precision, performance, and elegance.</p>
                    <div class="d-grid gap-2 d-md-block">
                        <a href="#" class="btn btn-outline-primary btn-lg">View Documentation</a>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- Bootstrap 5 JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Add subtle animation to feature cards
        document.addEventListener('DOMContentLoaded', function() {
            const featureCards = document.querySelectorAll('.feature-card');
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = 1;
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, {
                threshold: 0.1
            });
            
            // Initialize opacity for animation
            featureCards.forEach(card => {
                card.style.opacity = 0;
                card.style.transform = 'translateY(20px)';
                card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                observer.observe(card);
            });
        });
    </script>
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
    
    protected function createArtisan () : void {
      $artisan = $this->getRootPath() . "/artisan";
      
      if (!is_file($artisan)) {
        $template = $this->getArtisanTemplate();
        file_put_contents($artisan, $template);
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
}