<?php

/**
 * Task Scheduler Service Provider
 *
 * This service provider is responsible for registering and bootstrapping
 * task scheduler services. It binds interfaces to concrete implementations,
 * registers singleton instances, sets up configuration, and provides aliases
 * for easier access via the service container.
 *
 * @package Mlangeni\Machinjiri\Core\Providers\CoreProviders
 */

namespace Mlangeni\Machinjiri\Core\Providers\CoreProviders;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Providers\ServiceProvider;
use Mlangeni\Machinjiri\Core\Exceptions\TaskSchedulerException;
use Mlangeni\Machinjiri\Core\Components\Task\{TaskManager, TaskScheduler, ScheduleRepository};
use Mlangeni\Machinjiri\Core\Components\Task\Repositories\DatabaseScheduleRepository;

class TaskSchedulerServiceProvider extends ServiceProvider
{
    protected bool $defer = false;
    
    public function register(): void
    {
        // -------------------- Task Scheduler Components --------------------
        // TaskScheduler, TaskManager are registered as singletons.
        $this->singleton(TaskManager::class, function ($app) {
            return new TaskManager($app);
        });

        $this->singleton(TaskScheduler::class, function ($app) {
            return new TaskScheduler($app);
        });

        $this->singleton("task.scheduler.repository", function ($app) {
            $driver = $app->configurations['tasks']['default'] ?? 'database';
            return $this->createRepository($app, $driver);
        });

        // -------------------- Aliases for Convenience --------------------
        // Provide shorter names for common services to simplify dependency resolution.
        $this->aliasMany([
            'task.scheduler.manager'    => TaskManager::class,
            'task.scheduler'            => TaskScheduler::class,
        ]);
    }

    public function boot(): void
    {
        // Get the configuration directory path from the application.
        $configDir = $this->app->coreConfig;

        if (is_dir($configDir)) {
            $this->mergeConfigFrom($configDir . 'tasks.php', 'tasks');
        }
        
        // Validate configuration
        $this->validateConfiguration();
        
        // Register registered tasks from config
        $this->registerConfiguredTasks();
    }

    protected function createRepository(Container $app, string $driver): ScheduleRepository
    {
        switch ($driver) {
            case 'database':
                return new DatabaseScheduleRepository($app);
            default:
                // Try loading a custom repository
                $repository = "\\Mlangeni\\Machinjiri\\App\\Tasks\\Repositories\\" . ucfirst($driver) . "ScheduleRepository";
                if (class_exists($repository)) {
                    return new $repository($app);
                }
                throw TaskSchedulerException::repositoryError("Could not resolve schedule repository: " . $repository);
        }
    }

    protected function validateConfiguration(): void
    {
        $config = $this->app->configurations['tasks'] ?? [];
        
        // Validate driver
        $driver = $config['default'] ?? 'database';
        $validDrivers = ['database', 'redis', 'file'];
        if (!in_array($driver, $validDrivers) && !class_exists("\\Mlangeni\\Machinjiri\\App\\Tasks\\Repositories\\" . ucfirst($driver) . "ScheduleRepository")) {
            throw TaskSchedulerException::repositoryError(
                "Invalid task driver: {$driver}. Supported: " . implode(', ', $validDrivers)
            );
        }
        
        // Validate defaults
        $defaults = $config['defaults'] ?? [];
        if (isset($defaults['max_attempts']) && $defaults['max_attempts'] < 1) {
            throw TaskSchedulerException::jobError("max_attempts must be at least 1");
        }
        
        if (isset($defaults['timeout']) && $defaults['timeout'] < 1) {
            throw TaskSchedulerException::jobError("timeout must be at least 1 second");
        }
        
        if (isset($defaults['retry_delay']) && $defaults['retry_delay'] < 1) {
            throw TaskSchedulerException::jobError("retry_delay must be at least 1 second");
        }
    }
    
    protected function registerConfiguredTasks(): void
    {
        $config = $this->app->configurations['tasks'] ?? [];
        $registeredTasks = $config['registered_tasks'] ?? [];
        
        if (!empty($registeredTasks)) {
            $manager = $this->app->resolve(TaskManager::class);
            $manager->registerTasks($registeredTasks);
            
            // Sync tasks if running in console
            if (PHP_SAPI === 'cli') {
                try {
                    $manager->sync();
                } catch (\Exception $e) {
                    // Log but don't break application
                    error_log("Failed to sync tasks: " . $e->getMessage());
                }
            }
        }
    }

    public function provides(): array
    {
        // Combine all bindings, singletons, and aliases into a single list.
        return array_merge(
            array_keys($this->bindings),
            array_keys($this->singletons),
            array_keys($this->aliases)
        );
    }
}