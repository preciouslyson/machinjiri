<?php

namespace Mlangeni\Machinjiri\Core\Database\Migrations;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Artisans\Logging\LoggerFactory;

class MigrationCreator
{
    public string $migrationsPath;
    protected Logger $logger;

    public function __construct(?string $customPath = null)
    {
        $this->logger = LoggerFactory::system("migration-creator", "database", false);

        if ($customPath) {
            $this->migrationsPath = rtrim($customPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            @mkdir($this->migrationsPath, 0777, true);
            return;
        }

        $path = rtrim(Container::$appBasePath . "/../database/migrations/", DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!is_dir($path)) {
            $path = Container::$terminalBase . "database/migrations/";
        }
        @mkdir($path, 0777, true);
        $this->migrationsPath = $path;
    }

    /**
     * Create a new migration file
     */
    public function create(string $name, bool $useBluePrint = false): string
    {
        $tableName = strtolower($name);
        $name = $this->sanitizeName($name);

        $timestamp = date('Y_m_d_His');
        $filename = "{$timestamp}_{$name}.php";
        $filePath = $this->migrationsPath . $filename;

        $className = $this->generateClassName($name);

        $content = !$useBluePrint
            ? $this->generateStub($className, $tableName)
            : $this->generateBlueprintStub($className, $tableName);

        if (file_put_contents($filePath, $content) === false) {
            $this->logger->error('Failed to create migration file', ['path' => $filePath]);
            throw new MachinjiriException("Could not create migration file: {$filePath}");
        }

        $this->logger->info('Migration file created', ['file' => $filename]);
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

use Mlangeni\\Machinjiri\\Core\\Database\\Schema\\Blueprint;

class $className
{
    /**
     * Run the migration
     */
    public function up(Blueprint \$blueprint): void
    {
        // Add your columns here
        \$blueprint->id();
        // \$blueprint->string('column')->notNull();
        \$blueprint->build();
        
    }

    /**
     * Reverse the migration
     */
    public function down(Blueprint \$blueprint): void
    {
        \$blueprint->setAction('drop')->build();
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

use Mlangeni\\Machinjiri\\Core\\Database\\Schema\\Blueprint;

class {$className}
{
    /**
     * Run the migration
     */
    public function up(Blueprint \$blueprint): void
    {
        \$blueprint->id();
        // Add your columns here
        // \$blueprint->string('name')->notNull();
        \$blueprint->build();
    }

    /**
     * Reverse the migration
     */
    public function down(Blueprint \$blueprint): void
    {
        \$blueprint->setAction('drop')->build();
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