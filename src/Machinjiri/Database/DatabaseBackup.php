<?php

namespace Mlangeni\Machinjiri\Core\Database;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use MongoDB\Database as MongoDatabase;
use MongoDB\Driver\Command;
use MongoDB\BSON\UTCDateTime;
use \DateTime;

class DatabaseBackup
{
    protected array $config;
    protected $path = __DIR__ . "/../../../database/backups/";
    protected $connection;
    protected $backupPath;

    public function __construct(string $backupPath = null)
    {
        $container = new Container();
        $this->config = $container->getConfigurations()['database'];
        $this->backupPath = rtrim($path = ($backupPath === null) ? $this->path : $backupPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        
        // Set config for DatabaseConnection
        DatabaseConnection::setConfig($container->getConfigurations()['database']);
        $this->connection = DatabaseConnection::getInstance();
        
        if (!is_dir($this->backupPath)) {
            mkdir($this->backupPath, 0755, true);
        }
    }

    /**
     * Create a database backup
     */
    public function backup(string $backupName = null): string
    {
        $driver = $this->config['driver'];
        $backupName = $backupName ?: $this->generateBackupName($driver);
        $backupFile = $this->backupPath . $backupName;

        switch ($driver) {
            case 'mysql':
            case 'pgsql':
            case 'sqlite':
                $this->backupSqlDatabase($backupFile);
                break;
            case 'mongodb':
                $this->backupMongoDatabase($backupFile);
                break;
            default:
                throw new MachinjiriException("Backup not supported for driver: {$driver}");
        }

        return $backupFile;
    }

    /**
     * Restore a database backup
     */
    public function restore(string $backupFile): bool
    {
        if (!file_exists($backupFile)) {
            throw new MachinjiriException("Backup file not found: {$backupFile}");
        }

        $driver = $this->config['driver'];

        switch ($driver) {
            case 'mysql':
            case 'pgsql':
            case 'sqlite':
                return $this->restoreSqlDatabase($backupFile);
            case 'mongodb':
                return $this->restoreMongoDatabase($backupFile);
            default:
                throw new MachinjiriException("Restore not supported for driver: {$driver}");
        }
    }

    /**
     * Backup SQL database using QueryBuilder
     */
    protected function backupSqlDatabase(string $backupFile): void
    {
        $driver = $this->config['driver'];
        
        if ($driver === 'sqlite') {
            // For SQLite, simply copy the database file
            if (!copy($this->config['path'], $backupFile)) {
                throw new MachinjiriException("Failed to copy SQLite database file");
            }
            return;
        }

        // For MySQL and PostgreSQL, get all tables and export data
        $tables = $this->getAllTables();
        $backupContent = "";
        
        foreach ($tables as $table) {
            // Get table structure
            $createTable = $this->getCreateTableStatement($table);
            $backupContent .= $createTable . ";\n\n";
            
            // Get table data
            $data = (new QueryBuilder($table))->get();
            if (!empty($data)) {
                foreach ($data as $row) {
                    $columns = implode(', ', array_keys($row));
                    $values = implode(', ', array_map(function($value) {
                        return "'" . addslashes($value) . "'";
                    }, array_values($row)));
                    
                    $backupContent .= "INSERT INTO {$table} ({$columns}) VALUES ({$values});\n";
                }
                $backupContent .= "\n";
            }
        }
        
        // Write to backup file
        file_put_contents($backupFile, $backupContent);
    }

    /**
     * Restore SQL database using QueryBuilder
     */
    protected function restoreSqlDatabase(string $backupFile): bool
    {
        $driver = $this->config['driver'];
        
        if ($driver === 'sqlite') {
            // For SQLite, simply replace the database file
            if (!copy($backupFile, $this->config['path'])) {
                throw new MachinjiriException("Failed to restore SQLite database");
            }
            return true;
        }

        // For MySQL and PostgreSQL, execute SQL statements from backup file
        $sql = file_get_contents($backupFile);
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        
        try {
            DatabaseConnection::beginTransaction();
            
            foreach ($statements as $statement) {
                if (!empty($statement)) {
                    DatabaseConnection::executeQuery($statement);
                }
            }
            
            DatabaseConnection::commit();
            return true;
        } catch (\Exception $e) {
            DatabaseConnection::rollback();
            throw new MachinjiriException("Restore failed: " . $e->getMessage());
        }
    }

    /**
     * Get all tables in the database
     */
    protected function getAllTables(): array
    {
        $driver = $this->config['driver'];
        $tables = [];
        
        if ($driver === 'mysql') {
            $result = DatabaseConnection::executeQuery("SHOW TABLES")->fetchAll();
            $tables = array_map('current', $result);
        } elseif ($driver === 'pgsql') {
            $result = DatabaseConnection::executeQuery(
                "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'"
            )->fetchAll();
            $tables = array_column($result, 'table_name');
        }
        
        return $tables;
    }

    /**
     * Get CREATE TABLE statement for a table
     */
    protected function getCreateTableStatement(string $table): string
    {
        $driver = $this->config['driver'];
        
        if ($driver === 'mysql') {
            $result = DatabaseConnection::executeQuery("SHOW CREATE TABLE {$table}")->fetch();
            return $result['Create Table'] ?? '';
        } elseif ($driver === 'pgsql') {
            $result = DatabaseConnection::executeQuery(
                "SELECT pg_get_viewdef('{$table}'::regclass, true) as create_statement"
            )->fetch();
            return $result['create_statement'] ?? '';
        }
        
        return '';
    }

    /**
     * Backup MongoDB database using QueryBuilder
     */
    protected function backupMongoDatabase(string $backupDir): void
    {
        if (!$this->connection instanceof \MongoDB\Client) {
            throw new MachinjiriException("MongoDB connection not established");
        }

        // Get all collections
        $database = $this->connection->selectDatabase($this->config['dbname']);
        $collections = $database->listCollections();
        
        // Create backup directory
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        // Backup each collection
        foreach ($collections as $collection) {
            $collectionName = $collection->getName();
            $documents = $database->{$collectionName}->find();
            
            $backupContent = [];
            foreach ($documents as $document) {
                $backupContent[] = $document;
            }
            
            file_put_contents(
                $backupDir . '/' . $collectionName . '.json',
                json_encode($backupContent, JSON_PRETTY_PRINT)
            );
        }
    }

    /**
     * Restore MongoDB database using QueryBuilder
     */
    protected function restoreMongoDatabase(string $backupDir): bool
    {
        if (!$this->connection instanceof \MongoDB\Client) {
            throw new MachinjiriException("MongoDB connection not established");
        }

        $database = $this->connection->selectDatabase($this->config['dbname']);
        
        // Get all backup files
        $files = glob($backupDir . '/*.json');
        
        foreach ($files as $file) {
            $collectionName = basename($file, '.json');
            $data = json_decode(file_get_contents($file), true);
            
            if (is_array($data)) {
                $database->{$collectionName}->insertMany($data);
            }
        }
        
        return true;
    }

    /**
     * Generate backup filename with timestamp
     */
    protected function generateBackupName(string $driver): string
    {
        $date = date('Ymd_His');
        $dbName = $this->config['database'] ?? ($this->config['dbname'] ?? 'unknown');
        
        return sprintf(
            '%s_%s.%s',
            $dbName,
            $date,
            $this->getBackupExtension($driver)
        );
    }

    /**
     * Get file extension for backup based on driver
     */
    protected function getBackupExtension(string $driver): string
    {
        return match ($driver) {
            'mysql', 'pgsql' => 'sql',
            'sqlite' => 'sqlite',
            'mongodb' => 'mongodump',
            default => 'bak'
        };
    }

    /**
     * Get available backups sorted by date
     */
    public function getAvailableBackups(): array
    {
        $pattern = $this->backupPath . '*.' . $this->getBackupExtension($this->config['driver']);
        $files = glob($pattern);
        
        if ($files === false) {
            return [];
        }

        // Sort by modification time (newest first)
        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        return $files;
    }
}