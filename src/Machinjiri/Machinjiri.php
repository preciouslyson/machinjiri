<?php
/**
 * Machinjiri
 *
 * Core application bootstrapper and service container entry point.
 *
 * Developer: preciouslyson
 *
 * Responsibilities:
 *  - Provide a singleton application instance
 *  - Initialize error handling, event listeners and logging
 *  - Establish database connections and run default migrations
 *  - Expose a small fluent API to initialize and load application resources
 *
 * Implementation notes:
 *  - This class extends Container which provides configuration, path and resource helpers.
 *  - MachinjiriException instances are presented to the user via the exception's show() helper.
 */

namespace Mlangeni\Machinjiri\Core;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Exceptions\ErrorHandler;
use Mlangeni\Machinjiri\Core\Database\DatabaseConnection;
use Mlangeni\Machinjiri\Core\Database\Migrations\MigrationHandler;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;

/**
 * Final application class that bootstraps the framework.
 *
 * This class follows the singleton pattern and is intended to be the
 * main entry point for initializing the application.
 */
final class Machinjiri extends Container
{
    /**
     * The singleton instance of the application.
     *
     * @var self|null
     */
    public static $instance = null;

    /**
     * Current environment flag. true -> development, false -> production.
     *
     * @var bool
     */
    private static $environment;
    
    /**
     * Service provider loader
     *
     * @var ProviderLoader
     */
    public ?ProviderLoader $providerLoader = null;

    /**
     * Logger instance used by the application (general purpose).
     *
     * @var Logger
     */
    private Logger $logger;

    /**
     * Event listener used to trigger and handle application events.
     *
     * @var EventListener
     */
    private EventListener $listener;

    /**
     * Create (if necessary) and return the singleton application instance.
     *
     * @param string $appBasePath Absolute path to the application base directory.
     * @param bool   $dev        Whether the application should run in development mode.
     * @return self
     */
    public static function App(string $appBasePath, bool $dev = true): self
    {
       if (self::$instance === null) {
           self::$environment = $dev;
           self::$instance = new self($appBasePath, self::$environment);
       }
       return self::$instance;
    }

    /**
     * Return the singleton instance if initialized, otherwise throw.
     *
     * @throws MachinjiriException when the application has not been initialized.
     * @return self
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new MachinjiriException("App not initialised. Call App() first!", 80001);
        }
        return self::$instance;
    }

    /**
     * Get a string representation of the current environment.
     *
     * @return string 'development' or 'production'
     */
    public function getEnvironment(): string
    {
        return (self::$environment) ? 'development' : 'production';
    }

    /**
     * Set the global container instance
     *
     * @param Container $container
     * @return void
     */
    public static function setInstance(Container $container): void
    {
        self::$instance = $container;
        parent::setInstance($container);
    }

    /**
     * Private constructor to enforce singleton usage.
     *
     * The constructor:
     *  - Calls parent Container constructor to set up paths/configs
     *  - Registers the global error handler
     *  - Sets up the event listener and logger
     *  - Initializes the application, database connection and default migrations
     *
     * Any MachinjiriException thrown during boot is presented via show().
     *
     * @param string $appBasePath
     * @param bool   $dev
     */
    private function __construct(string $appBasePath, bool $dev = true)
    {
        parent::__construct($appBasePath, $dev);

        // Set the global instance
        self::setInstance($this);

        // Register the global error/exception handler for the chosen environment
        ErrorHandler::register($dev);

        // Prepare an event listener with a dedicated logger for event-related messages
        $this->listener = new EventListener(new Logger('events'));

        // Create logger instance
        $this->logger = new Logger('app');

        try {
            // Initialize service provider loader
            $this->providerLoader = new ProviderLoader($this);

            // Initialize application resources and services
            $this->initialize();

            // Register service providers
            $this->providerLoader->register();

            // Establish database connection
            $this->dbConnect();

            // Run any default migrations required by the framework
            $this->defaultMigrations();

            // Boot service providers
            $this->providerLoader->boot();

            // Signal that the application has finished initializing
            $this->listener->trigger('app.initialize');
        } catch (MachinjiriException $me) {
            // Present the error to the user/developer via the framework's error presentation
            $me->show();
        }
    }

    /**
     * Initialize application resources that are loaded after bootstrap.
     *
     * This method triggers an event, loads designated route containers and returns
     * the application instance for convenient chaining.
     *
     * @throws MachinjiriException bubbled up and shown via show()
     * @return self
     */
    public function init(): self
    {
        try {
            // Allow listeners to react before resources are loaded
            $this->listener->trigger('app.load.resources');

            // Load designated route containers (implemented in parent Container)
            //$this->loadRoutes();

            // Return the same instance for chaining
            return $this;
        } catch (MachinjiriException $e) {
            // Show any initialization error to the user/developer
            $e->show();
        }
    }

    /**
     * Establish database connection using framework DatabaseConnection wrapper.
     *
     * This method sets the path and configuration for the DatabaseConnection and triggers
     * an event indicating which driver was connected. On failure, an error is logged and presented.
     *
     * @return void
     */
    private function dbConnect(): void
    {
        $dbLogger = new Logger('database');
        try {
            DatabaseConnection::setPath($this->database);
            
            // Get database configuration
            $dbConfig = $this->getConfigurations()['database'] ?? [];
            
            if (empty($dbConfig)) {
                throw new MachinjiriException("Database configuration not found", 40002);
            }
            
            DatabaseConnection::setConfig($dbConfig);

            // Notify listeners which DB driver is in use
            $this->listener->trigger('db.connected.driver.' . DatabaseConnection::getDriver());
        } catch (MachinjiriException $e) {
            // Log a critical error with context and show the error
            $dbLogger->critical('connection failed', [
                'driver' => DatabaseConnection::getDriver(),
                'message' => $e->getMessage()
            ]);
            $e->show();
        }
    }

    /**
     * Run the framework's default migrations.
     *
     * If a migration-related error happens it will be shown via the exception's show() helper.
     *
     * @return void
     */
    private function defaultMigrations(): void
    {
        try {
            $migration = new MigrationHandler();
            $migration->migrate();
        } catch (MachinjiriException $e) {
            $e->show();
        }
    }
    
    /**
     * Resolve a service from the container
     *
     * @param string $abstract
     * @param array $parameters
     * @return mixed
     */
    public function resolve(string $abstract, array $parameters = [])
    {
        // Check for aliases first
        if (isset($this->aliases[$abstract])) {
            $abstract = $this->aliases[$abstract];
        }
        
        // Check if the service is bound
        if (!$this->bound($abstract)) {
            // Try to load deferred provider
            if ($this->providerLoader) {
                $this->providerLoader->loadDeferredProvider($abstract);
            }
            
            // If still not bound, try to auto-resolve
            if (!$this->bound($abstract) && class_exists($abstract)) {
                // Auto-resolve class
                try {
                    $instance = new $abstract(...$parameters);
                    
                    // If it's a singleton-like service, store it
                    if ($this->isSharedByDefault($abstract)) {
                        $this->instances[$abstract] = $instance;
                    }
                    
                    return $instance;
                } catch (\Exception $e) {
                    throw new MachinjiriException(
                        "Unable to auto-resolve service: {$abstract}. Error: " . $e->getMessage(),
                        40001
                    );
                }
            }
        }
        
        // Use parent's resolve method
        return parent::resolve($abstract, $parameters);
    }
    
    /**
     * Check if a class should be treated as shared by default
     *
     * @param string $abstract
     * @return bool
     */
    private function isSharedByDefault(string $abstract): bool
    {
        // Add classes that should be singletons by default
        $sharedClasses = [
            Logger::class,
            ProviderLoader::class,
            DatabaseConnection::class,
        ];
        
        return in_array($abstract, $sharedClasses) || 
               strpos($abstract, 'ServiceProvider') !== false;
    }

    /**
     * Get the service provider loader instance
     *
     * @return ProviderLoader
     */
    public function getProviderLoader(): ProviderLoader
    {
        return $this->providerLoader;
    }
    
    /**
     * Get the logger instance
     *
     * @return Logger
     */
    public function getLogger(): Logger
    {
        return $this->logger;
    }
    
    /**
     * Get the event listener instance
     *
     * @return EventListener
     */
    public function getEventListener(): EventListener
    {
        return $this->listener;
    }
    
    /**
     * Bind a service to the container (override for additional functionality)
     *
     * @param string $abstract
     * @param mixed $concrete
     * @param bool $shared
     * @return void
     */
    public function bind(string $abstract, $concrete = null, bool $shared = false): void
    {
        parent::bind($abstract, $concrete, $shared);
        
        // Trigger event when service is bound
        $this->listener->trigger('container.bound', [
            'abstract' => $abstract,
            'concrete' => $concrete,
            'shared' => $shared
        ]);
    }
    
    /**
     * Bind a singleton (override for additional functionality)
     *
     * @param string $abstract
     * @param mixed $concrete
     * @return void
     */
    public function singleton(string $abstract, $concrete = null): void
    {
        parent::singleton($abstract, $concrete);
        
        // Trigger event when singleton is registered
        $this->listener->trigger('container.singleton', [
            'abstract' => $abstract,
            'concrete' => $concrete
        ]);
    }
    
    /**
     * Register an alias (override for additional functionality)
     *
     * @param string $abstract
     * @param string $alias
     * @return void
     */
    public function alias(string $abstract, string $alias): void
    {
        parent::alias($abstract, $alias);
        
        // Trigger event when alias is registered
        $this->listener->trigger('container.alias', [
            'abstract' => $abstract,
            'alias' => $alias
        ]);
    }
    
    /**
     * Make (resolve) a service from the container (alias for resolve)
     *
     * @param string $abstract
     * @param array $parameters
     * @return mixed
     */
    public function make(string $abstract, array $parameters = [])
    {
        return $this->resolve($abstract, $parameters);
    }
    
    /**
     * Check if a service is bound
     *
     * @param string $abstract
     * @return bool
     */
    public function bound(string $abstract): bool
    {
        return parent::bound($abstract);
    }
    
    /**
     * Check if a singleton instance exists
     *
     * @param string $abstract
     * @return bool
     */
    public function hasInstance(string $abstract): bool
    {
        return parent::hasInstance($abstract);
    }
    
    /**
     * Get all bound services
     *
     * @return array
     */
    public function getBindings(): array
    {
        return parent::getBindings();
    }
    
    /**
     * Remove a binding
     *
     * @param string $abstract
     * @return void
     */
    public function unbind(string $abstract): void
    {
        parent::unbind($abstract);
        
        // Trigger event when service is unbound
        $this->listener->trigger('container.unbound', ['abstract' => $abstract]);
    }
    
    /**
     * Flush all bindings and instances
     *
     * @return void
     */
    public function flush(): void
    {
        parent::flush();
        
        // Trigger event when container is flushed
        $this->listener->trigger('container.flushed');
    }
    
    /**
     * Initialize application resources
     *
     * @return void
     */
    public function initialize(): void
    {
        // Load configurations
        $this->loadConfigurations();
        
        // Set up paths
        $this->setupPaths();
        
        // Trigger initialization event
        $this->listener->trigger('app.initializing');
    }
    
    /**
     * Load application configurations
     *
     * @return void
     */
    private function loadConfigurations(): void
    {
        $configPath = $this->getConfigPath();
        
        if (is_dir($configPath)) {
            $configFiles = glob($configPath . '*.php');
            
            foreach ($configFiles as $configFile) {
                $configName = pathinfo($configFile, PATHINFO_FILENAME);
                
                if ($configName === 'providers') {
                    continue; // Skip providers.php as it's handled by ProviderLoader
                }
                
                $config = require $configFile;
                
                if (is_array($config)) {
                    $this->configurations[$configName] = $config;
                }
            }
        }
    }
    
    
    /**
     * Get the base application path
     *
     * @return string
     */
    public function getBasePath(): string
    {
        return $this->basePath ?? dirname(__DIR__, 3);
    }
    
    /**
     * Get the configuration path
     *
     * @return string
     */
    public function getConfigPath(): string
    {
        return $this->paths['config'] ?? $this->getBasePath() . '/config';
    }
    
    /**
     * Get the database path
     *
     * @return string
     */
    public function getDatabasePath(): string
    {
        return $this->paths['database'] ?? $this->getBasePath() . '/database';
    }
    
    /**
     * Get the storage path
     *
     * @return string
     */
    public function getStoragePath(): string
    {
        return $this->paths['storage'] ?? $this->getBasePath() . '/storage';
    }
    
    /**
     * Get the public path
     *
     * @return string
     */
    public function getPublicPath(): string
    {
        return $this->paths['public'] ?? $this->getBasePath() . '/public';
    }
    
    /**
     * Get the resources path
     *
     * @return string
     */
    public function getResourcesPath(): string
    {
        return $this->paths['resources'] ?? $this->getBasePath() . '/resources';
    }
    
    /**
     * Get the routes path
     *
     * @return string
     */
    public function getRoutesPath(): string
    {
        return $this->paths['routes'] ?? $this->getBasePath() . '/routes';
    }
    
    /**
     * Magic method for method chaining and dynamic property access
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters)
    {
        // Try to resolve service if method starts with "get"
        if (strpos($method, 'get') === 0) {
            $serviceName = lcfirst(substr($method, 3));
            
            if ($this->bound($serviceName)) {
                return $this->resolve($serviceName);
            }
        }
        
        // Try to call method on parent
        if (method_exists(parent::class, $method)) {
            return parent::$method(...$parameters);
        }
        
        throw new MachinjiriException("Method {$method} not found on application.", 40003);
    }
    
    /**
     * Magic getter for dynamic property access
     *
     * @param string $name
     * @return mixed
     */
    public function __get(string $name)
    {
        // Try to resolve service
        if ($this->bound($name)) {
            return $this->resolve($name);
        }
        
        // Check if it's a configuration
        if (isset($this->configurations[$name])) {
            return $this->configurations[$name];
        }
        
        // Check if it's a path
        if (isset($this->paths[$name])) {
            return $this->paths[$name];
        }
        
        throw new MachinjiriException("Property {$name} not found on application.", 40004);
    }
    
    /**
     * Magic setter for dynamic property access
     *
     * @param string $name
     * @param mixed $value
     * @return void
     */
    public function __set(string $name, $value): void
    {
        // Allow setting configurations
        if (strpos($name, 'config.') === 0) {
            $configKey = substr($name, 7);
            $this->configurations[$configKey] = $value;
            return;
        }
        
        // Allow setting paths
        if (strpos($name, 'path.') === 0) {
            $pathKey = substr($name, 5);
            $this->paths[$pathKey] = $value;
            return;
        }
        
        // Store as property
        $this->{$name} = $value;
    }
    
    /**
     * Magic isset for dynamic property access
     *
     * @param string $name
     * @return bool
     */
    public function __isset(string $name): bool
    {
        return $this->bound($name) || 
               isset($this->configurations[$name]) || 
               isset($this->paths[$name]) || 
               property_exists($this, $name);
    }
    
    /**
     * String representation of the application
     *
     * @return string
     */
    public function __toString(): string
    {
        return sprintf(
            'Machinjiri Application [%s]',
            self::getEnvironment()
        );
    }
}