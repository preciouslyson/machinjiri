<?php

namespace Mlangeni\Machinjiri\Core\Components\Task;

use Mlangeni\Machinjiri\Core\Container;

/**
 * Base class for all scheduled tasks.
 */
abstract class ScheduledTask
{
    protected Container $app;
    protected string $name;
    protected string $cronExpression;
    protected array $options = [];
    protected int $priority = 0; // Lower = higher priority
    protected string $group = 'default';
    protected bool $runInMaintenanceMode = false;
    protected bool $withoutOverlapping = true;

    protected function __construct(Container $app)
    {
        $this->app = $app;
        $this->initialize();
    }
    
    /**
     * Initialize task (override for custom setup).
     */
    public function initialize(): void
    {
        // Override in child classes
    }

    /**
     * Get the task name.
     */
    public function getName(): string
    {
        return $this->name ?? static::class;
    }

    /**
     * Get the cron expression defining when to run.
     */
    public function getCronExpression(): string
    {
        return $this->cronExpression;
    }

    /**
     * Get additional options (queue, timeout, max attempts, etc.)
     */
    public function getOptions(): array
    {
        return array_merge([
            'maxAttempts' => 3,
            'timeout' => 60,
            'retryDelay' => 60,
            'retryStrategy' => 'exponential',
        ], $this->options);
    }
    
    /**
     * Get task priority (lower = higher priority).
     */
    public function getPriority(): int
    {
        return $this->priority;
    }
    
    /**
     * Get task group.
     */
    public function getGroup(): string
    {
        return $this->group;
    }
    
    /**
     * Check if task should run in maintenance mode.
     */
    public function shouldRunInMaintenanceMode(): bool
    {
        return $this->runInMaintenanceMode;
    }
    
    /**
     * Check if task should prevent overlapping.
     */
    public function shouldPreventOverlapping(): bool
    {
        return $this->withoutOverlapping;
    }

    /**
     * Execute the task.
     */
    abstract public function handle(): mixed;
    
    /**
     * Handle task failure.
     */
    public function failed(\Throwable $e): void
    {
        // Override for custom failure handling
    }
    
    /**
     * Get task description.
     */
    public function getDescription(): string
    {
        return "Task: " . $this->getName();
    }
}