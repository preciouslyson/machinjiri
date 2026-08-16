<?php

namespace Mlangeni\Machinjiri\Core\Components\Task;

interface ScheduleRepository
{
    /**
     * Fetch all enabled tasks whose next_run is <= now.
     * Returns an array of associative arrays with keys:
     * id, name, cron_expression, handler_class, options, queue, etc.
     */
    public function getDueTasks(): array;

    /**
     * Update the next_run timestamp for a task.
     */
    public function updateNextRun(int $taskId, int $nextRun): void;

    /**
     * Record an execution attempt.
     */
    public function recordExecution(int $taskId, array $data): void;

    /**
     * Get a task by its ID.
     */
    public function getTaskById(int $id): ?array;

    /**
     * List all tasks (optionally filter by enabled).
     */
    public function listTasks(bool $onlyEnabled = false): array;

    /**
     * Create or update a task definition.
     */
    public function saveTask(array $taskData): int;

    /**
     * Enable/disable a task.
     */
    public function setEnabled(int $taskId, bool $enabled): void;
    
    /**
     * Get execution history for a task.
     */
    public function getExecutionHistory(int $taskId, int $limit = 10): array;
    
    /**
     * Clean up old execution records.
     */
    public function cleanExecutionHistory(int $daysToKeep = 30): void;
    
    /**
     * Get task statistics.
     */
    public function getTaskStats(int $taskId): array;
}