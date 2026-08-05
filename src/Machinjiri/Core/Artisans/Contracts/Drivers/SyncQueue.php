<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Contracts\Drivers;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\{BaseQueue, JobInterface};
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Artisans\Logging\LoggerFactory;
use Mlangeni\Machinjiri\Core\Container;

/**
 * Sync Queue Driver
 *
 * Synchronous queue driver for immediate job processing (mainly for testing).
 */
class SyncQueue extends BaseQueue
{
    protected array $jobs = [];
    protected array $processing = [];

    /**
     * Create a new queue instance
     */
    public function __construct(
        Container $app,
        string $name,
        array $config = []
    ) {
        parent::__construct($app, $name, $config);
    }

    /**
     * Push a job onto the queue
     */
    public function push(JobInterface $job, string $queue = 'default', int $delay = 0): string
    {
        // For sync queue, process immediately if no delay
        if ($delay === 0) {
            $this->processImmediately($job, $queue);
            return $job->getId();
        }
        
        // Store for delayed processing (simulate with sleep in real usage)
        if (!isset($this->jobs[$queue])) {
            $this->jobs[$queue] = [];
        }
        
        $this->jobs[$queue][] = [
            'job' => $job,
            'available_at' => time() + $delay,
        ];
        
        $this->events->trigger('queue.job.pushed', [
            'job_id' => $job->getId(),
            'queue' => $queue,
            'job_name' => $job->getName(),
        ]);
        
        return $job->getId();
    }

    /**
     * Process job immediately
     */
    protected function processImmediately(JobInterface $job, string $queue): void
    {
        $this->events->trigger('job.processing', [
            'job_id' => $job->getId(),
            'job_name' => $job->getName(),
            'queue' => $queue,
        ]);
        
        try {
            $job->handle();
            
            $this->events->trigger('job.processed', [
                'job_id' => $job->getId(),
                'job_name' => $job->getName(),
                'queue' => $queue,
            ]);
        } catch (MachinjiriException $e) {
            $job->failed(new MachinjiriException($e->getMessage()));
            
            $this->events->trigger('job.failed', [
                'job_id' => $job->getId(),
                'job_name' => $job->getName(),
                'queue' => $queue,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Pop the next job from the queue
     */
    public function pop(string $queue = 'default'): ?JobInterface
    {
        if (empty($this->jobs[$queue])) {
            return null;
        }
        
        $now = time();
        foreach ($this->jobs[$queue] as $index => $jobData) {
            if ($jobData['available_at'] <= $now) {
                $job = $jobData['job'];
                unset($this->jobs[$queue][$index]);
                
                // Mark as processing
                $this->processing[$job->getId()] = [
                    'job' => $job,
                    'queue' => $queue,
                    'started_at' => $now,
                ];
                
                return $job;
            }
        }
        
        return null;
    }

    /**
     * Release a job back onto the queue
     */
    public function release(JobInterface $job, string $queue = 'default', int $delay = 0): bool
    {
        // Remove from processing
        unset($this->processing[$job->getId()]);
        
        // Add back to queue with delay
        if (!isset($this->jobs[$queue])) {
            $this->jobs[$queue] = [];
        }
        
        $this->jobs[$queue][] = [
            'job' => $job,
            'available_at' => time() + $delay,
        ];
        
        return true;
    }

    /**
     * Delete a job from the queue
     */
    public function delete(JobInterface $job, string $queue = 'default'): bool
    {
        // Remove from processing
        unset($this->processing[$job->getId()]);
        
        // Remove from jobs array
        if (isset($this->jobs[$queue])) {
            foreach ($this->jobs[$queue] as $index => $jobData) {
                if ($jobData['job']->getId() === $job->getId()) {
                    unset($this->jobs[$queue][$index]);
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Get the size of the queue
     */
    public function size(string $queue = 'default'): int
    {
        return isset($this->jobs[$queue]) ? count($this->jobs[$queue]) : 0;
    }

    /**
     * Clear the queue
     */
    public function clear(string $queue = 'default'): int
    {
        $count = isset($this->jobs[$queue]) ? count($this->jobs[$queue]) : 0;
        unset($this->jobs[$queue]);
        return $count;
    }

    /**
     * Get all available queues
     */
    public function getQueues(): array
    {
        return array_keys($this->jobs);
    }

    /**
     * Check if queue connection is healthy
     */
    public function isHealthy(): bool
    {
        // Sync queue is always healthy
        return true;
    }

    /**
     * Get queue statistics
     */
    public function getStats(string $queue = 'default'): array
    {
        $stats = [
            'queued' => $this->size($queue),
            'processing' => 0,
            'delayed' => 0,
        ];
        
        // Count processing jobs for this queue
        foreach ($this->processing as $processing) {
            if ($processing['queue'] === $queue) {
                $stats['processing']++;
            }
        }
        
        // Count delayed jobs
        if (isset($this->jobs[$queue])) {
            $now = time();
            foreach ($this->jobs[$queue] as $jobData) {
                if ($jobData['available_at'] > $now) {
                    $stats['delayed']++;
                }
            }
        }
        
        return $stats;
    }
}