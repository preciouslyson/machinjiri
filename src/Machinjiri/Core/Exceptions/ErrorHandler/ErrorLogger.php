<?php

namespace Mlangeni\Machinjiri\Core\Exceptions\ErrorHandler;

use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;

class ErrorLogger
{
    private static ?Logger $logger = null;

    public static function setLogger(Logger $logger): void
    {
        self::$logger = $logger;
    }

    public static function logError(\Throwable $exception, array $context = []): void
    {
        if (!self::$logger) {
            return;
        }

        $logContext = [
            'timestamp'        => date('Y-m-d H:i:s'),
            'exception_class'  => get_class($exception),
            'message'          => $exception->getMessage(),
            'file'             => $exception->getFile(),
            'line'             => $exception->getLine(),
            'code'             => $exception->getCode(),
            'trace'            => $exception->getTraceAsString(),
            'request_uri'      => $_SERVER['REQUEST_URI'] ?? 'CLI',
            'request_method'   => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'user_agent'       => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'ip_address'       => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'session_id'       => session_id() ?: 'none',
            'memory_usage'     => memory_get_usage(true) / 1024 / 1024 . ' MB',
            'peak_memory'      => memory_get_peak_usage(true) / 1024 / 1024 . ' MB',
            'additional_context' => $context,
        ];

        self::$logger->error($exception->getMessage(), [
            'exception' => $logContext['exception_class'],
            'file'      => $logContext['file'],
            'line'      => $logContext['line'],
            'trace'     => $exception->getTrace(),
            'context'   => $context,
        ]);
    }

    public static function getErrorLevel(\Throwable $exception): string
    {
        if ($exception instanceof \ErrorException) {
            $severity = $exception->getSeverity();
            if (in_array($severity, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                return 'CRITICAL';
            }
        }
        if ($exception instanceof \PDOException) {
            return 'DATABASE';
        }
        return 'ERROR';
    }
}