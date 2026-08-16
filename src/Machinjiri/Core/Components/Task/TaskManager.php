<?php

namespace Mlangeni\Machinjiri\Core\Components\Task;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Components\Task\ScheduledTask;
use Mlangeni\Machinjiri\Core\Exceptions\{TaskSchedulerException, MachinjiriException};
use Mlangeni\Machinjiri\Core\Artisans\Contracts\JobDispatcherInterface;
use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;
use Mlangeni\Machinjiri\Core\Artisans\Logging\{Logger, LoggerFactory};
use Cron\CronExpression;

class TaskManager
{
    protected Container $app;
    protected ScheduleRepository $repository;
    protected CacheManager $cache;
    protected Logger $logger;
    protected array $registeredTasks = [];
    protected array $taskCache = [];
    
    // Cache keys
    protected const CACHE_KEY_TASKS = 'scheduler:manager:tasks';
    protected const CACHE_KEY_TASK = 'scheduler:manager:task:';
    protected const CACHE_KEY_STATS = 'scheduler:manager:stats';
    protected const CACHE_TTL_SHORT = 60;
    protected const CACHE_TTL_MEDIUM = 300;
    protected const CACHE_TTL_LONG = 3600;

    public function __construct(Container $app)
    {
        $this->app = $app;
        
        if (!$this->app->bound('task.scheduler.repository')) {
            throw TaskSchedulerException::repositoryError("task.scheduler.repository not bound in container");
        }

        if (!class_exists(CronExpression::class)) {
            throw TaskSchedulerException::schedulerError("CronExpression library not installed. install using 'composer require dragonmantank/cron-expression'");
        }
        
        $this->repository = $this->app->resolve('task.scheduler.repository');
        $this->cache = $this->initializeCache();
        $this->logger = LoggerFactory::system('scheduler', 'TaskScheduler');

        $this->autoRegisterAndSync();

    }
    
    /**
     * Initialize cache manager.
     * @throws TaskSchedulerException
     * @return CacheManager
     */
    protected function initializeCache(): CacheManager
    {
        if (!$this->app->bound(CacheManager::class)) {
            throw TaskSchedulerException::schedulerError("Cache manager not bound in container");
        }
        return $this->app->resolve(CacheManager::class);
    }

    /**
     * Register a task class (to be persisted in DB).
     */
    public function registerTask(string $taskClass): void
    {
        if (!in_array($taskClass, $this->registeredTasks)) {
            $this->registeredTasks[] = $taskClass;
            // Invalidate cache when new tasks are registered
            $this->invalidateCache();
        }
    }
    
    /**
     * Register multiple tasks at once.
     */
    public function registerTasks(array $taskClasses): void
    {
        foreach ($taskClasses as $taskClass) {
            $this->registerTask($taskClass);
        }
    }

    /**
     * Sync registered tasks with the database.
     * Improved with caching and batch operations.
     */
    public function sync(): void
    {
        // Try to get existing tasks from cache
        $existingTasks = $this->getTasksFromCache();
        
        if ($existingTasks === null) {
            $existingTasks = $this->repository->listTasks(true);
            $this->cacheTasks($existingTasks);
        }
        
        $existingMap = [];
        foreach ($existingTasks as $task) {
            $existingMap[$task['handler_class']] = $task;
        }
        
        $tasksToSave = [];
        $updatedTasks = [];
        
        foreach ($this->registeredTasks as $taskClass) {
            // Skip if already registered
            if (isset($existingMap[$taskClass])) {
                continue;
            }
            
            try {

                if (!class_exists($taskClass)) {
                    continue;
                }

                $task = new $taskClass($this->app);
    
                if (!$task instanceof ScheduledTask) {
                    continue;
                }
                
                $cronExpression = $task->getCronExpression();
                $nextRun = $this->calculateNextRun($cronExpression);
                
                $taskData = [
                    'name' => $task->getName(),
                    'cron_expression' => $cronExpression,
                    'handler_class' => $taskClass,
                    'options' => json_encode($task->getOptions()),
                    'priority' => $task->getPriority(),
                    'task_group' => $task->getGroup(),
                    'enabled' => 1,
                    'next_run' => $nextRun,
                    'created_at' => time(),
                    'updated_at' => time(),
                ];
                
                $tasksToSave[] = $taskData;
                $updatedTasks[] = $taskClass;
                
            } catch (MachinjiriException $e) {
                // Log error but continue with other tasks
                $this->logger->error("Failed to sync task {$taskClass}: " . $e->getMessage());
            }
        }
        
        // Batch save tasks
        foreach ($tasksToSave as $taskData) {
            $id = $this->repository->saveTask($taskData);
            if ($id) {
                $taskData['id'] = $id;
                $this->cacheTask($taskData);
            }
        }
        
        // Update cache if changes were made
        if (!empty($updatedTasks)) {
            $this->invalidateCache();
            // Preload new tasks
            $this->preloadCache();
        }
    }
    
    /**
     * Get tasks from cache.
     */
    protected function getTasksFromCache(): ?array
    {
        return $this->cache->get(self::CACHE_KEY_TASKS);
    }
    
    /**
     * Cache all tasks.
     */
    protected function cacheTasks(array $tasks): void
    {
        $this->cache->set(self::CACHE_KEY_TASKS, $tasks, self::CACHE_TTL_LONG);
        
        // Cache each task individually
        foreach ($tasks as $task) {
            $this->cacheTask($task);
        }
    }
    
    /**
     * Cache a single task.
     */
    protected function cacheTask(array $taskData): void
    {
        $cacheKey = self::CACHE_KEY_TASK . $taskData['id'];
        $this->cache->set($cacheKey, $taskData, self::CACHE_TTL_MEDIUM);
    }
    
    /**
     * Get a task from cache.
     */
    protected function getCachedTask(int $taskId): ?array
    {
        $cacheKey = self::CACHE_KEY_TASK . $taskId;
        return $this->cache->get($cacheKey);
    }
    
    /**
     * Invalidate task cache.
     */
    protected function invalidateCache(): void
    {
        $this->cache->delete(self::CACHE_KEY_TASKS);
        $this->cache->delete(self::CACHE_KEY_STATS);
    }
    
    /**
     * Preload cache.
     */
    public function preloadCache(): void
    {
        try {
            $tasks = $this->repository->listTasks();
            $this->cacheTasks($tasks);
            
            // Cache stats
            $stats = $this->getAllStats();
            $this->cache->set(self::CACHE_KEY_STATS, $stats, self::CACHE_TTL_MEDIUM);
            
        } catch (MachinjiriException $e) {
            $this->logger->error("Failed to preload task cache: " . $e->getMessage());
        }
    }
    
    /**
     * Calculate next run time from cron expression.
     */
    protected function calculateNextRun(string $cronExpression): int
    {
        try {
            return CronExpression::factory($cronExpression)->getNextRunDate()->getTimestamp();
        } catch (MachinjiriException $e) {
            // Default to now + 1 hour if invalid cron
            return time() + 3600;
        }
    }

    /**
     * Manually trigger a task by ID.
     */
    public function runNow(int $taskId): void
    {
        // Try to get from cache first
        $taskData = $this->getCachedTask($taskId);
        
        if (!$taskData) {
            $taskData = $this->repository->getTaskById($taskId);
            if ($taskData) {
                $this->cacheTask($taskData);
            }
        }
        
        if (!$taskData) {
            throw new \Exception("Task not found with ID: {$taskId}");
        }
        
        if (!$taskData['enabled']) {
            throw new \Exception("Task is disabled: {$taskData['name']}");
        }
        
        // Dispatch directly without waiting for scheduler
        $job = new ExecuteScheduledTaskJob(
            $this->app, 
            $taskData['handler_class'], 
            json_decode($taskData['options'] ?? '{}', true),
            [
                'queue' => $taskData['queue'] ?? 'scheduler',
                'maxAttempts' => 1, // Manual run should only attempt once
                'timeout' => 300, // 5 minutes for manual runs
            ]
        );
        
        if ($this->app->bound('queue.dispatcher')) {
            $dispatcher = $this->app->resolve(JobDispatcherInterface::class);
            $dispatcher->dispatch($job);
        } else {
            // Fallback: run synchronously
            $job->handle();
        }
    }

    /**
     * List all tasks (with optional filter).
     */
    public function listTasks(bool $onlyEnabled = false): array
    {
        if ($onlyEnabled) {
            // Try to get from cache
            $tasks = $this->getTasksFromCache();
            if ($tasks !== null) {
                return array_filter($tasks, function($task) {
                    return $task['enabled'] == 1;
                });
            }
        }
        
        $tasks = $this->repository->listTasks($onlyEnabled);
        
        // Cache if fetching all tasks
        if (!$onlyEnabled) {
            $this->cacheTasks($tasks);
        }
        
        return $tasks;
    }
    
    /**
     * Get a task by ID with caching.
     */
    public function getTask(int $taskId): ?array
    {
        // Try cache first
        $task = $this->getCachedTask($taskId);
        
        if (!$task) {
            $task = $this->repository->getTaskById($taskId);
            if ($task) {
                $this->cacheTask($task);
            }
        }
        
        return $task;
    }
    
    /**
     * Enable or disable a task.
     */
    public function setTaskEnabled(int $taskId, bool $enabled): void
    {
        $this->repository->setEnabled($taskId, $enabled);
        
        // Invalidate cache
        $this->invalidateCache();
        $this->cache->delete(self::CACHE_KEY_TASK . $taskId);
    }
    
    /**
     * Get execution history for a task.
     */
    public function getTaskHistory(int $taskId, int $limit = 10): array
    {
        return $this->repository->getExecutionHistory($taskId, $limit);
    }
    
    /**
     * Get task statistics with caching.
     */
    public function getTaskStats(int $taskId): array
    {
        // Try to get from cache
        $cacheKey = self::CACHE_KEY_TASK . $taskId . ':stats';
        $stats = $this->cache->get($cacheKey);
        
        if ($stats !== null) {
            return $stats;
        }
        
        $stats = $this->repository->getTaskStats($taskId);
        $this->cache->set($cacheKey, $stats, self::CACHE_TTL_SHORT);
        
        return $stats;
    }
    
    /**
     * Get all task statistics with caching.
     */
    public function getAllStats(): array
    {
        // Try to get from cache
        $stats = $this->cache->get(self::CACHE_KEY_STATS);
        
        if ($stats !== null) {
            return $stats;
        }
        
        $tasks = $this->listTasks();
        $stats = [];
        
        foreach ($tasks as $task) {
            $stats[$task['id']] = $this->getTaskStats($task['id']);
        }
        
        $this->cache->set(self::CACHE_KEY_STATS, $stats, self::CACHE_TTL_MEDIUM);
        
        return $stats;
    }
    
    /**
     * Clear all cache.
     */
    public function clearCache(): void
    {
        $this->cache->delete(self::CACHE_KEY_TASKS);
        $this->cache->delete(self::CACHE_KEY_STATS);
        
        // Clear individual task caches
        $tasks = $this->repository->listTasks();
        foreach ($tasks as $task) {
            $this->cache->delete(self::CACHE_KEY_TASK . $task['id']);
            $this->cache->delete(self::CACHE_KEY_TASK . $task['id'] . ':stats');
        }
        
        $this->logger->info("Task manager cache cleared");
    }
    
    /**
     * Get cache manager instance.
     */
    public function getCache(): CacheManager
    {
        return $this->cache;
    }

    private function autoRegisterAndSync(): void 
    {
        $config = $this->app->configurations['tasks'] ?? [];
        $registeredTasks = $this->app->config . '/components/tasks.php';
        if ($this->validateConfig($config)) return;
        if (!is_file($registeredTasks)) return;
        $registeredTasks = require $registeredTasks;
        if (!is_array($registeredTasks) || count($registeredTasks) === 0) return;

        foreach($registeredTasks['registered_tasks'] as $registeredTask) {
            $this->registerTask($registeredTask);
        }

        $this->sync();
    }

    private function validateConfig(array $config): bool
    {
        if (count($config) === 0) return false;
        if (!isset($config['default'])) return false;
        if (isset($config['drivers']) && count($config['drivers'][$config['default']])) return false;
        return true;
    }
}