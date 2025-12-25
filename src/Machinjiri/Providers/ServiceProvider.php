<?php
/**
 * Service Provider
 *
 * Base class for registering and booting services in the Machinjiri framework.
 *
 * Responsibilities:
 *  - Provide a base class for all service providers
 *  - Handle service registration and booting
 *  - Integrate with the application container
 *  - Support deferred loading for performance optimization
 *
 * Implementation notes:
 *  - Service providers should extend this class and implement the register() method
 *  - The boot() method is optional and runs after all providers are registered
 *  - Deferred providers only load when their provided services are needed
 */

namespace Mlangeni\Machinjiri\Core\Providers;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;

/**
 * Base service provider class
 *
 * All service providers should extend this class and implement the register() method.
 * Service providers are used to bootstrap application components, register bindings,
 * and perform initialization tasks.
 */
abstract class ServiceProvider
{
    /**
     * The application container instance
     *
     * @var Container
     */
    protected Container $app;

    /**
     * The event listener instance for provider events
     *
     * @var EventListener
     */
    protected EventListener $events;

    /**
     * Indicates if loading of the provider is deferred
     *
     * @var bool
     */
    protected bool $defer = false;

    /**
     * The service bindings provided by this provider
     *
     * @var array
     */
    protected array $bindings = [];

    /**
     * The singleton bindings provided by this provider
     *
     * @var array
     */
    protected array $singletons = [];

    /**
     * The aliases provided by this provider
     *
     * @var array
     */
    protected array $aliases = [];

    /**
     * Create a new service provider instance
     *
     * @param Container $app
     */
    public function __construct(Container $app)
    {
        $this->app = $app;
        $this->events = new EventListener(new Logger());
    }

    /**
     * Register the service provider
     *
     * This method should be implemented by all concrete service providers.
     * It should register bindings, singletons, and perform other setup tasks.
     *
     * @return void
     */
    abstract public function register(): void;

    /**
     * Boot the service provider
     *
     * This method is called after all service providers have been registered.
     * It's the ideal place to perform initialization that requires other services.
     *
     * @return void
     */
    public function boot(): void
    {
        // Default implementation - can be overridden by child classes
    }

    /**
     * Get the services provided by the provider
     *
     * @return array
     */
    public function provides(): array
    {
        return array_merge(
            array_keys($this->bindings),
            array_keys($this->singletons),
            array_keys($this->aliases)
        );
    }

    /**
     * Determine if the provider is deferred
     *
     * @return bool
     */
    public function isDeferred(): bool
    {
        return $this->defer;
    }

    /**
     * Register a binding with the container
     *
     * @param string $abstract
     * @param string|callable|null $concrete
     * @param bool $shared
     * @return void
     */
    protected function bind(string $abstract, $concrete = null, bool $shared = false): void
    {
        $concrete = $concrete ?: $abstract;
        
        // Initialize bindings array if not exists
        if (!property_exists($this->app, 'bindings') || !isset($this->app->bindings)) {
            $this->app->bindings = [];
        }
        
        $this->app->bindings[$abstract] = [
            'concrete' => $concrete,
            'shared' => $shared
        ];
        
        $this->bindings[$abstract] = true;
    }

    /**
     * Register a shared binding (singleton) in the container
     *
     * @param string $abstract
     * @param string|callable|null $concrete
     * @return void
     */
    protected function singleton(string $abstract, $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
        $this->singletons[$abstract] = true;
    }

    /**
     * Register an alias for a binding
     *
     * @param string $abstract
     * @param string $alias
     * @return void
     */
    protected function alias(string $abstract, string $alias): void
    {
        if (!property_exists($this->app, 'aliases') || !isset($this->app->aliases)) {
            $this->app->aliases = [];
        }
        
        $this->app->aliases[$alias] = $abstract;
        $this->aliases[$alias] = true;
    }

    /**
     * Register multiple bindings at once
     *
     * @param array $bindings
     * @return void
     */
    protected function bindMany(array $bindings): void
    {
        foreach ($bindings as $abstract => $concrete) {
            if (is_numeric($abstract)) {
                $this->bind($concrete);
            } else {
                $this->bind($abstract, $concrete);
            }
        }
    }

    /**
     * Register multiple singletons at once
     *
     * @param array $singletons
     * @return void
     */
    protected function singletonMany(array $singletons): void
    {
        foreach ($singletons as $abstract => $concrete) {
            if (is_numeric($abstract)) {
                $this->singleton($concrete);
            } else {
                $this->singleton($abstract, $concrete);
            }
        }
    }

    /**
     * Register multiple aliases at once
     *
     * @param array $aliases
     * @return void
     */
    protected function aliasMany(array $aliases): void
    {
        foreach ($aliases as $alias => $abstract) {
            $this->alias($abstract, $alias);
        }
    }

    /**
     * Register an event listener
     *
     * @param string $event
     * @param callable $listener
     * @param int $priority
     * @return void
     */
    protected function listen(string $event, callable $listener, int $priority = 0): void
    {
        $this->events->on($event, $listener, $priority);
    }

    /**
     * Register multiple event listeners
     *
     * @param array $listeners
     * @return void
     */
    protected function listenMany(array $listeners): void
    {
        foreach ($listeners as $event => $listenersArray) {
            if (is_array($listenersArray)) {
                foreach ($listenersArray as $listener) {
                    if (is_callable($listener)) {
                      $this->listen($event, $listener);
                    }
                }
            } else {
                $this->listen($event, $listenersArray);
            }
        }
    }

    /**
     * Merge configuration from a file
     *
     * @param string $path
     * @param string $key
     * @return void
     * @throws MachinjiriException
     */
    protected function mergeConfigFrom(string $path, string $key): void
    {
        /*
        if (!file_exists($path)) {
            throw new MachinjiriException("Configuration file not found: {$path}", 30101);
        }
        

        $config = require $path;
        
        */
        
        if (!property_exists($this->app, 'configurations') || !isset($this->app->configurations)) {
            $this->app->configurations = [];
        }
        
        if (!isset($this->app->configurations[$key])) {
            $this->app->configurations[$key] = [];
        }
        
        $this->app->configurations[$key] = array_merge(
            $this->app->configurations[$key],
            //$config
            $_ENV
        );
    }

    /**
     * Load routes from a file
     *
     * @param string $path
     * @return void
     */
    protected function loadRoutesFrom(string $path): void
    {
        if (file_exists($path)) {
            require $path;
        }
    }

    /**
     * Load views from a directory
     *
     * @param string $path
     * @param string|null $namespace
     * @return void
     */
    protected function loadViewsFrom(string $path, ?string $namespace = null): void
    {
        if (!property_exists($this->app, 'viewPaths') || !isset($this->app->viewPaths)) {
            $this->app->viewPaths = [];
        }
        
        if ($namespace) {
            $this->app->viewPaths[$namespace] = $path;
        } else {
            $this->app->viewPaths[] = $path;
        }
    }

    /**
     * Load migrations from a directory
     *
     * @param string $path
     * @return void
     */
    protected function loadMigrationsFrom(string $path): void
    {
        if (!property_exists($this->app, 'migrationPaths') || !isset($this->app->migrationPaths)) {
            $this->app->migrationPaths = [];
        }
        
        $this->app->migrationPaths[] = $path;
    }

    /**
     * Publish assets to the public directory
     *
     * @param array $assets
     * @param string $group
     * @return void
     */
    protected function publishes(array $assets, string $group = 'default'): void
    {
        if (!property_exists($this->app, 'publishes') || !isset($this->app->publishes)) {
            $this->app->publishes = [];
        }
        
        if (!isset($this->app->publishes[$group])) {
            $this->app->publishes[$group] = [];
        }
        
        $this->app->publishes[$group] = array_merge(
            $this->app->publishes[$group],
            $assets
        );
    }

    /**
     * Get the paths that should be published
     *
     * @param string $group
     * @return array
     */
    public static function pathsToPublish(string $group = 'default'): array
    {
        // This would typically be called from a console command
        return [];
    }

    /**
     * Register console commands
     *
     * @param array $commands
     * @return void
     */
    protected function commands(array $commands): void
    {
        if (!property_exists($this->app, 'commands') || !isset($this->app->commands)) {
            $this->app->commands = [];
        }
        
        $this->app->commands = array_merge($this->app->commands, $commands);
    }

    /**
     * Register middleware
     *
     * @param string|array $middleware
     * @param string|null $name
     * @return void
     */
    protected function registerMiddleware($middleware, ?string $name = null): void
    {
        if (!property_exists($this->app, 'middleware') || !isset($this->app->middleware)) {
            $this->app->middleware = [];
        }
        
        if (is_array($middleware)) {
            foreach ($middleware as $key => $value) {
                if (is_numeric($key)) {
                    $this->app->middleware[] = $value;
                } else {
                    $this->app->middleware[$key] = $value;
                }
            }
        } elseif ($name) {
            $this->app->middleware[$name] = $middleware;
        } else {
            $this->app->middleware[] = $middleware;
        }
    }

    /**
     * Trigger provider events
     *
     * @param string $event
     * @param mixed $data
     * @return void
     */
    protected function triggerEvent(string $event, $data = null): void
    {
        $this->events->trigger($event, $data);
    }

    /**
     * Get a service from the container
     *
     * @param string $id
     * @return mixed
     */
    protected function get(string $id)
    {
        return $this->resolve($id);
    }

    /**
     * Resolve a service from the container
     *
     * @param string $abstract
     * @param array $parameters
     * @return mixed
     */
    protected function resolve(string $abstract, array $parameters = [])
    {
        // Check for aliases
        if (isset($this->app->aliases[$abstract])) {
            $abstract = $this->app->aliases[$abstract];
        }
        
        // Check if binding exists
        if (isset($this->app->bindings[$abstract])) {
            $binding = $this->app->bindings[$abstract];
            
            // If it's a singleton and already instantiated, return the instance
            if ($binding['shared'] && isset($this->app->instances[$abstract])) {
                return $this->app->instances[$abstract];
            }
            
            // Resolve the concrete implementation
            $concrete = $binding['concrete'];
            
            if ($concrete instanceof \Closure || is_callable($concrete)) {
                $instance = call_user_func_array($concrete, array_merge([$this->app], $parameters));
            } elseif (is_string($concrete) && class_exists($concrete)) {
                $instance = new $concrete(...$parameters);
            } else {
                $instance = $concrete;
            }
            
            // Store singleton instance
            if ($binding['shared']) {
                if (!property_exists($this->app, 'instances') || !isset($this->app->instances)) {
                    $this->app->instances = [];
                }
                $this->app->instances[$abstract] = $instance;
            }
            
            return $instance;
        }
        
        // Try to auto-resolve class
        if (class_exists($abstract)) {
            return new $abstract(...$parameters);
        }
        
        throw new MachinjiriException("Unable to resolve service: {$abstract}", 30102);
    }

    /**
     * Call a method on the application instance
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call(string $method, array $parameters)
    {
        if (method_exists($this->app, $method)) {
            return $this->app->$method(...$parameters);
        }
        
        throw new MachinjiriException("Method {$method} not found on application container.", 30103);
    }
}