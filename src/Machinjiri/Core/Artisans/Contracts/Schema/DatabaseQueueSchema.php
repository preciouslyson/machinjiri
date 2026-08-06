<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Contracts\Schema;


final class DatabaseQueueSchema
{
    
    public static function getMySqlEssentialSchema(string $jobsTable, string $failedTable): string
    {
        return "
CREATE TABLE IF NOT EXISTS `{$jobsTable}` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `job_id` VARCHAR(255) NOT NULL UNIQUE,
    `queue` VARCHAR(255) NOT NULL,
    `payload` LONGTEXT NOT NULL,
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `reserved_at` INT UNSIGNED NULL DEFAULT NULL,
    `available_at` INT UNSIGNED NOT NULL,
    `created_at` INT UNSIGNED NOT NULL,
    INDEX idx_queue (`queue`),
    INDEX idx_reserved_at (`reserved_at`),
    INDEX idx_available_at (`available_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `{$failedTable}` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `job_id` VARCHAR(255) NOT NULL UNIQUE,
    `queue` TEXT NOT NULL,
    `payload` LONGTEXT NOT NULL,
    `exception` LONGTEXT NOT NULL,
    `failed_at` VARCHAR(20),
    INDEX idx_queue (`queue`(255)),
    INDEX idx_failed_at (`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
    }
    
    public static function getMySqlFullSchema(): string
    {
        return "
-- Jobs table
CREATE TABLE IF NOT EXISTS `jobs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `queue` VARCHAR(255) NOT NULL,
    `job_id` VARCHAR(255) NOT NULL UNIQUE,
    `payload` LONGTEXT NOT NULL,
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `reserved_at` INT UNSIGNED NULL DEFAULT NULL,
    `available_at` INT UNSIGNED NOT NULL,
    `created_at` INT UNSIGNED NOT NULL,
    INDEX idx_queue (`queue`),
    INDEX idx_reserved_at (`reserved_at`),
    INDEX idx_available_at (`available_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Failed jobs table
CREATE TABLE IF NOT EXISTS `failed_jobs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `job_id` VARCHAR(255) NOT NULL UNIQUE,
    `queue` TEXT NOT NULL,
    `payload` LONGTEXT NOT NULL,
    `exception` LONGTEXT NOT NULL,
    `failed_at` VARCHAR(255),
    INDEX idx_queue (`queue`(255)),
    INDEX idx_failed_at (`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Job batches table
CREATE TABLE IF NOT EXISTS `job_batches` (
    `id` VARCHAR(255) NOT NULL PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `total_jobs` INT NOT NULL,
    `pending_jobs` INT NOT NULL,
    `failed_jobs` INT NOT NULL DEFAULT 0,
    `failed_job_ids` TEXT NULL,
    `options` TEXT NULL,
    `cancelled_at` INT NULL,
    `created_at` INT NOT NULL,
    `finished_at` INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE INDEX idx_job_batches_finished_at ON `job_batches` (`finished_at`);

-- Queue workers table
CREATE TABLE IF NOT EXISTS `queue_workers` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL UNIQUE,
    `queue` VARCHAR(255) NOT NULL DEFAULT 'default',
    `status` VARCHAR(50) NOT NULL DEFAULT 'idle',
    `process_id` INT NULL,
    `jobs_processed` INT NOT NULL DEFAULT 0,
    `jobs_failed` INT NOT NULL DEFAULT 0,
    `memory_usage` INT NULL,
    `last_heartbeat` INT NULL,
    `started_at` INT NOT NULL,
    `stopped_at` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_queue_workers_status ON `queue_workers` (`status`);
CREATE INDEX idx_queue_workers_queue ON `queue_workers` (`queue`);
CREATE INDEX idx_queue_workers_last_heartbeat ON `queue_workers` (`last_heartbeat`);

-- Queue connections table
CREATE TABLE IF NOT EXISTS `queue_connections` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL UNIQUE,
    `driver` VARCHAR(100) NOT NULL,
    `host` VARCHAR(255) NULL,
    `port` INT NULL,
    `database` VARCHAR(255) NULL,
    `username` VARCHAR(255) NULL,
    `password` TEXT NULL,
    `prefix` VARCHAR(50) NULL,
    `options` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_connected_at` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_queue_connections_driver ON `queue_connections` (`driver`);
CREATE INDEX idx_queue_connections_is_active ON `queue_connections` (`is_active`);

-- Job attempts table
CREATE TABLE IF NOT EXISTS `job_attempts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `job_id` INT UNSIGNED NOT NULL,
    `attempt_number` INT NOT NULL,
    `status` VARCHAR(50) NOT NULL DEFAULT 'pending',
    `started_at` INT NULL,
    `completed_at` INT NULL,
    `duration` INT NULL,
    `error_message` TEXT NULL,
    `exception_trace` TEXT NULL,
    `worker_name` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_job_attempts_job_id ON `job_attempts` (`job_id`);
CREATE INDEX idx_job_attempts_status ON `job_attempts` (`status`);
CREATE INDEX idx_job_attempts_started_at ON `job_attempts` (`started_at`);

-- Job logs table
CREATE TABLE IF NOT EXISTS `job_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `job_id` INT UNSIGNED NOT NULL,
    `level` VARCHAR(50) NOT NULL DEFAULT 'info',
    `message` TEXT NOT NULL,
    `context` TEXT NULL,
    `extra` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_job_logs_job_id ON `job_logs` (`job_id`);
CREATE INDEX idx_job_logs_level ON `job_logs` (`level`);
CREATE INDEX idx_job_logs_created_at ON `job_logs` (`created_at`);

-- Queue events table
CREATE TABLE IF NOT EXISTS `queue_events` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `event_type` VARCHAR(100) NOT NULL,
    `job_id` INT UNSIGNED NULL,
    `worker_name` VARCHAR(255) NULL,
    `queue_name` VARCHAR(255) NULL,
    `payload` TEXT NULL,
    `metadata` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_queue_events_event_type ON `queue_events` (`event_type`);
CREATE INDEX idx_queue_events_job_id ON `queue_events` (`job_id`);
CREATE INDEX idx_queue_events_worker_name ON `queue_events` (`worker_name`);
CREATE INDEX idx_queue_events_created_at ON `queue_events` (`created_at`);
";
    }
    
    public static function getPgsqlEssentialSchema(string $jobsTable, string $failedTable): string
    {
        return "
CREATE TABLE IF NOT EXISTS \"{$jobsTable}\" (
    id BIGSERIAL PRIMARY KEY,
    job_id VARCHAR(255) NOT NULL UNIQUE,
    queue VARCHAR(255) NOT NULL,
    payload TEXT NOT NULL,
    attempts SMALLINT NOT NULL DEFAULT 0,
    reserved_at INTEGER NULL,
    available_at INTEGER NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_{$jobsTable}_queue ON \"{$jobsTable}\" (queue);
CREATE INDEX IF NOT EXISTS idx_{$jobsTable}_reserved_at ON \"{$jobsTable}\" (reserved_at);
CREATE INDEX IF NOT EXISTS idx_{$jobsTable}_available_at ON \"{$jobsTable}\" (available_at);

CREATE TABLE IF NOT EXISTS \"{$failedTable}\" (
    id BIGSERIAL PRIMARY KEY,
    job_id VARCHAR(255) NOT NULL UNIQUE,
    connection TEXT NOT NULL,
    queue TEXT NOT NULL,
    payload TEXT NOT NULL,
    exception TEXT NOT NULL,
    failed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_{$failedTable}_queue ON \"{$failedTable}\" (queue);
CREATE INDEX IF NOT EXISTS idx_{$failedTable}_failed_at ON \"{$failedTable}\" (failed_at);
";
    }
    
    public static function getPgsqlFullSchema(): string
    {
        return "
-- Jobs table
CREATE TABLE IF NOT EXISTS \"jobs\" (
    id BIGSERIAL PRIMARY KEY,
    job_id VARCHAR(255) NOT NULL UNIQUE,
    queue VARCHAR(255) NOT NULL,
    payload TEXT NOT NULL,
    attempts SMALLINT NOT NULL DEFAULT 0,
    reserved_at INTEGER NULL,
    available_at INTEGER NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_jobs_queue ON jobs (queue);
CREATE INDEX IF NOT EXISTS idx_jobs_reserved_at ON jobs (reserved_at);
CREATE INDEX IF NOT EXISTS idx_jobs_available_at ON jobs (available_at);

-- Failed jobs table
CREATE TABLE IF NOT EXISTS \"failed_jobs\" (
    id BIGSERIAL PRIMARY KEY,
    job_id VARCHAR(255) NOT NULL UNIQUE,
    connection TEXT NOT NULL,
    queue TEXT NOT NULL,
    payload TEXT NOT NULL,
    exception TEXT NOT NULL,
    failed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_failed_jobs_queue ON failed_jobs (queue);
CREATE INDEX IF NOT EXISTS idx_failed_jobs_failed_at ON failed_jobs (failed_at);

-- Job batches table
CREATE TABLE IF NOT EXISTS \"job_batches\" (
    id VARCHAR(255) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    total_jobs INTEGER NOT NULL,
    pending_jobs INTEGER NOT NULL,
    failed_jobs INTEGER NOT NULL DEFAULT 0,
    failed_job_ids TEXT NULL,
    options TEXT NULL,
    cancelled_at INTEGER NULL,
    created_at INTEGER NOT NULL,
    finished_at INTEGER NULL
);
CREATE INDEX IF NOT EXISTS idx_job_batches_name ON job_batches (name);
CREATE INDEX IF NOT EXISTS idx_job_batches_finished_at ON job_batches (finished_at);

-- Queue workers table
CREATE TABLE IF NOT EXISTS \"queue_workers\" (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    queue VARCHAR(255) NOT NULL DEFAULT 'default',
    status VARCHAR(50) NOT NULL DEFAULT 'idle',
    process_id INTEGER NULL,
    jobs_processed INTEGER NOT NULL DEFAULT 0,
    jobs_failed INTEGER NOT NULL DEFAULT 0,
    memory_usage INTEGER NULL,
    last_heartbeat INTEGER NULL,
    started_at INTEGER NOT NULL,
    stopped_at INTEGER NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_queue_workers_status ON queue_workers (status);
CREATE INDEX IF NOT EXISTS idx_queue_workers_queue ON queue_workers (queue);
CREATE INDEX IF NOT EXISTS idx_queue_workers_last_heartbeat ON queue_workers (last_heartbeat);

-- Queue connections table
CREATE TABLE IF NOT EXISTS \"queue_connections\" (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    driver VARCHAR(100) NOT NULL,
    host VARCHAR(255) NULL,
    port INTEGER NULL,
    database VARCHAR(255) NULL,
    username VARCHAR(255) NULL,
    password TEXT NULL,
    prefix VARCHAR(50) NULL,
    options TEXT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    last_connected_at INTEGER NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_queue_connections_driver ON queue_connections (driver);
CREATE INDEX IF NOT EXISTS idx_queue_connections_is_active ON queue_connections (is_active);

-- Job attempts table
CREATE TABLE IF NOT EXISTS \"job_attempts\" (
    id SERIAL PRIMARY KEY,
    job_id INTEGER NOT NULL,
    attempt_number INTEGER NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    started_at INTEGER NULL,
    completed_at INTEGER NULL,
    duration INTEGER NULL,
    error_message TEXT NULL,
    exception_trace TEXT NULL,
    worker_name VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_job_attempts_job_id ON job_attempts (job_id);
CREATE INDEX IF NOT EXISTS idx_job_attempts_status ON job_attempts (status);
CREATE INDEX IF NOT EXISTS idx_job_attempts_started_at ON job_attempts (started_at);

-- Job logs table
CREATE TABLE IF NOT EXISTS \"job_logs\" (
    id SERIAL PRIMARY KEY,
    job_id INTEGER NOT NULL,
    level VARCHAR(50) NOT NULL DEFAULT 'info',
    message TEXT NOT NULL,
    context TEXT NULL,
    extra TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_job_logs_job_id ON job_logs (job_id);
CREATE INDEX IF NOT EXISTS idx_job_logs_level ON job_logs (level);
CREATE INDEX IF NOT EXISTS idx_job_logs_created_at ON job_logs (created_at);

-- Queue events table
CREATE TABLE IF NOT EXISTS \"queue_events\" (
    id SERIAL PRIMARY KEY,
    event_type VARCHAR(100) NOT NULL,
    job_id INTEGER NULL,
    worker_name VARCHAR(255) NULL,
    queue_name VARCHAR(255) NULL,
    payload TEXT NULL,
    metadata TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_queue_events_event_type ON queue_events (event_type);
CREATE INDEX IF NOT EXISTS idx_queue_events_job_id ON queue_events (job_id);
CREATE INDEX IF NOT EXISTS idx_queue_events_worker_name ON queue_events (worker_name);
CREATE INDEX IF NOT EXISTS idx_queue_events_created_at ON queue_events (created_at);
";
    }
    
    public static function getSqliteEssentialSchema(string $jobsTable, string $failedTable): string
    {
        return "
CREATE TABLE IF NOT EXISTS \"{$jobsTable}\" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id VARCHAR(255) NOT NULL UNIQUE,
    queue VARCHAR(255) NOT NULL,
    payload TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    reserved_at INTEGER NULL,
    available_at INTEGER NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_{$jobsTable}_queue ON \"{$jobsTable}\" (queue);
CREATE INDEX IF NOT EXISTS idx_{$jobsTable}_reserved_at ON \"{$jobsTable}\" (reserved_at);
CREATE INDEX IF NOT EXISTS idx_{$jobsTable}_available_at ON \"{$jobsTable}\" (available_at);

CREATE TABLE IF NOT EXISTS \"{$failedTable}\" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id VARCHAR(255) NOT NULL UNIQUE,
    connection TEXT NOT NULL,
    queue TEXT NOT NULL,
    payload TEXT NOT NULL,
    exception TEXT NOT NULL,
    failed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_{$failedTable}_queue ON \"{$failedTable}\" (queue);
CREATE INDEX IF NOT EXISTS idx_{$failedTable}_failed_at ON \"{$failedTable}\" (failed_at);
";
    }
    
    public static function getSqliteFullSchema(): string
    {
        return "
-- Jobs table
CREATE TABLE IF NOT EXISTS \"jobs\" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    queue VARCHAR(255) NOT NULL,
    payload TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    reserved_at INTEGER NULL,
    available_at INTEGER NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_jobs_queue ON jobs (queue);
CREATE INDEX IF NOT EXISTS idx_jobs_reserved_at ON jobs (reserved_at);
CREATE INDEX IF NOT EXISTS idx_jobs_available_at ON jobs (available_at);

-- Failed jobs table
CREATE TABLE IF NOT EXISTS \"failed_jobs\" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id VARCHAR(255) NOT NULL UNIQUE,
    connection TEXT NOT NULL,
    queue TEXT NOT NULL,
    payload TEXT NOT NULL,
    exception TEXT NOT NULL,
    failed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_failed_jobs_queue ON failed_jobs (queue);
CREATE INDEX IF NOT EXISTS idx_failed_jobs_failed_at ON failed_jobs (failed_at);

-- Job batches table
CREATE TABLE IF NOT EXISTS \"job_batches\" (
    id VARCHAR(255) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    total_jobs INTEGER NOT NULL,
    pending_jobs INTEGER NOT NULL,
    failed_jobs INTEGER NOT NULL DEFAULT 0,
    failed_job_ids TEXT NULL,
    options TEXT NULL,
    cancelled_at INTEGER NULL,
    created_at INTEGER NOT NULL,
    finished_at INTEGER NULL
);
CREATE INDEX IF NOT EXISTS idx_job_batches_name ON job_batches (name);
CREATE INDEX IF NOT EXISTS idx_job_batches_finished_at ON job_batches (finished_at);

-- Queue workers table
CREATE TABLE IF NOT EXISTS \"queue_workers\" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(255) NOT NULL UNIQUE,
    queue VARCHAR(255) NOT NULL DEFAULT 'default',
    status VARCHAR(50) NOT NULL DEFAULT 'idle',
    process_id INTEGER NULL,
    jobs_processed INTEGER NOT NULL DEFAULT 0,
    jobs_failed INTEGER NOT NULL DEFAULT 0,
    memory_usage INTEGER NULL,
    last_heartbeat INTEGER NULL,
    started_at INTEGER NOT NULL,
    stopped_at INTEGER NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_queue_workers_status ON queue_workers (status);
CREATE INDEX IF NOT EXISTS idx_queue_workers_queue ON queue_workers (queue);
CREATE INDEX IF NOT EXISTS idx_queue_workers_last_heartbeat ON queue_workers (last_heartbeat);

-- Queue connections table
CREATE TABLE IF NOT EXISTS \"queue_connections\" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(255) NOT NULL UNIQUE,
    driver VARCHAR(100) NOT NULL,
    host VARCHAR(255) NULL,
    port INTEGER NULL,
    database VARCHAR(255) NULL,
    username VARCHAR(255) NULL,
    password TEXT NULL,
    prefix VARCHAR(50) NULL,
    options TEXT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    last_connected_at INTEGER NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_queue_connections_driver ON queue_connections (driver);
CREATE INDEX IF NOT EXISTS idx_queue_connections_is_active ON queue_connections (is_active);

-- Job attempts table
CREATE TABLE IF NOT EXISTS \"job_attempts\" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id INTEGER NOT NULL,
    attempt_number INTEGER NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    started_at INTEGER NULL,
    completed_at INTEGER NULL,
    duration INTEGER NULL,
    error_message TEXT NULL,
    exception_trace TEXT NULL,
    worker_name VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_job_attempts_job_id ON job_attempts (job_id);
CREATE INDEX IF NOT EXISTS idx_job_attempts_status ON job_attempts (status);
CREATE INDEX IF NOT EXISTS idx_job_attempts_started_at ON job_attempts (started_at);

-- Job logs table
CREATE TABLE IF NOT EXISTS \"job_logs\" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    job_id INTEGER NOT NULL,
    level VARCHAR(50) NOT NULL DEFAULT 'info',
    message TEXT NOT NULL,
    context TEXT NULL,
    extra TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_job_logs_job_id ON job_logs (job_id);
CREATE INDEX IF NOT EXISTS idx_job_logs_level ON job_logs (level);
CREATE INDEX IF NOT EXISTS idx_job_logs_created_at ON job_logs (created_at);

-- Queue events table
CREATE TABLE IF NOT EXISTS \"queue_events\" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_type VARCHAR(100) NOT NULL,
    job_id INTEGER NULL,
    worker_name VARCHAR(255) NULL,
    queue_name VARCHAR(255) NULL,
    payload TEXT NULL,
    metadata TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_queue_events_event_type ON queue_events (event_type);
CREATE INDEX IF NOT EXISTS idx_queue_events_job_id ON queue_events (job_id);
CREATE INDEX IF NOT EXISTS idx_queue_events_worker_name ON queue_events (worker_name);
CREATE INDEX IF NOT EXISTS idx_queue_events_created_at ON queue_events (created_at);
";
    }
    
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