<?php

namespace Mlangeni\Machinjiri\Core\Database;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use MongoDB\Database as MongoDatabase;
use MongoDB\Driver\Command;
use MongoDB\BSON\UTCDateTime;
use \DateTime;

class DatabaseBackup
{
    protected array $config;
    protected string $backupPath;
    protected $connection;

    public function __construct(array $config, string $backupPath)
    {
        $this->config = $config;
        $this->backupPath = rtrim($backupPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $this->connection = DatabaseConnection::getInstance();
        
        if (!is_dir($this->backupPath) {
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
     * Backup SQL database
     */
    protected function backupSqlDatabase(string $backupFile): void
    {
        $driver = $this->config['driver'];
        $command = '';

        switch ($driver) {
            case 'mysql':
                $command = sprintf(
                    'mysqldump -h %s -P %d -u %s -p%s %s > %s',
                    escapeshellarg($this->config['host']),
                    $this->config['port'] ?? 3306,
                    escapeshellarg($this->config['username']),
                    escapeshellarg($this->config['password']),
                    escapeshellarg($this->config['database']),
                    escapeshellarg($backupFile)
                );
                break;
            case 'pgsql':
                putenv("PGPASSWORD=" . $this->config['password']);
                $command = sprintf(
                    'pg_dump -h %s -p %d -U %s -Fc %s > %s',
                    escapeshellarg($this->config['host']),
                    $this->config['port'] ?? 5432,
                    escapeshellarg($this->config['username']),
                    escapeshellarg($this->config['database']),
                    escapeshellarg($backupFile)
                );
                break;
            case 'sqlite':
                if (!copy($this->config['path'], $backupFile)) {
                    throw new MachinjiriException("Failed to copy SQLite database file");
                }
                return;
        }

        $this->executeCommand($command);
    }

    /**
     * Restore SQL database
     */
    protected function restoreSqlDatabase(string $backupFile): bool
    {
        $driver = $this->config['driver'];
        $command = '';

        switch ($driver) {
            case 'mysql':
                $command = sprintf(
                    'mysql -h %s -P %d -u %s -p%s %s < %s',
                    escapeshellarg($this->config['host']),
                    $this->config['port'] ?? 3306,
                    escapeshellarg($this->config['username']),
                    escapeshellarg($this->config['password']),
                    escapeshellarg($this->config['database']),
                    escapeshellarg($backupFile)
                );
                break;
            case 'pgsql':
                putenv("PGPASSWORD=" . $this->config['password']);
                $command = sprintf(
                    'pg_restore -h %s -p %d -U %s -d %s %s',
                    escapeshellarg($this->config['host']),
                    $this->config['port'] ?? 5432,
                    escapeshellarg($this->config['username']),
                    escapeshellarg($this->config['database']),
                    escapeshellarg($backupFile)
                );
                break;
            case 'sqlite':
                if (!copy($backupFile, $this->config['path'])) {
                    throw new MachinjiriException("Failed to restore SQLite database");
                }
                return true;
        }

        $this->executeCommand($command);
        return true;
    }

    /**
     * Backup MongoDB database
     */
    protected function backupMongoDatabase(string $backupDir): void
    {
        if (!$this->connection instanceof \MongoDB\Client) {
            throw new MachinjiriException("MongoDB connection not established");
        }

        $command = sprintf(
            'mongodump --host %s --port %d --username %s --password %s --db %s --out %s',
            escapeshellarg($this->config['host'] ?? 'localhost'),
            $this->config['port'] ?? 27017,
            escapeshellarg($this->config['username'] ?? ''),
            escapeshellarg($this->config['password'] ?? ''),
            escapeshellarg($this->config['dbname']),
            escapeshellarg($backupDir)
        );

        $this->executeCommand($command);
    }

    /**
     * Restore MongoDB database
     */
    protected function restoreMongoDatabase(string $backupDir): bool
    {
        if (!$this->connection instanceof \MongoDB\Client) {
            throw new MachinjiriException("MongoDB connection not established");
        }

        $command = sprintf(
            'mongorestore --host %s --port %d --username %s --password %s --db %s %s',
            escapeshellarg($this->config['host'] ?? 'localhost'),
            $this->config['port'] ?? 27017,
            escapeshellarg($this->config['username'] ?? ''),
            escapeshellarg($this->config['password'] ?? ''),
            escapeshellarg($this->config['dbname']),
            escapeshellarg($backupDir . DIRECTORY_SEPARATOR . $this->config['dbname'])
        );

        $this->executeCommand($command);
        return true;
    }

    /**
     * Execute a shell command
     */
    protected function executeCommand(string $command): void
    {
        $output = [];
        $returnVar = 0;
        exec($command . ' 2>&1', $output, $returnVar);

        if ($returnVar !== 0) {
            throw new MachinjiriException(
                "Backup/restore command failed: " . implode("\n", $output)
            );
        }
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
            'mysql' => 'sql',
            'pgsql' => 'pgdump',
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