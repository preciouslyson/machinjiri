<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Generators;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class ServiceProviderGenerator
{
    /**
     * Application base path
     *
     * @var string
     */
    private string $appBasePath;

    /**
     * Source directory for app files
     *
     * @var string
     */
    private string $srcPath;

    /**
     * Configuration directory
     *
     * @var string
     */
    private string $configPath;

    /**
     * Providers directory
     *
     * @var string
     */
    private string $providersPath;

    /**
     * Constructor
     *
     * @param string $appBasePath Application base path
     */
    public function __construct(string $appBasePath)
    {
        $this->appBasePath = rtrim($appBasePath, DIRECTORY_SEPARATOR);
        $this->srcPath = $this->appBasePath . '/src/Machinjiri/';
        $this->configPath = $this->appBasePath . '/config/';
        $this->providersPath = $this->appBasePath . '/app/Providers/';
    }

    /**
     * Generate a new service provider
     *
     * @param string $name Service provider name (without ServiceProvider suffix)
     * @param array $options Generation options
     * @return array Created files
     * @throws MachinjiriException
     */
    public function generate(string $name, array $options = []): array
    {
        $name = $this->normalizeName($name);
        
        // Validate name
        $this->validateName($name);
        
        // Get options
        $deferred = $options['deferred'] ?? false;
        $withConfig = $options['config'] ?? true;
        $configName = $options['config_name'] ?? strtolower($name);
        $bindings = $options['bindings'] ?? [];
        $singletons = $options['singletons'] ?? [];
        $aliases = $options['aliases'] ?? [];
        
        // Create provider file
        $providerFile = $this->createProviderFile($name, [
            'deferred' => $deferred,
            'bindings' => $bindings,
            'singletons' => $singletons,
            'aliases' => $aliases,
        ]);
        
        $createdFiles = [$providerFile];
        
        // Create configuration file if requested
        if ($withConfig) {
            $configFile = $this->createConfigFile($configName, $options['config_data'] ?? []);
            $createdFiles[] = $configFile;
        }
        
        // Update providers.php configuration if requested
        if ($options['register'] ?? true) {
            $this->registerInProvidersConfig($name, [
                'deferred' => $deferred,
                'config_name' => $configName,
            ]);
        }
        
        return $createdFiles;
    }

    /**
     * Normalize provider name
     *
     * @param string $name
     * @return string
     */
    private function normalizeName(string $name): string
    {
        // Remove "ServiceProvider" suffix if present
        $name = preg_replace('/ServiceProvider$/i', '', $name);
        
        // Convert to PascalCase
        $name = str_replace(['-', '_', ' '], '', ucwords($name, '-_ '));
        
        // Add ServiceProvider suffix
        return $name . 'ServiceProvider';
    }

    /**
     * Validate provider name
     *
     * @param string $name
     * @throws MachinjiriException
     */
    private function validateName(string $name): void
    {
        // Check if name is valid
        if (!preg_match('/^[A-Z][a-zA-Z0-9]*ServiceProvider$/', $name)) {
            throw new MachinjiriException(
                "Invalid service provider name: {$name}. Name must be in PascalCase and end with ServiceProvider.",
                90001
            );
        }
        
        // Check if provider already exists
        $providerFile = $this->providersPath . $name . '.php';
        if (file_exists($providerFile)) {
            throw new MachinjiriException(
                "Service provider already exists: {$name}",
                90002
            );
        }
        
        // Check if class already exists
        $className = "Mlangeni\\Machinjiri\\App\\Providers\\{$name}";
        if (class_exists($className)) {
            throw new MachinjiriException(
                "Service provider class already exists: {$className}",
                90003
            );
        }
    }

    /**
     * Create service provider file
     *
     * @param string $name
     * @param array $options
     * @return string
     * @throws MachinjiriException
     */
    private function createProviderFile(string $name, array $options): string
    {
        // Ensure providers directory exists
        $this->ensureDirectoryExists($this->providersPath);
        
        $providerFile = $this->providersPath . $name . '.php';
        
        // Generate template
        $template = $this->generateProviderTemplate($name, $options);
        
        // Write file
        if (file_put_contents($providerFile, $template) === false) {
            throw new MachinjiriException(
                "Failed to create service provider file: {$providerFile}",
                90004
            );
        }
        
        return $providerFile;
    }

    /**
     * Generate provider template
     *
     * @param string $name
     * @param array $options
     * @return string
     */
    private function generateProviderTemplate(string $name, array $options): string
    {
        $deferred = $options['deferred'] ? 'true' : 'false';
        $bindings = var_export($options['bindings'], true);
        $singletons = var_export($options['singletons'], true);
        $aliases = var_export($options['aliases'], true);
        
        $shortName = str_replace('ServiceProvider', '', $name);
        $configName = strtolower($shortName);
        
        return <<<PHP
<?php
namespace Mlangeni\Machinjiri\App\Providers;

use Mlangeni\Machinjiri\Core\Providers\ServiceProvider as BaseServiceProvider;

/**
 * {$shortName} Service Provider
 *
 * This service provider handles registration and bootstrapping
 * of {$shortName} related services.
 */
class {$name} extends BaseServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred
     */
    protected bool \$defer = {$deferred};

    /**
     * The service bindings provided by this provider
     */
    protected array \$bindings = {$bindings};

    /**
     * The singleton bindings provided by this provider
     */
    protected array \$singletons = {$singletons};

    /**
     * The aliases provided by this provider
     */
    protected array \$aliases = {$aliases};

    /**
     * Register services
     */
    public function register(): void
    {
        // Register your services here
        \$this->registerServices();
        
        // Register aliases
        \$this->registerAliases();
    }

    /**
     * Bootstrap services
     */
    public function boot(): void
    {
        // Load configuration
        \$this->loadConfiguration();
        
        // Register event listeners
        \$this->registerEventListeners();
        
        // Register middleware
        \$this->registerMiddleware([
            // Add your middleware classes here
            'middleware.name' => 'MiddlewareClass::class',
        ]);
        
        // Load routes if any
        \$this->loadRoutes();
        
        // Trigger boot event
        \$this->triggerEvent('{$configName}.booted');
    }

    /**
     * Register application services
     */
    public function registerServices(): void
    {
        // Example: Register a service
        // \$this->singleton('service.name', function(\$app) {
        //     return new ServiceClass();
        // });
    }

    /**
     * Register service aliases
     */
    public function registerAliases(): void
    {
        // Example: Register an alias
        // \$this->alias('alias.name', 'service.name');
    }

    /**
     * Load configuration
     */
    protected function loadConfiguration(): void
    {
        \$configFile = \$this->app->config . '{$configName}.php';
        
        if (file_exists(\$configFile)) {
            \$this->mergeConfigFrom(\$configFile, '{$configName}');
        }
    }

    /**
     * Register event listeners
     */
    public function registerEventListeners(): void
    {
        // Example: Register event listeners
        // \$this->listen('event.name', [ListenerClass::class, 'method']);
    }

    /**
     * Register middleware
     */
    protected function registerMiddleware(\$middleware, ?string \$name = null): void
    {
        // Example: Register middleware
        \$this->registerMiddleware(\$middleware);
    }

    /**
     * Load routes
     */
    public function loadRoutes(): void
    {
        // Example: Load routes
        // \$routesFile = \$this->app->routes . '{$configName}.php';
        // if (file_exists(\$routesFile)) {
        //     \$this->loadRoutesFrom(\$routesFile);
        // }
    }

    /**
     * Get the services provided by the provider
     */
    public function provides(): array
    {
        return array_merge(
            array_keys(\$this->bindings),
            array_keys(\$this->singletons),
            array_keys(\$this->aliases)
        );
    }
}
PHP;
    }

    /**
     * Create configuration file
     *
     * @param string $name
     * @param array $data
     * @return string
     * @throws MachinjiriException
     */
    private function createConfigFile(string $name, array $data = []): string
    {
        // Ensure config directory exists
        $this->ensureDirectoryExists($this->configPath);
        
        $configFile = $this->configPath . $name . '.php';
        
        // Check if config file already exists
        if (file_exists($configFile)) {
            throw new MachinjiriException(
                "Configuration file already exists: {$configFile}",
                90005
            );
        }
        
        // Generate configuration template
        $template = $this->generateConfigTemplate($name, $data);
        
        // Write file
        if (file_put_contents($configFile, $template) === false) {
            throw new MachinjiriException(
                "Failed to create configuration file: {$configFile}",
                90006
            );
        }
        
        return $configFile;
    }

    /**
     * Generate configuration template
     *
     * @param string $name
     * @param array $data
     * @return string
     */
    private function generateConfigTemplate(string $name, array $data): string
    {
        $title = ucwords(str_replace(['_', '-'], ' ', $name));
        
        if (!empty($data)) {
            $configData = var_export($data, true);
        } else {
            $configData = <<<'PHP'
    /*
    |--------------------------------------------------------------------------
    | Configuration Section
    |--------------------------------------------------------------------------
    |
    | Add your configuration values here. You can organize them into
    | logical sections as needed for your application.
    |
    */

    'section' => [
        'key' => 'value',
        'array' => [
            'nested_key' => 'nested_value',
        ],
    ],
PHP;
        }
        
        return <<<PHP
<?php
return [
    /*
    |--------------------------------------------------------------------------
    | {$title} Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration file manages the settings for the {$title}
    | component of your application.
    |
    | You can modify these values to suit your application needs.
    |
    */

{$configData}

];
PHP;
    }

    /**
     * Register provider in providers.php configuration
     *
     * @param string $providerName
     * @param array $options
     * @throws MachinjiriException
     */
    private function registerInProvidersConfig(string $providerName, array $options): void
    {
        $providersConfig = $this->configPath . 'providers.php';
        
        // Check if providers.php exists
        if (!file_exists($providersConfig)) {
            // Create default providers.php
            $this->createDefaultProvidersConfig();
        }
        
        // Read current configuration
        $config = require $providersConfig;
        
        // Add provider to providers array
        $providerClass = "Mlangeni\\Machinjiri\\App\\Providers\\{$providerName}";
        
        if (!in_array($providerClass, $config['providers'] ?? [])) {
            $config['providers'][] = $providerClass;
        }
        
        // Add to deferred array if needed
        if ($options['deferred']) {
            if (!in_array($providerClass, $config['deferred'] ?? [])) {
                $config['deferred'][] = $providerClass;
            }
        }
        
        // Write updated configuration
        $content = "<?php\nreturn " . var_export($config, true) . ";\n";
        
        if (file_put_contents($providersConfig, $content) === false) {
            throw new MachinjiriException(
                "Failed to update providers configuration: {$providersConfig}",
                90007
            );
        }
    }

    /**
     * Create default providers.php configuration
     *
     * @throws MachinjiriException
     */
    private function createDefaultProvidersConfig(): void
    {
        $providersConfig = $this->configPath . 'providers.php';
        
        $content = <<<'PHP'
<?php
return [
    /*
    |--------------------------------------------------------------------------
    | Application Service Providers
    |--------------------------------------------------------------------------
    |
    | These service providers will be loaded on every request to your application.
    | Feel free to add your own services to this array to grant expanded
    | functionality to your applications.
    |
    */

    'providers' => [
        // Core providers
        Mlangeni\Machinjiri\App\Providers\AppServiceProvider::class,
        Mlangeni\Machinjiri\App\Providers\DatabaseServiceProvider::class,
        Mlangeni\Machinjiri\App\Providers\AuthServiceProvider::class,
        Mlangeni\Machinjiri\App\Providers\EventServiceProvider::class,
        Mlangeni\Machinjiri\App\Providers\RouteServiceProvider::class,
        Mlangeni\Machinjiri\App\Providers\ViewServiceProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Deferred Service Providers
    |--------------------------------------------------------------------------
    |
    | These service providers are loaded only when needed (lazy loading).
    | They won't be loaded on every request, improving performance.
    |
    */

    'deferred' => [
        Mlangeni\Machinjiri\App\Providers\DatabaseServiceProvider::class,
        Mlangeni\Machinjiri\App\Providers\ViewServiceProvider::class,
    ],
];
PHP;

        if (file_put_contents($providersConfig, $content) === false) {
            throw new MachinjiriException(
                "Failed to create providers configuration: {$providersConfig}",
                90008
            );
        }
    }

    /**
     * Ensure directory exists
     *
     * @param string $directory
     * @throws MachinjiriException
     */
    private function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new MachinjiriException(
                "Failed to create directory: {$directory}",
                90009
            );
        }
    }

    /**
     * Generate all basic service providers
     *
     * @return array Created files
     * @throws MachinjiriException
     */
    public function generateAllBasic(): array
    {
        $providers = [
            'AppServiceProvider' => [
                'deferred' => false,
                'config_name' => 'app',
                'bindings' => [],
                'singletons' => [],
                'aliases' => [],
            ],
            'DatabaseServiceProvider' => [
                'deferred' => true,
                'config_name' => 'database',
                'bindings' => [],
                'singletons' => [],
                'aliases' => [],
            ],
            'AuthServiceProvider' => [
                'deferred' => false,
                'config_name' => 'auth',
                'bindings' => [],
                'singletons' => [],
                'aliases' => [],
            ],
            'EventServiceProvider' => [
                'deferred' => false,
                'config_name' => 'events',
                'bindings' => [],
                'singletons' => [],
                'aliases' => [],
            ],
            'RouteServiceProvider' => [
                'deferred' => false,
                'config_name' => 'routing',
                'bindings' => [],
                'singletons' => [],
                'aliases' => [],
            ],
            'ViewServiceProvider' => [
                'deferred' => true,
                'config_name' => 'views',
                'bindings' => [],
                'singletons' => [],
                'aliases' => [],
            ],
        ];
        
        $createdFiles = [];
        
        foreach ($providers as $name => $options) {
            try {
                $files = $this->generate($name, array_merge($options, ['register' => false]));
                $createdFiles = array_merge($createdFiles, $files);
            } catch (MachinjiriException $e) {
                // Skip if provider already exists
                if ($e->getCode() !== 90002) {
                    throw $e;
                }
            }
        }
        
        // Ensure providers.php exists
        if (!file_exists($this->configPath . 'providers.php')) {
            $this->createDefaultProvidersConfig();
        }
        
        return $createdFiles;
    }

    /**
     * List existing service providers
     *
     * @return array
     */
    public function listProviders(): array
    {
        $providers = [];
        
        if (!is_dir($this->providersPath)) {
            return $providers;
        }
        
        $files = scandir($this->providersPath);
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || !str_ends_with($file, 'ServiceProvider.php')) {
                continue;
            }
            
            $providerName = pathinfo($file, PATHINFO_FILENAME);
            $providerClass = "Mlangeni\\Machinjiri\\App\\Providers\\{$providerName}";
            
            $providers[] = [
                'name' => $providerName,
                'file' => $file,
                'path' => $this->providersPath . $file,
                'class' => $providerClass,
                'exists' => class_exists($providerClass),
            ];
        }
        
        return $providers;
    }

    /**
     * Remove a service provider
     *
     * @param string $name
     * @param bool $removeConfig
     * @param bool $removeFromProvidersConfig
     * @return bool
     * @throws MachinjiriException
     */
    public function remove(string $name, bool $removeConfig = false, bool $removeFromProvidersConfig = true): bool
    {
        $name = $this->normalizeName($name);
        
        // Remove provider file
        $providerFile = $this->providersPath . $name . '.php';
        $removed = false;
        
        if (file_exists($providerFile)) {
            if (!unlink($providerFile)) {
                throw new MachinjiriException(
                    "Failed to remove service provider file: {$providerFile}",
                    90010
                );
            }
            $removed = true;
        }
        
        // Remove configuration file if requested
        if ($removeConfig) {
            $configName = strtolower(str_replace('ServiceProvider', '', $name));
            $configFile = $this->configPath . $configName . '.php';
            
            if (file_exists($configFile)) {
                unlink($configFile);
            }
        }
        
        // Remove from providers.php if requested
        if ($removeFromProvidersConfig && file_exists($this->configPath . 'providers.php')) {
            $this->removeFromProvidersConfig($name);
        }
        
        return $removed;
    }

    /**
     * Remove provider from providers.php configuration
     *
     * @param string $providerName
     * @throws MachinjiriException
     */
    private function removeFromProvidersConfig(string $providerName): void
    {
        $providersConfig = $this->configPath . 'providers.php';
        
        if (!file_exists($providersConfig)) {
            return;
        }
        
        // Read current configuration
        $config = require $providersConfig;
        $providerClass = "Mlangeni\\Machinjiri\\App\\Providers\\{$providerName}";
        
        // Remove from providers array
        if (isset($config['providers']) && ($key = array_search($providerClass, $config['providers'])) !== false) {
            unset($config['providers'][$key]);
            $config['providers'] = array_values($config['providers']);
        }
        
        // Remove from deferred array
        if (isset($config['deferred']) && ($key = array_search($providerClass, $config['deferred'])) !== false) {
            unset($config['deferred'][$key]);
            $config['deferred'] = array_values($config['deferred']);
        }
        
        // Write updated configuration
        $content = "<?php\nreturn " . var_export($config, true) . ";\n";
        
        if (file_put_contents($providersConfig, $content) === false) {
            throw new MachinjiriException(
                "Failed to update providers configuration: {$providersConfig}",
                90011
            );
        }
    }

    /**
     * Get stub content for custom template
     *
     * @param string $stubName
     * @return string|null
     */
    public function getStub(string $stubName): ?string
    {
        $stubPath = __DIR__ . '/stubs/' . $stubName . '.stub';
        
        if (file_exists($stubPath)) {
            return file_get_contents($stubPath);
        }
        
        // Try in alternative location
        $stubPath = $this->srcPath . 'App/Providers/stubs/' . $stubName . '.stub';
        
        if (file_exists($stubPath)) {
            return file_get_contents($stubPath);
        }
        
        return null;
    }

    /**
     * Generate from custom stub
     *
     * @param string $name
     * @param string $stubName
     * @param array $replacements
     * @return string
     * @throws MachinjiriException
     */
    public function generateFromStub(string $name, string $stubName, array $replacements = []): string
    {
        $stub = $this->getStub($stubName);
        
        if ($stub === null) {
            throw new MachinjiriException(
                "Stub not found: {$stubName}",
                90012
            );
        }
        
        $name = $this->normalizeName($name);
        $this->validateName($name);
        
        // Ensure providers directory exists
        $this->ensureDirectoryExists($this->providersPath);
        
        $providerFile = $this->providersPath . $name . '.php';
        
        // Apply replacements
        $replacements = array_merge([
            '{{namespace}}' => 'Mlangeni\\Machinjiri\\App\\Providers',
            '{{class}}' => $name,
            '{{deferred}}' => 'false',
        ], $replacements);
        
        $content = str_replace(array_keys($replacements), array_values($replacements), $stub);
        
        // Write file
        if (file_put_contents($providerFile, $content) === false) {
            throw new MachinjiriException(
                "Failed to create service provider file from stub: {$providerFile}",
                90013
            );
        }
        
        return $providerFile;
    }

    /**
     * Generate ThirdPartyAuthServiceProvider
     *
     * @param array $options
     * @return array Created files
     * @throws MachinjiriException
     */
    public function generateThirdPartyAuth(array $options = []): array
    {
        $name = 'ThirdPartyAuthServiceProvider';
        $configName = 'thirdparty_auth';
        
        // Validate name
        $this->validateName($name);
        
        // Get options
        $deferred = $options['deferred'] ?? true;
        $withConfig = $options['config'] ?? true;
        $withDatabase = $options['database'] ?? true;
        $providers = $options['providers'] ?? ['google', 'github', 'facebook'];
        
        // Create provider file
        $providerFile = $this->createThirdPartyAuthProviderFile($name, [
            'deferred' => $deferred,
            'with_database' => $withDatabase,
        ]);
        
        $createdFiles = [$providerFile];
        
        // Create configuration file if requested
        if ($withConfig) {
            $configFile = $this->createThirdPartyAuthConfigFile($configName, [
                'providers' => $providers,
                'auto_create_users' => true,
                'auto_sync_profile' => true,
            ]);
            $createdFiles[] = $configFile;
        }
        
        // Create migration file for database tables
        if ($withDatabase) {
            $migrationFile = $this->createThirdPartyAuthMigration();
            $createdFiles[] = $migrationFile;
        }
        
        // Update providers.php configuration if requested
        if ($options['register'] ?? true) {
            $this->registerInProvidersConfig($name, [
                'deferred' => $deferred,
                'config_name' => $configName,
            ]);
        }
        
        return $createdFiles;
    }

    /**
     * Create ThirdPartyAuthServiceProvider file
     *
     * @param string $name
     * @param array $options
     * @return string
     * @throws MachinjiriException
     */
    private function createThirdPartyAuthProviderFile(string $name, array $options): string
    {
        // Ensure providers directory exists
        $this->ensureDirectoryExists($this->providersPath);
        
        $providerFile = $this->providersPath . $name . '.php';
        
        // Generate template
        $template = $this->generateThirdPartyAuthTemplate($name, $options);
        
        // Write file
        if (file_put_contents($providerFile, $template) === false) {
            throw new MachinjiriException(
                "Failed to create ThirdPartyAuth service provider file: {$providerFile}",
                90014
            );
        }
        
        return $providerFile;
    }

    /**
     * Generate ThirdPartyAuthServiceProvider template
     *
     * @param string $name
     * @param array $options
     * @return string
     */
    private function generateThirdPartyAuthTemplate(string $name, array $options): string
    {
        $deferred = $options['deferred'] ? 'true' : 'false';
        $withDatabase = $options['with_database'] ? 'true' : 'false';
        
        $databaseSetup = $withDatabase ? <<<'PHP'

        // Initialize database connection if available
        if ($this->app->has('database.connection')) {
            $connection = $this->app->get('database.connection');
            $queryBuilder = new QueryBuilder($connection);
            $thirdPartyAuth->setQueryBuilder($queryBuilder);
        }
PHP : '';

        return <<<PHP
<?php
namespace Mlangeni\Machinjiri\App\Providers;

use Mlangeni\Machinjiri\Core\Providers\ServiceProvider as BaseServiceProvider;
use Mlangeni\Machinjiri\Core\Authentication\ThirdPartyAuth;
use Mlangeni\Machinjiri\Core\Authentication\Session;
use Mlangeni\Machinjiri\Core\Authentication\Cookie;
use Mlangeni\Machinjiri\Core\Network\CurlHandler;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Database\QueryBuilder;

/**
 * Third-Party Authentication Service Provider
 *
 * This service provider handles registration and bootstrapping
 * of third-party OAuth authentication services for multiple providers
 * including Google, GitHub, Facebook, Twitter, LinkedIn, and more.
 */
class {$name} extends BaseServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred
     */
    protected bool \$defer = {$deferred};

    /**
     * Register services
     */
    public function register(): void
    {
        // Load and merge configuration
        \$this->mergeConfigFrom(
            \$this->app->config . 'thirdparty_auth.php',
            'thirdparty_auth'
        );

        // Register ThirdPartyAuth as a singleton
        \$this->app->singleton('auth.thirdparty', function (\$app) {
            \$config = \$app->config['thirdparty_auth'] ?? [];
            
            \$session = new Session();
            \$cookie = new Cookie();
            \$httpClient = new CurlHandler();
            \$logger = new Logger('auth', Logger::DEBUG);
            
            \$thirdPartyAuth = new ThirdPartyAuth(
                \$config,
                \$session,
                \$cookie,
                null, // QueryBuilder will be set in boot() if available
                \$httpClient,
                \$logger
            );
{$databaseSetup}

            return \$thirdPartyAuth;
        });

        // Register alias for easier access
        \$this->app->alias('auth.thirdparty', ThirdPartyAuth::class);
    }

    /**
     * Bootstrap services
     */
    public function boot(): void
    {
        // Publish configuration
        if (\$this->app->runningInConsole()) {
            \$this->publishes([
                __DIR__ . '/../../config/thirdparty_auth.php' => \$this->app->config . 'thirdparty_auth.php',
            ], 'thirdparty-auth-config');
        }

        // Set database connection if available
        if (\$this->app->has('auth.thirdparty')) {
            \$thirdPartyAuth = \$this->app->get('auth.thirdparty');
            
            if (\$this->app->has('database.connection')) {
                \$connection = \$this->app->get('database.connection');
                \$queryBuilder = new QueryBuilder(\$connection);
                \$thirdPartyAuth->setQueryBuilder(\$queryBuilder);
            }
        }

        // Log that service has booted
        \$this->triggerEvent('thirdparty_auth.booted');
    }

    /**
     * Get the services provided by the provider
     */
    public function provides(): array
    {
        return [
            'auth.thirdparty',
            ThirdPartyAuth::class,
        ];
    }
}
PHP;
    }

    /**
     * Create ThirdPartyAuth configuration file
     *
     * @param string $name
     * @param array $data
     * @return string
     * @throws MachinjiriException
     */
    private function createThirdPartyAuthConfigFile(string $name, array $data = []): string
    {
        // Ensure config directory exists
        $this->ensureDirectoryExists($this->configPath);
        
        $configFile = $this->configPath . $name . '.php';
        
        // Check if config file already exists
        if (file_exists($configFile)) {
            throw new MachinjiriException(
                "Configuration file already exists: {$configFile}",
                90015
            );
        }
        
        // Generate configuration template
        $template = $this->generateThirdPartyAuthConfigTemplate($name, $data);
        
        // Write file
        if (file_put_contents($configFile, $template) === false) {
            throw new MachinjiriException(
                "Failed to create ThirdPartyAuth configuration file: {$configFile}",
                90016
            );
        }
        
        return $configFile;
    }

    /**
     * Generate ThirdPartyAuth configuration template
     *
     * @param string $name
     * @param array $data
     * @return string
     */
    private function generateThirdPartyAuthConfigTemplate(string $name, array $data): string
    {
        $providers = var_export($data['providers'] ?? ['google', 'github', 'facebook'], true);
        
        return <<<PHP
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Third-Party Authentication Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration manages OAuth authentication with third-party providers.
    | Configure your OAuth credentials in the .env file:
    |
    | GOOGLE_CLIENT_ID=your-google-client-id
    | GOOGLE_CLIENT_SECRET=your-google-client-secret
    | GITHUB_CLIENT_ID=your-github-client-id
    | GITHUB_CLIENT_SECRET=your-github-client-secret
    | etc...
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Redirect URI
    |--------------------------------------------------------------------------
    |
    | The redirect URI after OAuth authentication. Should point to your
    | callback endpoint (typically /auth/callback)
    |
    */
    'redirect_uri' => env('APP_URL', 'http://localhost:8000') . '/auth/callback',

    /*
    |--------------------------------------------------------------------------
    | Session Configuration
    |--------------------------------------------------------------------------
    |
    | Key prefix for storing OAuth data in session
    |
    */
    'session_key_prefix' => 'thirdparty_auth_',

    /*
    |--------------------------------------------------------------------------
    | Database Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the tables used for storing OAuth data
    |
    */
    'user_table' => 'users',
    'provider_table' => 'user_providers',
    'token_table' => 'user_tokens',

    /*
    |--------------------------------------------------------------------------
    | Auto User Creation
    |--------------------------------------------------------------------------
    |
    | Automatically create a new user account when they authenticate
    | with a third-party provider for the first time
    |
    */
    'auto_create_users' => true,

    /*
    |--------------------------------------------------------------------------
    | Auto Sync Profile
    |--------------------------------------------------------------------------
    |
    | Automatically sync user profile data (name, avatar) from the
    | OAuth provider on each login
    |
    */
    'auto_sync_profile' => true,

    /*
    |--------------------------------------------------------------------------
    | Default Role for New Users
    |--------------------------------------------------------------------------
    |
    | The role assigned to automatically created users
    |
    */
    'default_role' => 'user',

    /*
    |--------------------------------------------------------------------------
    | Available OAuth Providers
    |--------------------------------------------------------------------------
    |
    | List of OAuth providers to enable. Only configured providers
    | will be available for authentication.
    |
    */
    'providers' => {$providers},

    /*
    |--------------------------------------------------------------------------
    | OAuth Scopes
    |--------------------------------------------------------------------------
    |
    | Define the scopes requested from each OAuth provider.
    | These determine what user data and permissions you'll have access to.
    |
    */
    'scopes' => [
        'google' => ['email', 'profile', 'openid'],
        'github' => ['user:email', 'read:user'],
        'facebook' => ['email', 'public_profile'],
        'twitter' => ['users.read', 'tweet.read'],
        'yahoo' => ['profile', 'email'],
        'linkedin' => ['r_liteprofile', 'r_emailaddress'],
        'microsoft' => ['User.Read', 'email'],
        'instagram' => ['user_profile', 'user_media'],
        'gitlab' => ['read_user'],
        'bitbucket' => ['account', 'email'],
        'amazon' => ['profile'],
        'slack' => ['users:read', 'users:read.email'],
    ],

    /*
    |--------------------------------------------------------------------------
    | OAuth Endpoints
    |--------------------------------------------------------------------------
    |
    | Authorization, token, and revocation endpoints for each provider.
    | These are pre-configured for official providers.
    |
    */
    'endpoints' => [
        'google' => [
            'authorization' => 'https://accounts.google.com/o/oauth2/auth',
            'token' => 'https://oauth2.googleapis.com/token',
            'revoke' => 'https://oauth2.googleapis.com/revoke',
        ],
        'github' => [
            'authorization' => 'https://github.com/login/oauth/authorize',
            'token' => 'https://github.com/login/oauth/access_token',
            'revoke' => null,
        ],
        'facebook' => [
            'authorization' => 'https://www.facebook.com/v12.0/dialog/oauth',
            'token' => 'https://graph.facebook.com/v12.0/oauth/access_token',
            'revoke' => 'https://graph.facebook.com/v12.0/{user_id}/permissions',
        ],
        'twitter' => [
            'authorization' => 'https://twitter.com/i/oauth2/authorize',
            'token' => 'https://api.twitter.com/2/oauth2/token',
            'revoke' => 'https://api.twitter.com/2/oauth2/revoke',
        ],
        'yahoo' => [
            'authorization' => 'https://api.login.yahoo.com/oauth2/request_auth',
            'token' => 'https://api.login.yahoo.com/oauth2/get_token',
            'revoke' => 'https://api.login.yahoo.com/oauth2/revoke',
        ],
        'linkedin' => [
            'authorization' => 'https://www.linkedin.com/oauth/v2/authorization',
            'token' => 'https://www.linkedin.com/oauth/v2/accessToken',
            'revoke' => null,
        ],
        'microsoft' => [
            'authorization' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'token' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'revoke' => 'https://login.microsoftonline.com/common/oauth2/v2.0/logout',
        ],
        'instagram' => [
            'authorization' => 'https://api.instagram.com/oauth/authorize',
            'token' => 'https://api.instagram.com/oauth/access_token',
            'revoke' => null,
        ],
        'gitlab' => [
            'authorization' => 'https://gitlab.com/oauth/authorize',
            'token' => 'https://gitlab.com/oauth/token',
            'revoke' => 'https://gitlab.com/oauth/revoke',
        ],
        'bitbucket' => [
            'authorization' => 'https://bitbucket.org/site/oauth2/authorize',
            'token' => 'https://bitbucket.org/site/oauth2/access_token',
            'revoke' => 'https://bitbucket.org/site/oauth2/revoke',
        ],
        'amazon' => [
            'authorization' => 'https://www.amazon.com/ap/oa',
            'token' => 'https://api.amazon.com/auth/o2/token',
            'revoke' => null,
        ],
        'slack' => [
            'authorization' => 'https://slack.com/oauth/v2/authorize',
            'token' => 'https://slack.com/api/oauth.v2.access',
            'revoke' => 'https://slack.com/api/auth.revoke',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Info Endpoints
    |--------------------------------------------------------------------------
    |
    | Endpoints for fetching user information from each OAuth provider
    |
    */
    'user_info_endpoints' => [
        'google' => 'https://www.googleapis.com/oauth2/v3/userinfo',
        'github' => 'https://api.github.com/user',
        'facebook' => 'https://graph.facebook.com/v12.0/me?fields=id,name,email,picture',
        'twitter' => 'https://api.twitter.com/2/users/me',
        'yahoo' => 'https://api.login.yahoo.com/openid/v1/userinfo',
        'linkedin' => 'https://api.linkedin.com/v2/me',
        'microsoft' => 'https://graph.microsoft.com/v1.0/me',
        'instagram' => 'https://graph.instagram.com/me?fields=id,username,account_type,media_count',
        'gitlab' => 'https://gitlab.com/api/v4/user',
        'bitbucket' => 'https://api.bitbucket.org/2.0/user',
        'amazon' => 'https://api.amazon.com/user/profile',
        'slack' => 'https://slack.com/api/users.profile.get',
    ],
];
PHP;
    }

    /**
     * Create ThirdPartyAuth database migration
     *
     * @return string
     * @throws MachinjiriException
     */
    private function createThirdPartyAuthMigration(): string
    {
        $migrationsPath = $this->appBasePath . '/database/migrations/';
        $this->ensureDirectoryExists($migrationsPath);
        
        $timestamp = date('Y_m_d_His');
        $migrationFile = $migrationsPath . $timestamp . '_create_third_party_auth_tables.php';
        
        // Generate migration content
        $template = $this->generateThirdPartyAuthMigrationTemplate();
        
        // Write file
        if (file_put_contents($migrationFile, $template) === false) {
            throw new MachinjiriException(
                "Failed to create migration file: {$migrationFile}",
                90017
            );
        }
        
        return $migrationFile;
    }

    /**
     * Generate ThirdPartyAuth migration template
     *
     * @return string
     */
    private function generateThirdPartyAuthMigrationTemplate(): string
    {
        $timestamp = date('Y-m-d H:i:s');
        
        return <<<'PHP'
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Create User Providers Table
    |--------------------------------------------------------------------------
    */
    'create_user_providers_table' => "
        CREATE TABLE IF NOT EXISTS user_providers (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            provider VARCHAR(50) NOT NULL,
            provider_id VARCHAR(255) NOT NULL,
            access_token LONGTEXT NOT NULL,
            refresh_token LONGTEXT,
            token_expires DATETIME,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_provider_per_user (user_id, provider),
            UNIQUE KEY unique_provider_id (provider, provider_id),
            INDEX idx_user_id (user_id),
            INDEX idx_provider (provider)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ",

    /*
    |--------------------------------------------------------------------------
    | Create User Tokens Table
    |--------------------------------------------------------------------------
    */
    'create_user_tokens_table' => "
        CREATE TABLE IF NOT EXISTS user_tokens (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            provider VARCHAR(50) NOT NULL,
            access_token LONGTEXT NOT NULL,
            refresh_token LONGTEXT,
            token_type VARCHAR(50) DEFAULT 'bearer',
            expires_in INT,
            scope TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_provider (user_id, provider),
            INDEX idx_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ",
];
PHP;
    }
}