<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\ProgressBar;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Artisans\Contracts\BaseWorker;
use Mlangeni\Machinjiri\Core\Artisans\Contracts\BaseJobProcessor;
use Mlangeni\Machinjiri\Core\Artisans\Contracts\JobInterface;
use Mlangeni\Machinjiri\Core\Artisans\Generators\QueueJobGenerator;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Database\DatabaseConnection;
use Mlangeni\Machinjiri\Core\Database\Migrations\MigrationCreator;
use Mlangeni\Machinjiri\Core\Database\Migrations\MigrationHandler;
use Mlangeni\Machinjiri\Core\Artisans\Contracts\BackgroundWorkerManager;

class QueueDriverResolver
{
    private $container;
    private $logger;
    private $generator;
    private $config;

    public function __construct(Container $container, Logger $logger, QueueJobGenerator $generator, array $config)
    {
        $this->container = $container;
        $this->logger    = $logger;
        $this->generator = $generator;
        $this->config    = $config;
    }

    public function ensureAllDriversInitialized(): void
    {
        $types = ['database', 'redis', 'file', 'memory', 'sync'];
        $this->generator->createDefaultQueueConfig();
        foreach ($types as $type) {
            try {
                $this->generator->generateQueueDriverIfNotExists($type, [
                    'type'     => $type,
                    'config'   => false,
                    'register' => false,
                    'command'  => false,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning("Could not initialise driver {$type}: " . $e->getMessage());
            }
        }
    }

    public function resolve(string $driver): ?object
    {
        $this->ensureAllDriversInitialized();

        $driverConfig = $this->config['drivers'][$driver] ?? null;
        if (!$driverConfig) {
            foreach ($this->config['drivers'] as $key => $cfg) {
                if (($cfg['class'] ?? '') === $driver) {
                    $driver = $key;
                    $driverConfig = $cfg;
                    break;
                }
            }
        }

        if (!$driverConfig) {
            $this->logger->warning("No configuration found for driver: {$driver}");
            return null;
        }

        $driverClass = $driverConfig['class'] ?? $this->resolveClassName($driver);
        if (!class_exists($driverClass)) {
            $this->logger->error("Queue driver class not found: {$driverClass}");
            return null;
        }

        return new $driverClass($this->container, $driver, $driverConfig);
    }

    private function resolveClassName(string $driver): string
    {
        $map = [
            'database' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\DatabaseQueue',
            'redis'    => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\RedisQueue',
            'sync'     => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\SyncQueue',
            'file'     => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\FileQueue',
            'memory'   => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\MemoryQueue',
        ];
        return $map[$driver] ?? 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\' . ucfirst($driver) . 'Queue';
    }
}
class SignalManager
{
    public function register(callable $handler, array $signals): void
    {
        if (!extension_loaded('pcntl')) {
            return;
        }
        pcntl_async_signals(true);
        foreach ($signals as $sig) {
            pcntl_signal($sig, $handler);
        }
    }
}
trait QueueCommandHelper
{
    use DatabaseQueueSetup;
    
    protected Container $appContainer;
    protected Logger $logger;
    protected QueueJobGenerator $queueGenerator;
    protected QueueDriverResolver $driverResolver;
    protected array $queueConfig;
    
    private function _init(): void 
    {
        $this->appContainer = $this->getContainerInstance();
        $this->logger = new Logger('queue-worker');
        $this->queueGenerator = new QueueJobGenerator(getcwd());
        $rawConfig = $this->loadQueueConfig(null);
        $this->queueConfig = $this->validateQueueConfig($rawConfig);
        $this->driverResolver = new QueueDriverResolver(
            $this->appContainer,
            $this->logger,
            $this->queueGenerator,
            $this->queueConfig
        );
    }

    private function bootstrapDependencies(): void
    {
        $this->_init();
        $this->loadEnvironmentVariables();
        $this->bootstrapDatabaseConnection();
    }

    private function loadEnvironmentVariables(): void
    {
        try {
            $dotenv = new \Mlangeni\Machinjiri\Core\Artisans\Helpers\DotEnv(false, false);
            $dotenv->setPath(getcwd());
            $dotenv->load();
        } catch (\Throwable $e) {
            $this->logger->debug("Could not load .env \n{file}\n{error}", [
                'file' => $envPath,
                'error' => $e->getMessage()
              ]);
        }
    }

    private function getContainerInstance(): Container
    {
        if (!Container::instancePresent()) {
            new Container(getcwd());
        }
        return Container::getInstance();
    }

    private function loadQueueConfig(?string $configPath): array
    {
        $basePath = getcwd();
        if ($configPath) {
            if (!file_exists($configPath)) {
                throw new MachinjiriException("Configuration file not found: {$configPath}");
            }
            return require $configPath;
        }
        $defaultPaths = [
            $basePath . '/config/queue.php',
            $basePath . '/../config/queue.php',
            __DIR__ . '/../../../../../config/queue.php',
        ];
        foreach ($defaultPaths as $path) {
            if (file_exists($path)) {
                return require $path;
            }
        }
        
        return [
            'default' => 'database',
            'drivers' => [
                'database' => [
                    'driver'      => 'database',
                    'table'       => 'jobs',
                    'queue'       => 'default',
                    'retry_after' => 90,
                ],
                'sync' => ['driver' => 'sync'],
            ],
        ];
    }

    private function validateQueueConfig(array $config): array
    {
        if (!isset($config['drivers']) || !is_array($config['drivers'])) {
            throw new MachinjiriException('Queue configuration must contain a "drivers" array.');
        }
        foreach ($config['drivers'] as $name => $cfg) {
            if (!is_array($cfg)) {
                throw new MachinjiriException("Driver configuration for '{$name}' must be an array.");
            }
        }
        return $config;
    }

    private function createJobProcessor(): object
    {
        return new class($this->appContainer) implements \Mlangeni\Machinjiri\Core\Artisans\Contracts\JobProcessorInterface {
            private $container;
            public function __construct($container) { $this->container = $container; }
            
            public function process(\Mlangeni\Machinjiri\Core\Artisans\Contracts\JobInterface $job): mixed
            {
                if (method_exists($job, 'handle')) {
                    return $job->handle($this->container);
                }
                throw new \RuntimeException('No job processor available');
            }
            
            public function handleFailure(\Mlangeni\Machinjiri\Core\Artisans\Contracts\JobInterface $job, \Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException $exception): void
            {
                error_log(sprintf('Job %s failed: %s', $job->getName(), $exception->getMessage()));
            }
            
            public function handleSuccess(\Mlangeni\Machinjiri\Core\Artisans\Contracts\JobInterface $job, mixed $result): void {}
            public function markAsCompleted(\Mlangeni\Machinjiri\Core\Artisans\Contracts\JobInterface $job): void {}
            public function markAsFailed(\Mlangeni\Machinjiri\Core\Artisans\Contracts\JobInterface $job, \Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException $exception): void {}
            public function retry(\Mlangeni\Machinjiri\Core\Artisans\Contracts\JobInterface $job, int $delay = 0): bool { return false; }
        };
    }

    protected function executeSafely(InputInterface $input, OutputInterface $output, callable $callback): int
    {
        try {
            return $callback($input, $output);
        } catch (\Throwable $e) {
            $io = new SymfonyStyle($input, $output);
            $io->error($e->getMessage() . " in: [" . $e->getFile() . "] on line: ". $e->getLine());
            if ($output->isVerbose()) {
                $io->writeln("<error>{$e->getTraceAsString()}</error>");
            }
            $this->logger->error($e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return Command::FAILURE;
        }
    }

    
    private function loadDatabaseConfig(): array
    {
        $basePath = getcwd();
        $configPaths = [
            $basePath . '/config/database.php',
            $basePath . '/../config/database.php',
            __DIR__ . '/../../../../../config/database.php',
        ];
        foreach ($configPaths as $path) {
            if (file_exists($path)) {
                $config = require $path;
                if (isset($config['driver'])) {
                    return $config;
                }
            }
        }

        $driver = getenv('DB_CONNECTION') ?: 'mysql';
        $config = ['driver' => $driver];

        switch ($driver) {
            case 'mysql':
            case 'pgsql':
                $host = getenv('DB_HOST') ?: '127.0.0.1';
                if ($host === 'localhost') {
                    $host = '127.0.0.1';
                }
                $config['host'] = $host;
                $config['port'] = getenv('DB_PORT') ?: ($driver === 'mysql' ? 3306 : 5432);
                $config['database'] = getenv('DB_DATABASE') ?: '';
                $config['username'] = getenv('DB_USERNAME') ?: '';
                $config['password'] = getenv('DB_PASSWORD') ?: '';
                $config['charset'] = getenv('DB_CHARSET') ?: 'utf8mb4';
                break;
            case 'sqlite':
                $config['path'] = getcwd() . '/database/database.sqlite';
                break;
            case 'mongodb':
                $config['host'] = getenv('DB_HOST') ?: 'localhost';
                $config['port'] = getenv('DB_PORT') ?: 27017;
                $config['database'] = getenv('DB_DATABASE') ?: '';
                $config['username'] = getenv('DB_USERNAME') ?: '';
                $config['password'] = getenv('DB_PASSWORD') ?: '';
                break;
            default:
                $config['dsn'] = getenv('DB_DSN') ?: '';
                $config['username'] = getenv('DB_USERNAME') ?: '';
                $config['password'] = getenv('DB_PASSWORD') ?: '';
        }

        return $config;
    }

    private function bootstrapDatabaseConnection(): void
    {
        $dbConfig = $this->loadDatabaseConfig();
        \Mlangeni\Machinjiri\Core\Database\DatabaseConnection::setConfig($dbConfig);

        if (($dbConfig['driver'] ?? '') === 'sqlite' && isset($dbConfig['path'])) {
            \Mlangeni\Machinjiri\Core\Database\DatabaseConnection::setPath(dirname($dbConfig['path']));
        }
    }

    protected function getQueueDriverOrInit(string $driverName, Container $container, array $config): ?object
    {
        $resolver = new QueueDriverResolver($container, $this->logger, $this->queueGenerator, $config);
        $resolver->ensureAllDriversInitialized();
        return $resolver->resolve($driverName);
    }
    
    private function ensureDatabaseQueueTables(): void
    {
        $defaultDriver = $this->queueConfig['default'] ?? 'database';
        if ($defaultDriver !== 'database') {
            return;
        }

        $queueDriver = $this->driverResolver->resolve('database');
        if (!$queueDriver || !method_exists($queueDriver, 'getConnection')) {
            $this->logger->warning('Cannot create queue tables: database driver not available or missing getConnection()');
            return;
        }

        try {
            $pdo = $queueDriver->getConnection();
            $table = $this->queueConfig['drivers']['database']['table'] ?? 'queue_jobs';
            $failedTable = $this->queueConfig['drivers']['database']['failed_table'] ?? 'queue_failed_jobs';
            $this->ensureQueueTablesExist($pdo, $table, $failedTable);
            $this->logger->info('Queue tables verified/created successfully');
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to create queue tables: ' . $e->getMessage());
        }
    }
    
    protected function requireDatabaseTables(InputInterface $input, OutputInterface $output): void
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $this->ensureDatabaseQueueTables();
        } catch (\Throwable $e) {
            $io->error('Database queue tables could not be created. Please run "php artisan queue:init" first.');
            $io->writeln('Error: ' . $e->getMessage());
            exit(Command::FAILURE);
        }
    }
}
trait DatabaseQueueSetup
{
    private function ensureQueueTablesExist(\PDO $connection, string $table, string $failedTable): void
    {
        $logger = new Logger('queue-setup');
        $driverName = $connection->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $driver = $this->normalizeDriver($driverName);
        
        $tableExists = function (string $tableName) use ($connection, $driverName): bool {
            try {
                switch ($driverName) {
                    case 'sqlite':
                        $stmt = $connection->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table");
                        $stmt->execute([':table' => $tableName]);
                        return $stmt->fetch() !== false;
                    case 'mysql':
                        $stmt = $connection->prepare("SHOW TABLES LIKE :table");
                        $stmt->execute([':table' => $tableName]);
                        return $stmt->rowCount() > 0;
                    case 'pgsql':
                        $stmt = $connection->prepare("SELECT tablename FROM pg_tables WHERE tablename = :table");
                        $stmt->execute([':table' => $tableName]);
                        return $stmt->fetch() !== false;
                    default:
                        try {
                            $stmt = $connection->prepare("SELECT 1 FROM \"$tableName\" LIMIT 1");
                            $stmt->execute();
                            return true;
                        } catch (\PDOException $e) {
                            return false;
                        }
                }
            } catch (\PDOException $e) {
                return false;
            }
        };
        
        $mainMissing = !$tableExists($table);
        $failedMissing = !$tableExists($failedTable);
        if (!$mainMissing && !$failedMissing) {
            return;
        }
        
        $logger->info("Essential queue tables missing, auto-creating for driver {$driver}...");
        $schema = $this->getEssentialSchema($driver, $table, $failedTable);
        $this->executeStatements($connection, $schema);
        $logger->info('Essential queue tables created successfully');
    }
    
    private function createFullSchema(\PDO $connection): void
    {
        $logger = new Logger('queue-setup');
        $driverName = $connection->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if (strpos($driverName, 'mongodb') !== false) {
            $logger->info('MongoDB detected – no relational tables to create.');
            return;
        }
        $driver = $this->normalizeDriver($driverName);
        
        $logger->info("Creating full queue schema for driver: {$driver}");
        $schema = $this->getFullSchema($driver);
        $this->executeStatements($connection, $schema);
        $logger->info('Full queue schema created successfully');
    }
    
    private function normalizeDriver(string $driverName): string
    {
        return match ($driverName) {
            'mysql' => 'mysql',
            'pgsql', 'postgresql' => 'pgsql',
            'sqlite' => 'sqlite',
            default => 'mysql'  // fallback
        };
    }
    
    private function getEssentialSchema(string $driver, string $jobsTable, string $failedTable): string
    {
        return match ($driver) {
            'mysql' => $this->getMySqlEssentialSchema($jobsTable, $failedTable),
            'pgsql' => $this->getPgsqlEssentialSchema($jobsTable, $failedTable),
            'sqlite' => $this->getSqliteEssentialSchema($jobsTable, $failedTable),
            default => $this->getMySqlEssentialSchema($jobsTable, $failedTable),
        };
    }
    
    private function getFullSchema(string $driver): string
    {
        return match ($driver) {
            'mysql' => $this->getMySqlFullSchema(),
            'pgsql' => $this->getPgsqlFullSchema(),
            'sqlite' => $this->getSqliteFullSchema(),
            default => $this->getMySqlFullSchema(),
        };
    }
    
    private function getMySqlEssentialSchema(string $jobsTable, string $failedTable): string
    {
        return "
CREATE TABLE IF NOT EXISTS `{$jobsTable}` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
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
    `uuid` CHAR(36) NOT NULL UNIQUE,
    `connection` TEXT NOT NULL,
    `queue` TEXT NOT NULL,
    `payload` LONGTEXT NOT NULL,
    `exception` LONGTEXT NOT NULL,
    `failed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_queue (`queue`(255)),
    INDEX idx_failed_at (`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
    }
    
    private function getMySqlFullSchema(): string
    {
        return "
-- Jobs table
CREATE TABLE IF NOT EXISTS `jobs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
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

-- Failed jobs table
CREATE TABLE IF NOT EXISTS `failed_jobs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `uuid` CHAR(36) NOT NULL UNIQUE,
    `connection` TEXT NOT NULL,
    `queue` TEXT NOT NULL,
    `payload` LONGTEXT NOT NULL,
    `exception` LONGTEXT NOT NULL,
    `failed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
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

CREATE INDEX idx_job_batches_name ON `job_batches` (`name`);
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
    
    private function getPgsqlEssentialSchema(string $jobsTable, string $failedTable): string
    {
        return "
CREATE TABLE IF NOT EXISTS \"{$jobsTable}\" (
    id BIGSERIAL PRIMARY KEY,
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
    uuid UUID NOT NULL UNIQUE,
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
    
    private function getPgsqlFullSchema(): string
    {
        return "
-- Jobs table
CREATE TABLE IF NOT EXISTS \"jobs\" (
    id BIGSERIAL PRIMARY KEY,
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
    uuid UUID NOT NULL UNIQUE,
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
    
    private function getSqliteEssentialSchema(string $jobsTable, string $failedTable): string
    {
        return "
CREATE TABLE IF NOT EXISTS \"{$jobsTable}\" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
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
    uuid CHAR(36) NOT NULL UNIQUE,
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
    
    private function getSqliteFullSchema(): string
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
    uuid CHAR(36) NOT NULL UNIQUE,
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
    
    private function executeStatements(\PDO $connection, string $sql): void
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
trait QueueWorkerValidationTrait
{
    private function validateQueue(string $queue, string $driver, SymfonyStyle $ss, bool $checkDriverExists = true): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $queue)) {
            $ss->error("Invalid queue name: {$queue}");
            return false;
        }
        if (!preg_match('/^[a-z]+$/', $driver)) {
            $ss->error("Invalid driver name: {$driver}");
            return false;
        }
        return true;
    }
}
class QueueWorkerCommand
{
    public static function getCommands(): array
    {
        return [
            // queue:make
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;

                public function __construct() {
                    parent::__construct('queue:make');
                    $this->setDescription('Creates a Queue Driver');
                }

                protected function configure(): void {
                    $this->addArgument('name', InputArgument::REQUIRED, 'The name of the queue driver')
                         ->addOption('type', null, InputOption::VALUE_OPTIONAL, 'Queue type (database, redis, sync, file, memory)', 'database')
                         ->addOption('config', null, InputOption::VALUE_NONE, 'Create configuration file')
                         ->addOption('register', null, InputOption::VALUE_NONE, 'Register in service providers')
                         ->addOption('command', null, InputOption::VALUE_NONE, 'Register in queue:work command');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeSafely($input, $output, function () use ($input, $output) {
                        $ss = new SymfonyStyle($input, $output);
                        $this->bootstrapDependencies();

                        $name = $input->getArgument('name');
                        $options = [
                            'type'     => $input->getOption('type'),
                            'config'   => $input->getOption('config'),
                            'register' => $input->getOption('register'),
                            'command'  => $input->getOption('command'),
                        ];

                        $file = $this->queueGenerator->generateQueueDriver($name, $options);
                        $ss->success('Queue driver created successfully!');
                        $ss->text(['File: ' . $file, 'Type: ' . $options['type']]);

                        $usage = $this->queueGenerator->generateCommandUsage($name, $options['type']);
                        $ss->section('Command Line Usage');
                        $ss->text(explode("\n", $usage));

                        $this->logger->info('Queue driver created', ['name' => $name, 'type' => $options['type']]);
                        return Command::SUCCESS;
                    });
                }
            },

            // queue:init
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;

                public function __construct() {
                    parent::__construct('queue:init');
                    $this->setDescription('Initialize all required queue driver files if they do not exist');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeSafely($input, $output, function () use ($input, $output) {
                        $ss = new SymfonyStyle($input, $output);
                        $this->bootstrapDependencies();

                        $types = ['database', 'redis', 'file', 'memory', 'sync'];
                        $this->driverResolver->ensureAllDriversInitialized();

                        $existing = [];
                        foreach ($this->queueGenerator->listQueues() as $q) {
                            if (in_array($q['name'], $types) && $q['exists']) {
                                $existing[] = $q['name'];
                            }
                        }
                        $initialized = array_diff($types, $existing);
                        if (empty($initialized)) {
                            $ss->success('All queue drivers are already present.');
                        } else {
                            $ss->success('Initialized: ' . implode(', ', $initialized));
                        }
                        if (!empty($existing)) {
                            $ss->text('Already present: ' . implode(', ', $existing));
                        }
                        
                        $ss->section('Database Setup');
                        try {
                            $pdo = DatabaseConnection::getInstance();
                            if (!$pdo instanceof \PDO) {
                                throw new \RuntimeException('Database connection not available or not PDO');
                            }
                            $this->createFullSchema($pdo);
                            $ss->success('Full queue database schema created successfully.');
                        } catch (\Throwable $e) {
                            $ss->error('Failed to create database schema: ' . $e->getMessage());
                            $ss->writeln('Please check your database configuration in .env and ensure the connection works.');
                            return Command::FAILURE;
                        }
                        
                        $this->logger->info('Queue init completed', ['initialized' => $initialized, 'present' => $existing]);
                        return Command::SUCCESS;
                    });
                }
            },

            // queue:work
            new class extends Command {
                use CommandHelper, QueueCommandHelper, DatabaseQueueSetup, QueueWorkerValidationTrait;
            
                public function __construct() {
                    parent::__construct('queue:work');
                    $this->setDescription('Start processing jobs from the queue');
                }
            
                protected function configure(): void {
                    $this->addOption('driver', 'd', InputOption::VALUE_OPTIONAL, 'Queue driver to use', 'database')
                         ->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Queue name to process', 'default')
                         ->addOption('sleep', 's', InputOption::VALUE_OPTIONAL, 'Seconds to sleep when no jobs are available', 3)
                         ->addOption('memory', 'm', InputOption::VALUE_OPTIONAL, 'Memory limit in MB', 128)
                         ->addOption('timeout', 't', InputOption::VALUE_OPTIONAL, 'Job timeout in seconds', 60)
                         ->addOption('max-jobs', null, InputOption::VALUE_OPTIONAL, 'Maximum number of jobs to process before exiting')
                         ->addOption('stop-on-empty', null, InputOption::VALUE_NONE, 'Stop when the queue is empty')
                         ->addOption('tries', null, InputOption::VALUE_OPTIONAL, 'Number of times to attempt a job', 3)
                         ->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'Path to queue configuration file')
                         ->addOption('daemon', null, InputOption::VALUE_NONE, 'Run the worker in daemon mode')
                         ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force the worker to run even in maintenance mode')
                          ->addOption('pid-file', null, InputOption::VALUE_OPTIONAL, 'Write process ID to this file')
                          ->addOption('instance', null, InputOption::VALUE_OPTIONAL, 'Worker instance number (when managed by supervisor)', 1);
                }
            
                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeSafely($input, $output, function () use ($input, $output) {
                        $ss = new SymfonyStyle($input, $output);
                        $this->bootstrapDependencies();
                        
                        $bootstrapPath = $this->findBootstrapFile();
                        if ($bootstrapPath && file_exists($bootstrapPath)) {
                            $app = require $bootstrapPath;
                            if (!Container::instancePresent()) {
                                Container::setInstance($app);
                            }
                            $this->appContainer = $app;
                        } else {
                            $ss->warning("Full bootstrap file not found at {$bootstrapPath}. Using minimal bootstrap - some features may be missing.");
                            $this->bootstrapDependencies();
                        }
                        $driver      = $input->getOption('driver');
                        $queue       = $input->getOption('queue');
                        $sleep       = (int) $input->getOption('sleep');
                        $memory      = (int) $input->getOption('memory');
                        $timeout     = (int) $input->getOption('timeout');
                        $maxJobs     = $input->getOption('max-jobs') ? (int) $input->getOption('max-jobs') : null;
                        $tries       = (int) $input->getOption('tries');
                        $stopOnEmpty = $input->getOption('stop-on-empty');
                        $daemon      = $input->getOption('daemon');
                        $force       = $input->getOption('force');
                        $instance    = (int) $input->getOption('instance');
            
                        if (!$force && file_exists(getcwd() . '/storage/framework/down')) {
                            $ss->error('Application is in maintenance mode. Use --force to override.');
                            return Command::FAILURE;
                        }
            
                        if (file_exists($bootstrapPath)) {
                            $this->queueConfig = $this->loadQueueConfig($input->getOption('config'));
                            $this->driverResolver = new QueueDriverResolver(
                                $this->appContainer,
                                $this->logger,
                                $this->queueGenerator,
                                $this->queueConfig
                            );
                        }
                        
                        $driver = ($driver === "default") ? "database" : $driver;
            
                        $queueDriver = $this->driverResolver->resolve($driver);
                        if (!$queueDriver) {
                            $ss->error("Queue driver '{$driver}' not found after initialization.");
                            return Command::FAILURE;
                        }
            
                        if ($driver === 'database') {
                            $pdo = DatabaseConnection::getInstance();
                            if (!$pdo instanceof \PDO) {
                                $ss->error('Database connection not available. Check your .env and config.');
                                return Command::FAILURE;
                            }
                            $table = $this->queueConfig['drivers']['database']['table'] ?? 'queue_jobs';
                            $failedTable = $this->queueConfig['drivers']['database']['failed_table'] ?? 'queue_failed_jobs';
                            $this->ensureQueueTablesExist($pdo, $table, $failedTable);
                        }
            
                        $processor = $this->createJobProcessor();
                        $workerOptions = [
                            'sleep'       => $sleep,
                            'memory'      => $memory,
                            'timeout'     => $timeout,
                            'maxTries'    => $tries,
                            'maxJobs'     => $maxJobs,
                            'stopOnEmpty' => $stopOnEmpty,
                        ];
                        
                        $manager = new BackgroundWorkerManager($this->appContainer);
                        $heartbeatInterval = (int) getenv('QUEUE_WORKER_HEARTBEAT_INTERVAL', 60);
                        $lastHeartbeat = 0;
            
                        $ss->title("Queue Worker");
                        $ss->text([
                            "Driver: <info>{$driver}</info>",
                            "Queue: <info>{$queue}</info>",
                            "Sleep: <info>{$sleep}s</info>",
                            "Memory: <info>{$memory}MB</info>",
                            "Timeout: <info>{$timeout}s</info>",
                            "Max Tries: <info>{$tries}</info>",
                            $maxJobs ? "Max Jobs: <info>{$maxJobs}</info>" : "Max Jobs: <info>unlimited</info>",
                            $stopOnEmpty ? "Stop on Empty: <info>yes</info>" : "Stop on Empty: <info>no</info>",
                            $daemon ? "Mode: <info>daemon</info>" : "Mode: <info>single run</info>",
                        ]);
            
                        $signalManager = new SignalManager();
                        $worker = null;
                        
                        $pidFile = $input->getOption('pid-file');
                        if ($pidFile) {
                            file_put_contents($pidFile, getmypid());
                            register_shutdown_function(function() use ($pidFile) {
                                if (file_exists($pidFile)) unlink($pidFile);
                            });
                        }
            
                        do {
                            $worker = new BaseWorker($this->appContainer, $queueDriver, $processor);
                            if (extension_loaded('pcntl')) {
                                $signalManager->register(fn() => $worker->stop(), [SIGINT, SIGTERM]);
                            }
            
                            $ss->newLine();
                            $ss->writeln("Starting worker... Press Ctrl+C to stop.");
                            $this->logger->info('Worker started', ['driver' => $driver, 'queue' => $queue]);
            
                            $startTime = time();
                            try {
                              $this->runWorkerWithHeartbeat($worker, $queue, $workerOptions, $manager, $driver, $queue, $instance, $heartbeatInterval, $lastHeartbeat);
                            } catch (\Throwable $e) {
                              $this->logger->error('Worker crashed: ' . $e->getMessage(), [
                                    'error' => $e->getMessage(),
                                    'trace' => $e->getTraceAsString(),
                                ]);
                            }
                            $endTime = time();
                            $status  = $worker->getStatus();
                            $this->logger->info('Worker finished', array_merge($status, ['queue' => $queue]));
            
                            $ss->newLine(2);
                            $ss->section("Worker Statistics");
                            $ss->text([
                                "Runtime: <info>" . ($endTime - $startTime) . "s</info>",
                                "Jobs Processed: <info>{$status['processed']}</info>",
                                "Jobs Failed: <info>{$status['failed']}</info>",
                                "Memory Peak: <info>" . round($status['memory_peak'] / 1024 / 1024, 2) . "MB</info>",
                                "Last Job: <info>" . ($status['last_job_at'] ? date('Y-m-d H:i:s', $status['last_job_at']) : 'Never') . "</info>",
                            ]);
            
                            if ($daemon && ($status['processed'] ?? 0) === 0) {
                                sleep(1);
                            }
            
                            if ($daemon && $status['memory_peak'] > $memory * 1024 * 1024) {
                                $ss->warning("Memory limit exceeded, restarting worker...");
                                unset($worker);
                            }
                        } while ($daemon);
            
                        return Command::SUCCESS;
                    });
                }
                
                private function findBootstrapFile(): ?string
                {
                    // Try relative to current working directory
                    $cwd = getcwd();
                    $candidate = $cwd . '/bootstrap/artisan.php';
                    if (file_exists($candidate)) {
                        return $candidate;
                    }
                    
                    // Try relative to the project root (if we can determine it from container)
                    if (isset($this->appContainer)) {
                        $root = $this->appContainer->getBasePath(); // assume container has getBasePath()
                        $candidate = $root . '/bootstrap/artisan.php';
                        if (file_exists($candidate)) {
                            return $candidate;
                        }
                    }
                    
                    // Walk upwards from cwd
                    $dir = $cwd;
                    for ($i = 0; $i < 10; $i++) {
                        $candidate = $dir . '/bootstrap/artisan.php';
                        if (file_exists($candidate)) {
                            return $candidate;
                        }
                        $parent = dirname($dir);
                        if ($parent === $dir) break;
                        $dir = $parent;
                    }
                    
                    return null;
                }
                
                private function runWorkerWithHeartbeat(BaseWorker $worker, string $queue, array $options, BackgroundWorkerManager $manager, string $driver, string $queueName, int $instance, int $interval, int &$lastHeartbeat): void
                {
                    if (method_exists($worker, 'setHeartbeatCallback')) {
                        $worker->setHeartbeatCallback(function() use ($manager, $driver, $queueName, $instance) {
                            $manager->updateHeartbeat($queueName, $driver, $instance);
                        });
                    }
                    $worker->start($queue, $options);
                }
            },
            // queue:supervisor
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;
                
                public function __construct() {
                    parent::__construct('queue:supervisor');
                    $this->setDescription('Run a supervisor that keeps the specified number of workers alive');
                }
                
                protected function configure(): void {
                    $this->addOption('driver', 'd', InputOption::VALUE_OPTIONAL, 'Queue driver', 'database')
                         ->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Queue name', 'default')
                         ->addOption('concurrency', 'c', InputOption::VALUE_OPTIONAL, 'Number of worker instances', 1)
                         ->addOption('daemon', null, InputOption::VALUE_NONE, 'Run as a daemon (keep monitoring forever)');
                }
                
                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeSafely($input, $output, function () use ($input, $output) {
                        $this->bootstrapDependencies();
                        $ss = new SymfonyStyle($input, $output);
                        
                        $driver      = $input->getOption('driver');
                        $queue       = $input->getOption('queue');
                        $concurrency = (int) $input->getOption('concurrency');
                        $daemon      = $input->getOption('daemon');
                        
                        if (!$this->validateQueue($queue, $driver, $ss)) {
                            return Command::FAILURE;
                        }
                        
                        $manager = new BackgroundWorkerManager($this->appContainer);
                        
                        if ($daemon) {
                            $ss->note("Starting supervisor for {$driver}:{$queue} with {$concurrency} workers. Press Ctrl+C to stop.");
                            $manager->monitorWorker($queue, $driver, $concurrency, function($msg) use ($output) {
                                $output->writeln($msg);
                            });
                            return Command::SUCCESS;
                        }
                        
                        // One-time check and start if needed
                        $statuses = $manager->workerStatus($queue, $driver);
                        $running = count(array_filter($statuses, fn($s) => $s['running']));
                        if ($running >= $concurrency) {
                            $ss->success("Already have {$running} worker(s) running.");
                        } else {
                            $needed = $concurrency - $running;
                            $ss->text("Starting {$needed} worker(s)...");
                            $started = $manager->startWorker($queue, $driver, $needed);
                            $ss->success("Started {$started} worker(s).");
                        }
                        
                        return Command::SUCCESS;
                    });
                }
            },
            // queue:worker-start
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;
                
                public function __construct() {
                    parent::__construct('queue:worker-start');
                    $this->setDescription('Start one or more queue workers');
                }
                
                protected function configure(): void {
                    $this->addOption('driver', 'd', InputOption::VALUE_OPTIONAL, 'Queue driver', 'database')
                         ->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Queue name', 'default')
                         ->addOption('concurrency', 'c', InputOption::VALUE_OPTIONAL, 'Number of worker instances to start', 1);
                }
                
                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeSafely($input, $output, function () use ($input, $output) {
                        $this->bootstrapDependencies();
                        $ss = new SymfonyStyle($input, $output);
                        
                        $driver      = $input->getOption('driver');
                        $queue       = $input->getOption('queue');
                        $concurrency = (int) $input->getOption('concurrency');
                        
                        if (!$this->validateQueue($queue, $driver, $ss)) {
                            return Command::FAILURE;
                        }
                        
                        $manager = new BackgroundWorkerManager($this->appContainer);
                        $started = $manager->startWorker($queue, $driver, $concurrency);
                        
                        if ($started > 0) {
                            $ss->success("Started {$started} worker(s) for {$driver}:{$queue}");
                        } else {
                            $ss->warning("No workers started. They may already be running.");
                        }
                        return Command::SUCCESS;
                    });
                }
            },
            // queue:worker-stop
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;
                
                public function __construct() {
                    parent::__construct('queue:worker-stop');
                    $this->setDescription('Stop queue workers');
                }
                
                protected function configure(): void {
                    $this->addOption('driver', 'd', InputOption::VALUE_OPTIONAL, 'Queue driver', 'database')
                         ->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Queue name', 'default')
                         ->addOption('instance', null, InputOption::VALUE_OPTIONAL, 'Specific instance number (omit to stop all)');
                }
                
                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeSafely($input, $output, function () use ($input, $output) {
                        $this->bootstrapDependencies();
                        $ss = new SymfonyStyle($input, $output);
                        
                        $driver   = $input->getOption('driver');
                        $queue    = $input->getOption('queue');
                        $instance = $input->getOption('instance') ? (int) $input->getOption('instance') : null;
                        
                        if (!$this->validateQueue($queue, $driver, $ss)) {
                            return Command::FAILURE;
                        }
                        
                        $manager = new BackgroundWorkerManager($this->appContainer);
                        $stopped = $manager->stopWorker($queue, $driver, $instance);
                        
                        if ($stopped === 0) {
                            $ss->warning("No running workers found for {$driver}:{$queue}");
                        } else {
                            $ss->success("Stopped {$stopped} worker(s).");
                        }
                        return Command::SUCCESS;
                    });
                }
            },
            
            // queue:worker-restart
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;
                
                public function __construct() {
                    parent::__construct('queue:worker-restart');
                    $this->setDescription('Restart queue workers');
                }
                
                protected function configure(): void {
                    $this->addOption('driver', 'd', InputOption::VALUE_OPTIONAL, 'Queue driver', 'database')
                         ->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Queue name', 'default')
                         ->addOption('concurrency', 'c', InputOption::VALUE_OPTIONAL, 'Number of workers to keep after restart', 1);
                }
                
                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeSafely($input, $output, function () use ($input, $output) {
                        $this->bootstrapDependencies();
                        $ss = new SymfonyStyle($input, $output);
                        
                        $driver      = $input->getOption('driver');
                        $queue       = $input->getOption('queue');
                        $concurrency = (int) $input->getOption('concurrency');
                        
                        if (!$this->validateQueue($queue, $driver, $ss)) {
                            return Command::FAILURE;
                        }
                        
                        $manager = new BackgroundWorkerManager($this->appContainer);
                        $manager->stopWorker($queue, $driver);
                        $started = $manager->startWorker($queue, $driver, $concurrency);
                        
                        $ss->success("Restarted {$started} worker(s) for {$driver}:{$queue}");
                        return Command::SUCCESS;
                    });
                }
            },
            // queue:worker-status
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;
                
                public function __construct() {
                    parent::__construct('queue:worker-status');
                    $this->setDescription('Show status of managed workers');
                }
                
                protected function configure(): void {
                    $this->addOption('driver', 'd', InputOption::VALUE_OPTIONAL, 'Queue driver', 'database')
                         ->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Queue name', 'default')
                         ->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Output format (table, json)', 'table');
                }
                
                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeSafely($input, $output, function () use ($input, $output) {
                        $this->bootstrapDependencies();
                        $ss = new SymfonyStyle($input, $output);
                        
                        $driver = $input->getOption('driver');
                        $queue  = $input->getOption('queue');
                        $format = $input->getOption('format');
                        
                        if (!$this->validateQueue($queue, $driver, $ss, false)) {
                            return Command::FAILURE;
                        }
                        
                        $manager = new BackgroundWorkerManager($this->appContainer);
                        $statuses = $manager->workerStatus($queue, $driver);
                        
                        if (empty($statuses)) {
                            $ss->warning("No workers found for {$driver}:{$queue}");
                            return Command::SUCCESS;
                        }
                        
                        if ($format === 'json') {
                            $ss->writeln(json_encode($statuses, JSON_PRETTY_PRINT));
                            return Command::SUCCESS;
                        }
                        
                        $table = new Table($output);
                        $table->setHeaders(['Instance', 'PID', 'Running', 'Healthy', 'Memory (MB)', 'Last Heartbeat']);
                        foreach ($statuses as $status) {
                            $table->addRow([
                                $status['instance'],
                                $status['pid'] ?? '-',
                                $status['running'] ? 'Yes' : 'No',
                                $status['healthy'] ? 'Yes' : ($status['running'] ? 'No' : '-'),
                                isset($status['memory_mb']) ? round($status['memory_mb'], 2) : '-',
                                isset($status['last_heartbeat']) ? date('Y-m-d H:i:s', $status['last_heartbeat']) : '-',
                            ]);
                        }
                        $table->render();
                        
                        return Command::SUCCESS;
                    });
                }
            },
            // queue:worker-cleanup
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;
                
                public function __construct() {
                    parent::__construct('queue:worker-cleanup');
                    $this->setDescription('Remove orphaned PID files and stale heartbeats');
                }
                
                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeSafely($input, $output, function () use ($input, $output) {
                        $this->bootstrapDependencies();
                        $ss = new SymfonyStyle($input, $output);
                        
                        $manager = new BackgroundWorkerManager($this->appContainer);
                        $manager->cleanupOrphanedPids();
                        $ss->success("Orphaned PID files and stale heartbeats cleaned.");
                        return Command::SUCCESS;
                    });
                }
            },
            // queue:list
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;

                public function __construct() {
                    parent::__construct('queue:list');
                    $this->setDescription('List all available queue drivers and jobs');
                }

                protected function configure(): void {
                    $this->addOption('type', null, InputOption::VALUE_OPTIONAL, 'Filter by type (drivers, jobs, all)', 'all')
                         ->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Output format (table, json, list)', 'table');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Queue Driver', function (SymfonyStyle $ss) use ($input) {
                        $this->bootstrapDependencies();
                        $generator = $this->queueGenerator;
                        $type   = $input->getOption('type');
                        $format = $input->getOption('format');

                        $data = [];
                        if ($type === 'all' || $type === 'drivers') {
                            $queues = $generator->listQueues();
                            $data['drivers'] = $queues;
                            if ($format === 'table' && $type === 'drivers') {
                                $ss->title('Available Queue Drivers');
                                $rows = array_map(fn($q) => [$q['name'], $q['file'], $q['exists'] ? 'Yes' : 'No', $q['path']], $queues);
                                $ss->table(['Name', 'File', 'Loaded', 'Path'], $rows);
                            }
                        }

                        if ($type === 'all' || $type === 'jobs') {
                            $jobs = $generator->listJobs();
                            $data['jobs'] = $jobs;
                            if ($format === 'table' && $type === 'jobs') {
                                $ss->title('Available Jobs');
                                $rows = array_map(fn($j) => [$j['name'], $j['file'], $j['exists'] ? 'Yes' : 'No', $j['path']], $jobs);
                                $ss->table(['Name', 'File', 'Loaded', 'Path'], $rows);
                            }
                        }

                        if ($format === 'json') {
                            $ss->writeln(json_encode($data, JSON_PRETTY_PRINT));
                        } elseif ($format === 'list') {
                            if ($type === 'all' || $type === 'drivers') {
                                $ss->section('Queue Drivers');
                                foreach ($data['drivers'] as $driver) {
                                    $ss->writeln("  • {$driver['name']} ({$driver['file']})");
                                }
                            }
                            if ($type === 'all' || $type === 'jobs') {
                                $ss->section('Jobs');
                                foreach ($data['jobs'] as $job) {
                                    $ss->writeln("  • {$job['name']} ({$job['file']})");
                                }
                            }
                        }
                        return Command::SUCCESS;
                    });
                }
            },
            // queue:make-job
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;

                public function __construct() {
                    parent::__construct('queue:make-job');
                    $this->setDescription('Create a new job class');
                }

                protected function configure(): void {
                    $this->addArgument('name', InputArgument::REQUIRED, 'The name of the job')
                         ->addOption('type', null, InputOption::VALUE_OPTIONAL, 'Job type (standard, email, notification, model, report, sync)', 'standard')
                         ->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Queue name', 'default')
                         ->addOption('max-attempts', null, InputOption::VALUE_OPTIONAL, 'Maximum attempts', 3)
                         ->addOption('timeout', null, InputOption::VALUE_OPTIONAL, 'Timeout in seconds', 60)
                         ->addOption('delay', null, InputOption::VALUE_OPTIONAL, 'Default delay in seconds', 0)
                         ->addOption('database', null, InputOption::VALUE_NONE, 'Create database migration for model jobs')
                         ->addOption('command', null, InputOption::VALUE_NONE, 'Register job command');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Create Job', function (SymfonyStyle $ss) use ($input) {
                        $this->bootstrapDependencies();
                        $logger = $this->logger;
                        $generator = $this->queueGenerator;

                        $name    = $input->getArgument('name');
                        $options = [
                            'type'          => $input->getOption('type'),
                            'queue'         => $input->getOption('queue'),
                            'max_attempts'  => (int) $input->getOption('max-attempts'),
                            'timeout'       => (int) $input->getOption('timeout'),
                            'delay'         => (int) $input->getOption('delay'),
                            'database'      => $input->getOption('database'),
                            'command'       => $input->getOption('command'),
                        ];

                        $file = $generator->generateJob($name, $options);
                        $ss->success('Job created successfully!');
                        $ss->text([
                            'File: ' . $file,
                            'Type: ' . $options['type'],
                            'Queue: ' . $options['queue'],
                            'Max Attempts: ' . $options['max_attempts'],
                            'Timeout: ' . $options['timeout'] . 's',
                        ]);

                        $ss->section('Usage Example');
                        $ss->text([
                            'Dispatch job:',
                            '  $job = new ' . str_replace('Job', '', $name) . 'Job($data);',
                            '  $dispatcher->dispatch($job);',
                            '',
                            'Or use the command:',
                            '  php artisan queue:work --queue=' . $options['queue'],
                        ]);

                        $logger->info('Job class created', ['name' => $name, 'queue' => $options['queue']]);
                        return Command::SUCCESS;
                    });
                }
            },
            // queue:status
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;

                public function __construct() {
                    parent::__construct('queue:status');
                    $this->setDescription('Display the status of queues');
                }

                protected function configure(): void {
                    $this->addOption('driver', 'd', InputOption::VALUE_OPTIONAL, 'Queue driver to use', 'database')
                         ->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Specific queue to check', 'all')
                         ->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Output format (table, json)', 'table');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeSafely($input, $output, function () use ($input, $output) {
                        $this->bootstrapDependencies();
                        $ss = new SymfonyStyle($input, $output);

                        $driverName = $input->getOption('driver');
                        $queueDriver = $this->driverResolver->resolve($driverName);
                        if (!$queueDriver) {
                            $ss->error("Queue driver '{$driverName}' not found.");
                            return Command::FAILURE;
                        }

                        $specificQueue = $input->getOption('queue');
                        $format        = $input->getOption('format');

                        if ($specificQueue === 'all') {
                            $queues = $queueDriver->getQueues();
                        } else {
                            $queues = [$specificQueue];
                        }

                        $stats = [];
                        foreach ($queues as $queue) {
                            $stats[] = $queueDriver->getStats($queue);
                        }

                        if ($format === 'json') {
                            $ss->writeln(json_encode($stats, JSON_PRETTY_PRINT));
                        } else {
                            if (empty($stats)) {
                                $ss->warning('No queues found.');
                            } else {
                                $table = new Table($output);
                                $table->setHeaders(['Queue', 'Size', 'Driver', 'Health']);
                                foreach ($stats as $stat) {
                                    $table->addRow([
                                        $stat['name'] ?? 'unknown',
                                        $stat['size'] ?? 0,
                                        $stat['driver'] ?? 'unknown',
                                        $queueDriver->isHealthy() ? '<info>✓ Healthy</info>' : '<error>✗ Unhealthy</error>'
                                    ]);
                                }
                                $table->render();
                            }
                        }

                        $this->logger->info('Queue status checked', ['driver' => $driverName, 'queues' => $queues ?? []]);
                        return Command::SUCCESS;
                    });
                }
            },
            // queue:failed
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;

                public function __construct() {
                    parent::__construct('queue:failed');
                    $this->setDescription('List all failed queue jobs');
                }

                protected function configure(): void {
                    $this->addOption('driver', 'd', InputOption::VALUE_OPTIONAL, 'Queue driver to use', 'database')
                         ->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Queue name', 'default')
                         ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Limit number of failed jobs to display', 50)
                         ->addOption('offset', 'o', InputOption::VALUE_OPTIONAL, 'Offset for failed jobs', 0)
                         ->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Output format (table, json)', 'table');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Failed Queue Jobs', function (SymfonyStyle $ss) use ($input, $output) {
                        $this->bootstrapDependencies();
                        $logger = $this->logger;
                        $config    = $this->loadQueueConfig(null);
                        $container = $this->getContainerInstance();
                        $driverName = $input->getOption('driver');
                        $queueDriver = $this->getQueueDriverOrInit($driverName, $container, $config);

                        if (!$queueDriver) {
                            $ss->error("Queue driver '{$driverName}' not found after initialization.");
                            return Command::FAILURE;
                        }

                        $queue  = $input->getOption('queue');
                        $limit  = (int) $input->getOption('limit');
                        $offset = (int) $input->getOption('offset');
                        $format = $input->getOption('format');

                        $failedJobs = $queueDriver->getFailed($queue, $limit, $offset);

                        if ($format === 'json') {
                            $ss->writeln(json_encode($failedJobs, JSON_PRETTY_PRINT));
                        } else {
                            if (empty($failedJobs)) {
                                $ss->success('No failed jobs found.');
                            } else {
                                $table = new Table($output);
                                $table->setHeaders(['ID', 'Job Name', 'Queue', 'Attempts', 'Failed At', 'Error']);
                                $rows = [];
                                foreach ($failedJobs as $job) {
                                    $rows[] = [
                                        $job['id'] ?? 'N/A',
                                        $job['name'] ?? 'Unknown',
                                        $job['queue'] ?? 'default',
                                        $job['attempts'] ?? 0,
                                        isset($job['failed_at']) ? date('Y-m-d H:i:s', $job['failed_at']) : 'N/A',
                                        substr($job['error'] ?? 'No error message', 0, 50) . '...'
                                    ];
                                }
                                $table->setRows($rows);
                                $table->render();
                                $ss->newLine();
                                $ss->text("Total failed jobs: " . count($failedJobs));
                            }
                        }

                        $logger->info('Listed failed jobs', ['queue' => $queue, 'count' => count($failedJobs)]);
                        return Command::SUCCESS;
                    });
                }
            },
            // queue:retry
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;

                public function __construct() {
                    parent::__construct('queue:retry');
                    $this->setDescription('Retry a failed queue job');
                }

                protected function configure(): void {
                    $this->addArgument('id', InputArgument::OPTIONAL, 'The ID of the failed job (use "all" to retry all)')
                         ->addOption('driver', 'd', InputOption::VALUE_OPTIONAL, 'Queue driver to use', 'database')
                         ->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Queue name', 'default');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Retry Failed Queue Job', function (SymfonyStyle $ss) use ($input, $output) {
                        $this->bootstrapDependencies();
                        $logger = $this->logger;
                        $config    = $this->loadQueueConfig(null);
                        $container = $this->getContainerInstance();
                        $driverName = $input->getOption('driver');
                        $queueDriver = $this->getQueueDriverOrInit($driverName, $container, $config);

                        if (!$queueDriver) {
                            $ss->error("Queue driver '{$driverName}' not found after initialization.");
                            return Command::FAILURE;
                        }

                        $jobId = $input->getArgument('id');
                        $queue = $input->getOption('queue');

                        if (!$jobId) {
                            $ss->error('Job ID is required. Use "all" to retry all failed jobs.');
                            return Command::FAILURE;
                        }

                        if ($jobId === 'all') {
                            $failedJobs = $queueDriver->getFailed($queue, 1000, 0);
                            $successCount = 0;
                            $totalCount = count($failedJobs);

                            if ($totalCount === 0) {
                                $ss->success('No failed jobs to retry.');
                                return Command::SUCCESS;
                            }

                            $ss->text("Retrying {$totalCount} failed jobs...");
                            $progressBar = new ProgressBar($output, $totalCount);
                            $progressBar->start();

                            foreach ($failedJobs as $job) {
                                if ($queueDriver->retryFailed($job['id'] ?? '', $queue)) {
                                    $successCount++;
                                }
                                $progressBar->advance();
                            }
                            $progressBar->finish();
                            $ss->newLine(2);

                            $logger->info("Retried all failed jobs", [
                                'queue' => $queue, 'total' => $totalCount, 'success' => $successCount
                            ]);

                            if ($successCount === $totalCount) {
                                $ss->success("All {$totalCount} jobs retried successfully.");
                            } else {
                                $ss->warning("Retried {$successCount} out of {$totalCount} jobs.");
                            }
                        } else {
                            if ($queueDriver->retryFailed($jobId, $queue)) {
                                $ss->success("Job {$jobId} retried successfully.");
                                $logger->info('Failed job retried', ['job_id' => $jobId, 'queue' => $queue]);
                            } else {
                                $ss->error("Failed to retry job {$jobId}.");
                                $logger->warning('Failed to retry job', ['job_id' => $jobId]);
                                return Command::FAILURE;
                            }
                        }

                        return Command::SUCCESS;
                    });
                }
            },
            // queue:forget
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;

                public function __construct() {
                    parent::__construct('queue:forget');
                    $this->setDescription('Remove a failed queue job from the failed jobs list');
                }

                protected function configure(): void {
                    $this->addArgument('id', InputArgument::REQUIRED, 'The ID of the failed job')
                         ->addOption('driver', 'd', InputOption::VALUE_OPTIONAL, 'Queue driver to use', 'database')
                         ->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Queue name', 'default');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Remove Failed Queue Job', function (SymfonyStyle $ss) use ($input) {
                        $this->bootstrapDependencies();
                        $logger = $this->logger;
                        $config    = $this->loadQueueConfig(null);
                        $container = $this->getContainerInstance();
                        $driverName = $input->getOption('driver');
                        $queueDriver = $this->getQueueDriverOrInit($driverName, $container, $config);

                        if (!$queueDriver) {
                            $ss->error("Queue driver '{$driverName}' not found after initialization.");
                            return Command::FAILURE;
                        }

                        $jobId = $input->getArgument('id');
                        $queue = $input->getOption('queue');

                        if ($queueDriver->forgetFailed($jobId, $queue)) {
                            $ss->success("Job {$jobId} removed from failed jobs list.");
                            $logger->info('Forgot failed job', ['job_id' => $jobId]);
                        } else {
                            $ss->error("Failed to remove job {$jobId}.");
                            return Command::FAILURE;
                        }

                        return Command::SUCCESS;
                    });
                }
            },

            // queue:flush
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;

                public function __construct() {
                    parent::__construct('queue:flush');
                    $this->setDescription('Flush all failed queue jobs');
                }

                protected function configure(): void {
                    $this->addOption('driver', 'd', InputOption::VALUE_OPTIONAL, 'Queue driver to use', 'database')
                         ->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Queue name', 'default')
                         ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force flush without confirmation');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Flush Failed Queue Jobs', function (SymfonyStyle $ss) use ($input) {
                        $this->bootstrapDependencies();
                        $logger = $this->logger;
                        $config    = $this->loadQueueConfig(null);
                        $container = $this->getContainerInstance();
                        $driverName = $input->getOption('driver');
                        $queueDriver = $this->getQueueDriverOrInit($driverName, $container, $config);

                        if (!$queueDriver) {
                            $ss->error("Queue driver '{$driverName}' not found after initialization.");
                            return Command::FAILURE;
                        }

                        $queue = $input->getOption('queue');
                        $force = $input->getOption('force');

                        if (!$force) {
                            $confirmed = $ss->confirm(
                                'Are you sure you want to flush all failed jobs? This action cannot be undone.',
                                false
                            );
                            if (!$confirmed) {
                                $ss->warning('Operation cancelled.');
                                return Command::SUCCESS;
                            }
                        }

                        $count = $queueDriver->flushFailed($queue);
                        if ($count > 0) {
                            $ss->success("Flushed {$count} failed jobs.");
                            $logger->info('Flushed failed jobs', ['queue' => $queue, 'count' => $count]);
                        } else {
                            $ss->info('No failed jobs to flush.');
                        }

                        return Command::SUCCESS;
                    });
                }
            },

            // queue:clear
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;

                public function __construct() {
                    parent::__construct('queue:clear');
                    $this->setDescription('Clear all jobs from a queue');
                }

                protected function configure(): void {
                    $this->addOption('driver', 'd', InputOption::VALUE_OPTIONAL, 'Queue driver to use', 'database')
                         ->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Queue name to clear', 'default')
                         ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force clear without confirmation');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Clear Queue', function (SymfonyStyle $ss) use ($input) {
                        $this->bootstrapDependencies();
                        $logger = $this->logger;
                        $config    = $this->loadQueueConfig(null);
                        $container = $this->getContainerInstance();
                        $driverName = $input->getOption('driver');
                        $queueDriver = $this->getQueueDriverOrInit($driverName, $container, $config);

                        if (!$queueDriver) {
                            $ss->error("Queue driver '{$driverName}' not found after initialization.");
                            return Command::FAILURE;
                        }

                        $queue = $input->getOption('queue');
                        $force = $input->getOption('force');

                        $queueSize = $queueDriver->size($queue);
                        if ($queueSize === 0) {
                            $ss->info("Queue '{$queue}' is already empty.");
                            return Command::SUCCESS;
                        }

                        if (!$force) {
                            $confirmed = $ss->confirm(
                                "Are you sure you want to clear {$queueSize} jobs from queue '{$queue}'? This action cannot be undone.",
                                false
                            );
                            if (!$confirmed) {
                                $ss->warning('Operation cancelled.');
                                return Command::SUCCESS;
                            }
                        }

                        $clearedCount = $queueDriver->clear($queue);
                        $ss->success("Cleared {$clearedCount} jobs from queue '{$queue}'.");
                        $logger->info('Queue cleared', ['queue' => $queue, 'cleared' => $clearedCount]);

                        return Command::SUCCESS;
                    });
                }
            },

            // queue:health
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;

                public function __construct() {
                    parent::__construct('queue:health');
                    $this->setDescription('Check the health of queue connections');
                }

                protected function configure(): void {
                    $this->addOption('driver', 'd', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Specific drivers to check (default: all)')
                         ->addOption('timeout', 't', InputOption::VALUE_OPTIONAL, 'Timeout for health check in seconds', 5);
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Queue Health Check', function (SymfonyStyle $ss) use ($input, $output) {
                        $this->bootstrapDependencies();
                        $logger = $this->logger;
                        $config    = $this->loadQueueConfig(null);
                        $container = $this->getContainerInstance();

                        $driversToCheck = $input->getOption('driver');
                        $timeout        = (int) $input->getOption('timeout');

                        if (empty($driversToCheck)) {
                            $driversToCheck = array_keys($config['drivers'] ?? []);
                        }

                        if (empty($driversToCheck)) {
                            $ss->warning('No queue drivers configured.');
                            return Command::SUCCESS;
                        }

                        $results = [];
                        foreach ($driversToCheck as $driverName) {
                            $queueDriver = $this->getQueueDriverOrInit($driverName, $container, $config);
                            if (!$queueDriver) {
                                $results[] = [
                                    'driver'  => $driverName,
                                    'status'  => 'NOT FOUND',
                                    'message' => 'Driver not configured'
                                ];
                                continue;
                            }

                            $startTime = microtime(true);
                            $isHealthy = false;
                            $message   = '';

                            try {
                                if (function_exists('set_time_limit')) {
                                    @set_time_limit($timeout);
                                }
                                $isHealthy = $queueDriver->isHealthy();
                                $message   = $isHealthy ? 'Connection successfull' : 'Connection failed';
                            } catch (\Exception $e) {
                                $message = 'Error: ' . $e->getMessage();
                            }

                            $responseTime = round((microtime(true) - $startTime) * 1000, 2);
                            $results[] = [
                                'driver'        => $driverName,
                                'status'        => $isHealthy ? 'HEALTHY' : 'UNHEALTHY',
                                'response_time' => $responseTime . 'ms',
                                'message'       => $message
                            ];
                        }

                        $table = new Table($output);
                        $table->setHeaders(['Driver', 'Status', 'Response Time', 'Message']);
                        $rows = [];
                        foreach ($results as $result) {
                            $statusColor = $result['status'] === 'HEALTHY' ? 'info' : ($result['status'] === 'NOT FOUND' ? 'comment' : 'error');
                            $rows[] = [
                                $result['driver'],
                                "<{$statusColor}>" . $result['status'] . "</{$statusColor}>",
                                $result['response_time'] ?? 'N/A',
                                $result['message']
                            ];
                        }
                        $table->setRows($rows);
                        $table->render();

                        $healthyCount = count(array_filter($results, fn($r) => $r['status'] === 'HEALTHY'));
                        $totalCount   = count($results);

                        $ss->newLine();
                        if ($healthyCount === $totalCount) {
                            $ss->success("All {$totalCount} queue drivers are healthy.");
                        } else {
                            $ss->warning("{$healthyCount} out of {$totalCount} queue drivers are healthy.");
                        }

                        $logger->info('Health check completed', ['healthy' => $healthyCount, 'total' => $totalCount]);
                        return Command::SUCCESS;
                    });
                }
            },
            // queue:db:info
            new class extends Command {
                use CommandHelper, QueueCommandHelper, QueueWorkerValidationTrait;
            
                public function __construct() {
                    parent::__construct('queue:db-info');
                    $this->setDescription('Display current database driver information and test connection');
                }
            
                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Driver Info', function (SymfonyStyle $ss) {
                        $this->bootstrapDependencies();
                        try {
                            $config = \Mlangeni\Machinjiri\Core\Database\DatabaseConnection::getConfig();
                            if (!$config) {
                                $config = $this->loadDatabaseConfig();
                            }
                        } catch (\Throwable $e) {
                            // Fallback: load from environment
                            $config = $this->loadDatabaseConfig();
                        }
            
                        $ss->section('Database Configuration');
            
                        $driver = $config['driver'] ?? 'unknown';
                        $ss->writeln("Driver:      <info>{$driver}</info>");
            
                        switch ($driver) {
                            case 'mysql':
                            case 'pgsql':
                                $host = $config['host'] ?? 'not set';
                                $port = $config['port'] ?? ($driver === 'mysql' ? 3306 : 5432);
                                $dbname = $config['database'] ?? 'not set';
                                $username = $config['username'] ?? 'not set';
                                $charset = $config['charset'] ?? 'utf8mb4';
                                $socket = $config['unix_socket'] ?? 'not used';
            
                                $ss->writeln("Host:        <info>{$host}</info>");
                                $ss->writeln("Port:        <info>{$port}</info>");
                                $ss->writeln("Database:    <info>{$dbname}</info>");
                                $ss->writeln("Username:    <info>{$username}</info>");
                                $ss->writeln("Charset:     <info>{$charset}</info>");
                                $ss->writeln("Unix Socket: <info>{$socket}</info>");
                                break;
            
                            case 'sqlite':
                                $path = $config['path'] ?? $config['database'] ?? 'not set';
                                $ss->writeln("Database file: <info>{$path}</info>");
                                break;
            
                            case 'mongodb':
                                $host = $config['host'] ?? 'localhost';
                                $port = $config['port'] ?? 27017;
                                $dbname = $config['database'] ?? 'not set';
                                $username = $config['username'] ?? 'not set';
                                $ss->writeln("Host:        <info>{$host}</info>");
                                $ss->writeln("Port:        <info>{$port}</info>");
                                $ss->writeln("Database:    <info>{$dbname}</info>");
                                $ss->writeln("Username:    <info>{$username}</info>");
                                break;
            
                            default:
                                $ss->writeln("DSN:         <info>" . ($config['dsn'] ?? 'not set') . "</info>");
                                $ss->writeln("Username:    <info>" . ($config['username'] ?? 'not set') . "</info>");
                        }
            
                        $ss->newLine();
                        $ss->section('Connection Test');
            
                        try {
                            $conn = \Mlangeni\Machinjiri\Core\Database\DatabaseConnection::getInstance();
            
                            if ($conn instanceof \PDO) {
                                $stmt = $conn->query('SELECT 1 as test');
                                $result = $stmt->fetch();
                                if ($result && $result['test'] == 1) {
                                    $ss->success("Database connection successful!");
            
                                    try {
                                        $versionStmt = $conn->query('SELECT VERSION() as version');
                                        $version = $versionStmt->fetch();
                                        if ($version) {
                                            $ss->writeln("MySQL Version: <info>" . $version['version'] . "</info>");
                                        }
                                    } catch (\Throwable $e) {
                                        // ignore
                                    }
                                } else {
                                    $ss->error("Query failed: unexpected result");
                                    return Command::FAILURE;
                                }
                            } elseif ($conn instanceof \MongoDB\Client) {
                                $ss->success("MongoDB connection successful!");
                                $ss->writeln("MongoDB version: <info>" . $conn->getManager()->getServer()->getVersion() . "</info>");
                            } else {
                                $ss->warning("Connection object is of unknown type: " . get_class($conn));
                            }
                        } catch (\Throwable $e) {
                            $ss->error("Connection failed: " . $e->getMessage());
            
                            $message = $e->getMessage();
                            if (str_contains($message, 'No such file or directory')) {
                                $ss->writeln("");
                                $ss->writeln("<comment>Possible causes:</comment>");
                                $ss->writeln("  • Using 'localhost' as host forces a Unix socket connection, but the socket file is missing.");
                                $ss->writeln("  • Solution: change DB_HOST in .env to '127.0.0.1' to force TCP/IP connection.");
                                $ss->writeln("  • Or provide the correct MySQL socket path in configuration (unix_socket).");
                            } elseif (str_contains($message, 'Access denied')) {
                                $ss->writeln("");
                                $ss->writeln("<comment>Possible causes:</comment>");
                                $ss->writeln("  • Incorrect username or password.");
                                $ss->writeln("  • Check DB_USERNAME and DB_PASSWORD in .env file.");
                            } elseif (str_contains($message, 'Unknown database')) {
                                $ss->writeln("");
                                $ss->writeln("<comment>Possible causes:</comment>");
                                $ss->writeln("  • Database name does not exist.");
                                $ss->writeln("  • Check DB_DATABASE in .env file.");
                            }
            
                            return Command::FAILURE;
                        }
            
                        return Command::SUCCESS;
                    });
                }
            },
        ];
    }
}