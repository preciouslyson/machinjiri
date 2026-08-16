<?php

namespace Mlangeni\Machinjiri\Core\Components\Task\Repositories;

use Mlangeni\Machinjiri\Core\Database\Builders\QueryBuilder;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Components\Task\ScheduleRepository;

class DatabaseScheduleRepository implements ScheduleRepository
{
    protected QueryBuilder $query;
    protected string $table = 'scheduled_tasks';
    protected string $executionsTable = 'task_executions';

    public function __construct(Container $app)
    {
        $this->query = new QueryBuilder($this->table);
    }

    public function getDueTasks(): array
    {
        $now = time();
        return $this->query
            ->where('enabled', '=', 1)
            ->where('next_run', '<=', $now)
            ->orderBy('priority', 'ASC')
            ->get();
    }

    public function updateNextRun(int $taskId, int $nextRun): void
    {
        $this->query
            ->where('id', '=', $taskId)
            ->update([
                'next_run' => $nextRun,
                'updated_at' => time(),
            ]);
    }

    public function recordExecution(int $taskId, array $data): void
    {
        $execQuery = new QueryBuilder($this->executionsTable);
        
        // Build execution record
        $record = [
            'task_id' => $taskId,
            'status' => $data['status'] ?? 'pending',
            'started_at' => $data['started_at'] ?? time(),
            'created_at' => time(),
        ];
        
        // Add optional fields
        if (isset($data['completed_at'])) {
            $record['completed_at'] = $data['completed_at'];
        }
        
        if (isset($data['duration'])) {
            $record['duration'] = (int) $data['duration'];
        }
        
        if (isset($data['memory_usage'])) {
            $record['memory_usage'] = (int) $data['memory_usage'];
        }
        
        if (isset($data['peak_memory'])) {
            $record['peak_memory'] = (int) $data['peak_memory'];
        }
        
        if (isset($data['error'])) {
            $record['error'] = substr($data['error'], 0, 1000);
        }
        
        if (isset($data['error_code'])) {
            $record['error_code'] = $data['error_code'];
        }
        
        if (isset($data['error_file'])) {
            $record['error_file'] = $data['error_file'];
        }
        
        if (isset($data['error_line'])) {
            $record['error_line'] = $data['error_line'];
        }
        
        $execQuery->insert($record);
    }

    public function getTaskById(int $id): ?array
    {
        $result = $this->query->where('id', '=', $id)->first();
        return $result ?: null;
    }

    public function listTasks(bool $onlyEnabled = false): array
    {
        $q = $this->query;
        if ($onlyEnabled) {
            $q->where('enabled', '=', 1);
        }
        return $q->orderBy('name', 'ASC')->get();
    }

    public function saveTask(array $taskData): int
    {
        // Remove id if present for insertion
        $id = $taskData['id'] ?? null;
        unset($taskData['id']);
        
        if ($id) {
            // Update existing task
            $taskData['updated_at'] = time();
            $this->query->where('id', '=', $id)->update($taskData);
            return $id;
        } else {
            // Insert new task
            $taskData['created_at'] = time();
            $taskData['updated_at'] = time();
            $this->query->insert($taskData);
            
            // Get last insert ID
            $result = $this->query->execute();
            return $result['lastInsertId'] ?? 0;
        }
    }

    public function setEnabled(int $taskId, bool $enabled): void
    {
        $this->query->where('id', '=', $taskId)->update([
            'enabled' => $enabled ? 1 : 0,
            'updated_at' => time(),
        ]);
    }
    
    public function getExecutionHistory(int $taskId, int $limit = 10): array
    {
        $execQuery = new QueryBuilder($this->executionsTable);
        return $execQuery
            ->where('task_id', '=', $taskId)
            ->orderBy('started_at', 'DESC')
            ->limit($limit)
            ->get();
    }
    
    public function cleanExecutionHistory(int $daysToKeep = 30): void
    {
        $cutoff = time() - ($daysToKeep * 86400);
        $execQuery = new QueryBuilder($this->executionsTable);
        $execQuery->where('started_at', '<', $cutoff)->delete();
    }
    
    public function getTaskStats(int $taskId): array
    {
        $execQuery = new QueryBuilder($this->executionsTable);
        
        // Get total executions
        $total = $execQuery->where('task_id', '=', $taskId)->count();
        
        // Get success/failure counts
        $success = $execQuery->where('task_id', '=', $taskId)
            ->where('status', '=', 'success')
            ->count();
        
        $failed = $execQuery->where('task_id', '=', $taskId)
            ->where('status', '=', 'failed')
            ->count();
        
        // Get average duration
        $avgDuration = $execQuery->where('task_id', '=', $taskId)
            ->where('status', '=', 'success')
            ->avg('duration');
        
        // Get last execution
        $lastExecution = $execQuery->where('task_id', '=', $taskId)
            ->orderBy('started_at', 'DESC')
            ->first();
        
        return [
            'total_executions' => $total,
            'success_count' => $success,
            'failed_count' => $failed,
            'success_rate' => $total > 0 ? ($success / $total) * 100 : 0,
            'average_duration' => $avgDuration ?: 0,
            'last_execution' => $lastExecution,
            'last_execution_time' => $lastExecution ? $lastExecution['started_at'] : null,
        ];
    }
}