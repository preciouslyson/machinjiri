<?php

namespace Mlangeni\Machinjiri\Core\Database\Migrations;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class MigrationCreator
{
    public string $migrationsPath;

    public function __construct()
    {
        $path = rtrim(Container::$appBasePath."/../database/migrations/", DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        
        if (!is_dir($path)) {
            $path = Container::$terminalBase . "database/migrations/";
        }
        
        @mkdir($path, 0777);
        
        $this->migrationsPath = $path;
    }

    /**
     * Create a new migration file
     */
    public function create(string $name, bool $useBluePrint = false): string
    {
        // Sanitize migration name
        $tableName = strtolower($name);
        $name = $this->sanitizeName($name);
        
        
        // Generate timestamp for filename
        $timestamp = date('Y_m_d_His');
        $filename = "{$timestamp}_{$name}.php";
        $filePath = $this->migrationsPath . $filename;
        
        // Generate class name from migration name
        $className = $this->generateClassName($name);
        
        // Create migration file content
        $content =  (!$useBluePrint) ? $this->generateStub($className, $tableName) : $this->generateBlueprintStub($className, $tableName);
        
        if (file_put_contents($filePath, $content) === false) {
            throw new MachinjiriException("Could not create migration file: {$filePath}");
        }
        
        return $filePath;
    }

    /**
     * Sanitize the migration name
     */
    protected function sanitizeName(string $name): string
    {
        // Remove non-alphanumeric characters except underscores
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
        
        // Replace multiple underscores with single
        $name = preg_replace('/_+/', '_', $name);
        
        // Trim underscores from beginning/end
        return trim($name, '_');
    }

    /**
     * Generate class name from migration name
     */
    protected function generateClassName(string $name): string
    {
        $parts = explode('_', $name);
        $className = implode('', array_map('ucfirst', $parts));
        
        // Ensure class name is valid
        if (!preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $className)) {
            throw new MachinjiriException("Invalid migration name: {$name}");
        }
        
        return $className;
    }

    /**
     * Generate migration file stub
     */
    protected function generateStub(string $className, string $table): string
    {
        return <<<STUB
<?php

use Mlangeni\\Machinjiri\\Core\\Database\\QueryBuilder;

class $className
{
    /**
     * Run the migration
     */
    public function up(QueryBuilder \$query): void
    {
        // Implement your migration here
        
        // Example:
        
        // \$query->createTable('$table', [
          // \$query->id()->autoIncrement()->primaryKey(),
          // \$query->string('column', 255)->notNull()
        // ])->execute();
        
    }

    /**
     * Reverse the migration
     */
    public function down(QueryBuilder \$query): void
    {
        // Implement rollback here
        // Example: 
        // \$query-->dropTable('$table')->execute();
    }
}
STUB;
    }
    
    public function getMigrationFiles () : array {
      return scandir($this->migrationsPath);
    }
    
    public function removeMigration (string $name) : bool {
      $path = $this->migrationsPath . $name = preg_match('/(.php)/', $name) ? $name : $name .".php";
      if (is_file($path)) {
        @unlink($path);
        return true;
      }
      return false;
    }
    
    /**
     * Generate migration file stub with Blueprint
     */
    protected function generateBlueprintStub(string $className, string $table): string
    { return <<<STUB
<?php

use Mlangeni\\Machinjiri\\Core\\Database\\QueryBuilder;
use Mlangeni\\Machinjiri\\Core\\Database\\Schema\\Blueprint;

class {$className}
{
    /**
     * Run the migration
     */
    public function up(QueryBuilder \$query): void
    {
        // Method 1: Using Blueprint directly
        \$blueprint = new Blueprint('$table', \$query);
        \$blueprint->id();
        // Add your columns here
        // \$blueprint->string('name')->notNull();
        \$blueprint->build();
        
        // Method 2: Using the helper method
        // \$query->createTableWithBlueprint('$table', function (Blueprint \$table) {
        //     \$table->id();
        //     \$table->string('name')->notNull();
        // });
    }

    /**
     * Reverse the migration
     */
    public function down(QueryBuilder \$query): void
    {
        \$query->dropTable('$table')->execute();
    }
}
STUB;
    }
    
    public function getFileName (string $filename): string 
    {
      $baseName = pathinfo($filename, PATHINFO_FILENAME);
      $parts = explode('_', $baseName);
      
      $nameParts = array_slice($parts, 4);
      
      $className = implode('', array_map(function ($part) {
          return ucfirst($part);
      }, $nameParts));
      
      return $className;
    }
    
}