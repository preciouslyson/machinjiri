<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Contracts;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;

/**
 * Abstract Queue Driver
 */
abstract class BaseQueue implements QueueInterface
{
    protected Container $app;
    protected EventListener $events;
    protected array $config = [];
    protected string $name;
    
    /**
     * Create a new queue instance
     */
    public function __construct(Container $app, string $name, array $config = [])
    {
        $this->app = $app;
        $this->name = $name;
        $this->config = $config;
        $this->events = new EventListener(new \Mlangeni\Machinjiri\Core\Artisans\Logging\Logger('queue'));
    }
    
    /**
     * Get queue name
     */
    public function getName(): string
    {
        return $this->name;
    }
    
    /**
     * Get queue configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }
    
    /**
     * Push multiple jobs onto the queue
     */
    public function bulk(array $jobs, string $queue = 'default', int $delay = 0): array
    {
        $jobIds = [];
        
        foreach ($jobs as $job) {
            if ($job instanceof JobInterface) {
                $jobIds[] = $this->push($job, $queue, $delay);
            }
        }
        
        $this->events->trigger('queue.bulk', [
            'queue' => $queue,
            'count' => count($jobIds),
            'delay' => $delay,
        ]);
        
        return $jobIds;
    }
    
    /**
     * Check if queue connection is healthy
     */
    public function isHealthy(): bool
    {
        // Default implementation - override in concrete classes
        return true;
    }
    
    /**
     * Get queue statistics
     */
    public function getStats(string $queue = 'default'): array
    {
        return [
            'size' => $this->size($queue),
            'name' => $queue,
            'driver' => $this->getName(),
        ];
    }
    
    /**
     * Get failed jobs
     */
    public function getFailed(string $queue = 'default', int $limit = 50, int $offset = 0): array
    {
        // Default implementation - override if driver supports failed jobs storage
        return [];
    }
    
    /**
     * Retry a failed job
     */
    public function retryFailed(string $jobId, string $queue = 'default'): bool
    {
        // Default implementation - override if driver supports failed jobs storage
        return false;
    }
    
    /**
     * Forget a failed job
     */
    public function forgetFailed(string $jobId, string $queue = 'default'): bool
    {
        // Default implementation - override if driver supports failed jobs storage
        return false;
    }
    
    /**
     * Flush failed jobs
     */
    public function flushFailed(string $queue = 'default'): int
    {
        // Default implementation - override if driver supports failed jobs storage
        return 0;
    }
    
    // Add batch processing with chunking
    public function popBatch(string $queue = 'default', int $batchSize = 10): array
    {
        $jobs = [];
        for ($i = 0; $i < $batchSize; $i++) {
            $job = $this->pop($queue);
            if ($job) {
                $jobs[] = $job;
            } else {
                break;
            }
        }
        
        $this->events->trigger('queue.batch_popped', [
            'queue' => $queue,
            'batch_size' => count($jobs),
        ]);
        
        return $jobs;
    }
    
    // Add job prioritization
    public function pushWithPriority(JobInterface $job, string $queue = 'default', int $priority = 0, int $delay = 0): string
    {
        $job->addMetadata('priority', $priority);
        return $this->push($job, $queue, $delay);
    }

    

    public function getHealthMetrics(string $queue = 'default'): array
    {
        return [
            'size' => $this->size($queue),
            'is_healthy' => $this->isHealthy(),
            'avg_process_time' => $this->getAverageProcessTime($queue),
            'failure_rate' => $this->getFailureRate($queue),
            'last_processed' => $this->getLastProcessedTime($queue),
        ];
    }

    /**
     * Get average job processing time for a queue
     */
    public function getAverageProcessTime(string $queue = 'default'): float
    {
        // Default implementation - override in concrete classes
        // This could be implemented by tracking job processing times in a persistent storage
        // or by calculating from completed job metadata
        
        return 0.0;
    }

    /**
     * Get failure rate for a queue (percentage)
     */
    public function getFailureRate(string $queue = 'default'): float
    {
        // Default implementation - override in concrete classes
        // This could be calculated as: (failed_jobs / total_jobs) * 100
        // Where failed_jobs and total_jobs are tracked over a specific time period
        
        return 0.0;
    }

    /**
     * Get timestamp of last processed job
     */
    public function getLastProcessedTime(string $queue = 'default'): ?string
    {
        // Default implementation - override in concrete classes
        // This could track the timestamp of when the last job was successfully processed
        // Return null if no jobs have been processed yet
        
        return null;
    }

}