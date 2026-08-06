<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Contracts\Schema;

final class DatabaseQueueSchema
{
    /**
     * MySQL essential schema with configurable table names.
     * Creates jobs, failed_jobs, and processed_jobs tables.
     */
    public static function getMySqlEssentialSchema(
        string $jobsTable,
        string $failedTable,
        string $processedTable
    ): string {
        return "
CREATE TABLE IF NOT EXISTS `{$jobsTable}` (
    id BIGINT(20) NOT NULL AUTO_INCREMENT,
    queue VARCHAR(255) NOT NULL,
    job_id VARCHAR(255) NOT NULL,
    payload LONGTEXT NOT NULL,
    attempts TINYINT(3) DEFAULT 0,
    reserved_at INT(10) NULL,
    available_at INT(10) NOT NULL,
    created_at INT(20),
    PRIMARY KEY (id),
    INDEX idx_{$jobsTable}_queue (queue),
    INDEX idx_{$jobsTable}_reserved_at (reserved_at),
    INDEX idx_{$jobsTable}_available_at (available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{$failedTable}` (
    id BIGINT(20) NOT NULL AUTO_INCREMENT,
    queue VARCHAR(255) NOT NULL,
    job_id VARCHAR(255) NOT NULL,
    payload LONGTEXT NOT NULL,
    exception VARCHAR(255) NOT NULL,
    failed_at INT(20),
    PRIMARY KEY (id),
    INDEX idx_{$failedTable}_queue (queue),
    INDEX idx_{$failedTable}_failed_at (failed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{$processedTable}` (
    id BIGINT(20) NOT NULL AUTO_INCREMENT,
    queue VARCHAR(255) NOT NULL,
    job_id VARCHAR(255) NOT NULL,
    payload LONGTEXT NOT NULL,
    processed_at INT(20),
    PRIMARY KEY (id),
    INDEX idx_{$processedTable}_queue (queue),
    INDEX idx_{$processedTable}_processed_at (processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
    }

    /**
     * MySQL full schema using standard table names.
     */
    public static function getMySqlFullSchema(): string
    {
        return "
CREATE TABLE IF NOT EXISTS `jobs` (
    id BIGINT(20) NOT NULL AUTO_INCREMENT,
    queue VARCHAR(255) NOT NULL,
    job_id VARCHAR(255) NOT NULL,
    payload LONGTEXT NOT NULL,
    attempts TINYINT(3) DEFAULT 0,
    reserved_at INT(10) NULL,
    available_at INT(10) NOT NULL,
    created_at INT(20),
    PRIMARY KEY (id),
    INDEX idx_jobs_queue (queue),
    INDEX idx_jobs_reserved_at (reserved_at),
    INDEX idx_jobs_available_at (available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `failed_jobs` (
    id BIGINT(20) NOT NULL AUTO_INCREMENT,
    queue VARCHAR(255) NOT NULL,
    job_id VARCHAR(255) NOT NULL,
    payload LONGTEXT NOT NULL,
    exception VARCHAR(255) NOT NULL,
    failed_at INT(20),
    PRIMARY KEY (id),
    INDEX idx_failed_jobs_queue (queue),
    INDEX idx_failed_jobs_failed_at (failed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `processed_jobs` (
    id BIGINT(20) NOT NULL AUTO_INCREMENT,
    queue VARCHAR(255) NOT NULL,
    job_id VARCHAR(255) NOT NULL,
    payload LONGTEXT NOT NULL,
    processed_at INT(20),
    PRIMARY KEY (id),
    INDEX idx_processed_jobs_queue (queue),
    INDEX idx_processed_jobs_processed_at (processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
    }

    /**
     * PostgreSQL essential schema with configurable table names.
     */
    public static function getPgsqlEssentialSchema(
        string $jobsTable,
        string $failedTable,
        string $processedTable
    ): string {
        return "
CREATE TABLE IF NOT EXISTS \"{$jobsTable}\" (
    id BIGSERIAL PRIMARY KEY,
    queue VARCHAR(255) NOT NULL,
    job_id VARCHAR(255) NOT NULL,
    payload LONGTEXT NOT NULL,
    attempts SMALLINT DEFAULT 0,
    reserved_at INTEGER NULL,
    available_at INTEGER NOT NULL,
    created_at INT(20)
);
CREATE INDEX IF NOT EXISTS idx_{$jobsTable}_queue ON \"{$jobsTable}\" (queue);
CREATE INDEX IF NOT EXISTS idx_{$jobsTable}_reserved_at ON \"{$jobsTable}\" (reserved_at);
CREATE INDEX IF NOT EXISTS idx_{$jobsTable}_available_at ON \"{$jobsTable}\" (available_at);

CREATE TABLE IF NOT EXISTS \"{$failedTable}\" (
    id BIGSERIAL PRIMARY KEY,
    queue VARCHAR(255) NOT NULL,
    job_id VARCHAR(255) NOT NULL,
    payload LONGTEXT NOT NULL,
    exception VARCHAR(255) NOT NULL,
    failed_at INT(20)
);
CREATE INDEX IF NOT EXISTS idx_{$failedTable}_queue ON \"{$failedTable}\" (queue);
CREATE INDEX IF NOT EXISTS idx_{$failedTable}_failed_at ON \"{$failedTable}\" (failed_at);

CREATE TABLE IF NOT EXISTS \"{$processedTable}\" (
    id BIGSERIAL PRIMARY KEY,
    queue VARCHAR(255) NOT NULL,
    job_id VARCHAR(255) NOT NULL,
    payload LONGTEXT NOT NULL,
    processed_at INT(20)
);
CREATE INDEX IF NOT EXISTS idx_{$processedTable}_queue ON \"{$processedTable}\" (queue);
CREATE INDEX IF NOT EXISTS idx_{$processedTable}_processed_at ON \"{$processedTable}\" (processed_at);
";
    }

    /**
     * PostgreSQL full schema using standard table names.
     */
    public static function getPgsqlFullSchema(): string
    {
        return "
CREATE TABLE IF NOT EXISTS \"jobs\" (
    id BIGSERIAL PRIMARY KEY,
    queue VARCHAR(255) NOT NULL,
    job_id VARCHAR(255) NOT NULL,
    payload LONGTEXT NOT NULL,
    attempts SMALLINT DEFAULT 0,
    reserved_at INTEGER NULL,
    available_at INTEGER NOT NULL,
    created_at INT(20)
);
CREATE INDEX IF NOT EXISTS idx_jobs_queue ON jobs (queue);
CREATE INDEX IF NOT EXISTS idx_jobs_reserved_at ON jobs (reserved_at);
CREATE INDEX IF NOT EXISTS idx_jobs_available_at ON jobs (available_at);

CREATE TABLE IF NOT EXISTS \"failed_jobs\" (
    id BIGSERIAL PRIMARY KEY,
    queue VARCHAR(255) NOT NULL,
    job_id VARCHAR(255) NOT NULL,
    payload LONGTEXT NOT NULL,
    exception VARCHAR(255) NOT NULL,
    failed_at INT(20)
);
CREATE INDEX IF NOT EXISTS idx_failed_jobs_queue ON failed_jobs (queue);
CREATE INDEX IF NOT EXISTS idx_failed_jobs_failed_at ON failed_jobs (failed_at);

CREATE TABLE IF NOT EXISTS \"processed_jobs\" (
    id BIGSERIAL PRIMARY KEY,
    queue VARCHAR(255) NOT NULL,
    job_id VARCHAR(255) NOT NULL,
    payload LONGTEXT NOT NULL,
    processed_at INT(20)
);
CREATE INDEX IF NOT EXISTS idx_processed_jobs_queue ON processed_jobs (queue);
CREATE INDEX IF NOT EXISTS idx_processed_jobs_processed_at ON processed_jobs (processed_at);
";
    }

    /**
     * SQLite essential schema with configurable table names.
     */
    public static function getSqliteEssentialSchema(
        string $jobsTable,
        string $failedTable,
        string $processedTable
    ): string {
        return "
CREATE TABLE IF NOT EXISTS \"{$jobsTable}\" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    queue VARCHAR(255) NOT NULL,
    job_id VARCHAR(255) NOT NULL,
    payload LONGTEXT NOT NULL,
    attempts INTEGER DEFAULT 0,
    reserved_at INTEGER NULL,
    available_at INTEGER NOT NULL,
    created_at INT(20)
);
CREATE INDEX IF NOT EXISTS idx_{$jobsTable}_queue ON \"{$jobsTable}\" (queue);
CREATE INDEX IF NOT EXISTS idx_{$jobsTable}_reserved_at ON \"{$jobsTable}\" (reserved_at);
CREATE INDEX IF NOT EXISTS idx_{$jobsTable}_available_at ON \"{$jobsTable}\" (available_at);

CREATE TABLE IF NOT EXISTS \"{$failedTable}\" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    queue VARCHAR(255) NOT NULL,
    job_id VARCHAR(255) NOT NULL,
    payload LONGTEXT NOT NULL,
    exception VARCHAR(255) NOT NULL,
    failed_at INT(20)
);
CREATE INDEX IF NOT EXISTS idx_{$failedTable}_queue ON \"{$failedTable}\" (queue);
CREATE INDEX IF NOT EXISTS idx_{$failedTable}_failed_at ON \"{$failedTable}\" (failed_at);

CREATE TABLE IF NOT EXISTS \"{$processedTable}\" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    queue VARCHAR(255) NOT NULL,
    job_id VARCHAR(255) NOT NULL,
    payload LONGTEXT NOT NULL,
    processed_at INT(20)
);
CREATE INDEX IF NOT EXISTS idx_{$processedTable}_queue ON \"{$processedTable}\" (queue);
CREATE INDEX IF NOT EXISTS idx_{$processedTable}_processed_at ON \"{$processedTable}\" (processed_at);
";
    }

    /**
     * SQLite full schema using standard table names.
     */
    public static function getSqliteFullSchema(): string
    {
        return "
CREATE TABLE IF NOT EXISTS \"jobs\" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    queue VARCHAR(255) NOT NULL,
    job_id VARCHAR(255) NOT NULL,
    payload LONGTEXT NOT NULL,
    attempts INTEGER DEFAULT 0,
    reserved_at INTEGER NULL,
    available_at INTEGER NOT NULL,
    created_at INT(20)
);
CREATE INDEX IF NOT EXISTS idx_jobs_queue ON jobs (queue);
CREATE INDEX IF NOT EXISTS idx_jobs_reserved_at ON jobs (reserved_at);
CREATE INDEX IF NOT EXISTS idx_jobs_available_at ON jobs (available_at);

CREATE TABLE IF NOT EXISTS \"failed_jobs\" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    queue VARCHAR(255) NOT NULL,
    job_id VARCHAR(255) NOT NULL,
    payload LONGTEXT NOT NULL,
    exception VARCHAR(255) NOT NULL,
    failed_at INT(20)
);
CREATE INDEX IF NOT EXISTS idx_failed_jobs_queue ON failed_jobs (queue);
CREATE INDEX IF NOT EXISTS idx_failed_jobs_failed_at ON failed_jobs (failed_at);

CREATE TABLE IF NOT EXISTS \"processed_jobs\" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    queue VARCHAR(255) NOT NULL,
    job_id VARCHAR(255) NOT NULL,
    payload LONGTEXT NOT NULL,
    processed_at INT(20)
);
CREATE INDEX IF NOT EXISTS idx_processed_jobs_queue ON processed_jobs (queue);
CREATE INDEX IF NOT EXISTS idx_processed_jobs_processed_at ON processed_jobs (processed_at);
";
    }

    /**
     * Execute multiple SQL statements (split by ';') using a PDO connection.
     */
    public static function executeStatements(\PDO $connection, string $sql): void
    {
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $statement) {
            if (empty($statement)) {
                continue;
            }
            $connection->exec($statement);
        }
    }
}