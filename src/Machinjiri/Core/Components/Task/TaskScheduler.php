<?php

namespace Mlangeni\Machinjiri\Core\Components\Task;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Logging\{LoggerFactory, Logger};
use Mlangeni\Machinjiri\Core\Exceptions\TaskSchedulerException;
use Mlangeni\Machinjiri\Core\Artisans\Contracts\JobDispatcherInterface;
use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;
use Cron\CronExpression;

class TaskScheduler
{
    protected Container $app;
    protected ScheduleRepository $repository;
    protected JobDispatcherInterface $dispatcher;
    protected Logger $logger;
    protected EventListener $events;
    protected CacheManager $cache;
    protected string $defaultQueue = 'scheduler';
    protected array $locks = [];
    protected array $config = [];
    protected bool $isRunning = false;
    
    // Cache keys
    protected const CACHE_KEY_LOCKS = 'scheduler:locks';
    protected const CACHE_KEY_TASKS = 'scheduler:tasks';
    protected const CACHE_KEY_STATUS = 'scheduler:status';
    protected const CACHE_KEY_STATS = 'scheduler:stats';
    protected const CACHE_TTL_SHORT = 60; // 1 minute
    protected const CACHE_TTL_MEDIUM = 300; // 5 minutes
    protected const CACHE_TTL_LONG = 3600; // 1 hour

    public function __construct(Container $app)
    {
        $this->app = $app;
        $this->config = $app->configurations['tasks'] ?? [];
        
        if (!$app->bound('task.scheduler.repository')) {
            throw TaskSchedulerException::repositoryError("task.scheduler.repository not bound in container");
        }

        if (!$app->bound('queue.dispatcher')) {
            throw TaskSchedulerException::jobError("queue.dispatcher not bound in container");
        }
        
        $this->repository = $app->resolve('task.scheduler.repository');
        $this->dispatcher = $app->resolve('queue.dispatcher');
        $this->logger = LoggerFactory::system('scheduler', 'task');
        $this->events = new EventListener(
            LoggerFactory::system('scheduler', 'task', true)
        );
        
        // Initialize CacheManager
        $this->cache = $this->initializeCache();
        
        // Register shutdown handler
        register_shutdown_function([$this, 'shutdown']);
    }
    
    /**
     * Initialize the cache manager with scheduler-specific configuration.
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
     * Run all due tasks.
     */
    public function run(): void
    {
        if ($this->isRunning) {
            $this->logger->warning("Scheduler is already running, skipping");
            return;
        }
        
        $this->isRunning = true;
        $startTime = microtime(true);
        
        try {
            $this->logger->info("Scheduler run started");
            
            // Try to get tasks from cache first
            $dueTasks = $this->getDueTasksFromCache();
            
            if ($dueTasks === null) {
                $dueTasks = $this->repository->getDueTasks();
                $this->cacheDueTasks($dueTasks);
            }
            
            $this->logger->debug("Found " . count($dueTasks) . " due tasks");
            
            foreach ($dueTasks as $taskData) {
                $this->processTask($taskData);
            }
            
            $duration = microtime(true) - $startTime;
            $this->logger->info("Scheduler run completed", [
                'duration' => $duration,
                'tasks_processed' => count($dueTasks),
            ]);
            
            // Update status in cache
            $this->updateSchedulerStatus();
            
            $this->events->trigger('scheduler.completed', [
                'duration' => $duration,
                'tasks_processed' => count($dueTasks),
            ]);
            
        } catch (\Throwable $e) {
            $this->logger->error("Scheduler run failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            $this->events->trigger('scheduler.failed', [
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        } finally {
            $this->isRunning = false;
            $this->cache->set(self::CACHE_KEY_STATUS . ':running', false, 60);
        }
    }

    /**
     * Get due tasks from cache.
     */
    protected function getDueTasksFromCache(): ?array
    {
        $cacheKey = self::CACHE_KEY_TASKS . ':due';
        return $this->cache->get($cacheKey);
    }
    
    /**
     * Cache due tasks.
     */
    protected function cacheDueTasks(array $tasks): void
    {
        $cacheKey = self::CACHE_KEY_TASKS . ':due';
        $this->cache->set($cacheKey, $tasks, self::CACHE_TTL_SHORT);
    }
    
    /**
     * Invalidate task cache.
     */
    protected function invalidateTaskCache(): void
    {
        $this->cache->delete(self::CACHE_KEY_TASKS . ':due');
        $this->cache->delete(self::CACHE_KEY_TASKS . ':all');
        $this->cache->delete(self::CACHE_KEY_STATUS);
    }

    /**
     * Process a single task.
     */
    protected function processTask(array $taskData): void
    {
        $taskId = $taskData['id'];
        
        // Check if task should run in maintenance mode
        $options = json_decode($taskData['options'] ?? '{}', true);
        $runInMaintenance = $options['runInMaintenanceMode'] ?? false;
        
        if (!$runInMaintenance && $this->app->isDownForMaintenance()) {
            $this->logger->debug("Skipping task due to maintenance mode", [
                'task_id' => $taskId,
                'task_name' => $taskData['name'],
            ]);
            return;
        }
        
        // Acquire lock to prevent overlapping
        $preventOverlap = $options['withoutOverlapping'] ?? true;
        if ($preventOverlap && !$this->acquireLock($taskId)) {
            $this->logger->debug("Task already running, skipping", [
                'task_id' => $taskId,
                'task_name' => $taskData['name'],
            ]);
            return;
        }
        
        try {
            $this->dispatchTask($taskData);
            $this->updateNextRun($taskData);
            
            // Record success
            $this->repository->recordExecution($taskId, [
                'status' => 'success',
                'started_at' => time(),
                'completed_at' => time(),
            ]);
            
            // Invalidate cache after successful execution
            $this->invalidateTaskCache();
            
        } catch (\Throwable $e) {
            // Log the error and record failure
            $this->logger->error("Task execution failed", [
                'task_id' => $taskId,
                'task_name' => $taskData['name'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            $this->repository->recordExecution($taskId, [
                'status' => 'failed',
                'started_at' => time(),
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
            ]);
            
            $this->events->trigger('task.error', [
                'task_id' => $taskId,
                'task_name' => $taskData['name'],
                'error' => $e->getMessage(),
            ]);
            
            // Re-throw if you want to handle it upstream
            throw $e;
        } finally {
            if ($preventOverlap) {
                $this->releaseLock($taskId);
            }
        }
    }

    /**
     * Run a single task by ID (for manual execution).
     */
    public function runTask(int $taskId): void
    {
        $taskData = $this->getTaskFromCache($taskId);
        if ($taskData === null) {
            $taskData = $this->repository->getTaskById($taskId);
            if ($taskData) {
                $this->cacheTask($taskData);
            }
        }
        
        if (!$taskData) {
            throw new \Exception("Task not found: {$taskId}");
        }
        
        if (!$taskData['enabled']) {
            throw new \Exception("Task is disabled: {$taskId}");
        }
        
        $this->processTask($taskData);
    }
    
    /**
     * Get a single task from cache.
     */
    protected function getTaskFromCache(int $taskId): ?array
    {
        $cacheKey = self::CACHE_KEY_TASKS . ":id:{$taskId}";
        return $this->cache->get($cacheKey);
    }
    
    /**
     * Cache a single task.
     */
    protected function cacheTask(array $taskData): void
    {
        $cacheKey = self::CACHE_KEY_TASKS . ":id:{$taskData['id']}";
        $this->cache->set($cacheKey, $taskData, self::CACHE_TTL_MEDIUM);
    }

    /**
     * Dispatch a single task as a job.
     */
    protected function dispatchTask(array $taskData): void
    {
        $options = json_decode($taskData['options'] ?? '{}', true);
        
        // Configure retry strategy
        $retryStrategy = $options['retryStrategy'] ?? 'exponential';
        $maxAttempts = $options['maxAttempts'] ?? 3;
        
        $jobOptions = [
            'queue' => $taskData['queue'] ?? $this->defaultQueue,
            'maxAttempts' => $maxAttempts,
            'timeout' => $options['timeout'] ?? 60,
            'retryDelay' => $this->calculateRetryDelay($retryStrategy, $options),
            'retryStrategy' => $retryStrategy,
        ];
        
        $job = new ExecuteScheduledTaskJob(
            $this->app,
            $taskData['handler_class'],
            $options,
            $jobOptions
        );
        
        $this->dispatcher->dispatch($job);
        
        $this->events->trigger('task.dispatched', [
            'task_id' => $taskData['id'],
            'task_name' => $taskData['name'],
            'queue' => $jobOptions['queue'],
        ]);
        
        $this->logger->debug("Task dispatched", [
            'task_id' => $taskData['id'],
            'task_name' => $taskData['name'],
            'queue' => $jobOptions['queue'],
        ]);
    }
    
    /**
     * Calculate retry delay based on strategy.
     */
    protected function calculateRetryDelay(string $strategy, array $options): int
    {
        $baseDelay = $options['retryDelay'] ?? 60;
        
        switch ($strategy) {
            case 'exponential':
                return $baseDelay;
            case 'fixed':
                return $baseDelay;
            case 'linear':
                return $baseDelay;
            default:
                return 60;
        }
    }

    /**
     * Update next run time using cron expression.
     */
    protected function updateNextRun(array $taskData): void
    {
        try {
            $cron = CronExpression::factory($taskData['cron_expression']);
            $nextRun = $cron->getNextRunDate()->getTimestamp();
            $this->repository->updateNextRun($taskData['id'], $nextRun);
            
            // Invalidate task cache after updating next run
            $this->invalidateTaskCache();
            
            $this->logger->debug("Next run updated", [
                'task_id' => $taskData['id'],
                'next_run' => date('Y-m-d H:i:s', $nextRun),
            ]);
        } catch (\Exception $e) {
            $this->logger->error("Failed to calculate next run", [
                'task_id' => $taskData['id'],
                'cron_expression' => $taskData['cron_expression'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Acquire a lock for a task using CacheManager.
     */
    protected function acquireLock(int $taskId): bool
    {
        $lockKey = self::CACHE_KEY_LOCKS . ":task:{$taskId}";
        $lockTimeout = 300; // 5 minutes
        
        try {
            // Try to acquire lock using CacheManager
            $locked = $this->cache->set($lockKey, time(), $lockTimeout);
            
            if ($locked) {
                $this->locks[$taskId] = $lockKey;
                return true;
            }
            
            return false;
        } catch (\Exception $e) {
            $this->logger->warning("Cache lock failed, falling back to file lock", [
                'task_id' => $taskId,
                'error' => $e->getMessage(),
            ]);
            
            // Fallback to file-based lock
            return $this->acquireFileLock($taskId);
        }
    }
    
    /**
     * Fallback file-based lock.
     */
    protected function acquireFileLock(int $taskId): bool
    {
        $lockDir = $this->config['lock_dir'] ?? sys_get_temp_dir();
        $lockFile = $lockDir . "/scheduler_task_{$taskId}.lock";
        
        $fp = @fopen($lockFile, 'c');
        if ($fp === false) {
            return false;
        }
        
        if (flock($fp, LOCK_EX | LOCK_NB)) {
            $this->locks[$taskId] = $fp;
            return true;
        }
        
        fclose($fp);
        return false;
    }

    /**
     * Release a lock for a task.
     */
    protected function releaseLock(int $taskId): void
    {
        // Try CacheManager lock first
        if (isset($this->locks[$taskId]) && is_string($this->locks[$taskId])) {
            $lockKey = $this->locks[$taskId];
            $this->cache->delete($lockKey);
            unset($this->locks[$taskId]);
            return;
        }
        
        // Release file lock
        if (isset($this->locks[$taskId])) {
            flock($this->locks[$taskId], LOCK_UN);
            fclose($this->locks[$taskId]);
            unset($this->locks[$taskId]);
        }
    }
    
    /**
     * Update scheduler status in cache.
     */
    protected function updateSchedulerStatus(): void
    {
        try {
            $status = [
                'last_run' => time(),
                'is_running' => false,
                'tasks_processed' => $this->getTasksProcessedCount(),
                'memory_usage' => memory_get_usage(),
            ];
            
            $this->cache->set(self::CACHE_KEY_STATUS, $status, self::CACHE_TTL_MEDIUM);
        } catch (\Exception $e) {
            // Don't fail if status update fails
            $this->logger->debug("Failed to update scheduler status in cache", [
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Get tasks processed count from cache.
     */
    protected function getTasksProcessedCount(): int
    {
        return (int) $this->cache->get('scheduler:processed_count') ?? 0;
    }
    
    /**
     * Increment tasks processed count.
     */
    protected function incrementProcessedCount(): void
    {
        $this->cache->increment('scheduler:processed_count');
    }
    
    /**
     * Get cached scheduler status.
     */
    public function getCachedStatus(): ?array
    {
        return $this->cache->get(self::CACHE_KEY_STATUS);
    }
    
    /**
     * Shutdown handler to release all locks.
     */
    public function shutdown(): void
    {
        if (!empty($this->locks)) {
            $this->logger->info("Scheduler shutdown, releasing locks", [
                'locks_count' => count($this->locks),
            ]);
            
            foreach (array_keys($this->locks) as $taskId) {
                $this->releaseLock($taskId);
            }
        }
        
        // Save final status
        $this->updateSchedulerStatus();
    }
    
    /**
     * Get the scheduler status.
     */
    public function getStatus(): array
    {
        // Try to get from cache first
        $cachedStatus = $this->getCachedStatus();
        
        $totalTasks = count($this->repository->listTasks());
        $enabledTasks = count($this->repository->listTasks(true));
        $dueTasks = count($this->repository->getDueTasks());
        
        $status = [
            'is_running' => $this->isRunning,
            'total_tasks' => $totalTasks,
            'enabled_tasks' => $enabledTasks,
            'due_tasks' => $dueTasks,
            'active_locks' => count($this->locks),
            'default_queue' => $this->defaultQueue,
            'memory_usage' => memory_get_usage(),
            'peak_memory' => memory_get_peak_usage(),
            'cache_driver' => $this->config['cache_driver'] ?? 'redis',
        ];
        
        // Add cached status if available
        if ($cachedStatus) {
            $status['last_run'] = $cachedStatus['last_run'] ?? null;
            $status['cached_tasks_processed'] = $cachedStatus['tasks_processed'] ?? 0;
        }
        
        // Cache the status for future requests
        $this->cache->set(self::CACHE_KEY_STATUS, $status, self::CACHE_TTL_SHORT);
        
        return $status;
    }
    
    /**
     * Clean up old execution records.
     */
    public function cleanHistory(int $daysToKeep = 30): void
    {
        $this->repository->cleanExecutionHistory($daysToKeep);
        $this->logger->info("Cleaned execution history", [
            'days_kept' => $daysToKeep,
        ]);
        
        // Invalidate caches
        $this->invalidateTaskCache();
    }
    
    /**
     * Get cache manager instance.
     */
    public function getCache(): CacheManager
    {
        return $this->cache;
    }
    
    /**
     * Clear all scheduler caches.
     */
    public function clearCache(): void
    {
        $this->cache->delete(self::CACHE_KEY_TASKS . ':due');
        $this->cache->delete(self::CACHE_KEY_TASKS . ':all');
        $this->cache->delete(self::CACHE_KEY_STATUS);
        $this->cache->delete(self::CACHE_KEY_STATS);
        $this->cache->delete('scheduler:processed_count');
        
        $this->logger->info("Scheduler cache cleared");
    }
    
    /**
     * Preload tasks into cache.
     */
    public function preloadCache(): void
    {
        try {
            // Load all tasks
            $allTasks = $this->repository->listTasks();
            $this->cache->set(self::CACHE_KEY_TASKS . ':all', $allTasks, self::CACHE_TTL_LONG);
            
            // Cache each task individually
            foreach ($allTasks as $task) {
                $this->cacheTask($task);
            }
            
            // Load due tasks
            $dueTasks = $this->repository->getDueTasks();
            $this->cacheDueTasks($dueTasks);
            
            // Update status
            $this->updateSchedulerStatus();
            
            $this->logger->info("Scheduler cache preloaded", [
                'tasks_cached' => count($allTasks),
                'due_tasks_cached' => count($dueTasks),
            ]);
        } catch (\Exception $e) {
            $this->logger->error("Failed to preload cache", [
                'error' => $e->getMessage(),
            ]);
        }
    }
}