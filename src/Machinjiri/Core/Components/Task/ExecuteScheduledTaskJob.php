<?php

namespace Mlangeni\Machinjiri\Core\Components\Task;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\BaseJob;
use Mlangeni\Machinjiri\Core\Exceptions\TaskSchedulerException;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Logging\{Logger, LoggerFactory};
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class ExecuteScheduledTaskJob extends BaseJob
{
    protected string $taskClass;
    protected array $taskOptions;
    protected array $executionMetrics = [];
    protected ?int $executionId = null;
    private EventListener $events;

    public function __construct(Container $app, string $taskClass, array $taskOptions = [], array $options = [])
    {
        parent::__construct($app, [], $options);
        $this->taskClass = $taskClass;
        $this->taskOptions = $taskOptions;
        $this->name = 'scheduled_task_' . $taskClass;
        
        // Set default retry settings
        $this->maxAttempts = $options['maxAttempts'] ?? 3;
        $this->timeout = $options['timeout'] ?? 60;

        $this->events = new EventListener(LoggerFactory::system("task-job", "task", true));
    }

    public function handle(): void
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();
        
        try {
            // Resolve and validate task
            $task = new $this->taskClass($this->app);

            if (!$task instanceof ScheduledTask) {
                throw TaskSchedulerException::jobError(
                    "Task class must be instance of ScheduledTask: {$this->taskClass}"
                );
            }
            
            // Execute task
            $result = $task->handle();
            
            // Record success metrics
            $this->recordExecution([
                'status' => 'success',
                'duration' => microtime(true) - $startTime,
                'memory_usage' => memory_get_usage() - $startMemory,
                'peak_memory' => memory_get_peak_usage(),
                'completed_at' => time(),
            ]);
            
            // Trigger success event
            $this->events->trigger('task.executed', [
                'task_class' => $this->taskClass,
                'status' => 'success',
                'duration' => microtime(true) - $startTime,
            ]);

            $this->logger->info("Task executed successfully", ['result' => $result]);
            
        } catch (\Throwable $e) {
            // Record failure metrics
            $this->recordExecution([
                'status' => 'failed',
                'duration' => microtime(true) - $startTime,
                'memory_usage' => memory_get_usage() - $startMemory,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
            ]);
            
            // Trigger failure event
            $this->events->trigger('task.failed', [
                'task_class' => $this->taskClass,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts ?? 1,
            ]);
            
            // Re-throw with context
            throw new TaskSchedulerException(
                "Task execution failed: {$e->getMessage()}",
                $e->getCode(),
                $e,
                ['task_class' => $this->taskClass, 'attempt' => $this->attempts ?? 1]
            );
        }
    }
    
    /**
     * Record execution metrics to repository.
     */
    protected function recordExecution(array $data): void
    {
        try {
            if ($this->app->bound('task.scheduler.repository')) {
                $repository = $this->app->resolve('task.scheduler.repository');
                if (method_exists($repository, 'recordExecution')) {
                    // Find task ID from handler class
                    $tasks = $repository->listTasks();
                    $taskId = null;
                    foreach ($tasks as $task) {
                        if ($task['handler_class'] === $this->taskClass) {
                            $taskId = $task['id'];
                            break;
                        }
                    }
                    
                    if ($taskId) {
                        $repository->recordExecution($taskId, $data);
                    }
                }
            }
        } catch (MachinjiriException $e) {
            $this->logger->error("Failed to record execution metrics: " . $e->getMessage());
        }
    }
    
    /**
     * Get execution metrics.
     */
    public function getMetrics(): array
    {
        return $this->executionMetrics;
    }
}