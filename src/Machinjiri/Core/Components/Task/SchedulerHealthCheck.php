<?php

namespace Mlangeni\Machinjiri\Core\Components\Task;

use Mlangeni\Machinjiri\Core\Container;

class SchedulerHealthCheck
{
    protected TaskScheduler $scheduler;
    protected ScheduleRepository $repository;
    protected Container $app;
    
    public function __construct(Container $app)
    {
        $this->app = $app;
        $this->scheduler = $app->resolve(TaskScheduler::class);
        $this->repository = $app->resolve('task.scheduler.repository');
    }
    
    /**
     * Perform a health check on the scheduler.
     */
    public function check(): array
    {
        $status = $this->scheduler->getStatus();
        
        $health = [
            'status' => 'healthy',
            'timestamp' => time(),
            'metrics' => $status,
            'checks' => [
                'repository' => $this->checkRepository(),
                'locks' => $this->checkLocks(),
                'due_tasks' => $this->checkDueTasks(),
                'queue' => $this->checkQueue(),
            ]
        ];
        
        // Determine overall status
        if ($health['checks']['repository'] !== 'ok') {
            $health['status'] = 'critical';
        }
        
        if ($health['checks']['due_tasks'] > 100) {
            $health['status'] = 'warning';
        }
        
        if ($health['checks']['locks'] > 10) {
            $health['status'] = 'degraded';
        }
        
        return $health;
    }
    
    /**
     * Check repository connectivity.
     */
    protected function checkRepository(): string
    {
        try {
            $this->repository->listTasks(true);
            return 'ok';
        } catch (\Exception $e) {
            return 'failed: ' . $e->getMessage();
        }
    }
    
    /**
     * Check active locks.
     */
    protected function checkLocks(): int
    {
        return $this->scheduler->getStatus()['active_locks'] ?? 0;
    }
    
    /**
     * Check due tasks count.
     */
    protected function checkDueTasks(): int
    {
        return count($this->repository->getDueTasks());
    }
    
    /**
     * Check queue connectivity.
     */
    protected function checkQueue(): string
    {
        try {
            if ($this->app->bound('queue.dispatcher')) {
                return 'ok';
            }
            return 'warning: queue.dispatcher not bound';
        } catch (\Exception $e) {
            return 'failed: ' . $e->getMessage();
        }
    }
    
    /**
     * Get detailed health report.
     */
    public function getDetailedReport(): array
    {
        $tasks = $this->repository->listTasks();
        $taskStatus = [];
        
        foreach ($tasks as $task) {
            $stats = $this->repository->getTaskStats($task['id']);
            $taskStatus[] = [
                'id' => $task['id'],
                'name' => $task['name'],
                'enabled' => (bool)$task['enabled'],
                'cron' => $task['cron_expression'],
                'next_run' => $task['next_run'] ? date('Y-m-d H:i:s', $task['next_run']) : null,
                'last_execution' => $stats['last_execution_time'] ? date('Y-m-d H:i:s', $stats['last_execution_time']) : null,
                'success_rate' => round($stats['success_rate'], 2),
                'total_executions' => $stats['total_executions'],
            ];
        }
        
        return [
            'overall' => $this->check(),
            'tasks' => $taskStatus,
            'summary' => [
                'total_tasks' => count($tasks),
                'enabled_tasks' => count(array_filter($tasks, fn($t) => $t['enabled'])),
                'disabled_tasks' => count(array_filter($tasks, fn($t) => !$t['enabled'])),
                'tasks_with_errors' => count(array_filter($taskStatus, fn($t) => $t['success_rate'] < 90)),
            ],
        ];
    }
}