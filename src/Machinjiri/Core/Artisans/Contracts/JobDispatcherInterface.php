<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Contracts;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;

/**
 * Job Dispatcher Interface
 */
interface JobDispatcherInterface
{
    /**
     * Dispatch a job to the queue
     */
    public function dispatch(JobInterface $job): string;
    
    /**
     * Dispatch a job to a specific queue
     */
    public function dispatchToQueue(JobInterface $job, string $queue): string;
    
    /**
     * Dispatch a job with a delay
     */
    public function dispatchWithDelay(JobInterface $job, int $delay): string;
    
    /**
     * Dispatch a job immediately (sync)
     */
    public function dispatchNow(JobInterface $job): mixed;
    
    /**
     * Dispatch multiple jobs
     */
    public function dispatchBulk(array $jobs): array;
    
    /**
     * Get the default queue name
     */
    public function getDefaultQueue(): string;
}