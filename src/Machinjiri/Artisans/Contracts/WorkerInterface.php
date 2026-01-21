<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Contracts;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;

/**
 * Worker Interface
 */
interface WorkerInterface
{
    /**
     * Start the worker
     */
    public function start(string $queue = 'default', array $options = []): void;
    
    /**
     * Stop the worker
     */
    public function stop(): void;
    
    /**
     * Pause the worker
     */
    public function pause(): void;
    
    /**
     * Resume the worker
     */
    public function resume(): void;
    
    /**
     * Check if worker is running
     */
    public function isRunning(): bool;
    
    /**
     * Get worker status
     */
    public function getStatus(): array;
    
    /**
     * Process a single job
     */
    public function processNextJob(string $queue = 'default'): bool;
    
    /**
     * Process jobs until empty or stopped
     */
    public function run(string $queue = 'default', int $maxJobs = null): int;

    
}