<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Contracts;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

/**
 * Job Dispatcher
 */
class BaseJobDispatcher implements JobDispatcherInterface
{
    protected Container $app;
    protected QueueInterface $queue;
    protected string $defaultQueue = 'default';
    
    /**
     * Create a new job dispatcher
     */
    public function __construct(Container $app, QueueInterface $queue)
    {
        $this->app = $app;
        $this->queue = $queue;
    }
    
    /**
     * Dispatch a job to the queue
     */
    public function dispatch(JobInterface $job): string
    {
        return $this->dispatchToQueue($job, $this->getDefaultQueue());
    }
    
    /**
     * Dispatch a job to a specific queue
     */
    public function dispatchToQueue(JobInterface $job, string $queue): string
    {
        $jobId = $this->queue->push($job, $queue, $job->getDelay());
        
        resolve('events')->trigger('job.dispatched', [
            'job_id' => $jobId,
            'job_name' => $job->getName(),
            'queue' => $queue,
            'delay' => $job->getDelay(),
        ]);
        
        return $jobId;
    }
    
    /**
     * Dispatch a job with a delay
     */
    public function dispatchWithDelay(JobInterface $job, int $delay): string
    {
        $job->addMetadata('delay', $delay);
        return $this->queue->push($job, $job->getQueue(), $delay);
    }
    
    /**
     * Dispatch a job immediately (sync)
     */
    public function dispatchNow(JobInterface $job): mixed
    {
        if (!$job instanceof JobInterface) {
            throw new MachinjiriException('Invalid job instance', 60011);
        }
        
        $processor = $this->app->getProviderLoader()->resolve('job.processor');
        if (!$processor) {
            throw new MachinjiriException('Job processor not configured', 60012);
        }
        
        $this->app->getProviderLoader()->resolve('events')->trigger('job.processing', [
            'job_id' => $job->getId(),
            'job_name' => $job->getName(),
            'queue' => 'sync',
        ]);
        
        try {
            $result = $processor->process($job);
            $processor->handleSuccess($job, $result);
            return $result;
        } catch (MachinjiriException $e) {
            $processor->handleFailure($job, $e);
            throw $e;
        } catch (\Throwable $e) {
            $exception = new MachinjiriException('Sync job execution failed: ' . $e->getMessage(), 60013, $e);
            $processor->handleFailure($job, $exception);
            throw $exception;
        }
    }
    
    /**
     * Dispatch multiple jobs
     */
    public function dispatchBulk(array $jobs): array
    {
        if (empty($jobs)) {
            throw new MachinjiriException('Cannot dispatch empty jobs array', 60014);
        }
        
        $jobIds = [];
        
        foreach ($jobs as $job) {
            if (!($job instanceof JobInterface)) {
                throw new MachinjiriException('All items must implement JobInterface', 60015);
            }
            $jobIds[] = $this->dispatch($job);
        }
        
        $this->app->getProviderLoader()->resolve('events')->trigger('job.bulk_dispatched', [
            'count' => count($jobIds),
            'queue' => $this->getDefaultQueue(),
        ]);
        
        return $jobIds;
    }
    
    /**
     * Get the default queue name
     */
    public function getDefaultQueue(): string
    {
        return $this->defaultQueue;
    }
    
    /**
     * Set the default queue name
     */
    public function setDefaultQueue(string $queue): void
    {
        $this->defaultQueue = $queue;
    }
}