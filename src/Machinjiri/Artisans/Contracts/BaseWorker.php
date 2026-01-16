<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Contracts;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;

/**
 * Abstract Worker
 */
class BaseWorker implements WorkerInterface
{
    protected Container $app;
    protected QueueInterface $queue;
    protected JobProcessorInterface $processor;
    protected EventListener $events;
    protected bool $shouldStop = false;
    protected bool $paused = false;
    protected array $options = [
        'sleep' => 3,
        'maxTries' => 3,
        'memory' => 128,
        'timeout' => 60,
        'maxJobs' => null,
        'stopOnEmpty' => false,
    ];
    protected array $status = [
        'started_at' => null,
        'processed' => 0,
        'failed' => 0,
        'memory_peak' => 0,
        'last_job_at' => null,
    ];
    
    protected array $processedJobs = [];
    protected int $maxProcessedJobs = 100;
    
    /**
     * Create a new worker instance
     */
    public function __construct(Container $app, QueueInterface $queue, JobProcessorInterface $processor)
    {
        $this->app = $app;
        $this->queue = $queue;
        $this->processor = $processor;
        $this->events = new EventListener(new \Mlangeni\Machinjiri\Core\Artisans\Logging\Logger('worker'));
    }
    
    /**
     * Start the worker
     */
    public function start(string $queue = 'default', array $options = []): void
    {
        $this->shouldStop = false;
        $this->paused = false;
        $this->options = array_merge($this->options, $options);
        $this->status['started_at'] = time();
        $this->status['processed'] = 0;
        $this->status['failed'] = 0;
        $this->status['memory_peak'] = memory_get_usage();
        
        $this->events->trigger('worker.started', [
            'queue' => $queue,
            'options' => $this->options,
        ]);
        
        $this->run($queue, $this->options['maxJobs']);
    }
    
    /**
     * Stop the worker
     */
    public function stop(): void
    {
        $this->shouldStop = true;
        
        $this->events->trigger('worker.stopped', [
            'status' => $this->status,
        ]);
    }
    
    /**
     * Pause the worker
     */
    public function pause(): void
    {
        $this->paused = true;
        
        $this->events->trigger('worker.paused');
    }
    
    /**
     * Resume the worker
     */
    public function resume(): void
    {
        $this->paused = false;
        
        $this->events->trigger('worker.resumed');
    }
    
    /**
     * Check if worker is running
     */
    public function isRunning(): bool
    {
        return !$this->shouldStop;
    }
    
    /**
     * Get worker status
     */
    public function getStatus(): array
    {
        $this->status['memory_current'] = memory_get_usage();
        $this->status['memory_peak'] = max($this->status['memory_peak'], memory_get_peak_usage());
        $this->status['uptime'] = $this->status['started_at'] ? time() - $this->status['started_at'] : 0;
        
        return $this->status;
    }
    
    /**
     * Process a single job
     */
    public function processNextJob(string $queue = 'default'): bool
    {
        if ($this->paused) {
            return false;
        }
        
        $job = $this->queue->pop($queue);
        
        if (!$job) {
            return false;
        }
        
        $this->events->trigger('job.processing', [
            'job_id' => $job->getId(),
            'job_name' => $job->getName(),
            'queue' => $queue,
        ]);
        
        try {
            $result = $this->processor->process($job);
            $this->processor->handleSuccess($job, $result);
            $this->status['processed']++;
            $this->status['last_job_at'] = time();
            
            $this->events->trigger('job.processed', [
                'job_id' => $job->getId(),
                'job_name' => $job->getName(),
                'queue' => $queue,
                'result' => $result,
            ]);
            
            return true;
        } catch (MachinjiriException $e) {
            $this->processor->handleFailure($job, $e);
            $this->status['failed']++;
            
            $this->events->trigger('job.failed', [
                'job_id' => $job->getId(),
                'job_name' => $job->getName(),
                'queue' => $queue,
                'exception' => $e->getMessage(),
            ]);
            
            return false;
        }
    }
    
    /**
     * Process jobs until empty or stopped
     */
    public function run(string $queue = 'default', int $maxJobs = null): int
    {
        $processed = 0;
        $emptyCycles = 0;
        
        while (!$this->shouldStop) {
            // Memory optimization
            if ($processed % 10 === 0) {
                $this->cleanupProcessedJobs();
                gc_collect_cycles(); // Force garbage collection
            }
            
            // Adaptive sleep logic
            if ($emptyCycles > 0) {
                $sleepTime = $this->calculateAdaptiveSleep($emptyCycles);
                if ($sleepTime > 0) {
                    sleep($sleepTime);
                }
            }
            
            $processedJob = $this->processNextJob($queue);
            
            if ($processedJob) {
                $processed++;
                $emptyCycles = 0; // Reset empty cycles
                
                if ($maxJobs && $processed >= $maxJobs) {
                    break;
                }
            } else {
                $emptyCycles++;
                
                if ($this->options['stopOnEmpty']) {
                    break;
                }
            }
        }
        
        return $processed;
    }
    
    /**
     * Check if memory limit is exceeded
     */
    protected function memoryExceeded(): bool
    {
        return memory_get_usage() > $this->options['memory'] * 1024 * 1024;
    }
    
    protected function cleanupProcessedJobs(): void
    {
        if (count($this->processedJobs) > $this->maxProcessedJobs) {
            array_shift($this->processedJobs);
        }
    }
    
    protected function calculateAdaptiveSleep(int $emptyCycles): int
    {
        if ($emptyCycles < 3) return 0; // No sleep for recent jobs
        if ($emptyCycles < 10) return 1; // 1 second sleep
        if ($emptyCycles < 30) return 3; // 3 seconds sleep
        return 5; // Maximum 5 seconds for idle queues
    }
    
    // Add batch processing capability
    public function processBatch(string $queue = 'default', int $batchSize = 10): int
    {
        $jobs = $this->queue->popBatch($queue, $batchSize);
        $processed = 0;
        
        if (empty($jobs)) {
            return 0;
        }
        
        // Sort by priority if available
        usort($jobs, function($a, $b) {
            $priorityA = $a->getMetadata()['priority'] ?? 0;
            $priorityB = $b->getMetadata()['priority'] ?? 0;
            return $priorityB <=> $priorityA; // Higher priority first
        });
        
        foreach ($jobs as $job) {
            if ($this->processJob($job, $queue)) {
                $processed++;
            }
        }
        
        return $processed;
    }
    
    protected function processJob(JobInterface $job, string $queue): bool
    {
        // Simplified job processing logic
        try {
            $result = $this->processor->process($job);
            $this->processor->handleSuccess($job, $result);
            $this->status['processed']++;
            return true;
        } catch (MachinjiriException $e) {
            $this->processor->handleFailure($job, $e);
            $this->status['failed']++;
            return false;
        }
    }
    
}