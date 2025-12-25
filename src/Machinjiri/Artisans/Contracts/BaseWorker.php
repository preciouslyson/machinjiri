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
        
        while (!$this->shouldStop) {
            // Check memory limit
            if (memory_get_usage() > $this->options['memory'] * 1024 * 1024) {
                $this->events->trigger('worker.memory_exceeded', [
                    'memory' => memory_get_usage(),
                    'limit' => $this->options['memory'] * 1024 * 1024,
                ]);
                break;
            }
            
            if ($this->paused) {
                sleep(1);
                continue;
            }
            
            $processedJob = $this->processNextJob($queue);
            
            if ($processedJob) {
                $processed++;
                
                if ($maxJobs && $processed >= $maxJobs) {
                    break;
                }
            } else {
                if ($this->options['stopOnEmpty']) {
                    break;
                }
                
                // Sleep when no jobs available
                sleep($this->options['sleep']);
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
}