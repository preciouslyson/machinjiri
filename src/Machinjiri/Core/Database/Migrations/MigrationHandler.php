<?php

namespace Mlangeni\Machinjiri\Core\Database\Migrations;
use Mlangeni\Machinjiri\Core\Database\QueryBuilder;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;

class MigrationHandler
{
    protected string $migrationsTable = 'migrations';
    protected string $migrationsPath;
    protected QueryBuilder $queryBuilder;
    protected Logger $logger;
    public array $errors = [];

    public function __construct(
        ?Container $container = null,
        ?\PDO $connection = null
    )
    {
        $this->logger = new Logger('migrations');

        $container = $container ?? (Container::instancePresent()) ? Container::getInstance() : null;

        $this->migrationsPath = rtrim($container->migrations, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        // If a PDO connection is provided, create a QueryBuilder bound to it.
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
        $queryBuilder = $this->queryBuilder;
        $queryBuilder->createTable($this->migrationsTable, [
          $queryBuilder->string('migration')->primaryKey()->notNull(),
          $queryBuilder->integer('batch')->notNull(),
          $queryBuilder->timestamp('created_at')->default('CURRENT_TIMESTAMP')
          ])->execute();
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
     * Returns ran migrations count
     * @return int 
     */
    public function migrate(): array
    {
        $ranMigrations = array_column($this->getRanMigrations(), 'migration');
        $files = $this->getMigrationFiles();
        $batch = $this->getNextBatchNumber();

        // Sort migrations by filename
        sort($files);
        $countRan = 0;
        $countFailed = 0;
        foreach ($files as $file) {
            if (in_array($file, $ranMigrations)) {
                continue;
            }
            try {
                $this->runMigration($file, $batch);
                if (isset($this->errors['run-errors'])) {
                    $countFailed++;
                } else {
                    $countRan++;
                }
            } catch (MachinjiriException $e) {
                $this->logger->error("Unable to run migration \n{migration}\n{batch}\n{error}", [
                    "migration" => $file,
                    "batch" => $batch,
                    "error" => $e->getMessage()
                ]);
                $this->clearErrors();
            }
        }
        return ['successfull' => $countRan, 'failed' => $countFailed];
    }

    /**
     * Execute a specific migration
     */
    public function runMigration(string $migrationFile, int $batch): void
    {
        $path = $this->migrationsPath . $migrationFile;
        if (!is_file($path)) throw new MachinjiriException("Unable to locate migration file in: " . $path);
        
        require_once $path;
        $className = $this->getMigrationClassName($path);

        try {
            $migration = new $className();
            $result = $migration->up();

            // Record migration
            $this->queryBuilder
                ->insert([
                    'migration' => $migrationFile,
                    'batch' => $batch
                ])
                ->execute();
        } catch (MachinjiriException $e) {
            $this->logger->debug($e->getMessage());
            $this->errors['run-errors'] = [
                "migration" => $migrationFile,
                "batch" => $batch,
                "error" => $e->getMessage(),
                "line" => $e->getLine()
            ];
        }
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
        $migration->down(new QueryBuilder(''));

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
    
    public function migrateFiles(array $files): void
    {
        $batch = $this->getNextBatchNumber();
        sort($files);

        foreach ($files as $file) {
            $this->runMigration($file, $batch);
        }
    }

    private function clearErrors (): void 
    {
        $this->errors = [];
    }
    
}