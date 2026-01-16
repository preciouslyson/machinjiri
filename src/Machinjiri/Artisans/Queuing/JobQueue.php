<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Queuing;

use Mlangeni\Machinjiri\Core\Database\QueryBuilder;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Date\DateTimeHandler;

class JobQueue
{
    protected QueryBuilder $queryBuilder;
    protected QueryBuilder $failedJobsQueryBuilder;
    protected QueryBuilder $workersQueryBuilder;
    protected Logger $logger;
    protected string $tableName = 'jobs';
    protected string $failedJobsTableName = 'failed_jobs';
    protected string $workersTableName = 'queue_workers';
    protected int $maxAttempts = 3;
    protected int $retryDelay = 60; // seconds
    protected int $workerTimeout = 300; // 5 minutes
    protected string $workerId;

    public function __construct()
    {
        $this->queryBuilder = new QueryBuilder($this->tableName);
        $this->failedJobsQueryBuilder = new QueryBuilder($this->failedJobsTableName);
        $this->workersQueryBuilder = new QueryBuilder($this->workersTableName);
        $this->logger = new Logger('queue');
        $this->workerId = uniqid(gethostname() . '_', true);
        
        $this->createJobsTable();
        $this->createFailedJobsTable();
        $this->createWorkersTable();
    }

    /**
     * Create jobs table if it doesn't exist
     */
    protected function createJobsTable(): void
    {
        $this->queryBuilder->createTable($this->tableName, [
            $this->queryBuilder->id()->autoIncrement()->primaryKey(),
            $this->queryBuilder->string('queue')->default('default'),
            $this->queryBuilder->integer('priority')->default(0), // 0 = normal, higher = more important
            $this->queryBuilder->text('payload'),
            $this->queryBuilder->integer('attempts')->default(0),
            $this->queryBuilder->integer('progress')->default(0), // 0-100 percentage
            $this->queryBuilder->text('progress_data')->nullable(), // Additional progress information
            $this->queryBuilder->dateTime('available_at'),
            $this->queryBuilder->dateTime('reserved_at')->nullable(),
            $this->queryBuilder->dateTime('completed_at')->nullable(),
            $this->queryBuilder->dateTime('created_at')->default('CURRENT_TIMESTAMP')
        ])->execute();
    }

    /**
     * Create failed jobs table if it doesn't exist
     */
    protected function createFailedJobsTable(): void
    {
        $this->failedJobsQueryBuilder->createTable($this->failedJobsTableName, [
            $this->failedJobsQueryBuilder->id()->autoIncrement()->primaryKey(),
            $this->failedJobsQueryBuilder->string('queue'),
            $this->failedJobsQueryBuilder->text('payload'),
            $this->failedJobsQueryBuilder->integer('attempts'),
            $this->failedJobsQueryBuilder->text('error_message'),
            $this->failedJobsQueryBuilder->text('error_trace')->nullable(),
            $this->failedJobsQueryBuilder->dateTime('failed_at')->default('CURRENT_TIMESTAMP')
        ])->execute();
    }

    /**
     * Create workers table if it doesn't exist
     */
    protected function createWorkersTable(): void
    {
        $this->workersQueryBuilder->createTable($this->workersTableName, [
            $this->workersQueryBuilder->string('worker_id')->primaryKey(),
            $this->workersQueryBuilder->string('queue'),
            $this->workersQueryBuilder->string('status'), // running, paused, stopped
            $this->workersQueryBuilder->integer('processed_jobs')->default(0),
            $this->workersQueryBuilder->integer('failed_jobs')->default(0),
            $this->workersQueryBuilder->dateTime('last_heartbeat'),
            $this->workersQueryBuilder->dateTime('started_at')->default('CURRENT_TIMESTAMP')
        ])->execute();
    }

    /**
     * Push a new job to the queue with priority support
     */
    public function push(string $queue, $job, array $data = [], int $delay = 0, int $priority = 0): int
    {
        $payload = [
            'job' => $job,
            'data' => $data,
            'attempts' => 0
        ];

        $availableAt = date('Y-m-d H:i:s', time() + $delay);

        $result = $this->queryBuilder
            ->insert([
                'queue' => $queue,
                'priority' => $priority,
                'payload' => json_encode($payload),
                'available_at' => $availableAt,
                'created_at' => date('Y-m-d H:i:s')
            ])
            ->execute();

        $this->logger->info("Job pushed to queue: {$queue}", [
            'job' => $job,
            'priority' => $priority,
            'delay' => $delay
        ]);

        return $result['lastInsertId'] ?? 0;
    }

    /**
     * Get the next available job from the queue with priority support
     */
    public function pop(string $queue = 'default'): ?array
    {
        $now = date('Y-m-d H:i:s');

        $job = $this->queryBuilder
            ->select()
            ->where('queue', '=', $queue)
            ->where('available_at', '<=', $now)
            ->where('reserved_at', 'IS', null)
            ->orderBy('priority', 'desc') // Higher priority first
            ->orderBy('created_at', 'asc') // Then oldest first
            ->limit(1)
            ->first();

        if ($job) {
            // Mark job as reserved
            $this->queryBuilder
                ->update([
                    'reserved_at' => $now,
                    'attempts' => $job['attempts'] + 1
                ])
                ->where('id', '=', $job['id'])
                ->execute();
        }

        return $job ? array_merge($job, ['payload' => json_decode($job['payload'], true)]) : null;
    }

    /**
     * Update job progress
     */
    public function updateProgress(int $jobId, int $progress, array $progressData = []): void
    {
        $this->queryBuilder
            ->update([
                'progress' => $progress,
                'progress_data' => json_encode($progressData)
            ])
            ->where('id', '=', $jobId)
            ->execute();
    }

    /**
     * Process a job from the queue
     */
    public function process(string $queue = 'default'): bool
    {
        $job = $this->pop($queue);
        
        if (!$job) {
            return false;
        }

        try {
            $this->logger->info("Processing job: {$job['payload']['job']}", ['job_id' => $job['id']]);
            
            // Execute the job
            $result = $this->executeJob($job['payload']['job'], $job['payload']['data'], $job['id']);
            
            if ($result) {
                $this->complete($job['id']);
                $this->logger->info("Job completed successfully: {$job['payload']['job']}");
                
                // Update worker stats
                $this->updateWorkerStats($queue, 'processed_jobs');
                
                return true;
            } else {
                throw new MachinjiriException("Job execution returned false");
            }
        } catch (\Exception $e) {
            $this->handleFailure($job, $e);
            $this->logger->error("Job failed: {$job['payload']['job']}", [
                'error' => $e->getMessage(),
                'job_id' => $job['id']
            ]);
            
            // Update worker stats
            $this->updateWorkerStats($queue, 'failed_jobs');
            
            return false;
        }
    }

    /**
     * Execute a job with progress tracking support
     */
    protected function executeJob(string $job, array $data = [], int $jobId = 0)
    {
        if (class_exists($job) && method_exists($job, 'handle')) {
            $jobInstance = new $job();
            
            // If job implements Progressable interface, pass the queue instance
            if ($jobId > 0 && in_array('Mlangeni\Machinjiri\Core\Artisans\Queuing\Progressable', class_implements($job))) {
                $jobInstance->setQueue($this);
                $jobInstance->setJobId($jobId);
            }
            
            return $jobInstance->handle($data);
        } elseif (is_callable($job)) {
            return call_user_func($job, $data);
        } else {
            throw new MachinjiriException("Invalid job: {$job}");
        }
    }

    /**
     * Mark a job as completed
     */
    public function complete(int $jobId): void
    {
        $this->queryBuilder
            ->update([
                'completed_at' => date('Y-m-d H:i:s')
            ])
            ->where('id', '=', $jobId)
            ->execute();
            
        // Clean up after a short delay
        $this->queryBuilder
            ->delete()
            ->where('id', '=', $jobId)
            ->where('completed_at', 'IS NOT', null)
            ->execute();
    }

    /**
     * Handle a failed job
     */
    protected function handleFailure(array $job, \Exception $exception): void
    {
        if ($job['attempts'] >= $this->maxAttempts) {
            // Max attempts reached, move to failed jobs
            $this->failedJobsQueryBuilder
                ->insert([
                    'queue' => $job['queue'],
                    'payload' => $job['payload'],
                    'attempts' => $job['attempts'],
                    'error_message' => $exception->getMessage(),
                    'error_trace' => $exception->getTraceAsString(),
                    'failed_at' => date('Y-m-d H:i:s')
                ])
                ->execute();
                
            // Remove from main queue
            $this->queryBuilder
                ->delete()
                ->where('id', '=', $job['id'])
                ->execute();
                
            $this->logger->error("Job moved to failed jobs: {$job['payload']['job']}");
        } else {
            // Reschedule for retry
            $retryAt = date('Y-m-d H:i:s', time() + $this->retryDelay);
            
            $this->queryBuilder
                ->update([
                    'reserved_at' => null,
                    'available_at' => $retryAt
                ])
                ->where('id', '=', $job['id'])
                ->execute();
        }
    }

    /**
     * Process multiple jobs from the queue
     */
    public function work(string $queue = 'default', int $maxJobs = 10): void
    {
        // Register worker
        $this->registerWorker($queue);
        
        $processed = 0;
        
        try {
            while ($processed < $maxJobs && $this->process($queue)) {
                $processed++;
                
                // Send heartbeat
                $this->heartbeat($queue);
                
                // Check if we should pause or stop
                if ($this->shouldPause($queue)) {
                    $this->logger->info("Worker paused by command");
                    break;
                }
                
                if ($this->shouldStop($queue)) {
                    $this->logger->info("Worker stopped by command");
                    break;
                }
            }
        } finally {
            // Always unregister worker
            $this->unregisterWorker();
        }
        
        $this->logger->info("Processed {$processed} jobs from queue: {$queue}");
    }

    /**
     * Register worker in the database
     */
    protected function registerWorker(string $queue): void
    {
        $this->workersQueryBuilder
            ->insert([
                'worker_id' => $this->workerId,
                'queue' => $queue,
                'status' => 'running',
                'last_heartbeat' => date('Y-m-d H:i:s'),
                'started_at' => date('Y-m-d H:i:s')
            ])
            ->onDuplicateKeyUpdate([
                'status' => 'running',
                'last_heartbeat' => date('Y-m-d H:i:s')
            ])
            ->execute();
    }

    /**
     * Update worker heartbeat
     */
    protected function heartbeat(string $queue): void
    {
        $this->workersQueryBuilder
            ->update([
                'last_heartbeat' => date('Y-m-d H:i:s')
            ])
            ->where('worker_id', '=', $this->workerId)
            ->execute();
    }

    /**
     * Update worker statistics
     */
    protected function updateWorkerStats(string $queue, string $field): void
    {
        $this->workersQueryBuilder
            ->update([
                $field => $this->workersQueryBuilder->raw("$field + 1"),
                'last_heartbeat' => date('Y-m-d H:i:s')
            ])
            ->where('worker_id', '=', $this->workerId)
            ->execute();
    }

    /**
     * Check if worker should pause
     */
    protected function shouldPause(string $queue): bool
    {
        $worker = $this->workersQueryBuilder
            ->select(['status'])
            ->where('worker_id', '=', $this->workerId)
            ->first();
            
        return $worker && $worker['status'] === 'paused';
    }

    /**
     * Check if worker should stop
     */
    protected function shouldStop(string $queue): bool
    {
        $worker = $this->workersQueryBuilder
            ->select(['status'])
            ->where('worker_id', '=', $this->workerId)
            ->first();
            
        return $worker && $worker['status'] === 'stopped';
    }

    /**
     * Unregister worker
     */
    protected function unregisterWorker(): void
    {
        $this->workersQueryBuilder
            ->delete()
            ->where('worker_id', '=', $this->workerId)
            ->execute();
    }

    /**
     * Get queue statistics
     */
    public function stats(string $queue = 'default'): array
    {
        $total = $this->queryBuilder
            ->select(['COUNT(*) as count'])
            ->where('queue', '=', $queue)
            ->first();
            
        $pending = $this->queryBuilder
            ->select(['COUNT(*) as count'])
            ->where('queue', '=', $queue)
            ->where('reserved_at', 'IS', null)
            ->where('available_at', '<=', date('Y-m-d H:i:s'))
            ->first();
            
        $reserved = $this->queryBuilder
            ->select(['COUNT(*) as count'])
            ->where('queue', '=', $queue)
            ->where('reserved_at', 'IS NOT', null)
            ->first();
            
        $failed = $this->failedJobsQueryBuilder
            ->select(['COUNT(*) as count'])
            ->where('queue', '=', $queue)
            ->first();
            
        $workers = $this->workersQueryBuilder
            ->select(['COUNT(*) as count'])
            ->where('queue', '=', $queue)
            ->where('status', '=', 'running')
            ->where('last_heartbeat', '>=', date('Y-m-d H:i:s', time() - $this->workerTimeout))
            ->first();

        return [
            'total' => $total['count'] ?? 0,
            'pending' => $pending['count'] ?? 0,
            'reserved' => $reserved['count'] ?? 0,
            'failed' => $failed['count'] ?? 0,
            'active_workers' => $workers['count'] ?? 0
        ];
    }

    /**
     * Get failed jobs
     */
    public function getFailedJobs(string $queue = 'default', int $limit = 50): array
    {
        return $this->failedJobsQueryBuilder
            ->select()
            ->where('queue', '=', $queue)
            ->orderBy('failed_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Retry a failed job
     */
    public function retryFailedJob(int $failedJobId, string $queue = 'default'): bool
    {
        $failedJob = $this->failedJobsQueryBuilder
            ->select()
            ->where('id', '=', $failedJobId)
            ->first();
            
        if (!$failedJob) {
            return false;
        }
        
        $payload = json_decode($failedJob['payload'], true);
        
        if (!isset($payload['job'])) {
            return false;
        }
        
        // Push back to queue
        $this->push(
            $queue, 
            $payload['job'], 
            $payload['data'] ?? [],
            0, // No delay
            10 // Higher priority for retried jobs
        );
        
        // Remove from failed jobs
        $this->failedJobsQueryBuilder
            ->delete()
            ->where('id', '=', $failedJobId)
            ->execute();
            
        return true;
    }

    /**
     * Control worker status
     */
    public function controlWorker(string $workerId, string $action): bool
    {
        $validActions = ['pause', 'resume', 'stop'];
        
        if (!in_array($action, $validActions)) {
            return false;
        }
        
        $statusMap = [
            'pause' => 'paused',
            'resume' => 'running',
            'stop' => 'stopped'
        ];
        
        return $this->workersQueryBuilder
            ->update(['status' => $statusMap[$action]])
            ->where('worker_id', '=', $workerId)
            ->execute() > 0;
    }

    /**
     * Get active workers
     */
    public function getWorkers(string $queue = 'default'): array
    {
        return $this->workersQueryBuilder
            ->select()
            ->where('queue', '=', $queue)
            ->where('last_heartbeat', '>=', date('Y-m-d H:i:s', time() - $this->workerTimeout))
            ->get();
    }

    /**
     * Clean up stale workers
     */
    public function cleanupStaleWorkers(): int
    {
        return $this->workersQueryBuilder
            ->delete()
            ->where('last_heartbeat', '<', date('Y-m-d H:i:s', time() - $this->workerTimeout))
            ->execute();
    }

    /**
     * Get job progress
     */
    public function getProgress(int $jobId): ?array
    {
        $job = $this->queryBuilder
            ->select(['progress', 'progress_data'])
            ->where('id', '=', $jobId)
            ->first();
            
        if (!$job) {
            return null;
        }
        
        return [
            'progress' => $job['progress'],
            'progress_data' => json_decode($job['progress_data'], true) ?? []
        ];
    }
}

/**
 * Interface for jobs that support progress tracking
 */
interface Progressable
{
    public function setQueue(JobQueue $queue);
    public function setJobId(int $jobId);
}