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
        // \$this->registerMiddleware(\$middleware);
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
}