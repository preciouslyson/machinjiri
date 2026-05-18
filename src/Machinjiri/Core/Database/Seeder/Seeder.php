<?php
namespace Mlangeni\Machinjiri\Core\Database\Seeder;

use Mlangeni\Machinjiri\Core\Database\DatabaseConnection;
use Mlangeni\Machinjiri\Core\Database\QueryBuilder;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

abstract class Seeder
{
    /**
     * Run the database seeds.
     */
    abstract public function run(): void;
    
    /**
     * Create a new query builder instance.
     */
    protected function table(string $table): QueryBuilder
    {
        return new QueryBuilder($table);
    }
    
    /**
     * Execute a raw SQL query.
     */
    protected function query(string $sql): void
    {
        DatabaseConnection::executeQuery($sql);
    }
    
    /**
     * Call another seeder class.
     */
    protected function call(string|array $seeders): void
    {
        if (is_string($seeders)) {
            $seeders = [$seeders];
        }
        
        foreach ($seeders as $seeder) {
            // Support both namespaces
            $fullClassName = $seeder;
            
            // If not fully qualified, try with our namespace
            if (strpos($seeder, '\\') === false) {
                $fullClassName = 'Mlangeni\Machinjiri\Database\Seeders\\' . $seeder;
                
                if (!class_exists($fullClassName)) {
                    $fullClassName = 'Database\\Seeders\\' . $seeder;
                }
            }
            
            if (!class_exists($fullClassName)) {
                throw new MachinjiriException("Seeder class '{$fullClassName}' does not exist.", 500);
            }
            
            $instance = new $fullClassName();
            if (!$instance instanceof self) {
                throw new MachinjiriException(
                    "Class '{$fullClassName}' must extend " . self::class,
                    500
                );
            }
            
            $instance->run();
        }
    }
    
    /**
     * Seed data with transaction support.
     */
    protected function seedWithTransaction(callable $callback): void
    {
        try {
            DatabaseConnection::beginTransaction();
            $callback();
            DatabaseConnection::commit();
        } catch (\Exception $e) {
            DatabaseConnection::rollback();
            throw new MachinjiriException(
                "Seeding failed: " . $e->getMessage(),
                500,
                $e
            );
        }
    }
    
    /**
     * Get the number of records in a table.
     */
    protected function count(string $table): int
    {
        $result = $this->table($table)
            ->select(['COUNT(*) as count'])
            ->first();
        
        return $result['count'] ?? 0;
    }
    
    /**
     * Disable foreign key checks.
     */
    protected function disableForeignKeyConstraints(): void
    {
        $driver = DatabaseConnection::getDriver();
        
        if ($driver === 'mysql') {
            $this->query("SET FOREIGN_KEY_CHECKS=0");
        } elseif ($driver === 'sqlite') {
            $this->query("PRAGMA foreign_keys = OFF");
        }
    }
    
    /**
     * Enable foreign key checks.
     */
    protected function enableForeignKeyConstraints(): void
    {
        $driver = DatabaseConnection::getDriver();
        
        if ($driver === 'mysql') {
            $this->query("SET FOREIGN_KEY_CHECKS=1");
        } elseif ($driver === 'sqlite') {
            $this->query("PRAGMA foreign_keys = ON");
        }
    }
}