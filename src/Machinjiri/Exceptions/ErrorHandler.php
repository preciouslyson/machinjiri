<?php

namespace Mlangeni\Machinjiri\Core\Exceptions;
use Mlangeni\Machinjiri\Core\Container;
class ErrorHandler
{
    /**
     * @var bool Whether to display errors to the user
     */
    private static $displayErrors = false;

    /**
     * @var string Path to the error log file
     */
    private static $logFile;

    /**
     * Register the custom error handler
     *
     * @param bool $displayErrors Whether to display errors to users
     * @param string|null $logFile Path to custom log file (optional)
     */
    public static function register(bool $displayErrors = false, ?string $logFile = null): void
    {
        self::$displayErrors = $displayErrors;
        self::$logFile = $logFile ?: Container::$appBasePath . '/../storage/logs/error.log';

        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /**
     * Handle standard PHP errors
     *
     * @param int $errno Error level
     * @param string $errstr Error message
     * @param string $errfile File where error occurred
     * @param int $errline Line number where error occurred
     * @return bool
     * @throws \ErrorException
     */
    public static function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        // Don't handle errors that are not in the error_reporting level
        if (!(error_reporting() & $errno)) {
            return false;
        }

        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    }

    /**
     * Handle uncaught exceptions
     *
     * @param \Throwable $exception
     */
    public static function handleException(\Throwable $exception): void
    {
        self::logError($exception);
        self::displayError($exception);
        exit(1);
    }

    /**
     * Handle shutdown for fatal errors
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();
        
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $exception = new \ErrorException(
                $error['message'], 0, $error['type'], $error['file'], $error['line']
            );
            
            self::logError($exception);
            self::displayError($exception);
            exit(1);
        }
    }

    /**
     * Log error details
     *
     * @param \Throwable $exception
     */
    private static function logError(\Throwable $exception): void
    {
        $logMessage = sprintf(
            "[%s] %s: %s in %s on line %d\nStack Trace:\n%s\n\n",
            date('Y-m-d H:i:s'),
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        );

        error_log($logMessage, 3, self::$logFile);
    }

    /**
     * Display error to user in a user-friendly way
     *
     * @param \Throwable $exception
     */
    private static function displayError(\Throwable $exception): void
    {
        // Clear any previous output
        if (ob_get_length()) {
            ob_clean();
        }

        // http_response_code(500);

        if (self::$displayErrors) {
            // Development mode - show detailed error
            self::renderErrorPage($exception);
        } else {
            // Production mode - show generic error message
            self::renderGenericErrorPage();
        }
    }

    /**
     * Render detailed error page for development
     *
     * @param \Throwable $exception
     */
    private static function renderErrorPage(\Throwable $exception): void
    {
        $errorClass = get_class($exception);
        $errorMessage = htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
        $errorFile = htmlspecialchars($exception->getFile(), ENT_QUOTES, 'UTF-8');
        $errorLine = $exception->getLine();
        $errorTrace = htmlspecialchars($exception->getTraceAsString(), ENT_QUOTES, 'UTF-8');

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Machinjiri - Error</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f8f9fa;
            margin: 0;
            padding: 20px;
        }
        .error-container {
            max-width: 800px;
            margin: 0 auto;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .error-header {
            background: #dc3545;
            color: white;
            padding: 20px;
            font-size: 24px;
            font-weight: bold;
        }
        .error-body {
            padding: 20px;
        }
        .error-detail {
            margin-bottom: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-left: 4px solid #dc3545;
            border-radius: 4px;
        }
        .error-label {
            font-weight: bold;
            color: #495057;
            display: block;
            margin-bottom: 5px;
        }
        .error-value {
            color: #212529;
        }
        .error-trace {
            background: #2b303b;
            color: #dee2e6;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            font-family: monospace;
            white-space: pre-wrap;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-header">Machinjiri - Application Error</div>
        <div class="error-body">
            <div class="error-detail">
                <span class="error-label">Message:</span>
                <span class="error-value">$errorMessage</span>
            </div>
            <div class="error-detail">
                <span class="error-label">Location:</span>
                <span class="error-value">$errorFile on line $errorLine</span>
            </div>
            <div class="error-detail">
                <span class="error-label">Stack Trace:</span>
                <div class="error-trace">$errorTrace</div>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Render generic error page for production
     */
    private static function renderGenericErrorPage(): void
    {
        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error - Application Exception</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f8f9fa;
            margin: 0;
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .error-container {
            max-width: 500px;
            text-align: center;
            background: #fff;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .error-icon {
            font-size: 48px;
            color: #dc3545;
            margin-bottom: 20px;
        }
        .error-title {
            font-size: 24px;
            margin-bottom: 10px;
            color: #212529;
        }
        .error-message {
            color: #6c757d;
            margin-bottom: 20px;
        }
        .error-action {
            margin-top: 20px;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            transition: background 0.3s;
        }
        .btn:hover {
            background: #0069d9;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">Machinjiri</div>
        <h1 class="error-title">Something Went Wrong</h1>
        <p class="error-message">We apologize for the inconvenience. Our team has been notified and is working to fix the issue.</p>
        <div class="error-action">
            <a href="/" class="btn">Return to Homepage</a>
        </div>
    </div>
</body>
</html>
HTML;
    }
}