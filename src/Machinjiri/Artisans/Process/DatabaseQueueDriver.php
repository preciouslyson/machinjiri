<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Process;
use Mlangeni\Machinjiri\Core\Artisans\Process\QueueDriverInterface;
use Mlangeni\Machinjiri\Core\Database\QueryBuilder;
use Mlangeni\Machinjiri\Core\Database\DatabaseConnection;
use \RuntimeException;

class DatabaseQueueDriver implements QueueDriverInterface
{
    protected string $table = 'queue_jobs';
    protected string $failedTable = 'failed_jobs';
    protected QueryBuilder $queryBuilder;

    public function __construct()
    {
        $this->queryBuilder = new QueryBuilder($this->table);
    }

    /**
     * Push a job to the queue.
     */
    public function push(array $payload): string
    {
        $jobId = uniqid('job_', true);
        
        $this->queryBuilder
            ->insert([
                'id' => $jobId,
                'queue' => 'default',
                'payload' => json_encode($payload),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => time(),
                'created_at' => time()
            ])
            ->execute();
            
        return $jobId;
    }

    /**
     * Push a delayed job to the queue.
     */
    public function later(int $delay, array $payload): string
    {
        $jobId = uniqid('job_', true);
        
        $this->queryBuilder
            ->insert([
                'id' => $jobId,
                'queue' => 'default',
                'payload' => json_encode($payload),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => time() + $delay,
                'created_at' => time()
            ])
            ->execute();
            
        return $jobId;
    }

    /**
     * Pop the next available job from the queue.
     */
    public function pop(): ?array
    {
        // Use a transaction to prevent race conditions
        try {
            DatabaseConnection::beginTransaction();
            
            $job = $this->queryBuilder
                ->select()
                ->where('queue', '=', 'default')
                ->where('reserved_at', '=', null)
                ->where('available_at', '<=', time())
                ->orderBy('available_at', 'ASC')
                ->limit(1)
                ->first();
                
            if ($job) {
                $this->queryBuilder
                    ->update([
                        'reserved_at' => time(),
                        'attempts' => $job['attempts'] + 1
                    ])
                    ->where('id', '=', $job['id'])
                    ->execute();
            }
            
            DatabaseConnection::commit();
            
            return $job ? array_merge($job, ['payload' => json_decode($job['payload'], true)]) : null;
        } catch (\Exception $e) {
            DatabaseConnection::rollBack();
            throw new RuntimeException("Failed to pop job from queue: " . $e->getMessage());
        }
    }

    /**
     * Delete a job from the queue.
     */
    public function delete(string $jobId): void
    {
        $this->queryBuilder
            ->delete()
            ->where('id', '=', $jobId)
            ->execute();
    }

    /**
     * Mark a job as failed.
     */
    public function fail(array $payload): void
    {
        // Move to failed jobs table
        $failedQueryBuilder = new QueryBuilder($this->failedTable);
        
        $failedQueryBuilder
            ->insert([
                'id' => $payload['job_id'],
                'queue' => 'default',
                'payload' => json_encode($payload),
                'attempts' => $payload['attempts'],
                'failed_at' => time()
            ])
            ->execute();
            
        // Delete from active jobs
        $this->delete($payload['job_id']);
    }

    /**
     * Retry a failed job.
     */
    public function retry(string $jobId, array $payload): void
    {
        // Remove from failed jobs
        $failedQueryBuilder = new QueryBuilder($this->failedTable);
        $failedQueryBuilder
            ->delete()
            ->where('id', '=', $jobId)
            ->execute();
            
        // Add back to active queue with delay
        $this->later(60, $payload); // Retry after 60 seconds
    }

    /**
     * Get a failed job by ID.
     */
    public function getFailed(string $jobId): ?array
    {
        $failedQueryBuilder = new QueryBuilder($this->failedTable);
        
        $job = $failedQueryBuilder
            ->select()
            ->where('id', '=', $jobId)
            ->first();
            
        return $job ? array_merge($job, ['payload' => json_decode($job['payload'], true)]) : null;
    }

    /**
     * Create the necessary database tables for queue storage.
     */
    public function createTables(): void
    {
        // Create queue_jobs table
        $this->queryBuilder->createTable($this->table, [
            'id' => $this->queryBuilder->string('id', 255)->primary(),
            'queue' => $this->queryBuilder->string('queue', 255)->index(),
            'payload' => $this->queryBuilder->text('payload'),
            'attempts' => $this->queryBuilder->integer('attempts')->default(0),
            'reserved_at' => $this->queryBuilder->integer('reserved_at')->nullable(),
            'available_at' => $this->queryBuilder->integer('available_at'),
            'created_at' => $this->queryBuilder->integer('created_at')
        ])->execute();

        // Create failed_jobs table
        $failedQueryBuilder = new QueryBuilder($this->failedTable);
        $failedQueryBuilder->createTable($this->failedTable, [
            'id' => $failedQueryBuilder->string('id', 255)->primary(),
            'queue' => $failedQueryBuilder->string('queue', 255)->index(),
            'payload' => $failedQueryBuilder->text('payload'),
            'attempts' => $failedQueryBuilder->integer('attempts')->default(0),
            'failed_at' => $failedQueryBuilder->integer('failed_at')
        ])->execute();
    }
}