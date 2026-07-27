<?php

namespace Mlangeni\Machinjiri\Core\Database\Migrations;
use Mlangeni\Machinjiri\Core\Database\Builders\QueryBuilder;
use Mlangeni\Machinjiri\Core\Database\Schema\Blueprint;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Artisans\Logging\LoggerFactory;

class MigrationHandler
{
    protected string $migrationsTable = 'migrations';
    protected string $migrationsPath;
    protected QueryBuilder $queryBuilder;
    protected Logger $logger;

    public function __construct(Container $container, ?\PDO $connection = null)
    {
        $this->logger = LoggerFactory::system("migration-handler", "database", false);

        $path = $container->getRootPath() . "database/migrations/";
        if (!is_dir($path)) {
            throw new MachinjiriException("Migrations directory does not exist: {$path}");
        }
        $this->migrationsPath = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        // If a PDO connection is provided, bind the migration builders to it.
        // Otherwise, QueryBuilder will use its default connection (from global config).
        $this->queryBuilder = $connection
            ? new QueryBuilder($this->migrationsTable, $connection)
            : new QueryBuilder($this->migrationsTable);

        $this->createMigrationsTable();
    }

    /**
     * Create migrations table if it doesn't exist
     */
    protected function createMigrationsTable(): void
    {
                $blueprint = new Blueprint($this->migrationsTable, $this->queryBuilder);
                $blueprint->string('migration')->primaryKey()->notNull();
                $blueprint->integer('batch')->notNull();
                $blueprint->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $blueprint->build();
    }

    /**
     * Get all migrations that have been run
     */
    public function getRanMigrations(bool $all = false): array
    {
        if ($all) {
          return $this->queryBuilder
            ->select()
            ->orderBy('batch', 'asc')
            ->orderBy('migration', 'asc')
            ->get();
        }
        return $this->queryBuilder
            ->select(['migration'])
            ->orderBy('batch', 'asc')
            ->orderBy('migration', 'asc')
            ->get();
    }

    /**
     * Get all migration files from the migrations path
     */
    public function getMigrationFiles(): array
    {
        $files = glob($this->migrationsPath . '*.php');
        
        if ($files === false) {
            throw new MachinjiriException("Could not read migration directory");
        }

        return array_map('basename', $files);
    }

    /**
     * Get the next batch number for migrations
     */
    protected function getNextBatchNumber(): int
    {
        $lastBatch = $this->queryBuilder
            ->select(['batch'])
            ->orderBy('batch', 'desc')
            ->limit(1)
            ->first();

        return ($lastBatch ? $lastBatch['batch'] : 0) + 1;
    }

    /**
     * Run all pending migrations
     */
    public function migrate(): void
    {
        $ranMigrations = array_column($this->getRanMigrations(), 'migration');
        $files = $this->getMigrationFiles();
        $batch = $this->getNextBatchNumber();

        // Sort migrations by filename
        sort($files);

        foreach ($files as $file) {
            if (in_array($file, $ranMigrations)) {
                continue;
            }

            $this->runMigration($file, $batch);
        }
    }

    /**
     * Execute a specific migration
     */
    protected function runMigration(string $migrationFile, int $batch): void
    {
        require_once $this->migrationsPath . $migrationFile;

        $className = $this->getMigrationClassName($migrationFile);
        $migration = new $className();

        // Run migration
        $migration->up(new Blueprint($this->getMigrationTableName($migrationFile), $this->queryBuilder));

        // Record migration
        $this->queryBuilder
            ->insert([
                'migration' => $migrationFile,
                'batch' => $batch
            ])
            ->execute();
    }

    /**
     * Rollback the last batch of migrations
     */
    public function rollback(): void
    {
        $batch = $this->queryBuilder
            ->select(['batch'])
            ->orderBy('batch', 'desc')
            ->limit(1)
            ->first();

        if (!$batch) {
            return;
        }

        $migrations = $this->queryBuilder
            ->select(['migration'])
            ->where('batch', '=', $batch['batch'])
            ->orderBy('migration', 'desc')
            ->get();

        foreach ($migrations as $migration) {
            $this->rollbackMigration($migration['migration']);
        }
    }

    /**
     * Rollback a specific migration
     */
    protected function rollbackMigration(string $migrationFile): void
    {
        require_once $this->migrationsPath . $migrationFile;

        $className = $this->getMigrationClassName($migrationFile);
        $migration = new $className();

        // Rollback migration
        $migration->down(new Blueprint($this->getMigrationTableName($migrationFile), $this->queryBuilder));

        // Remove migration record
        $this->queryBuilder
            ->where('migration', '=', $migrationFile)
            ->delete()
            ->execute();
    }

    /**
     * Get class name from migration filename
     */
    public function getMigrationClassName(string $filename): string
    {
        $baseName = pathinfo($filename, PATHINFO_FILENAME);
        $parts = explode('_', $baseName);
        
        // Skip the first 4 timestamp parts (e.g., 0001_01_01_000000)
        $nameParts = array_slice($parts, 4);
        
        $className = implode('', array_map(function ($part) {
            return ucfirst($part);
        }, $nameParts));
        
        if (!class_exists($className)) {
            throw new MachinjiriException("Migration class {$className} not found");
        }
        
        return $className;
    }

    /**
     * Get the table name encoded in a migration filename.
     */
    protected function getMigrationTableName(string $filename): string
    {
        $baseName = pathinfo($filename, PATHINFO_FILENAME);
        $parts = explode('_', $baseName);

        return strtolower(implode('_', array_slice($parts, 4)));
    }
    
    public function migrateFiles(array $files): void
    {
        $batch = $this->getNextBatchNumber();
        sort($files);

        foreach ($files as $file) {
            $this->runMigration($file, $batch);
        }
    }
    
}