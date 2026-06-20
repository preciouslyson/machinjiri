<?php

namespace Mlangeni\Machinjiri\Core\Database\Migrations;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;

class MigrationCreator
{
    public string $migrationsPath;
    protected Logger $logger;

    public function __construct(?string $customPath = null)
    {
        $this->logger = new Logger('migrations');

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
     * Create a new migration file using the Blueprint schema builder.
     */
    public function create(string $name): string
    {
        $tableName = strtolower($name);
        $name = $this->sanitizeName($name);

        $timestamp = date('Y_m_d_His');
        $filename = "{$timestamp}_{$name}.php";
        $filePath = $this->migrationsPath . $filename;

        $className = $this->generateClassName($name);

        $content = $this->generateStub($className, $tableName);

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
     * Generate migration file stub using Blueprint.
     */
    protected function generateStub(string $className, string $table): string
    {
        return <<<STUB
<?php

use Mlangeni\\Machinjiri\\Core\\Database\\Schema\\Blueprint;

class {$className}
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        // Create a new Blueprint instance for the table.
        \$blueprint = new Blueprint('$table');
        
        // Define your table columns here.
        \$blueprint->id();
        // \$blueprint->string('name')->notNull();
        // \$blueprint->timestamps();
        // \$blueprint->softDeletes();
        
        // (Optional) Add indexes, foreign keys, or table options.
        // \$blueprint->unique('email');
        // \$blueprint->foreign('user_id')->references('id')->on('users');
        // \$blueprint->engine('InnoDB');
        
        // Execute the migration.
        \$blueprint->setAction('create')->build();
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        // Drop the table.
        (new Blueprint('user_roles'))
            ->setAction('drop')
            ->build();
    }
}
STUB;
    }

    /**
     * Get all migration file names in the migration directory.
     */
    public function getMigrationFiles(): array
    {
        $files = scandir($this->migrationsPath);
        return $files ? array_filter($files, function ($file) {
            return pathinfo($file, PATHINFO_EXTENSION) === 'php';
        }) : [];
    }

    /**
     * Remove a migration file by name or full filename.
     */
    public function removeMigration(string $name): bool
    {
        // If name doesn't end with .php, append it.
        $path = $this->migrationsPath . (str_ends_with($name, '.php') ? $name : $name . '.php');
        if (is_file($path)) {
            @unlink($path);
            return true;
        }
        return false;
    }

    /**
     * Extract the class name from a migration filename.
     */
    public function getFileName(string $filename): string
    {
        $baseName = pathinfo($filename, PATHINFO_FILENAME);
        $parts = explode('_', $baseName);
        
        // The class name is after the timestamp (first 4 parts).
        $nameParts = array_slice($parts, 4);
        
        $className = implode('', array_map(function ($part) {
            return ucfirst($part);
        }, $nameParts));
        
        return $className;
    }
}