<?php
/**
 * Task Generator
 *
 * Generates repository and task templates for the Machinjiri framework.
 */

namespace Mlangeni\Machinjiri\Core\Artisans\Generators;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class TaskGenerator
{
    private string $appBasePath;
    private string $tasksPath;
    private string $configPath;
    private string $stubPath;

    public function __construct(Container $container)
    {
        $this->appBasePath = rtrim($container->getRootPath(), DIRECTORY_SEPARATOR);
        $this->tasksPath = $this->appBasePath . '/app/Scheduler/Tasks/';
        $this->configPath = $this->appBasePath . '/config/components/';
        $this->stubPath = $this->appBasePath . '/resources/stubs/';
    }

    /**
     * Generate a task template.
     */
    public function generateTask(string $name, string $type = "standard", bool $register = false, string $cron = "0 * * * *"): string
    {
        $name = $this->normalizeTaskName($name);
        $this->validateTaskName($name);
        $this->ensureDirectoryExists($this->tasksPath);
        
        $taskFile = $this->tasksPath . $name . '.php';
        
        // Generate template based on type
        $template = $this->generateTaskTemplate($name, $type, $cron);
        
        if (file_put_contents($taskFile, $template) === false) {
            throw new MachinjiriException(
                "Failed to create task file: {$taskFile}",
                91001
            );
        }
        
        if ($register) {
            $this->registerTaskInConfig($name);
        }
        
        return $taskFile;
    }

    /**
     * Normalize task name.
     */
    private function normalizeTaskName(string $name): string
    {
        $name = preg_replace('/Task$/i', '', $name);
        $name = str_replace(['-', '_', ' '], '', ucwords($name, '-_ '));
        return $name . 'Task';
    }

    /**
     * Validate task name.
     */
    private function validateTaskName(string $name): void
    {
        if (!preg_match('/^[A-Z][a-zA-Z0-9]*Task$/', $name)) {
            throw new MachinjiriException(
                "Invalid task name: {$name}. Name must be in PascalCase and end with Task.",
                91002
            );
        }
        
        $taskFile = $this->tasksPath . $name . '.php';
        if (file_exists($taskFile)) {
            throw new MachinjiriException(
                "Task already exists: {$name}",
                91003
            );
        }
        
        $className = "Mlangeni\\Machinjiri\\App\\Scheduler\\Tasks\\{$name}";
        if (class_exists($className)) {
            throw new MachinjiriException(
                "Task class already exists: {$className}",
                91004
            );
        }
    }
    
    /**
     * Generate task template.
     */
    private function generateTaskTemplate(string $name, string $type, string $cron): string
    {
        $shortName = str_replace('Task', '', $name);
        $lowerName = strtolower($shortName);
        
        switch ($type) {
            case 'simple':
                return $this->generateSimpleTaskTemplate($shortName, $cron);
            case 'advanced':
                return $this->generateAdvancedTaskTemplate($shortName, $cron);
            default:
                return $this->generateStandardTaskTemplate($shortName, $cron);
        }
    }

    /**
     * Ensure directory exists.
     */
    private function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new MachinjiriException(
                "Failed to create directory: {$directory}",
                91019
            );
        }
    }

    /**
     * Generate standard task template.
     */
    private function generateStandardTaskTemplate(string $name, string $cron): string
    {
        $lowerName = strtolower($name);
        $className = $name . 'Task';
        
        return <<<PHP
<?php

namespace Mlangeni\Machinjiri\App\Scheduler\Tasks;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Components\Task\ScheduledTask;

/**
 * {$name} Task
 *
 * This task handles {$lowerName} processing.
 */
class {$className} extends ScheduledTask
{
    protected string \$name = '{$name}';
    protected string \$cronExpression = '{$cron}';
    protected array \$options = [
        'maxAttempts' => 3,
        'timeout' => 60,
        'retryDelay' => 60,
        'retryStrategy' => 'exponential',
        'priority' => 0,
        'group' => 'default',
        'withoutOverlapping' => true,
        'runInMaintenanceMode' => false,
    ];

    /**
     * Create a new task instance.
     */
    public function __construct(Container \$app)
    {
        parent::__construct(\$app);
    }

    /**
     * Execute the task.
     */
    public function handle(): mixed
    {
        // TODO: Implement your task logic here
        
        // Example logic:
        // \$this->app->resolve(SomeService::class)->doSomething();
        
        return true;
    }
    
    /**
     * Handle task failure.
     */
    public function failed(\Throwable \$e): void
    {
        // Optional: Custom failure handling
        // Log the error, send notification, etc.
    }
}
PHP;
    }

    /**
     * Generate simple task template.
     */
    private function generateSimpleTaskTemplate(string $name, string $cron): string
    {
        $lowerName = strtolower($name);
        $className = $name . 'Task';
        
        return <<<PHP
<?php

namespace Mlangeni\Machinjiri\App\Scheduler\Tasks;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Components\Task\ScheduledTask;

/**
 * {$name} Task
 */
class {$className} extends ScheduledTask
{
    protected string \$cronExpression = '{$cron}';

    public function __construct(Container \$app)
    {
        parent::__construct(\$app);
        \$this->name = '{$name}';
    }

    public function handle(): mixed
    {
        // Simple task implementation
        return true;
    }
}
PHP;
    }

    /**
     * Generate advanced task template.
     */
    private function generateAdvancedTaskTemplate(string $name, string $cron): string
    {
        $lowerName = strtolower($name);
        $className = $name . 'Task';
        
        return <<<PHP
<?php

namespace Mlangeni\Machinjiri\App\Scheduler\Tasks;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Components\Task\ScheduledTask;
use Mlangeni\Machinjiri\Core\Artisans\Logging\LoggerFactory;

/**
 * {$name} Task
 *
 * This is an advanced task with custom configuration.
 */
class {$className} extends ScheduledTask
{
    protected string \$name = '{$name}';
    protected string \$cronExpression = '{$cron}';
    protected int \$priority = 0;
    protected string \$group = 'advanced';
    
    protected array \$options = [
        'maxAttempts' => 5,
        'timeout' => 300,
        'retryDelay' => 120,
        'retryStrategy' => 'exponential',
        'withoutOverlapping' => true,
        'runInMaintenanceMode' => false,
        'queue' => 'scheduler_high',
    ];

    public function __construct(Container \$app)
    {
        parent::__construct(\$app);
    }

    /**
     * Execute the task.
     */
    public function handle(): mixed
    {
        // Advanced task logic with logging
        \$logger = LoggerFactory::system('task_{$lowerName}', '{$lowerName}');
        \$logger->info("Task {$name} started");
        
        try {
            // Your complex logic here
            
            \$logger->info("Task {$name} completed successfully");
            return true;
        } catch (\Exception \$e) {
            \$logger->error("Task {$name} failed", [
                'error' => \$e->getMessage(),
                'trace' => \$e->getTraceAsString(),
            ]);
            throw \$e;
        }
    }
    
    /**
     * Handle task failure.
     */
    public function failed(\Throwable \$e): void
    {
        // Send notification, create ticket, etc.
        \$logger = LoggerFactory::system('task_{$lowerName}', '{$lowerName}');
        \$logger->critical("Task {$name} failed permanently", [
            'error' => \$e->getMessage(),
        ]);
    }
}
PHP;
    }

    /**
     * Register task in configuration.
     */
    private function registerTaskInConfig(string $name): void
    {
        $configFile = $this->configPath . 'tasks.php';
        
        // Create tasks config if it doesn't exist
        if (!file_exists($configFile)) {
            $this->createDefaultTasksConfig();
        }
        
        // Read current configuration
        $config = require $configFile;
        $className = "Mlangeni\\Machinjiri\\App\\Scheduler\\Tasks\\{$name}";

        // Add to registered tasks if not already present
        if (!isset($config['registered_tasks']) || !in_array($className, $config['registered_tasks'])) {
            $config['registered_tasks'][] = $className;
            
            $content = "<?php\n\nreturn " . var_export($config, true) . ";\n";
            
            if (file_put_contents($configFile, $content) === false) {
                throw new MachinjiriException(
                    "Failed to update tasks configuration: {$configFile}",
                    91012
                );
            }
        }
    }

    /**
     * Create default tasks configuration.
     */
    private function createDefaultTasksConfig(): void
    {
        $configFile = $this->configPath . 'tasks.php';
        $this->ensureDirectoryExists($this->configPath);
        
        $content = <<<'PHP'
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Task Scheduler Configuration
    |--------------------------------------------------------------------------
    */
    
    'default' => 'database',
    
    'drivers' => [
        'database' => [
            'table' => 'scheduled_tasks',
            'executions_table' => 'task_executions',
        ],
        'redis' => [
            'connection' => 'default',
            'prefix' => 'scheduler:',
        ],
    ],
    
    'registered_tasks' => [
        // Add task classes here or use --register when creating
    ],
    
    'defaults' => [
        'max_attempts' => 3,
        'timeout' => 60,
        'retry_delay' => 60,
        'queue' => 'scheduler',
        'retry_strategy' => 'exponential',
        'without_overlapping' => true,
    ],
    
    'lock_dir' => '/tmp/scheduler_locks',
];
PHP;

        if (file_put_contents($configFile, $content) === false) {
            throw new MachinjiriException(
                "Failed to create tasks configuration: {$configFile}",
                91013
            );
        }
    }
}