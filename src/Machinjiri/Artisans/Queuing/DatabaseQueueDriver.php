<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Queuing;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Artisans\Contracts\BaseQueue;
use Mlangeni\Machinjiri\Core\Artisans\Contracts\JobInterface;

/**
 * Database Queue Driver - Production Implementation
 * 
 * Stores jobs in a database table for reliable, persistent job queuing.
 */
class DatabaseQueueDriver extends BaseQueue
{
    protected \PDO $connection;
    protected string $table = 'queue_jobs';
    protected string $failedTable = 'queue_failed_jobs';

    /**
     * Create a new database queue driver
     */
    public function __construct(Container $app, \PDO $connection, string $name = 'database', array $config = [])
    {
        parent::__construct($app, $name, $config);
        
        if (!$this->isHealthy()) {
            throw new MachinjiriException('Database queue connection is not healthy', 60030);
        }
        
        $this->connection = $connection;
        $this->table = $config['table'] ?? 'queue_jobs';
        $this->failedTable = $config['failed_table'] ?? 'queue_failed_jobs';
    }

    /**
     * Push a job onto the queue
     */
    public function push(JobInterface $job, string $queue = 'default', int $delay = 0): string
    {
        if (empty($queue)) {
            throw new MachinjiriException('Queue name cannot be empty', 60031);
        }

        $serialized = $job->serialize();
        $delayedUntil = $delay > 0 ? time() + $delay : 0;

        try {
            $stmt = $this->connection->prepare(sprintf(
                'INSERT INTO %s (job_id, queue, payload, attempts, delayed_until, created_at) 
                 VALUES (?, ?, ?, ?, ?, ?)',
                $this->table
            ));

            $stmt->execute([
                $job->getId(),
                $queue,
                json_encode($serialized, JSON_THROW_ON_ERROR),
                0,
                $delayedUntil,
                time(),
            ]);

            $this->events->trigger('queue.pushed', [
                'job_id' => $job->getId(),
                'queue' => $queue,
                'delay' => $delay,
            ]);

            return $job->getId();
        } catch (\PDOException $e) {
            throw new MachinjiriException('Failed to push job to queue: ' . $e->getMessage(), 60032, $e);
        }
    }

    /**
     * Pop the next job from the queue
     */
    public function pop(string $queue = 'default'): ?JobInterface
    {
        try {
            $stmt = $this->connection->prepare(sprintf(
                'SELECT * FROM %s 
                 WHERE queue = ? AND (delayed_until IS NULL OR delayed_until <= ?)
                 ORDER BY priority DESC, created_at ASC 
                 LIMIT 1 FOR UPDATE',
                $this->table
            ));

            $stmt->execute([$queue, time()]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$result) {
                return null;
            }

            // Lock the job for processing
            $this->updateJobStatus($result['job_id'], 'processing');

            $payload = json_decode($result['payload'], true);
            $jobClass = $payload['name'] ?? null;

            if (!class_exists($jobClass)) {
                throw new MachinjiriException("Job class not found: {$jobClass}", 60033);
            }

            // Reconstruct the job with Container
            $job = $jobClass::unserialize($payload, $this->app);

            $this->events->trigger('queue.popped', [
                'job_id' => $job->getId(),
                'queue' => $queue,
            ]);

            return $job;
        } catch (\PDOException $e) {
            throw new MachinjiriException('Failed to pop job from queue: ' . $e->getMessage(), 60034, $e);
        }
    }

    /**
     * Release a job back to the queue
     */
    public function release(JobInterface $job, string $queue = 'default', int $delay = 0): bool
    {
        try {
            $delayedUntil = $delay > 0 ? time() + $delay : 0;

            $stmt = $this->connection->prepare(sprintf(
                'UPDATE %s SET status = ?, delayed_until = ?, attempts = attempts + 1 
                 WHERE job_id = ?',
                $this->table
            ));

            $result = $stmt->execute(['queued', $delayedUntil, $job->getId()]);

            $this->events->trigger('queue.released', [
                'job_id' => $job->getId(),
                'queue' => $queue,
                'delay' => $delay,
            ]);

            return $result;
        } catch (\PDOException $e) {
            $this->events->trigger('queue.release_failed', [
                'job_id' => $job->getId(),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Delete a job from the queue
     */
    public function delete(JobInterface $job, string $queue = 'default'): bool
    {
        try {
            $stmt = $this->connection->prepare(sprintf(
                'DELETE FROM %s WHERE job_id = ?',
                $this->table
            ));

            $result = $stmt->execute([$job->getId()]);

            $this->events->trigger('queue.deleted', [
                'job_id' => $job->getId(),
            ]);

            return $result;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Get the size of the queue
     */
    public function size(string $queue = 'default'): int
    {
        try {
            $stmt = $this->connection->prepare(sprintf(
                'SELECT COUNT(*) as count FROM %s WHERE queue = ? AND status = ?',
                $this->table
            ));

            $stmt->execute([$queue, 'queued']);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            return $result['count'] ?? 0;
        } catch (\PDOException $e) {
            return 0;
        }
    }

    /**
     * Clear the queue
     */
    public function clear(string $queue = 'default'): int
    {
        try {
            $stmt = $this->connection->prepare(sprintf(
                'DELETE FROM %s WHERE queue = ?',
                $this->table
            ));

            $stmt->execute([$queue]);

            $this->events->trigger('queue.cleared', ['queue' => $queue]);

            return $stmt->rowCount();
        } catch (\PDOException $e) {
            return 0;
        }
    }

    /**
     * Get all available queues
     */
    public function getQueues(): array
    {
        try {
            $stmt = $this->connection->prepare(sprintf(
                'SELECT DISTINCT queue FROM %s WHERE status = ? ORDER BY queue',
                $this->table
            ));

            $stmt->execute(['queued']);
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return array_map(fn($row) => $row['queue'], $results);
        } catch (\PDOException $e) {
            return [];
        }
    }

    /**
     * Check if queue connection is healthy
     */
    public function isHealthy(): bool
    {
        try {
            if (!$this->connection) {
                return false;
            }
            $this->connection->query('SELECT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get failed jobs
     */
    public function getFailed(string $queue = 'default', int $limit = 50, int $offset = 0): array
    {
        try {
            $stmt = $this->connection->prepare(sprintf(
                'SELECT * FROM %s WHERE queue = ? ORDER BY failed_at DESC LIMIT ? OFFSET ?',
                $this->failedTable
            ));

            $stmt->execute([$queue, $limit, $offset]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return [];
        }
    }

    /**
     * Retry a failed job
     */
    public function retryFailed(string $jobId, string $queue = 'default'): bool
    {
        try {
            $stmt = $this->connection->prepare(sprintf(
                'SELECT payload FROM %s WHERE job_id = ?',
                $this->failedTable
            ));

            $stmt->execute([$jobId]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$result) {
                return false;
            }

            $payload = json_decode($result['payload'], true);

            // Re-insert into queue
            $pushStmt = $this->connection->prepare(sprintf(
                'INSERT INTO %s (job_id, queue, payload, attempts, created_at) 
                 VALUES (?, ?, ?, ?, ?)',
                $this->table
            ));

            $pushStmt->execute([
                $jobId,
                $queue,
                $result['payload'],
                0,
                time(),
            ]);

            // Remove from failed
            $deleteStmt = $this->connection->prepare(sprintf(
                'DELETE FROM %s WHERE job_id = ?',
                $this->failedTable
            ));

            $deleteStmt->execute([$jobId]);

            return true;
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Update job status
     */
    protected function updateJobStatus(string $jobId, string $status): bool
    {
        try {
            $stmt = $this->connection->prepare(sprintf(
                'UPDATE %s SET status = ?, updated_at = ? WHERE job_id = ?',
                $this->table
            ));

            return $stmt->execute([$status, time(), $jobId]);
        } catch (\PDOException $e) {
            return false;
        }
    }

    /**
     * Get average processing time
     */
    public function getAverageProcessTime(string $queue = 'default'): float
    {
        try {
            $stmt = $this->connection->prepare(sprintf(
                'SELECT AVG(UNIX_TIMESTAMP(completed_at) - UNIX_TIMESTAMP(started_at)) as avg_time 
                 FROM %s WHERE queue = ? AND status = ? AND started_at IS NOT NULL AND completed_at IS NOT NULL',
                $this->table
            ));

            $stmt->execute([$queue, 'completed']);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            return floatval($result['avg_time'] ?? 0);
        } catch (\PDOException $e) {
            return 0.0;
        }
    }

    /**
     * Get failure rate
     */
    public function getFailureRate(string $queue = 'default'): float
    {
        try {
            $stmt = $this->connection->prepare(sprintf(
                'SELECT 
                    COUNT(CASE WHEN status = "failed" THEN 1 END) as failed_count,
                    COUNT(*) as total_count
                 FROM %s WHERE queue = ?',
                $this->table
            ));

            $stmt->execute([$queue]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($result['total_count'] == 0) {
                return 0.0;
            }

            return ($result['failed_count'] / $result['total_count']) * 100;
        } catch (\PDOException $e) {
            return 0.0;
        }
    }
}
