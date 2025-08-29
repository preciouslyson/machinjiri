<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Helpers;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Process\TaskInterface;
use Mlangeni\Machinjiri\Core\Database\MigrationHandler;
use Mlangeni\Machinjiri\Core\Database\MigrationCreator;
use RuntimeException;

class Kabula
{
    protected static $migrationHandler;
    protected static $migrationCreator;

    /**
     * Get the migration handler instance.
     *
     * @return MigrationHandler
     */
    protected static function getMigrationHandler(): MigrationHandler
    {
        if (!self::$migrationHandler) {
            self::$migrationHandler = new MigrationHandler();
        }
        return self::$migrationHandler;
    }

    /**
     * Get the migration creator instance.
     *
     * @return MigrationCreator
     */
    protected static function getMigrationCreator(): MigrationCreator
    {
        if (!self::$migrationCreator) {
            self::$migrationCreator = new MigrationCreator();
        }
        return self::$migrationCreator;
    }

    /**
     * Create a new task template.
     *
     * @param string $name
     * @param string $path
     * @return bool
     */
    public static function createTask(string $name, string $path = null): bool
    {
        if (!$path) {
            $path = Container::$appBasePath . '/../app/Tasks/';
        }
        
        // Ensure directory exists
        if (!is_dir($path) && !mkdir($path, 0755, true)) {
            throw new RuntimeException("Cannot create directory: $path");
        }
        
        $className = ucfirst($name) . 'Task';
        $filePath = $path . $className . '.php';
        
        if (file_exists($filePath)) {
            throw new RuntimeException("Task file already exists: $filePath");
        }
        
        $template = self::generateTaskTemplate($className);
        
        return file_put_contents($filePath, $template) !== false;
    }
    
    /**
     * Generate task template content.
     *
     * @param string $className
     * @return string
     */
    private static function generateTaskTemplate(string $className): string
    {
        $namespace = 'Mlangeni\Machinjiri\App\Tasks';
        
        return <<<PHP
<?php

namespace $namespace;

use Mlangeni\Machinjiri\Core\Process\TaskInterface;

class $className implements TaskInterface
{
    /**
     * Execute the task.
     *
     * @return void
     */
    public function execute(): void
    {
        // Add your task logic here
        // This method will be called when the task is processed
        
        // Example:
        // \$this->sendEmail();
        // \$this->generateReport();
        // \$this->processData();
    }
    
    /**
     * Get the maximum number of attempts for this task.
     *
     * @return int
     */
    public function getMaxAttempts(): int
    {
        return 3; // Default to 3 attempts
    }
    
    // Add any additional methods your task needs
    // For example:
    
    // private function sendEmail(): void
    // {
    //     // Email sending logic
    // }
    
    // private function generateReport(): void
    // {
    //     // Report generation logic
    // }
    
    // private function processData(): void
    // {
    //     // Data processing logic
    // }
}
PHP;
    }
    
    /**
     * Create a worker script.
     *
     * @param string $path
     * @return bool
     */
    public static function createWorkerScript(string $path = null): bool
    {
        if (!$path) {
            $path = __DIR__ . '/../../../../app/';
        }
        
        $filePath = $path . 'worker.php';
        
        if (!file_exists($filePath)) {
          $template = self::generateWorkerScriptTemplate();
          return file_put_contents($filePath, $template) !== false;
        }
        
        return false;
        
    }
    
    /**
     * Generate worker script template.
     *
     * @return string
     */
    private static function generateWorkerScriptTemplate(): string
    {
        return <<<PHP
<?php

// Load composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

use Mlangeni\Machinjiri\Core\Process\Kameza;
use Mlangeni\Machinjiri\Core\Process\DatabaseQueueDriver;

// Set up the database queue driver
\$driver = new DatabaseQueueDriver();
Kameza::setDriver(\$driver);

// Process jobs indefinitely

while (true) {
    try {
        \$job = \$driver->pop();
        
        if (\$job) {
            
            try {
                Kameza::process(\$job['payload']);
                \$driver->delete(\$job['id']);
            } catch (Exception \$e) {
                // The job will be handled by Kameza::process which will move it to failed jobs
            }
        } else {
            sleep(5);
        }
    } catch (Exception \$e) {
        sleep(5); // Wait before continuing
    }
}
PHP;
    }
    
    /**
     * Create queue table migration using MigrationCreator.
     *
     * @param string $name
     * @param string $path
     * @return bool
     */
    public static function createQueueMigration(string $name = 'create_queue_tables', string $path = null): bool
    {
        try {
            $creator = self::getMigrationCreator();
            $filePath = $creator->create($name);
            
            // Overwrite with our custom migration content
            $content = self::generateQueueMigrationTemplate();
            return file_put_contents($filePath, $content) !== false;
        } catch (\Exception $e) {
            throw new RuntimeException("Failed to create queue migration: " . $e->getMessage());
        }
    }
    
    /**
     * Generate queue migration template.
     *
     * @return string
     */
    private static function generateQueueMigrationTemplate(): string
    {
        $timestamp = date('Y_m_d_His');
        $className = 'CreateQueueTables';
        
        return <<<PHP
<?php

use Mlangeni\\Machinjiri\\Core\\Database\\QueryBuilder;

class $className
{
    /**
     * Run the migration.
     *
     * @param QueryBuilder \$query
     * @return void
     */
    public function up(QueryBuilder \$query): void
    {
        // Create queue_jobs table
        \$query->createTable('queue_jobs', [
            'id' => \$query->string('id', 255)->primaryKey(),
            'queue' => \$query->string('queue', 255),
            'payload' => \$query->text('payload'),
            'attempts' => \$query->integer('attempts')->default(0),
            'reserved_at' => \$query->integer('reserved_at')->nullable(),
            'available_at' => \$query->integer('available_at'),
            'created_at' => \$query->integer('created_at')
        ])->execute();

        // Create failed_jobs table
        \$query->createTable('failed_jobs', [
            'id' => \$query->string('id', 255)->primaryKey(),
            'queue' => \$query->string('queue', 255),
            'payload' => \$query->text('payload'),
            'attempts' => \$query->integer('attempts')->default(0),
            'failed_at' => \$query->integer('failed_at')
        ])->execute();
        
    }
    
    /**
     * Reverse the migration.
     *
     * @param QueryBuilder \$query
     * @return void
     */
    public function down(QueryBuilder \$query): void
    {
        \$query->dropTable('queue_jobs')->execute();
        \$query->dropTable('failed_jobs')->execute();
        
    }
}
PHP;
    }
    
    /**
     * Run queue migrations using MigrationHandler.
     *
     * @return void
     */
    public static function runQueueMigrations(): void
    {
        try {
            $handler = self::getMigrationHandler();
            $handler->migrate();
        } catch (\Exception $e) {
            throw new RuntimeException("Failed to run queue migrations: " . $e->getMessage());
        }
    }
    
    /**
     * Rollback queue migrations using MigrationHandler.
     *
     * @return void
     */
    public static function rollbackQueueMigrations(): void
    {
        try {
            $handler = self::getMigrationHandler();
            $handler->rollback();
        } catch (\Exception $e) {
            throw new RuntimeException("Failed to rollback queue migrations: " . $e->getMessage());
        }
    }
    
    /**
     * Create a task factory template.
     *
     * @param string $name
     * @param string $path
     * @return bool
     */
    public static function createTaskFactory(string $name, string $path = null): bool
    {
        if (!$path) {
            $path = __DIR__ . '/../../../../app/Factories/';
        }
        
        // Ensure directory exists
        if (!is_dir($path) && !mkdir($path, 0755, true)) {
            throw new RuntimeException("Cannot create directory: $path");
        }
        
        $className = ucfirst($name) . 'TaskFactory';
        $filePath = $path . $className . '.php';
        
        if (file_exists($filePath)) {
            throw new RuntimeException("Factory file already exists: $filePath");
        }
        
        $template = self::generateTaskFactoryTemplate($className, $name);
        
        return file_put_contents($filePath, $template) !== false;
    }
    
    /**
     * Generate task factory template.
     *
     * @param string $className
     * @param string $taskName
     * @return string
     */
    private static function generateTaskFactoryTemplate(string $className, string $taskName): string
    {
        $namespace = 'Mlangeni\Machinjiri\Core\Factories';
        $taskClass = 'Mlangeni\Machinjiri\Core\Tasks\\' . ucfirst($taskName) . 'Task';
        
        return <<<PHP
<?php

namespace $namespace;

use $taskClass;

class $className
{
    /**
     * Create a new task instance.
     *
     * @param array \$parameters
     * @return $taskClass
     */
    public static function create(array \$parameters = []): $taskClass
    {
        // Example: Create task with parameters
        return new $taskClass(
            \$parameters['recipient'] ?? '',
            \$parameters['subject'] ?? '',
            \$parameters['message'] ?? ''
        );
        
        // Adjust based on your task constructor parameters
    }
    
    /**
     * Dispatch the task.
     *
     * @param array \$parameters
     * @param int \$delay
     * @return string
     */
    public static function dispatch(array \$parameters = [], int \$delay = 0): string
    {
        \$task = self::create(\$parameters);
        return \\Mlangeni\\Machinjiri\\Core\\Process\\Kameza::dispatch(\$task, \$delay);
    }
}
PHP;
    }
    
    /**
     * Generate a complete task with all related files.
     *
     * @param string $name
     * @return array
     */
    public static function scaffoldTask(string $name): array
    {
        $results = [];
        
        try {
            $results['task'] = self::createTask($name);
        } catch (\Exception $e) {
            $results['task_error'] = $e->getMessage();
        }
        
        try {
            $results['factory'] = self::createTaskFactory($name);
        } catch (\Exception $e) {
            $results['factory_error'] = $e->getMessage();
        }
        
        return $results;
    }
    
    /**
     * Setup the complete queue system.
     *
     * @return array
     */
    public static function setupQueueSystem(): array
    {
        $results = [];
        
        try {
            $results['migration'] = self::createQueueMigration();
        } catch (\Exception $e) {
            $results['migration_error'] = $e->getMessage();
        }
        
        try {
            self::runQueueMigrations();
            $results['migrate'] = true;
        } catch (\Exception $e) {
            $results['migrate_error'] = $e->getMessage();
        }
        
        try {
            $results['worker'] = self::createWorkerScript();
        } catch (\Exception $e) {
            $results['worker_error'] = $e->getMessage();
        }
        
        return $results;
    }
    
    /**
     * Get migration status.
     *
     * @return array
     */
    public static function getMigrationStatus(): array
    {
        try {
            $handler = self::getMigrationHandler();
            
            // Get ran migrations
            $ranMigrations = $handler->getRanMigrations();
            
            // Get all migration files
            $files = $handler->getMigrationFiles();
            
            $status = [];
            foreach ($files as $file) {
                $status[$file] = in_array($file, array_column($ranMigrations, 'migration')) ? 'ran' : 'pending';
            }
            
            return $status;
        } catch (\Exception $e) {
            throw new RuntimeException("Failed to get migration status: " . $e->getMessage());
        }
    }
    
    /**
     * Create a migration for a specific table.
     *
     * @param string $tableName
     * @param array $columns
     * @param string $migrationName
     * @return bool
     */
    public static function createTableMigration(string $tableName, array $columns, string $migrationName = null): bool
    {
        if (!$migrationName) {
            $migrationName = 'create_' . $tableName . '_table';
        }
        
        try {
            $creator = self::getMigrationCreator();
            $filePath = $creator->create($migrationName);
            
            // Generate migration content
            $content = self::generateTableMigrationTemplate($tableName, $columns, $migrationName);
            return file_put_contents($filePath, $content) !== false;
        } catch (\Exception $e) {
            throw new RuntimeException("Failed to create table migration: " . $e->getMessage());
        }
    }
    
    /**
     * Generate table migration template.
     *
     * @param string $tableName
     * @param array $columns
     * @param string $migrationName
     * @return string
     */
    private static function generateTableMigrationTemplate(string $tableName, array $columns, string $migrationName): string
    {
        $className = self::generateClassName($migrationName);
        
        $columnsCode = "[\n";
        foreach ($columns as $name => $definition) {
            $columnsCode .= "            '$name' => $definition,\n";
        }
        $columnsCode .= "        ]";
        
        return <<<PHP
<?php

use Mlangeni\\Machinjiri\\Core\\Database\\QueryBuilder;

class $className
{
    /**
     * Run the migration.
     *
     * @param QueryBuilder \$query
     * @return void
     */
    public function up(QueryBuilder \$query): void
    {
        \$query->createTable('$tableName', $columnsCode)->execute();
    }
    
    /**
     * Reverse the migration.
     *
     * @param QueryBuilder \$query
     * @return void
     */
    public function down(QueryBuilder \$query): void
    {
        \$query->dropTable('$tableName')->execute();
    }
}
PHP;
    }
    
    /**
     * Generate class name from migration name.
     *
     * @param string $name
     * @return string
     */
    private static function generateClassName(string $name): string
    {
        $parts = explode('_', $name);
        $className = implode('', array_map('ucfirst', $parts));
        
        // Ensure class name is valid
        if (!preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $className)) {
            throw new RuntimeException("Invalid migration name: $name");
        }
        
        return $className;
    }
}