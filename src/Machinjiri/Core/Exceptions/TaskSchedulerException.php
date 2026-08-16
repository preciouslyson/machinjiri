<?php

namespace Mlangeni\Machinjiri\Core\Exceptions;

use Throwable;

final class TaskSchedulerException extends MachinjiriException
{
    public static function schedulerError(string $message, int $code = 0, ?Throwable $previous = null, array $context = []): self
    {
        return new self('Scheduler: ' . $message, $code, $previous, $context, 'scheduler');
    }

    public static function jobError(string $message, int $code = 0, ?Throwable $previous = null, array $context = []): self
    {
        return new self('Job: ' . $message, $code, $previous, $context, 'scheduler_job');
    }

    public static function repositoryError(string $message, int $code = 0, ?Throwable $previous = null, array $context = []): self
    {
        return new self('ScheduleRepository: ' . $message, $code, $previous, $context, 'scheduler_repository');
    }
    
    public static function lockError(string $message, int $code = 0, ?Throwable $previous = null, array $context = []): self
    {
        return new self('Lock: ' . $message, $code, $previous, $context, 'scheduler_lock');
    }
    
    public static function cronError(string $message, int $code = 0, ?Throwable $previous = null, array $context = []): self
    {
        return new self('Cron: ' . $message, $code, $previous, $context, 'scheduler_cron');
    }
}