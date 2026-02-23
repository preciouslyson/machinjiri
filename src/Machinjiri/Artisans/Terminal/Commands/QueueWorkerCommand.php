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
use Mlangeni\Machinjiri\Core\Artisans\Generators\QueueJobGenerator;
use Mlangeni\Machinjiri\Core\Container;

class QueueWorkerCommand
{
    public static function getCommands(): array
    {
        return [
            // Existing commands
            new class extends Command {
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
                
                protected function execute(InputInterface $input, OutputInterface $output): int {
                    try {
                        $io = new SymfonyStyle($input, $output);
                        $io->title("Machinjiri - Queue Driver");
                        $generator = new QueueJobGenerator(getcwd());
                        
                        $name = $input->getArgument('name');
                        $options = [
                            'type' => $input->getOption('type'),
                            'config' => $input->getOption('config'),
                            'register' => $input->getOption('register'),
                            'command' => $input->getOption('command'),
                        ];
                        
                        $file = $generator->generateQueueDriver($name, $options);
                        
                        $io->success('Queue driver created successfully!');
                        $io->text([
                            'File: ' . $file,
                            'Type: ' . $options['type'],
                        ]);
                        
                        // Show usage instructions
                        $usage = $generator->generateCommandUsage($name, $options['type']);
                        $io->section('Command Line Usage');
                        $io->text(explode("\n", $usage));
                        
                        return Command::SUCCESS;
                    } catch (\Exception $e) {
                        $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
                        return Command::FAILURE;
                    }
                }
            },
            
            new class extends Command {
                public function __construct() {
                    parent::__construct('queue:work');
                    $this->setDescription('Start processing jobs from the queue');
                }
                
                protected function configure(): void {
                    $this->addOption('driver', 'd', InputOption::VALUE_OPTIONAL, 'Queue driver to use (database, redis, sync, file, memory, or custom)', 'database')
                         ->addOption('queue', 'qu', InputOption::VALUE_OPTIONAL, 'Queue name to process', 'default')
                         ->addOption('sleep', 's', InputOption::VALUE_OPTIONAL, 'Seconds to sleep when no jobs are available', 3)
                         ->addOption('memory', 'm', InputOption::VALUE_OPTIONAL, 'Memory limit in MB', 128)
                         ->addOption('timeout', 't', InputOption::VALUE_OPTIONAL, 'Job timeout in seconds', 60)
                         ->addOption('max-jobs', null, InputOption::VALUE_OPTIONAL, 'Maximum number of jobs to process before exiting')
                         ->addOption('stop-on-empty', null, InputOption::VALUE_NONE, 'Stop when the queue is empty')
                         ->addOption('tries', null, InputOption::VALUE_OPTIONAL, 'Number of times to attempt a job', 3)
                         ->addOption('config', 'c', InputOption::VALUE_OPTIONAL, 'Path to queue configuration file')
                         ->addOption('daemon', null, InputOption::VALUE_NONE, 'Run the worker in daemon mode')
                         ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force the worker to run even in maintenance mode');
                }
                
                protected function execute(InputInterface $input, OutputInterface $output): int {
                    $io = new SymfonyStyle($input, $output);
                    $io->title("Machinjiri - Queue Driver");
                    try {
                        $driver = $input->getOption('driver');
                        $queue = $input->getOption('queue');
                        $sleep = (int) $input->getOption('sleep');
                        $memory = (int) $input->getOption('memory');
                        $timeout = (int) $input->getOption('timeout');
                        $maxJobs = $input->getOption('max-jobs') ? (int) $input->getOption('max-jobs') : null;
                        $tries = (int) $input->getOption('tries');
                        $stopOnEmpty = $input->getOption('stop-on-empty');
                        $daemon = $input->getOption('daemon');
                        $force = $input->getOption('force');
                        
                        // Load configuration
                        $config = $this->loadQueueConfig($input->getOption('config'));
                        
                        // Create container instance
                        $container = Container::getInstance();
                        
                        // Create queue driver based on type
                        $queueDriver = $this->createQueueDriver($driver, $container, $config);
                        
                        if (!$queueDriver) {
                            $io->error("Queue driver '{$driver}' not found or not configured.");
                            $io->text("Available drivers: " . implode(', ', array_keys($config['drivers'] ?? [])));
                            return Command::FAILURE;
                        }
                        
                        // Create job processor
                        $processor = $this->createJobProcessor($container);
                        
                        // Create worker
                        $worker = new BaseWorker($container, $queueDriver, $processor);
                        
                        // Set worker options
                        $workerOptions = [
                            'sleep' => $sleep,
                            'memory' => $memory,
                            'timeout' => $timeout,
                            'maxTries' => $tries,
                            'maxJobs' => $maxJobs,
                            'stopOnEmpty' => $stopOnEmpty,
                        ];
                        
                        $io->title("Queue Worker");
                        $io->text([
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
                        
                        $io->newLine();
                        $io->writeln("Starting worker... Press Ctrl+C to stop.");
                        $io->newLine();
                        
                        // Register signal handlers for graceful shutdown
                        if (extension_loaded('pcntl')) {
                            pcntl_async_signals(true);
                            pcntl_signal(SIGINT, function() use ($worker, $io) {
                                $io->writeln("\n<comment>Stopping worker...</comment>");
                                $worker->stop();
                            });
                            pcntl_signal(SIGTERM, function() use ($worker, $io) {
                                $io->writeln("\n<comment>Terminating worker...</comment>");
                                $worker->stop();
                            });
                        }
                        
                        // Start the worker
                        $startTime = time();
                        $worker->start($queue, $workerOptions);
                        
                        // Show statistics
                        $endTime = time();
                        $status = $worker->getStatus();
                        
                        $io->newLine(2);
                        $io->section("Worker Statistics");
                        $io->text([
                            "Runtime: <info>" . ($endTime - $startTime) . "s</info>",
                            "Jobs Processed: <info>{$status['processed']}</info>",
                            "Jobs Failed: <info>{$status['failed']}</info>",
                            "Memory Peak: <info>" . round($status['memory_peak'] / 1024 / 1024, 2) . "MB</info>",
                            "Last Job: <info>" . ($status['last_job_at'] ? date('Y-m-d H:i:s', $status['last_job_at']) : 'Never') . "</info>",
                        ]);
                        
                        return Command::SUCCESS;
                        
                    } catch (\Exception $e) {
                        $io->error('Worker Error: ' . $e->getMessage());
                        $io->text('Trace: ' . $e->getTraceAsString());
                        return Command::FAILURE;
                    }
                }
                
                private function loadQueueConfig(?string $configPath): array {
                    $basePath = getcwd();
                    
                    if ($configPath) {
                        if (!file_exists($configPath)) {
                            throw new MachinjiriException("Configuration file not found: {$configPath}");
                        }
                        return require $configPath;
                    }
                    
                    // Try default locations
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
                    
                    // Return default configuration
                    return [
                        'default' => 'database',
                        'drivers' => [
                            'database' => [
                                'driver' => 'database',
                                'table' => 'jobs',
                                'queue' => 'default',
                                'retry_after' => 90,
                            ],
                            'sync' => [
                                'driver' => 'sync',
                            ],
                        ],
                    ];
                }
                
                private function createQueueDriver(string $driver, Container $container, array $config) {
                    $driverConfig = $config['drivers'][$driver] ?? null;
                    
                    if (!$driverConfig) {
                        // Try to find driver by class name
                        foreach ($config['drivers'] as $key => $config) {
                            if (($config['class'] ?? '') === $driver) {
                                $driver = $key;
                                $driverConfig = $config;
                                break;
                            }
                        }
                    }
                    
                    if (!$driverConfig) {
                        return null;
                    }
                    
                    // Determine driver class
                    $driverClass = $driverConfig['class'] ?? $this->getDriverClassName($driver);
                    
                    if (!class_exists($driverClass)) {
                        throw new MachinjiriException("Queue driver class not found: {$driverClass}");
                    }
                    
                    // Create driver instance
                    return new $driverClass($container, $driver, $driverConfig);
                }
                
                private function getDriverClassName(string $driver): string {
                    $driverMap = [
                        'database' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\DatabaseQueue',
                        'redis' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\RedisQueue',
                        'sync' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\SyncQueue',
                        'file' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\FileQueue',
                        'memory' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\MemoryQueue',
                    ];
                    
                    return $driverMap[$driver] ?? 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\' . ucfirst($driver) . 'Queue';
                }
                
                private function createJobProcessor(Container $container) {
                    $processorClass = 'Mlangeni\\Machinjiri\\Core\\Artisans\\Contracts\\BaseJobProcessor';
                    
                    if (class_exists($processorClass)) {
                        return new $processorClass($container);
                    }
                    
                    // Fallback to a simple processor
                    return new class($container) extends \Mlangeni\Machinjiri\Core\Artisans\Contracts\BaseJobProcessor {};
                }
            },
            
            new class extends Command {
                public function __construct() {
                    parent::__construct('queue:list');
                    $this->setDescription('List all available queue drivers and jobs');
                }
                
                protected function configure(): void {
                    $this->addOption('type', null, InputOption::VALUE_OPTIONAL, 'Filter by type (drivers, jobs, all)', 'all')
                         ->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Output format (table, json, list)', 'table');
                }
                
                protected function execute(InputInterface $input, OutputInterface $output): int {
                    $io = new SymfonyStyle($input, $output);
                    $io->title("Machinjiri - Queue Driver");
                    try {
                        $generator = new QueueJobGenerator(getcwd());
                        $type = $input->getOption('type');
                        $format = $input->getOption('format');
                        
                        $data = [];
                        
                        if ($type === 'all' || $type === 'drivers') {
                            $queues = $generator->listQueues();
                            $data['drivers'] = $queues;
                            
                            if ($format === 'table' && $type === 'drivers') {
                                $io->title('Available Queue Drivers');
                                $rows = array_map(function($queue) {
                                    return [
                                        $queue['name'],
                                        $queue['file'],
                                        $queue['exists'] ? 'Yes' : 'No',
                                        $queue['path'],
                                    ];
                                }, $queues);
                                
                                $io->table(['Name', 'File', 'Loaded', 'Path'], $rows);
                            }
                        }
                        
                        if ($type === 'all' || $type === 'jobs') {
                            $jobs = $generator->listJobs();
                            $data['jobs'] = $jobs;
                            
                            if ($format === 'table' && $type === 'jobs') {
                                $io->title('Available Jobs');
                                $rows = array_map(function($job) {
                                    return [
                                        $job['name'],
                                        $job['file'],
                                        $job['exists'] ? 'Yes' : 'No',
                                        $job['path'],
                                    ];
                                }, $jobs);
                                
                                $io->table(['Name', 'File', 'Loaded', 'Path'], $rows);
                            }
                        }
                        
                        if ($format === 'json') {
                            $io->writeln(json_encode($data, JSON_PRETTY_PRINT));
                        } elseif ($format === 'list') {
                            if ($type === 'all' || $type === 'drivers') {
                                $io->section('Queue Drivers');
                                foreach ($data['drivers'] as $driver) {
                                    $io->writeln("  • {$driver['name']} ({$driver['file']})");
                                }
                            }
                            
                            if ($type === 'all' || $type === 'jobs') {
                                $io->section('Jobs');
                                foreach ($data['jobs'] as $job) {
                                    $io->writeln("  • {$job['name']} ({$job['file']})");
                                }
                            }
                        }
                        
                        return Command::SUCCESS;
                    } catch (\Exception $e) {
                        $io->error('Error: ' . $e->getMessage());
                        return Command::FAILURE;
                    }
                }
            },
            
            new class extends Command {
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
                
                protected function execute(InputInterface $input, OutputInterface $output): int {
                    try {
                        $io = new SymfonyStyle($input, $output);
                        
                        $generator = new QueueJobGenerator(getcwd());
                        
                        $name = $input->getArgument('name');
                        $options = [
                            'type' => $input->getOption('type'),
                            'queue' => $input->getOption('queue'),
                            'max_attempts' => (int) $input->getOption('max-attempts'),
                            'timeout' => (int) $input->getOption('timeout'),
                            'delay' => (int) $input->getOption('delay'),
                            'database' => $input->getOption('database'),
                            'command' => $input->getOption('command'),
                        ];
                        
                        $file = $generator->generateJob($name, $options);
                        
                        $io->success('Job created successfully!');
                        $io->text([
                            'File: ' . $file,
                            'Type: ' . $options['type'],
                            'Queue: ' . $options['queue'],
                            'Max Attempts: ' . $options['max_attempts'],
                            'Timeout: ' . $options['timeout'] . 's',
                        ]);
                        
                        $io->section('Usage Example');
                        $io->text([
                            'Dispatch job:',
                            '  $job = new ' . str_replace('Job', '', $name) . 'Job($data);',
                            '  $dispatcher->dispatch($job);',
                            '',
                            'Or use the command:',
                            '  php artisan queue:work --queue=' . $options['queue'],
                        ]);
                        
                        return Command::SUCCESS;
                    } catch (\Exception $e) {
                        $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
                        return Command::FAILURE;
                    }
                }
            },

            // Queue status command
            new class extends Command {
                public function __construct() {
                    parent::__construct('queue:status');
                    $this->setDescription('Display the status of queues');
                }
                
                protected function configure(): void {
                    $this->addOption('driver', 'd', InputOption::VALUE_OPTIONAL, 'Queue driver to use', 'database')
                         ->addOption('queue', 'q', InputOption::VALUE_OPTIONAL, 'Specific queue to check', 'all')
                         ->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Output format (table, json)', 'table');
                }
                
                protected function execute(InputInterface $input, OutputInterface $output): int {
                    $io = new SymfonyStyle($input, $output);
                    $io->title("Queue Status");
                    
                    try {
                        $config = $this->loadQueueConfig(null);
                        $container = Container::getInstance();
                        $driverName = $input->getOption('driver');
                        
                        $queueDriver = $this->createQueueDriver($driverName, $container, $config);
                        
                        if (!$queueDriver) {
                            $io->error("Queue driver '{$driverName}' not found.");
                            return Command::FAILURE;
                        }
                        
                        $specificQueue = $input->getOption('queue');
                        $format = $input->getOption('format');
                        
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
                            $io->writeln(json_encode($stats, JSON_PRETTY_PRINT));
                        } else {
                            if (empty($stats)) {
                                $io->warning('No queues found.');
                            } else {
                                $table = new Table($output);
                                $table->setHeaders(['Queue', 'Size', 'Driver', 'Health']);
                                
                                $rows = [];
                                foreach ($stats as $stat) {
                                    $rows[] = [
                                        $stat['name'] ?? 'unknown',
                                        $stat['size'] ?? 0,
                                        $stat['driver'] ?? 'unknown',
                                        $queueDriver->isHealthy() ? '<info>✓ Healthy</info>' : '<error>✗ Unhealthy</error>'
                                    ];
                                }
                                $table->setRows($rows);
                                $table->render();
                            }
                        }
                        
                        return Command::SUCCESS;
                    } catch (\Exception $e) {
                        $io->error('Error: ' . $e->getMessage());
                        return Command::FAILURE;
                    }
                }
                
                private function loadQueueConfig(?string $configPath): array {
                    // Reuse logic from queue:work command
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
                                'driver' => 'database',
                                'table' => 'jobs',
                                'queue' => 'default',
                                'retry_after' => 90,
                            ],
                        ],
                    ];
                }
                
                private function createQueueDriver(string $driver, $container, array $config) {
                    $driverConfig = $config['drivers'][$driver] ?? null;
                    
                    if (!$driverConfig) {
                        return null;
                    }
                    
                    $driverClass = $driverConfig['class'] ?? $this->getDriverClassName($driver);
                    
                    if (!class_exists($driverClass)) {
                        throw new MachinjiriException("Queue driver class not found: {$driverClass}");
                    }
                    
                    return new $driverClass($container, $driver, $driverConfig);
                }
                
                private function getDriverClassName(string $driver): string {
                    $driverMap = [
                        'database' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\DatabaseQueue',
                        'redis' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\RedisQueue',
                        'sync' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\SyncQueue',
                        'file' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\FileQueue',
                        'memory' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\MemoryQueue',
                    ];
                    
                    return $driverMap[$driver] ?? 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\' . ucfirst($driver) . 'Queue';
                }
            },

            // Failed jobs commands
            new class extends Command {
                public function __construct() {
                    parent::__construct('queue:failed');
                    $this->setDescription('List all failed queue jobs');
                }
                
                protected function configure(): void {
                    $this->addOption('driver', 'd', InputOption::VALUE_OPTIONAL, 'Queue driver to use', 'database')
                         ->addOption('queue', 'q', InputOption::VALUE_OPTIONAL, 'Queue name', 'default')
                         ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Limit number of failed jobs to display', 50)
                         ->addOption('offset', 'o', InputOption::VALUE_OPTIONAL, 'Offset for failed jobs', 0)
                         ->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Output format (table, json)', 'table');
                }
                
                protected function execute(InputInterface $input, OutputInterface $output): int {
                    $io = new SymfonyStyle($input, $output);
                    $io->title("Failed Queue Jobs");
                    
                    try {
                        $config = $this->loadQueueConfig(null);
                        $container = Container::getInstance();
                        $driverName = $input->getOption('driver');
                        
                        $queueDriver = $this->createQueueDriver($driverName, $container, $config);
                        
                        if (!$queueDriver) {
                            $io->error("Queue driver '{$driverName}' not found.");
                            return Command::FAILURE;
                        }
                        
                        $queue = $input->getOption('queue');
                        $limit = (int) $input->getOption('limit');
                        $offset = (int) $input->getOption('offset');
                        $format = $input->getOption('format');
                        
                        $failedJobs = $queueDriver->getFailed($queue, $limit, $offset);
                        
                        if ($format === 'json') {
                            $io->writeln(json_encode($failedJobs, JSON_PRETTY_PRINT));
                        } else {
                            if (empty($failedJobs)) {
                                $io->success('No failed jobs found.');
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
                                
                                $io->newLine();
                                $io->text("Total failed jobs: " . count($failedJobs));
                            }
                        }
                        
                        return Command::SUCCESS;
                    } catch (\Exception $e) {
                        $io->error('Error: ' . $e->getMessage());
                        return Command::FAILURE;
                    }
                }
                
                private function loadQueueConfig(?string $configPath): array {
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
                                'driver' => 'database',
                                'table' => 'jobs',
                                'queue' => 'default',
                                'retry_after' => 90,
                            ],
                        ],
                    ];
                }
                
                private function createQueueDriver(string $driver, $container, array $config) {
                    $driverConfig = $config['drivers'][$driver] ?? null;
                    
                    if (!$driverConfig) {
                        return null;
                    }
                    
                    $driverClass = $driverConfig['class'] ?? $this->getDriverClassName($driver);
                    
                    if (!class_exists($driverClass)) {
                        throw new MachinjiriException("Queue driver class not found: {$driverClass}");
                    }
                    
                    return new $driverClass($container, $driver, $driverConfig);
                }
                
                private function getDriverClassName(string $driver): string {
                    $driverMap = [
                        'database' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\DatabaseQueue',
                        'redis' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\RedisQueue',
                        'sync' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\SyncQueue',
                        'file' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\FileQueue',
                        'memory' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\MemoryQueue',
                    ];
                    
                    return $driverMap[$driver] ?? 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\' . ucfirst($driver) . 'Queue';
                }
            },

            new class extends Command {
                public function __construct() {
                    parent::__construct('queue:retry');
                    $this->setDescription('Retry a failed queue job');
                }
                
                protected function configure(): void {
                    $this->addArgument('id', InputArgument::OPTIONAL, 'The ID of the failed job (use "all" to retry all)')
                         ->addOption('driver', 'd', InputOption::VALUE_OPTIONAL, 'Queue driver to use', 'database')
                         ->addOption('queue', 'q', InputOption::VALUE_OPTIONAL, 'Queue name', 'default');
                }
                
                protected function execute(InputInterface $input, OutputInterface $output): int {
                    $io = new SymfonyStyle($input, $output);
                    $io->title("Retry Failed Queue Job");
                    
                    try {
                        $config = $this->loadQueueConfig(null);
                        $container = Container::getInstance();
                        $driverName = $input->getOption('driver');
                        
                        $queueDriver = $this->createQueueDriver($driverName, $container, $config);
                        
                        if (!$queueDriver) {
                            $io->error("Queue driver '{$driverName}' not found.");
                            return Command::FAILURE;
                        }
                        
                        $jobId = $input->getArgument('id');
                        $queue = $input->getOption('queue');
                        
                        if (!$jobId) {
                            $io->error('Job ID is required. Use "all" to retry all failed jobs.');
                            return Command::FAILURE;
                        }
                        
                        if ($jobId === 'all') {
                            // Get all failed jobs and retry each one
                            $failedJobs = $queueDriver->getFailed($queue, 1000, 0);
                            $successCount = 0;
                            $totalCount = count($failedJobs);
                            
                            if ($totalCount === 0) {
                                $io->success('No failed jobs to retry.');
                                return Command::SUCCESS;
                            }
                            
                            $io->text("Retrying {$totalCount} failed jobs...");
                            $progressBar = new ProgressBar($output, $totalCount);
                            $progressBar->start();
                            
                            foreach ($failedJobs as $job) {
                                if ($queueDriver->retryFailed($job['id'] ?? '', $queue)) {
                                    $successCount++;
                                }
                                $progressBar->advance();
                            }
                            
                            $progressBar->finish();
                            $io->newLine(2);
                            
                            if ($successCount === $totalCount) {
                                $io->success("All {$totalCount} jobs retried successfully.");
                            } else {
                                $io->warning("Retried {$successCount} out of {$totalCount} jobs.");
                            }
                        } else {
                            // Retry single job
                            if ($queueDriver->retryFailed($jobId, $queue)) {
                                $io->success("Job {$jobId} retried successfully.");
                            } else {
                                $io->error("Failed to retry job {$jobId}.");
                                return Command::FAILURE;
                            }
                        }
                        
                        return Command::SUCCESS;
                    } catch (\Exception $e) {
                        $io->error('Error: ' . $e->getMessage());
                        return Command::FAILURE;
                    }
                }
                
                private function loadQueueConfig(?string $configPath): array {
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
                                'driver' => 'database',
                                'table' => 'jobs',
                                'queue' => 'default',
                                'retry_after' => 90,
                            ],
                        ],
                    ];
                }
                
                private function createQueueDriver(string $driver, $container, array $config) {
                    $driverConfig = $config['drivers'][$driver] ?? null;
                    
                    if (!$driverConfig) {
                        return null;
                    }
                    
                    $driverClass = $driverConfig['class'] ?? $this->getDriverClassName($driver);
                    
                    if (!class_exists($driverClass)) {
                        throw new MachinjiriException("Queue driver class not found: {$driverClass}");
                    }
                    
                    return new $driverClass($container, $driver, $driverConfig);
                }
                
                private function getDriverClassName(string $driver): string {
                    $driverMap = [
                        'database' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\DatabaseQueue',
                        'redis' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\RedisQueue',
                        'sync' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\SyncQueue',
                        'file' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\FileQueue',
                        'memory' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\MemoryQueue',
                    ];
                    
                    return $driverMap[$driver] ?? 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\' . ucfirst($driver) . 'Queue';
                }
            },

            new class extends Command {
                public function __construct() {
                    parent::__construct('queue:forget');
                    $this->setDescription('Remove a failed queue job from the failed jobs list');
                }
                
                protected function configure(): void {
                    $this->addArgument('id', InputArgument::REQUIRED, 'The ID of the failed job')
                         ->addOption('driver', 'd', InputOption::VALUE_OPTIONAL, 'Queue driver to use', 'database')
                         ->addOption('queue', 'q', InputOption::VALUE_OPTIONAL, 'Queue name', 'default');
                }
                
                protected function execute(InputInterface $input, OutputInterface $output): int {
                    $io = new SymfonyStyle($input, $output);
                    $io->title("Remove Failed Queue Job");
                    
                    try {
                        $config = $this->loadQueueConfig(null);
                        $container = Container::getInstance();
                        $driverName = $input->getOption('driver');
                        
                        $queueDriver = $this->createQueueDriver($driverName, $container, $config);
                        
                        if (!$queueDriver) {
                            $io->error("Queue driver '{$driverName}' not found.");
                            return Command::FAILURE;
                        }
                        
                        $jobId = $input->getArgument('id');
                        $queue = $input->getOption('queue');
                        
                        if ($queueDriver->forgetFailed($jobId, $queue)) {
                            $io->success("Job {$jobId} removed from failed jobs list.");
                        } else {
                            $io->error("Failed to remove job {$jobId}.");
                            return Command::FAILURE;
                        }
                        
                        return Command::SUCCESS;
                    } catch (\Exception $e) {
                        $io->error('Error: ' . $e->getMessage());
                        return Command::FAILURE;
                    }
                }
                
                private function loadQueueConfig(?string $configPath): array {
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
                                'driver' => 'database',
                                'table' => 'jobs',
                                'queue' => 'default',
                                'retry_after' => 90,
                            ],
                        ],
                    ];
                }
                
                private function createQueueDriver(string $driver, $container, array $config) {
                    $driverConfig = $config['drivers'][$driver] ?? null;
                    
                    if (!$driverConfig) {
                        return null;
                    }
                    
                    $driverClass = $driverConfig['class'] ?? $this->getDriverClassName($driver);
                    
                    if (!class_exists($driverClass)) {
                        throw new MachinjiriException("Queue driver class not found: {$driverClass}");
                    }
                    
                    return new $driverClass($container, $driver, $driverConfig);
                }
                
                private function getDriverClassName(string $driver): string {
                    $driverMap = [
                        'database' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\DatabaseQueue',
                        'redis' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\RedisQueue',
                        'sync' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\SyncQueue',
                        'file' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\FileQueue',
                        'memory' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\MemoryQueue',
                    ];
                    
                    return $driverMap[$driver] ?? 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\' . ucfirst($driver) . 'Queue';
                }
            },

            new class extends Command {
                public function __construct() {
                    parent::__construct('queue:flush');
                    $this->setDescription('Flush all failed queue jobs');
                }
                
                protected function configure(): void {
                    $this->addOption('driver', 'd', InputOption::VALUE_OPTIONAL, 'Queue driver to use', 'database')
                         ->addOption('queue', 'q', InputOption::VALUE_OPTIONAL, 'Queue name', 'default')
                         ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force flush without confirmation');
                }
                
                protected function execute(InputInterface $input, OutputInterface $output): int {
                    $io = new SymfonyStyle($input, $output);
                    $io->title("Flush Failed Queue Jobs");
                    
                    try {
                        $config = $this->loadQueueConfig(null);
                        $container = Container::getInstance();
                        $driverName = $input->getOption('driver');
                        
                        $queueDriver = $this->createQueueDriver($driverName, $container, $config);
                        
                        if (!$queueDriver) {
                            $io->error("Queue driver '{$driverName}' not found.");
                            return Command::FAILURE;
                        }
                        
                        $queue = $input->getOption('queue');
                        $force = $input->getOption('force');
                        
                        if (!$force) {
                            $confirmed = $io->confirm(
                                'Are you sure you want to flush all failed jobs? This action cannot be undone.',
                                false
                            );
                            
                            if (!$confirmed) {
                                $io->warning('Operation cancelled.');
                                return Command::SUCCESS;
                            }
                        }
                        
                        $count = $queueDriver->flushFailed($queue);
                        
                        if ($count > 0) {
                            $io->success("Flushed {$count} failed jobs.");
                        } else {
                            $io->info('No failed jobs to flush.');
                        }
                        
                        return Command::SUCCESS;
                    } catch (\Exception $e) {
                        $io->error('Error: ' . $e->getMessage());
                        return Command::FAILURE;
                    }
                }
                
                private function loadQueueConfig(?string $configPath): array {
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
                                'driver' => 'database',
                                'table' => 'jobs',
                                'queue' => 'default',
                                'retry_after' => 90,
                            ],
                        ],
                    ];
                }
                
                private function createQueueDriver(string $driver, $container, array $config) {
                    $driverConfig = $config['drivers'][$driver] ?? null;
                    
                    if (!$driverConfig) {
                        return null;
                    }
                    
                    $driverClass = $driverConfig['class'] ?? $this->getDriverClassName($driver);
                    
                    if (!class_exists($driverClass)) {
                        throw new MachinjiriException("Queue driver class not found: {$driverClass}");
                    }
                    
                    return new $driverClass($container, $driver, $driverConfig);
                }
                
                private function getDriverClassName(string $driver): string {
                    $driverMap = [
                        'database' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\DatabaseQueue',
                        'redis' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\RedisQueue',
                        'sync' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\SyncQueue',
                        'file' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\FileQueue',
                        'memory' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\MemoryQueue',
                    ];
                    
                    return $driverMap[$driver] ?? 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\' . ucfirst($driver) . 'Queue';
                }
            },

            // Queue clear command
            new class extends Command {
                public function __construct() {
                    parent::__construct('queue:clear');
                    $this->setDescription('Clear all jobs from a queue');
                }
                
                protected function configure(): void {
                    $this->addOption('driver', 'd', InputOption::VALUE_OPTIONAL, 'Queue driver to use', 'database')
                         ->addOption('queue', 'q', InputOption::VALUE_OPTIONAL, 'Queue name to clear', 'default')
                         ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force clear without confirmation');
                }
                
                protected function execute(InputInterface $input, OutputInterface $output): int {
                    $io = new SymfonyStyle($input, $output);
                    $io->title("Clear Queue");
                    
                    try {
                        $config = $this->loadQueueConfig(null);
                        $container = Container::getInstance();
                        $driverName = $input->getOption('driver');
                        
                        $queueDriver = $this->createQueueDriver($driverName, $container, $config);
                        
                        if (!$queueDriver) {
                            $io->error("Queue driver '{$driverName}' not found.");
                            return Command::FAILURE;
                        }
                        
                        $queue = $input->getOption('queue');
                        $force = $input->getOption('force');
                        
                        $queueSize = $queueDriver->size($queue);
                        
                        if ($queueSize === 0) {
                            $io->info("Queue '{$queue}' is already empty.");
                            return Command::SUCCESS;
                        }
                        
                        if (!$force) {
                            $confirmed = $io->confirm(
                                "Are you sure you want to clear {$queueSize} jobs from queue '{$queue}'? This action cannot be undone.",
                                false
                            );
                            
                            if (!$confirmed) {
                                $io->warning('Operation cancelled.');
                                return Command::SUCCESS;
                            }
                        }
                        
                        $clearedCount = $queueDriver->clear($queue);
                        
                        $io->success("Cleared {$clearedCount} jobs from queue '{$queue}'.");
                        
                        return Command::SUCCESS;
                    } catch (\Exception $e) {
                        $io->error('Error: ' . $e->getMessage());
                        return Command::FAILURE;
                    }
                }
                
                private function loadQueueConfig(?string $configPath): array {
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
                                'driver' => 'database',
                                'table' => 'jobs',
                                'queue' => 'default',
                                'retry_after' => 90,
                            ],
                        ],
                    ];
                }
                
                private function createQueueDriver(string $driver, $container, array $config) {
                    $driverConfig = $config['drivers'][$driver] ?? null;
                    
                    if (!$driverConfig) {
                        return null;
                    }
                    
                    $driverClass = $driverConfig['class'] ?? $this->getDriverClassName($driver);
                    
                    if (!class_exists($driverClass)) {
                        throw new MachinjiriException("Queue driver class not found: {$driverClass}");
                    }
                    
                    return new $driverClass($container, $driver, $driverConfig);
                }
                
                private function getDriverClassName(string $driver): string {
                    $driverMap = [
                        'database' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\DatabaseQueue',
                        'redis' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\RedisQueue',
                        'sync' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\SyncQueue',
                        'file' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\FileQueue',
                        'memory' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\MemoryQueue',
                    ];
                    
                    return $driverMap[$driver] ?? 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\' . ucfirst($driver) . 'Queue';
                }
            },

            // Queue health check command
            new class extends Command {
                public function __construct() {
                    parent::__construct('queue:health');
                    $this->setDescription('Check the health of queue connections');
                }
                
                protected function configure(): void {
                    $this->addOption('driver', 'd', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Specific drivers to check (default: all)')
                         ->addOption('timeout', 't', InputOption::VALUE_OPTIONAL, 'Timeout for health check in seconds', 5);
                }
                
                protected function execute(InputInterface $input, OutputInterface $output): int {
                    $io = new SymfonyStyle($input, $output);
                    $io->title("Queue Health Check");
                    
                    try {
                        $config = $this->loadQueueConfig(null);
                        $container = new Container(getcwd());
                        
                        $driversToCheck = $input->getOption('driver');
                        $timeout = (int) $input->getOption('timeout');
                        
                        if (empty($driversToCheck)) {
                            $driversToCheck = array_keys($config['drivers'] ?? []);
                        }
                        
                        if (empty($driversToCheck)) {
                            $io->warning('No queue drivers configured.');
                            return Command::SUCCESS;
                        }
                        
                        $results = [];
                        
                        foreach ($driversToCheck as $driverName) {
                            $queueDriver = $this->createQueueDriver($driverName, $container, $config);
                            
                            if (!$queueDriver) {
                                $results[] = [
                                    'driver' => $driverName,
                                    'status' => 'NOT FOUND',
                                    'message' => 'Driver not configured'
                                ];
                                continue;
                            }
                            
                            $startTime = microtime(true);
                            $isHealthy = false;
                            $message = '';
                            
                            try {
                                // Set timeout for health check
                                if (function_exists('set_time_limit')) {
                                    @set_time_limit($timeout);
                                }
                                
                                $isHealthy = $queueDriver->isHealthy();
                                $message = $isHealthy ? 'Connection successful' : 'Connection failed';
                            } catch (\Exception $e) {
                                $message = 'Error: ' . $e->getMessage();
                            }
                            
                            $responseTime = round((microtime(true) - $startTime) * 1000, 2);
                            
                            $results[] = [
                                'driver' => $driverName,
                                'status' => $isHealthy ? 'HEALTHY' : 'UNHEALTHY',
                                'response_time' => $responseTime . 'ms',
                                'message' => $message
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
                        
                        // Summary
                        $healthyCount = count(array_filter($results, fn($r) => $r['status'] === 'HEALTHY'));
                        $totalCount = count($results);
                        
                        $io->newLine();
                        if ($healthyCount === $totalCount) {
                            $io->success("All {$totalCount} queue drivers are healthy.");
                        } else {
                            $io->warning("{$healthyCount} out of {$totalCount} queue drivers are healthy.");
                        }
                        
                        return Command::SUCCESS;
                    } catch (\Exception $e) {
                        $io->error('Error: ' . $e->getMessage());
                        return Command::FAILURE;
                    }
                }
                
                private function loadQueueConfig(?string $configPath): array {
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
                                'driver' => 'database',
                                'table' => 'jobs',
                                'queue' => 'default',
                                'retry_after' => 90,
                            ],
                        ],
                    ];
                }
                
                private function createQueueDriver(string $driver, $container, array $config) {
                    $driverConfig = $config['drivers'][$driver] ?? null;
                    
                    if (!$driverConfig) {
                        return null;
                    }
                    
                    $driverClass = $driverConfig['class'] ?? $this->getDriverClassName($driver);
                    
                    if (!class_exists($driverClass)) {
                        throw new MachinjiriException("Queue driver class not found: {$driverClass}");
                    }
                    
                    return new $driverClass($container, $driver, $driverConfig);
                }
                
                private function getDriverClassName(string $driver): string {
                    $driverMap = [
                        'database' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\DatabaseQueue',
                        'redis' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\RedisQueue',
                        'sync' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\SyncQueue',
                        'file' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\FileQueue',
                        'memory' => 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\MemoryQueue',
                    ];
                    
                    return $driverMap[$driver] ?? 'Mlangeni\\Machinjiri\\App\\Queue\\Drivers\\' . ucfirst($driver) . 'Queue';
                }
            },
        ];
    }
}