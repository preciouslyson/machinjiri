<?php

namespace Mlangeni\Machinjiri\Core\Database\Seeder;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Database\DatabaseConnection;
use ReflectionClass;

class SeederManager
{
    protected Container $container;
    protected string $seedersPath;
    protected string $seedersAltPath;
    protected array $runSeeders = [];
    
    public bool $created = false;
    
    public function __construct(Container $container)
    {
        $this->container = $container;
        
        // Get seeders path from container
        $reflection = new ReflectionClass($container);
        $seedersProperty = $reflection->getProperty('seeders');
        $seedersProperty->setAccessible(true);
        $this->seedersPath = Container::$appBasePath . '/database/seeders/';
        $this->seedersAltPath = Container::$appBasePath . '/../database/seeders/';
        
        // Ensure directory exists
        if (!is_dir($this->seedersPath)) {
            mkdir($this->seedersPath, 0755, true);
        }
    }
    
    /**
     * Create a new seeder file with Mlangeni\Machinjiri\Database namespace
     */
    public function make(string $name, bool $overwrite = false): string
    {
        $filename = $this->getSeederFileName($name);
        $dir = is_dir($this->seedersPath) ? $this->seedersPath : $this->seedersAltPath;
        $fullPath = $dir . $filename;
        
        // Check if file already exists
        if (file_exists($fullPath) && !$overwrite) {
            throw new MachinjiriException(
                "Seeder file '{$filename}' already exists. Use --force to overwrite.",
                500
            );
        }
        
        $className = $this->getClassName($name);
        $tableName = $this->getTableName($name);
        $namespace = $this->getSeederNamespace();
        
        $stub = $this->getStubContent('seeder');
        
        $content = str_replace(
            ['{{Namespace}}', '{{ClassName}}', '{{TableName}}'],
            [$namespace, $className, $tableName],
            $stub
        );
        
        if (file_put_contents($fullPath, $content) === false) {
            throw new MachinjiriException("Failed to create seeder file: {$fullPath}", 500);
        }
        $this->created = true;
        return $fullPath;
    }
    
    /**
     * Run a specific seeder
     */
    public function run(string $name): array
    {
        $fullPath = $this->seedersAltPath . $name;
        
        if (!is_file($fullPath)) {
            throw new MachinjiriException("Seeder file not found: {$this->seedersPath}", 404);
        }
        
        require_once $fullPath;
        
        $className = $this->getClassName($name);
        $realClassName = $this->getRealClassName($className);
        $namespace = $this->getSeederNamespace();
        
        $fullClassName = $namespace . "\\" . $realClassName;
        
        if (!class_exists($fullClassName)) {
          throw new MachinjiriException("Seeder class '{$fullClassName}' not found in file.", 500);
        }
        
        $seeder = new $fullClassName();
        
        if (!$seeder instanceof Seeder) {
            throw new MachinjiriException(
                "Seeder must extend Mlangeni\Machinjiri\Core\Database\Seeder\Seeder",
                500
            );
        }
        
        $startTime = microtime(true);
        $seeder->run();
        $endTime = microtime(true);
        
        $this->runSeeders[] = [
            'name' => $name,
            'class' => $fullClassName,
            'time' => round($endTime - $startTime, 4)
        ];
        
        return [
            'seeder' => $fullClassName,
            'status' => 'success',
            'time' => round($endTime - $startTime, 4)
        ];
    }
    
    protected function getRealClassName (string $className): string
    {
      $splt = explode('.', $className);
      return substr($splt[0], 14, strlen($splt[0]));
    }
    
    /**
     * Run all seeders
     */
    public function runAll(): array
    {
        $files = glob($this->seedersAltPath . '*.php');
        
        $results = [];
        
        // Sort by timestamp if using timestamp format
        usort($files, function($a, $b) {
            return strcmp(basename($a), basename($b));
        });
        
        foreach ($files as $file) {
            $name = basename($file);
            try {
                $result = $this->run($name);
                $results[] = $result;
            } catch (MachinjiriException $e) {
                $results[] = [
                    'seeder' => $name,
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Run seeders in a transaction
     */
    public function runAllInTransaction(): array
    {
        try {
            DatabaseConnection::beginTransaction();
            
            $results = $this->runAll();
            
            // Check for errors
            $errors = array_filter($results, fn($r) => ($r['status'] ?? '') === 'error');
            
            if (empty($errors)) {
                DatabaseConnection::commit();
                return $results;
            } else {
                DatabaseConnection::rollback();
                throw new MachinjiriException(
                    "Seeders failed: " . count($errors) . " errors. Transaction rolled back.",
                    500
                );
            }
        } catch (\Exception $e) {
            DatabaseConnection::rollback();
            throw new MachinjiriException(
                "Transaction failed: " . $e->getMessage(),
                500,
                $e
            );
        }
    }
    
    /**
     * Refresh database (truncate and re-seed)
     */
    public function refresh(array $tables = [], array $seeders = []): array
    {
        if (empty($tables)) {
            $tables = $this->getAllTables();
        }
        
        if (empty($seeders)) {
            $seeders = $this->getAllSeederNames();
        }
        
        $results = [];
        
        // Disable foreign key checks
        $this->disableForeignKeyConstraints();
        
        try {
            // Truncate tables
            foreach ($tables as $table) {
                try {
                    $this->truncateTable($table);
                    $results['truncated'][] = $table;
                } catch (\Exception $e) {
                    $results['truncate_errors'][] = [
                        'table' => $table,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            // Re-enable foreign key checks
            $this->enableForeignKeyConstraints();
            
            // Run seeders
            $seedResults = [];
            foreach ($seeders as $seeder) {
                $seedResults[] = $this->run($seeder);
            }
            
            $results['seeders'] = $seedResults;
            
        } catch (\Exception $e) {
            $this->enableForeignKeyConstraints();
            throw $e;
        }
        
        return $results;
    }
    
    /**
     * List all available seeders
     */
    public function list(): array
    {
        $files = glob($this->seedersPath . '*.php');
        $seeders = [];
        
        foreach ($files as $file) {
            $filename = basename($file, '.php');
            $name = preg_replace('/^\d+_/', '', $filename);
            
            $seeders[] = [
                'file' => $filename,
                'name' => $name,
                'path' => $file,
                'class' => $this->getClassName($name),
                'full_class' => $this->getSeederNamespace() . $this->getClassName($name)
            ];
        }
        
        return $seeders;
    }
    
    /**
     * Get stub content for seeder with Mlangeni\Machinjiri\Database namespace
     */
    protected function getStubContent(string $type): string
    {
        $stubPath = __DIR__ . '/stubs/' . $type . '.stub';
        
        if (file_exists($stubPath)) {
            $content = file_get_contents($stubPath);
            // Replace namespace placeholder with correct namespace
            $content = str_replace('{{Namespace}}', $this->getSeederNamespace(), $content);
            return $content;
        }
        
        // Default stub content with Mlangeni\Machinjiri\Database namespace
        return <<<'EOD'
<?php

namespace {{Namespace}};

use Mlangeni\Machinjiri\Core\Database\Seeder\Seeder;

class {{ClassName}} extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Example: Insert data into {{TableName}} table
        // $this->table('{{TableName}}')->insert([
        //     'name' => 'Example',
        //     'created_at' => date('Y-m-d H:i:s'),
        //     'updated_at' => date('Y-m-d H:i:s'),
        // ])->execute();
        
        // Or use factory:
        // $factory = new \Mlangeni\Machinjiri\Core\Database\Factory\Factory();
        // $factory::raw('{{TableName}}', [
        //     'name' => 'Example',
        //     'created_at' => date('Y-m-d H:i:s'),
        // ]);
    }
}
EOD;
    }
    
    /**
     * Helper methods
     */
    protected function getSeederFileName(string $name): string
    {
        $timestamp = date('Y_m_d_His');
        return $timestamp . '_' . $this->getTableName($name) . '_seeder.php';
    }
    
    protected function getClassName(string $name): string
    {
        return ucfirst($this->camelCase($this->getTableName($name))) . 'Seeder';
    }
    
    protected function getTableName(string $name): string
    {
        // Convert from ClassName to table_name
        $name = preg_replace('/[Ss]eeder$/', '', $name);
        return $this->snakeCase($name);
    }
    
    /**
     * Get seeder namespace: Mlangeni\Machinjiri\Database\Seeders
     */
    protected function getSeederNamespace(): string
    {
        return 'Mlangeni\Machinjiri\Database\Seeders';
    }
    
    protected function getAllTables(): array
    {
        $driver = DatabaseConnection::getDriver();
        $tables = [];
        
        try {
            if ($driver === 'mysql') {
                $result = DatabaseConnection::executeQuery("SHOW TABLES");
                $tables = array_column($result->fetchAll(), 0);
            } elseif ($driver === 'sqlite') {
                $result = DatabaseConnection::executeQuery(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name != 'sqlite_sequence'"
                );
                $tables = array_column($result->fetchAll(), 'name');
            } elseif ($driver === 'pgsql') {
                $result = DatabaseConnection::executeQuery(
                    "SELECT tablename FROM pg_tables WHERE schemaname = 'public'"
                );
                $tables = array_column($result->fetchAll(), 'tablename');
            }
        } catch (\Exception $e) {
            // Silently fail and return empty array
        }
        
        return $tables;
    }
    
    protected function getAllSeederNames(): array
    {
        $files = glob($this->seedersPath . '*.php');
        $names = [];
        
        foreach ($files as $file) {
            $filename = basename($file, '.php');
            $name = preg_replace('/^\d+_/', '', $filename);
            $name = preg_replace('/_seeder$/i', '', $name);
            $names[] = $name;
        }
        
        return $names;
    }
    
    protected function truncateTable(string $table): void
    {
        $driver = DatabaseConnection::getDriver();
        
        if ($driver === 'mysql') {
            DatabaseConnection::executeQuery("TRUNCATE TABLE `{$table}`");
        } elseif ($driver === 'sqlite') {
            DatabaseConnection::executeQuery("DELETE FROM `{$table}`");
            DatabaseConnection::executeQuery("DELETE FROM sqlite_sequence WHERE name='{$table}'");
        } elseif ($driver === 'pgsql') {
            DatabaseConnection::executeQuery("TRUNCATE TABLE \"{$table}\" RESTART IDENTITY CASCADE");
        } else {
            DatabaseConnection::executeQuery("DELETE FROM {$table}");
        }
    }
    
    protected function disableForeignKeyConstraints(): void
    {
        $driver = DatabaseConnection::getDriver();
        
        if ($driver === 'mysql') {
            DatabaseConnection::executeQuery("SET FOREIGN_KEY_CHECKS = 0");
        } elseif ($driver === 'sqlite') {
            DatabaseConnection::executeQuery("PRAGMA foreign_keys = OFF");
        } elseif ($driver === 'pgsql') {
            // PostgreSQL doesn't support disabling foreign keys in the same way
            // We'll handle this with transaction deferral
        }
    }
    
    protected function enableForeignKeyConstraints(): void
    {
        $driver = DatabaseConnection::getDriver();
        
        if ($driver === 'mysql') {
            DatabaseConnection::executeQuery("SET FOREIGN_KEY_CHECKS = 1");
        } elseif ($driver === 'sqlite') {
            DatabaseConnection::executeQuery("PRAGMA foreign_keys = ON");
        }
    }
    
    /**
     * String helper methods
     */
    protected function camelCase(string $value): string
    {
        $value = ucwords(str_replace(['-', '_'], ' ', $value));
        $value = str_replace(' ', '', $value);
        return lcfirst($value);
    }
    
    protected function snakeCase(string $value): string
    {
        $value = preg_replace('/(?<=\\w)(?=[A-Z])/', '_$1', $value);
        return strtolower($value);
    }
    
    /**
     * Get statistics about run seeders
     */
    public function getStatistics(): array
    {
        return [
            'total_run' => count($this->runSeeders),
            'seeders' => $this->runSeeders,
            'path' => $this->seedersPath,
            'namespace' => $this->getSeederNamespace(),
            'exists' => is_dir($this->seedersPath)
        ];
    }
    
    /**
     * Create multiple seeder files at once
     */
    public function makeMultiple(array $names, bool $overwrite = false): array
    {
        $results = [];
        
        foreach ($names as $name) {
            try {
                $path = $this->make($name, $overwrite);
                $results[$name] = [
                    'status' => 'created',
                    'path' => $path,
                    'class' => $this->getClassName($name),
                    'namespace' => $this->getSeederNamespace()
                ];
            } catch (MachinjiriException $e) {
                $results[$name] = [
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Register seeder namespace with autoloader
     */
    public function registerAutoload(): void
    {
        $namespace = rtrim($this->getSeederNamespace(), '\\');
        $path = $this->seedersPath;
        
        spl_autoload_register(function ($class) use ($namespace, $path) {
            // Check if the class is in our namespace
            if (strpos($class, $namespace) === 0) {
                // Remove namespace prefix
                $relativeClass = substr($class, strlen($namespace));
                
                // Convert namespace separators to directory separators
                $relativePath = str_replace('\\', '/', $relativeClass);
                
                // Build the full path
                $file = $path . $relativePath . '.php';
                
                // If the file exists, require it
                if (file_exists($file)) {
                    require_once $file;
                }
            }
        });
    }
    
    private function load (string $path): array 
    {
      $files = [];
      $validate = function ($file) {
        $c = explode(".", $file);$ext = end($c);
        if ($ext === "php") {
          return true;
        }
        return false;
      };
      
      foreach (scandir($path) as $seeders) {
        if ($validate($seeders)) {
          $files[] = $seeders;
        }
      }
      
      return $files;
    }
}