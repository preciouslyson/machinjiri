<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Process;
use Mlangeni\Machinjiri\Core\Artisans\Process\QueueDriverInterface;
use Mlangeni\Machinjiri\Core\Artisans\Process\TaskInterface;
use RuntimeException;
use InvalidArgumentException;

class Kameza
{
    /**
     * @var QueueDriverInterface The queue driver instance.
     */
    private static $driver;

    /**
     * Set the queue driver.
     *
     * @param QueueDriverInterface $driver
     * @return void
     */
    public static function setDriver(QueueDriverInterface $driver): void
    {
        self::$driver = $driver;
    }

    /**
     * Get the queue driver.
     *
     * @return QueueDriverInterface
     * @throws RuntimeException if driver is not set
     */
    private static function getDriver(): QueueDriverInterface
    {
        if (!self::$driver instanceof QueueDriverInterface) {
            throw new RuntimeException('Queue driver has not been initialized');
        }
        return self::$driver;
    }

    /**
     * Dispatch a task to the queue.
     *
     * @param TaskInterface $task
     * @param int $delay Delay in seconds
     * @return string Job ID
     */
    public static function dispatch(TaskInterface $task, int $delay = 0): string
    {
        $payload = [
            'task_class' => get_class($task),
            'task_data' => serialize($task),
            'max_attempts' => $task->getMaxAttempts(),
            'attempts' => 0
        ];

        if ($delay > 0) {
            return self::getDriver()->later($delay, $payload);
        }

        return self::getDriver()->push($payload);
    }

    /**
     * Process a task from the queue.
     *
     * @param array $payload
     * @return void
     */
    public static function process(array $payload): void
    {
        $task = self::resolveTask($payload);
        $payload['attempts']++;

        try {
            $task->execute();
            self::getDriver()->delete($payload['job_id']);
        } catch (\Exception $e) {
            if ($payload['attempts'] >= $payload['max_attempts']) {
                self::getDriver()->fail($payload);
                return;
            }

            self::getDriver()->retry($payload['job_id'], $payload);
        }
    }

    /**
     * Retry a failed task.
     *
     * @param string $jobId
     * @return void
     */
    public static function retry(string $jobId): void
    {
        $payload = self::getDriver()->getFailed($jobId);
        if ($payload) {
            self::getDriver()->retry($jobId, $payload);
        }
    }

    /**
     * Create task instance from payload.
     *
     * @param array $payload
     * @return TaskInterface
     * @throws InvalidArgumentException
     */
    private static function resolveTask(array $payload): TaskInterface
    {
        if (!isset($payload['task_class']) || !class_exists($payload['task_class'])) {
            throw new InvalidArgumentException('Invalid task class');
        }

        $task = unserialize($payload['task_data']);
        if (!$task instanceof TaskInterface) {
            throw new InvalidArgumentException('Invalid task implementation');
        }

        return $task;
    }
}