<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Contracts;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;

/**
 * Abstract Job Processor
 */
abstract class BaseJobProcessor implements JobProcessorInterface
{
    protected Container $app;
    protected EventListener $events;
    
    protected array $eventBuffer = [];
    protected int $maxEventBufferSize = 100;
    
    /**
     * Create a new job processor
     */
    public function __construct(Container $app)
    {
        $this->app = $app;
        $this->events = new EventListener(new \Mlangeni\Machinjiri\Core\Artisans\Logging\Logger('job_processor'));
    }
    
    /**
     * Process a job
     */
    public function process(JobInterface $job): mixed
    {
        $job->incrementAttempts();
        
        $this->events->trigger('job.processing', [
            'job_id' => $job->getId(),
            'job_name' => $job->getName(),
            'attempt' => $job->getAttempts(),
        ]);
        
        $startTime = microtime(true);
        
        try {
            // Set timeout if supported
            if ($job->getTimeout() > 0) {
                set_time_limit($job->getTimeout());
            }
            
            // Handle the job
            $job->handle();
            
            $executionTime = microtime(true) - $startTime;
            
            $this->events->trigger('job.handled', [
                'job_id' => $job->getId(),
                'job_name' => $job->getName(),
                'execution_time' => $executionTime,
            ]);
            
            return null;
        } catch (\Exception $e) {
            $executionTime = microtime(true) - $startTime;
            
            $this->events->trigger('job.exception', [
                'job_id' => $job->getId(),
                'job_name' => $job->getName(),
                'exception' => $e->getMessage(),
                'execution_time' => $executionTime,
            ]);
            
            throw new MachinjiriException(
                sprintf('Job %s failed: %s', $job->getName(), $e->getMessage()),
                60001,
                $e
            );
        }
    }
    
    /**
     * Handle job failure
     */
    public function handleFailure(JobInterface $job, MachinjiriException $exception): void
    {
        $this->events->trigger('job.failed', [
            'job_id' => $job->getId(),
            'job_name' => $job->getName(),
            'exception' => $exception->getMessage(),
            'attempts' => $job->getAttempts(),
        ]);
        
        if ($job->getAttempts() >= $job->getMaxAttempts()) {
            $this->markAsFailed($job, $exception);
            $job->failed($exception);
        } else {
            $this->retry($job, $job->getRetryDelay());
        }
    }
    
    /**
     * Handle job success
     */
    public function handleSuccess(JobInterface $job, mixed $result): void
    {
        $this->markAsCompleted($job);
        
        $this->events->trigger('job.completed', [
            'job_id' => $job->getId(),
            'job_name' => $job->getName(),
            'attempts' => $job->getAttempts(),
            'result' => $result,
        ]);
    }
    
    /**
     * Retry a failed job
     */
    public function retry(JobInterface $job, int $delay = 0): bool
    {
        $this->events->trigger('job.retrying', [
            'job_id' => $job->getId(),
            'job_name' => $job->getName(),
            'delay' => $delay,
            'next_attempt' => $job->getAttempts() + 1,
        ]);
        
        return false; 
    }
    
    protected function triggerBuffered(string $event, array $data): void
    {
        $this->eventBuffer[] = ['event' => $event, 'data' => $data];
        
        if (count($this->eventBuffer) >= $this->maxEventBufferSize) {
            $this->flushEvents();
        }
    }
    
    protected function flushEvents(): void
    {
        if (empty($this->eventBuffer)) {
            return;
        }
        
        // Batch trigger events
        foreach ($this->eventBuffer as $eventData) {
            $this->events->trigger($eventData['event'], $eventData['data']);
        }
        
        $this->eventBuffer = [];
    }
    
}