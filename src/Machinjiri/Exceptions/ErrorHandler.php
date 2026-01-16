<?php

namespace Mlangeni\Machinjiri\Core\Exceptions;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;

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
     * @var callable|null Custom exception renderer
     */
    private static $exceptionRenderer = null;

    /**
     * @var int Detail level for error reporting (0 = minimal, 1 = normal, 2 = verbose)
     */
    private static $detailLevel = 1;

    /**
     * @var array Error context data
     */
    private static $context = [];

    /**
     * @var Logger|null Logger instance
     */
    private static $logger = null;

    /**
     * @var EventListener|null Event listener
     */
    private static $eventListener = null;

    /**
     * @var bool Whether to send error reports
     */
    private static $reportErrors = true;

    /**
     * @var array Ignored error types
     */
    private static $ignoredErrors = [];

    /**
     * @var array Error throttle configuration
     */
    private static $throttleConfig = [
        'max' => 10,
        'decay' => 60, // seconds
    ];

    /**
     * @var array Error counters
     */
    private static $errorCounters = [];

    /**
     * Register the custom error handler
     *
     * @param bool $displayErrors Whether to display errors to users
     * @param string|null $logFile Path to custom log file (optional)
     * @param int $detailLevel Level of detail for error reporting (0-2)
     * @param array $config Additional configuration
     */
    public static function register(
        bool $displayErrors = false, 
        ?string $logFile = null, 
        int $detailLevel = 1,
        array $config = []
    ): void {
        self::$displayErrors = $displayErrors;
        
        self::$logFile = $logFile ?: self::resolvePath() . 'error.log';
        self::$detailLevel = max(0, min(2, $detailLevel)); // Clamp between 0-2
        self::$reportErrors = $config['report_errors'] ?? true;
        self::$ignoredErrors = $config['ignored_errors'] ?? [];
        self::$throttleConfig = array_merge(self::$throttleConfig, $config['throttle'] ?? []);

        // Initialize logger
        self::$logger = new Logger('errors');
        
        // Initialize event listener
        self::$eventListener = new EventListener(new Logger('events'));

        // Set error reporting based on environment
        error_reporting($displayErrors ? E_ALL : E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR);

        // Register handlers
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);

        // Start output buffering for cleaner error handling
        if (!ob_get_level()) {
            ob_start();
        }

        self::$eventListener->trigger('error_handler.registered');
    }
    
    /**
     * @return string
     */
    private static function resolvePath(): string {
        $appBase = Container::$appBasePath . '/../storage/logs/';
        $artisanTerminal = Container::$terminalBase . 'storage/logs/';
        $path = is_dir($appBase) ? $appBase : $artisanTerminal;
        return !is_dir($path) ? Container::getSystemTempDir() : $path;
    }

    /**
     * Set a custom exception renderer
     *
     * @param callable $renderer Callback that accepts a Throwable
     */
    public static function setExceptionRenderer(callable $renderer): void
    {
        self::$exceptionRenderer = $renderer;
    }

    /**
     * Add context data for error reporting
     *
     * @param array $context
     */
    public static function addContext(array $context): void
    {
        self::$context = array_merge(self::$context, $context);
    }

    /**
     * Clear context data
     */
    public static function clearContext(): void
    {
        self::$context = [];
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

        // Check if error should be ignored
        if (in_array($errno, self::$ignoredErrors)) {
            return true;
        }

        // Check error throttling
        $errorKey = md5($errstr . $errfile . $errline);
        if (!self::shouldReportError($errorKey)) {
            return true;
        }

        // Trigger event
        if (self::$eventListener) {
            self::$eventListener->trigger('error.occurred', [
                'errno' => $errno,
                'errstr' => $errstr,
                'errfile' => $errfile,
                'errline' => $errline
            ]);
        }

        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    }

    /**
     * Check if error should be reported based on throttling
     *
     * @param string $errorKey
     * @return bool
     */
    private static function shouldReportError(string $errorKey): bool
    {
        $now = time();
        
        if (!isset(self::$errorCounters[$errorKey])) {
            self::$errorCounters[$errorKey] = [
                'count' => 1,
                'last_time' => $now
            ];
            return true;
        }

        $counter = self::$errorCounters[$errorKey];
        
        // Reset counter if decay period has passed
        if ($now - $counter['last_time'] > self::$throttleConfig['decay']) {
            self::$errorCounters[$errorKey] = [
                'count' => 1,
                'last_time' => $now
            ];
            return true;
        }

        // Increment counter
        $counter['count']++;
        $counter['last_time'] = $now;
        self::$errorCounters[$errorKey] = $counter;

        // Check if max threshold reached
        if ($counter['count'] > self::$throttleConfig['max']) {
            if ($counter['count'] === self::$throttleConfig['max'] + 1) {
                // Log that errors are being throttled
                self::$logger->warning("Error throttled: {$errorKey}", [
                    'max_errors' => self::$throttleConfig['max'],
                    'decay' => self::$throttleConfig['decay']
                ]);
            }
            return false;
        }

        return true;
    }

    /**
     * Handle uncaught exceptions
     *
     * @param \Throwable $exception
     */
    public static function handleException(\Throwable $exception): void
    {
        self::reportException($exception);
        
        // Check if we should render exception
        if (self::shouldRenderException($exception)) {
            self::renderException($exception);
        } else {
            // Just log and exit silently
            self::$logger->error('Exception occurred but rendering is disabled', [
                'exception' => get_class($exception),
                'message' => $exception->getMessage()
            ]);
        }
        
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
            
            self::reportException($exception);
            
            if (self::shouldRenderException($exception)) {
                self::renderException($exception);
            }
            
            exit(1);
        }
    }

    /**
     * Report exception (log and notify)
     *
     * @param \Throwable $exception
     */
    private static function reportException(\Throwable $exception): void
    {
        // Trigger event
        if (self::$eventListener) {
            self::$eventListener->trigger('exception.reported', [
                'exception' => $exception,
                'context' => self::$context
            ]);
        }

        // Log error
        self::logError($exception);

        // Send notification if configured
        if (self::$reportErrors) {
            self::notifyAboutError($exception);
        }
    }

    /**
     * Render exception to user
     *
     * @param \Throwable $exception
     */
    private static function renderException(\Throwable $exception): void
    {
        // Clear any previous output
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Use MachinjiriException's show method if it's a MachinjiriException
        if ($exception instanceof MachinjiriException) {
            $exception->show();
            return;
        }

        // Use custom renderer if set
        if (self::$exceptionRenderer && is_callable(self::$exceptionRenderer)) {
            call_user_func(self::$exceptionRenderer, $exception);
            return;
        }

        if (self::$displayErrors) {
            // Development mode - show detailed error
            self::renderErrorPage($exception);
        } else {
            // Production mode - show generic error message
            self::renderGenericErrorPage();
        }
    }

    /**
     * Check if exception should be rendered
     *
     * @param \Throwable $exception
     * @return bool
     */
    private static function shouldRenderException(\Throwable $exception): bool
    {
        // Don't render certain exceptions in production
        if (!self::$displayErrors && $exception instanceof \ErrorException) {
            $errno = $exception->getSeverity();
            return in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR]);
        }
        
        return true;
    }

    /**
     * Log error details with context information
     *
     * @param \Throwable $exception
     */
    private static function logError(\Throwable $exception): void
    {
        $context = [
            'timestamp' => date('Y-m-d H:i:s'),
            'exception_class' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'code' => $exception->getCode(),
            'trace' => $exception->getTraceAsString(),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'CLI',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'session_id' => session_id() ?: 'none',
            'memory_usage' => memory_get_usage(true) / 1024 / 1024 . ' MB',
            'peak_memory' => memory_get_peak_usage(true) / 1024 / 1024 . ' MB',
            'additional_context' => self::$context
        ];

        $logMessage = sprintf(
            "[%s] %s: %s in %s on line %d\n".
            "Code: %d | Session: %s | Memory: %s (Peak: %s)\n".
            "Request: %s %s | IP: %s | Agent: %s\n".
            "Additional Context: %s\n".
            "Stack Trace:\n%s\n\n",
            $context['timestamp'],
            $context['exception_class'],
            $context['message'],
            $context['file'],
            $context['line'],
            $context['code'],
            $context['session_id'],
            $context['memory_usage'],
            $context['peak_memory'],
            $context['request_method'],
            $context['request_uri'],
            $context['ip_address'],
            $context['user_agent'],
            json_encode($context['additional_context']),
            $context['trace']
        );

        error_log($logMessage, 3, self::$logFile);

        // Also log to logger if available
        if (self::$logger) {
            self::$logger->error($exception->getMessage(), [
                'exception' => $context['exception_class'],
                'file' => $context['file'],
                'line' => $context['line'],
                'trace' => $exception->getTrace(),
                'context' => self::$context
            ]);
        }
    }

    /**
     * Notify about error (e.g., email, slack, etc.)
     *
     * @param \Throwable $exception
     */
    private static function notifyAboutError(\Throwable $exception): void
    {
        // This is a placeholder for notification system
        // In a real implementation, you might send emails, Slack messages, etc.
        
        if (self::$eventListener) {
            self::$eventListener->trigger('error.notification', [
                'exception' => $exception,
                'level' => self::getErrorLevel($exception),
                'timestamp' => time()
            ]);
        }
    }

    /**
     * Get error level for notification
     *
     * @param \Throwable $exception
     * @return string
     */
    private static function getErrorLevel(\Throwable $exception): string
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

    /**
     * Display error to user in a user-friendly way
     *
     * @param \Throwable $exception
     */
    private static function displayError(\Throwable $exception): void
    {
        // Deprecated - use renderException instead
        self::renderException($exception);
    }

    /**
     * Render detailed error page for development
     *
     * @param \Throwable $exception
     */
    public static function renderErrorPage(\Throwable $exception): void
    {
        $context = [
            'timestamp' => date('Y-m-d H:i:s'),
            'exception_class' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'code' => $exception->getCode(),
            'trace' => $exception->getTraceAsString(),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'CLI',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'session_id' => session_id() ?: 'none',
            'memory_usage' => memory_get_usage(true) / 1024 / 1024 . ' MB',
            'peak_memory' => memory_get_peak_usage(true) / 1024 / 1024 . ' MB',
            'additional_context' => self::$context,
            'PHP_VERSION' => PHP_VERSION
        ];
        
        $errorClass = get_class($exception);
        $errorMessage = htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
        $errorFile = htmlspecialchars($exception->getFile(), ENT_QUOTES, 'UTF-8');
        $errorLine = $exception->getLine();
        $errorCode = $exception->getCode();
        $errorTrace = $exception->getTrace();
        
        // Get code snippet
        $codeSnippet = self::getCodeSnippet($errorFile, $errorLine);
        
        // Get request data
        $requestData = self::getRequestData();
        
        // Get environment data
        $environmentData = self::getEnvironmentData();
        
        // Get session data
        $sessionData = self::getSessionData();
        
        // Get registered routes
        $routesData = self::getRoutesData();
        
        // Get recent logs
        $logsData = self::getRecentLogs();

        $showTrace = self::$detailLevel >= 1;
        $showEnvironment = self::$detailLevel >= 2;
        
        $appName = getenv("APP_NAME") ?? "Machinjiri";
        $appVersion = getenv("APP_VERSION") ?? "1.0.0";
        $environment = getenv("APP_ENV") ?? "development";

        // Enhanced HTML with Laravel-like design
        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$appName} - Error #{$errorCode}</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #6f42c1;
            --primary-dark: #5a32a3;
            --secondary: #20c997;
            --danger: #e74c3c;
            --warning: #f39c12;
            --info: #3498db;
            --dark: #212529;
            --light: #f8f9fa;
            --gray: #6c757d;
            --light-gray: #e9ecef;
            --border-radius: 8px;
            --box-shadow: 0 1rem 3rem rgba(0, 0, 0, 0.175);
            --transition: all 0.3s ease;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: var(--dark);
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .error-wrapper {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .error-header {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px 30px;
            margin-bottom: 25px;
            box-shadow: var(--box-shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-left: 5px solid var(--danger);
        }
        
        .app-info h1 {
            color: var(--primary);
            margin-bottom: 5px;
            font-size: 24px;
        }
        
        .app-info .environment {
            display: inline-block;
            background: var(--light-gray);
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 5px;
        }
        
        .error-badge {
            background: var(--danger);
            color: white;
            padding: 8px 16px;
            border-radius: var(--border-radius);
            font-weight: 600;
            font-size: 14px;
        }
        
        .error-container {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 25px;
            margin-bottom: 25px;
        }
        
        .main-error {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--box-shadow);
        }
        
        .error-section {
            padding: 25px;
            border-bottom: 1px solid var(--light-gray);
        }
        
        .error-section:last-child {
            border-bottom: none;
        }
        
        .section-title {
            color: var(--primary);
            font-size: 18px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-title i {
            font-size: 16px;
        }
        
        .error-message {
            background: #fff5f5;
            border: 1px solid #fed7d7;
            border-left: 4px solid var(--danger);
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .error-message h3 {
            color: var(--danger);
            margin-bottom: 5px;
        }
        
        .error-location {
            background: var(--light-gray);
            padding: 12px 15px;
            border-radius: 4px;
            font-family: 'Monaco', 'Consolas', monospace;
            font-size: 14px;
            margin-bottom: 20px;
        }
        
        .code-snippet {
            background: #2d3748;
            color: #e2e8f0;
            border-radius: 6px;
            overflow: hidden;
            margin-bottom: 20px;
        }
        
        .code-header {
            background: #1a202c;
            padding: 10px 15px;
            font-size: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .code-line-numbers {
            display: flex;
            background: #1a202c;
            padding: 15px 5px;
            font-family: 'Monaco', 'Consolas', monospace;
            font-size: 13px;
            color: #718096;
        }
        
        .code-content {
            padding: 15px;
            font-family: 'Monaco', 'Consolas', monospace;
            font-size: 13px;
            overflow-x: auto;
        }
        
        .line-number {
            padding: 0 10px;
            text-align: right;
            min-width: 40px;
        }
        
        .line-error {
            background: #fed7d7;
            color: #c53030;
        }
        
        .trace-item {
            background: var(--light);
            border: 1px solid var(--light-gray);
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 10px;
        }
        
        .trace-location {
            color: var(--primary);
            font-family: 'Monaco', 'Consolas', monospace;
            font-size: 13px;
            margin-bottom: 5px;
        }
        
        .trace-file {
            color: var(--gray);
            font-size: 12px;
        }
        
        .sidebar {
            display: flex;
            flex-direction: column;
            gap: 25px;
        }
        
        .info-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--box-shadow);
        }
        
        .info-item {
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--light-gray);
        }
        
        .info-item:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }
        
        .info-label {
            font-size: 12px;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
        }
        
        .info-value {
            font-weight: 500;
            word-break: break-all;
        }
        
        .tabs {
            display: flex;
            border-bottom: 1px solid var(--light-gray);
            margin-bottom: 15px;
        }
        
        .tab-button {
            padding: 10px 20px;
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            cursor: pointer;
            font-weight: 500;
            color: var(--gray);
            transition: var(--transition);
        }
        
        .tab-button:hover {
            color: var(--primary);
        }
        
        .tab-button.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .preformatted {
            background: var(--light);
            padding: 15px;
            border-radius: 4px;
            font-family: 'Monaco', 'Consolas', monospace;
            font-size: 12px;
            overflow-x: auto;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .actions {
            display: flex;
            gap: 10px;
            margin-top: 25px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
        }
        
        .btn-secondary {
            background: var(--light-gray);
            color: var(--dark);
        }
        
        .btn-secondary:hover {
            background: #d1d5db;
        }
        
        .copy-btn {
            background: var(--info);
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            margin-left: 10px;
        }
        
        .expand-btn {
            background: transparent;
            border: none;
            color: var(--primary);
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .expandable {
            max-height: 150px;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        
        .expandable.expanded {
            max-height: none;
        }
        
        @media (max-width: 1024px) {
            .error-container {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .error-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="error-wrapper">
        <div class="error-header">
            <div class="app-info">
                <h1><i class="fas fa-exclamation-triangle"></i> {$appName} <small>v{$appVersion}</small></h1>
                <div>
                    <span class="environment">{$environment}</span>
                    <span class="environment" style="background: var(--danger); color: white;">Error #{$errorCode}</span>
                </div>
            </div>
            <div class="error-badge">
                {$errorClass}
            </div>
        </div>
        
        <div class="error-container">
            <div class="main-error">
                <div class="error-section">
                    <h2 class="section-title"><i class="fas fa-bug"></i> Error Details</h2>
                    
                    <div class="error-message">
                        <h3><i class="fas fa-times-circle"></i> {$errorMessage}</h3>
                        <p>Exception thrown in <code>{$errorClass}</code></p>
                    </div>
                    
                    <div class="error-location">
                        <i class="fas fa-map-marker-alt"></i> {$errorFile} <strong>on line {$errorLine}</strong>
                    </div>
                    
                    {$codeSnippet}
                    
HTML;

        if ($showTrace && !empty($errorTrace)) {
            echo <<<HTML
                    <div class="error-section">
                        <h2 class="section-title"><i class="fas fa-project-diagram"></i> Stack Trace</h2>
                        <div class="tabs">
                            <button class="tab-button active" onclick="switchTraceTab('trace-full')">Full Trace</button>
                            <button class="tab-button" onclick="switchTraceTab('trace-simple')">Simple</button>
                        </div>
                        
                        <div id="trace-full" class="tab-content active">
HTML;
            
            foreach ($errorTrace as $index => $trace) {
                $file = $trace['file'] ?? 'internal';
                $line = $trace['line'] ?? 0;
                $class = $trace['class'] ?? '';
                $type = $trace['type'] ?? '';
                $function = $trace['function'] ?? '';
                $args = isset($trace['args']) ? self::formatTraceArgs($trace['args']) : '';
                
                echo <<<HTML
                            <div class="trace-item">
                                <div class="trace-location">
                                    <strong>#{$index}</strong> {$class}{$type}{$function}({$args})
                                </div>
                                <div class="trace-file">
                                    <i class="far fa-file"></i> {$file}:{$line}
                                </div>
                            </div>
HTML;
            }
            
            echo <<<HTML
                        </div>
                        
                        <div id="trace-simple" class="tab-content">
HTML;
            
            // Show simplified trace
            foreach ($errorTrace as $index => $trace) {
                $file = $trace['file'] ?? 'internal';
                $line = $trace['line'] ?? 0;
                
                if ($index < 10) { // Show only first 10
                    echo <<<HTML
                            <div class="trace-item">
                                <div class="trace-location">
                                    <strong>#{$index}</strong> {$file}
                                </div>
                            </div>
HTML;
                }
            }
            
            echo <<<HTML
                        </div>
                    </div>
HTML;
        }

        if ($showEnvironment) {
            echo <<<HTML
                    <div class="error-section">
                        <h2 class="section-title"><i class="fas fa-cogs"></i> Debug Information</h2>
                        <div class="tabs">
                            <button class="tab-button active" onclick="switchDebugTab('debug-request')">Request</button>
                            <button class="tab-button" onclick="switchDebugTab('debug-session')">Session</button>
                            <button class="tab-button" onclick="switchDebugTab('debug-environment')">Environment</button>
                            <button class="tab-button" onclick="switchDebugTab('debug-routes')">Routes</button>
                        </div>
                        
                        <div id="debug-request" class="tab-content active">
                            <div class="preformatted">{$requestData}</div>
                        </div>
                        
                        <div id="debug-session" class="tab-content">
                            <div class="preformatted">{$sessionData}</div>
                        </div>
                        
                        <div id="debug-environment" class="tab-content">
                            <div class="preformatted">{$environmentData}</div>
                        </div>
                        
                        <div id="debug-routes" class="tab-content">
                            <div class="preformatted">{$routesData}</div>
                        </div>
                    </div>
HTML;
        }

        echo <<<HTML
                <div class="error-section">
                    <div class="actions">
                        <button class="btn btn-primary" onclick="copyErrorDetails()">
                            <i class="far fa-copy"></i> Copy Error Details
                        </button>
                        <button class="btn btn-secondary" onclick="reloadPage()">
                            <i class="fas fa-redo"></i> Reload Page
                        </button>
                        <button class="btn btn-secondary" onclick="goHome()">
                            <i class="fas fa-home"></i> Go Home
                        </button>
HTML;

        if (self::$displayErrors) {
            echo <<<HTML
                        <button class="btn btn-secondary" onclick="toggleDarkMode()">
                            <i class="fas fa-moon"></i> Dark Mode
                        </button>
HTML;
        }

        echo <<<HTML
                    </div>
                </div>
            </div>
            
            <div class="sidebar">
                <div class="info-card">
                    <h3 class="section-title"><i class="fas fa-info-circle"></i> Error Information</h3>
                    <div class="info-item">
                        <div class="info-label">Error Code</div>
                        <div class="info-value">#{$errorCode}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Exception Type</div>
                        <div class="info-value">{$errorClass}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Timestamp</div>
                        <div class="info-value">{$context['timestamp']}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Memory Usage</div>
                        <div class="info-value">{$context['memory_usage']}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Peak Memory</div>
                        <div class="info-value">{$context['peak_memory']}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">PHP Version</div>
                        <div class="info-value">PHP {$context['PHP_VERSION']}</div>
                    </div>
                </div>
                
                <div class="info-card">
                    <h3 class="section-title"><i class="fas fa-globe"></i> Request Information</h3>
                    <div class="info-item">
                        <div class="info-label">URL</div>
                        <div class="info-value">{$_SERVER['REQUEST_URI']}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Method</div>
                        <div class="info-value">{$_SERVER['REQUEST_METHOD']}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">IP Address</div>
                        <div class="info-value">{$_SERVER['REMOTE_ADDR']}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">User Agent</div>
                        <div class="info-value expandable" id="user-agent">
                            {$_SERVER['HTTP_USER_AGENT']}
                        </div>
                        <button class="expand-btn" onclick="toggleExpand('user-agent')">
                            <i class="fas fa-expand"></i> Expand
                        </button>
                    </div>
                </div>
                
                <div class="info-card">
                    <h3 class="section-title"><i class="fas fa-lightbulb"></i> Quick Actions</h3>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <button class="btn btn-secondary" style="font-size: 12px;" onclick="clearCache()">
                            <i class="fas fa-broom"></i> Clear Cache
                        </button>
                        <button class="btn btn-secondary" style="font-size: 12px;" onclick="runDiagnostics()">
                            <i class="fas fa-stethoscope"></i> Diagnostics
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Tab switching
        function switchTraceTab(tabId) {
            document.querySelectorAll('#trace-full, #trace-simple').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
            });
            document.getElementById(tabId).classList.add('active');
            event.target.classList.add('active');
        }
        
        function switchDebugTab(tabId) {
            document.querySelectorAll('#debug-request, #debug-session, #debug-environment, #debug-routes').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
            });
            document.getElementById(tabId).classList.add('active');
            event.target.classList.add('active');
        }
        
        // Expand/collapse
        function toggleExpand(elementId) {
            const element = document.getElementById(elementId);
            const button = event.target;
            
            if (element.classList.contains('expanded')) {
                element.classList.remove('expanded');
                button.innerHTML = '<i class="fas fa-expand"></i> Expand';
            } else {
                element.classList.add('expanded');
                button.innerHTML = '<i class="fas fa-compress"></i> Collapse';
            }
        }
        
        // Copy error details to clipboard
        function copyErrorDetails() {
            const errorDetails = `
Error: {$errorMessage}
File: {$errorFile}
Line: {$errorLine}
Code: {$errorCode}
Class: {$errorClass}
Time: {$context['timestamp']}
URL: {$_SERVER['REQUEST_URI']}
IP: {$_SERVER['REMOTE_ADDR']}
            `;
            
            navigator.clipboard.writeText(errorDetails).then(() => {
                alert('Error details copied to clipboard!');
            });
        }
        
        // Navigation
        function reloadPage() {
            window.location.reload();
        }
        
        function goHome() {
            window.location.href = '/';
        }
        
        // Toggle dark mode
        function toggleDarkMode() {
            document.body.classList.toggle('dark-mode');
            document.querySelectorAll('.error-header, .main-error, .info-card').forEach(el => {
                el.classList.toggle('dark-mode');
            });
        }
        
        // Quick actions
        function clearCache() {
            if (confirm('Clear application cache? This will log you out and clear temporary data.')) {
                fetch('/api/clear-cache', { method: 'POST' })
                    .then(() => alert('Cache cleared!'))
                    .catch(() => alert('Failed to clear cache'));
            }
        }
        
        function viewLogs() {
            alert('Log viewer would open here in a full implementation');
        }
        
        function runDiagnostics() {
            fetch('/api/diagnostics')
                .then(res => res.json())
                .then(data => {
                    alert('Diagnostics complete! Check console for details.');
                    console.log('Diagnostics:', data);
                });
        }
        
        
        // Add dark mode styles
        const style = document.createElement('style');
        style.textContent = `
            body.dark-mode {
                background: linear-gradient(135deg, #2d3748 0%, #4a5568 100%);
                color: #e2e8f0;
            }
            .dark-mode .error-header,
            .dark-mode .main-error,
            .dark-mode .info-card {
                background: #2d3748;
                color: #e2e8f0;
                border-color: #4a5568;
            }
            .dark-mode .code-snippet {
                background: #1a202c;
            }
            .dark-mode .error-message {
                background: #742a2a;
                border-color: #c53030;
            }
        `;
        document.head.appendChild(style);
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Add line highlighting to code snippet
            const codeLines = document.querySelectorAll('.code-content div');
            codeLines.forEach(line => {
                if (line.textContent.includes('{$errorLine}')) {
                    line.classList.add('line-error');
                }
            });
        });
    </script>
</body>
</html>
HTML;
    }

    /**
     * Get code snippet around error line
     *
     * @param string $file
     * @param int $errorLine
     * @return string
     */
    private static function getCodeSnippet(string $file, int $errorLine): string
    {
        if (!file_exists($file) || !is_readable($file)) {
            return '';
        }

        $lines = file($file);
        $start = max(0, $errorLine - 7);
        $end = min(count($lines), $errorLine + 3);
        
        $snippet = '<div class="code-snippet">';
        $snippet .= '<div class="code-header">';
        $snippet .= '<span><i class="fas fa-code"></i> Code around line ' . $errorLine . '</span>';
        $snippet .= '<button class="copy-btn" onclick="copyCodeSnippet()"><i class="far fa-copy"></i> Copy</button>';
        $snippet .= '</div>';
        $snippet .= '<div style="display: flex;">';
        $snippet .= '<div class="code-line-numbers">';
        
        for ($i = $start; $i < $end; $i++) {
            $lineNumber = $i + 1;
            $lineClass = $lineNumber === $errorLine ? 'line-error' : '';
            $snippet .= '<div class="line-number ' . $lineClass . '">' . $lineNumber . '</div>';
        }
        
        $snippet .= '</div>';
        $snippet .= '<div class="code-content">';
        
        for ($i = $start; $i < $end; $i++) {
            $lineNumber = $i + 1;
            $lineContent = htmlspecialchars($lines[$i], ENT_QUOTES, 'UTF-8');
            $lineClass = $lineNumber === $errorLine ? 'line-error' : '';
            $snippet .= '<div class="' . $lineClass . '">' . $lineContent . '</div>';
        }
        
        $snippet .= '</div></div></div>';
        
        return $snippet;
    }

    /**
     * Format trace arguments
     *
     * @param array $args
     * @return string
     */
    private static function formatTraceArgs(array $args): string
    {
        $formatted = [];
        foreach ($args as $arg) {
            if (is_object($arg)) {
                $formatted[] = get_class($arg) . ' object';
            } elseif (is_array($arg)) {
                $formatted[] = 'Array(' . count($arg) . ')';
            } elseif (is_string($arg)) {
                $formatted[] = "'" . (strlen($arg) > 50 ? substr($arg, 0, 50) . '...' : $arg) . "'";
            } elseif (is_bool($arg)) {
                $formatted[] = $arg ? 'true' : 'false';
            } elseif ($arg === null) {
                $formatted[] = 'null';
            } else {
                $formatted[] = (string)$arg;
            }
        }
        return implode(', ', $formatted);
    }

    /**
     * Get request data for debugging
     *
     * @return string
     */
    private static function getRequestData(): string
    {
        $data = [
            'GET Parameters' => $_GET,
            'POST Parameters' => $_POST,
            'Cookies' => $_COOKIE,
            'Headers' => self::getAllHeaders(),
            'Server' => array_diff_key($_SERVER, ['HTTP_COOKIE' => '', 'PATH' => '']),
            'Files' => !empty($_FILES) ? array_keys($_FILES) : []
        ];

        return self::formatDebugData($data);
    }

    /**
     * Get session data for debugging
     *
     * @return string
     */
    private static function getSessionData(): string
    {
        $sessionData = [];
        
        if (session_status() === PHP_SESSION_ACTIVE) {
            $sessionData = $_SESSION;
        }
        
        $data = [
            'Session Status' => session_status() === PHP_SESSION_ACTIVE ? 'Active' : 'Inactive',
            'Session ID' => session_id() ?: 'None',
            'Session Data' => $sessionData,
            'Cookie Parameters' => session_get_cookie_params()
        ];

        return self::formatDebugData($data);
    }

    /**
     * Get environment data for debugging
     *
     * @return string
     */
    private static function getEnvironmentData(): string
    {
        $info = [
            'PHP Version' => PHP_VERSION,
            'Zend Engine' => zend_version(),
            'OS' => PHP_OS,
            'Server API' => PHP_SAPI,
            'Loaded Extensions' => get_loaded_extensions(),
            'PHP INI File' => php_ini_loaded_file(),
            'Include Path' => get_include_path(),
            'Memory Limit' => ini_get('memory_limit'),
            'Max Execution Time' => ini_get('max_execution_time'),
            'Upload Max Filesize' => ini_get('upload_max_filesize'),
            'Post Max Size' => ini_get('post_max_size'),
            'Timezone' => date_default_timezone_get(),
            'Locale' => setlocale(LC_ALL, 0),
            'App Environment' => getenv('APP_ENV') ?: 'Not set',
            'App Debug' => getenv('APP_DEBUG') ?: 'Not set',
            'App URL' => getenv('APP_URL') ?: 'Not set'
        ];

        return self::formatDebugData($info);
    }

    /**
     * Get routes data for debugging
     *
     * @return string
     */
    private static function getRoutesData(): string
    {
        $routes = [];
        
        // This would be populated from your router
        if (class_exists('Mlangeni\\Machinjiri\\Core\\Routing\\Router')) {
            // Try to get routes from router if available
            try {
                // $router = Container::getInstance()->make('router');
                // $routes = $router->getRoutes();
            } catch (\Exception $e) {
                $routes = ['error' => 'Could not load routes: ' . $e->getMessage()];
            }
        } else {
            $routes = ['info' => 'Router not available in error context'];
        }

        $data = [
            'Current Route' => $_SERVER['REQUEST_URI'] ?? 'Unknown',
            'Request Method' => $_SERVER['REQUEST_METHOD'] ?? 'Unknown',
            'Registered Routes' => $routes
        ];

        return self::formatDebugData($data);
    }

    /**
     * Get recent logs for debugging
     *
     * @return string
     */
    private static function getRecentLogs(): string
    {
        $logFile = self::$logFile;
        
        if (!file_exists($logFile)) {
            return 'No log file found';
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES);
        $recent = array_slice($lines, -50, 50); // Last 50 lines
        
        return implode("\n", $recent);
    }

    /**
     * Get all HTTP headers
     *
     * @return array
     */
    private static function getAllHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }

    /**
     * Format data for debug display
     *
     * @param array $data
     * @return string
     */
    private static function formatDebugData(array $data): string
    {
        $output = '';
        foreach ($data as $key => $value) {
            $output .= "=== {$key} ===\n";
            if (is_array($value)) {
                $output .= self::formatArray($value);
            } else {
                $output .= print_r($value, true) . "\n";
            }
            $output .= "\n";
        }
        return htmlspecialchars($output, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Format array for display
     *
     * @param array $array
     * @param int $indent
     * @return string
     */
    private static function formatArray(array $array, int $indent = 0): string
    {
        $output = '';
        $indentStr = str_repeat('  ', $indent);
        
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $output .= "{$indentStr}[{$key}] => Array\n";
                $output .= self::formatArray($value, $indent + 1);
            } else {
                $value = is_string($value) ? "'{$value}'" : $value;
                $output .= "{$indentStr}[{$key}] => {$value}\n";
            }
        }
        
        return $output;
    }

    /**
     * Get environment information for debugging
     *
     * @return string
     */
    private static function getEnvironmentInfo(): string
    {
        $info = [
            'PHP Version' => PHP_VERSION,
            'OS' => PHP_OS,
            'Server API' => PHP_SAPI,
            'Memory Usage' => memory_get_usage(true) / 1024 / 1024 . ' MB',
            'Memory Peak Usage' => memory_get_peak_usage(true) / 1024 / 1024 . ' MB',
            'Include Path' => get_include_path(),
            'Loaded Extensions' => implode(', ', get_loaded_extensions()),
        ];

        $output = '';
        foreach ($info as $key => $value) {
            $output .= "$key: $value\n";
        }

        return htmlspecialchars($output, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Render generic error page for production
     */
    private static function renderGenericErrorPage(): void
    {
        $appName = getenv("APP_NAME") ?? "Machinjiri";
        $supportEmail = getenv("APP_SUPPORT_EMAIL") ?? "support@example.com";
        $errorId = uniqid('ERR-', true);
        
        // Log the generic error view
        if (self::$logger) {
            self::$logger->info('Generic error page displayed', ['error_id' => $errorId]);
        }

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application Error - {$appName}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .error-container {
            max-width: 600px;
            width: 100%;
            background: white;
            border-radius: 16px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            text-align: center;
        }
        
        .error-icon {
            font-size: 64px;
            color: #e74c3c;
            margin-bottom: 20px;
            animation: bounce 2s infinite;
        }
        
        .error-title {
            font-size: 32px;
            color: #2c3e50;
            margin-bottom: 15px;
            font-weight: 600;
        }
        
        .error-message {
            color: #7f8c8d;
            font-size: 18px;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        
        .error-id {
            background: #f8f9fa;
            padding: 10px 15px;
            border-radius: 8px;
            font-family: 'Monaco', 'Consolas', monospace;
            font-size: 14px;
            color: #6c757d;
            margin-bottom: 30px;
            display: inline-block;
        }
        
        .actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .btn-primary {
            background: #3498db;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #f8f9fa;
            color: #495057;
            border: 1px solid #dee2e6;
        }
        
        .btn-secondary:hover {
            background: #e9ecef;
            transform: translateY(-2px);
        }
        
        .contact-info {
            border-top: 1px solid #eee;
            padding-top: 20px;
            color: #6c757d;
            font-size: 14px;
        }
        
        .contact-info a {
            color: #3498db;
            text-decoration: none;
        }
        
        .contact-info a:hover {
            text-decoration: underline;
        }
        
        @keyframes bounce {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-10px);
            }
        }
        
        @media (max-width: 640px) {
            .error-container {
                padding: 30px 20px;
            }
            
            .actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">⚠️</div>
        <h1 class="error-title">Something Went Wrong</h1>
        <p class="error-message">
            We apologize for the inconvenience. Our technical team has been notified 
            and is working to resolve the issue.
        </p>
        
        <div class="error-id">
            Error ID: {$errorId}
        </div>
        
        <div class="actions">
            <button class="btn btn-primary" onclick="window.location.href='/'">
                <i class="fas fa-home"></i> Go to Homepage
            </button>
            <button class="btn btn-secondary" onclick="window.location.reload()">
                <i class="fas fa-redo"></i> Try Again
            </button>
            <button class="btn btn-secondary" onclick="showErrorDetails()">
                <i class="fas fa-info-circle"></i> More Info
            </button>
        </div>
        
        <div class="contact-info">
            <p>If the problem persists, please contact our support team:</p>
            <p>
                <i class="fas fa-envelope"></i> 
                <a href="mailto:{$supportEmail}">{$supportEmail}</a> 
                | Reference ID: <strong>{$errorId}</strong>
            </p>
            <p style="margin-top: 10px; font-size: 12px;">
                <i class="fas fa-clock"></i> 
                Response time: 24-48 hours
            </p>
        </div>
    </div>

    <script>
        function showErrorDetails() {
            const details = `
Error Reference: {{$errorId}}
Timestamp: {${new Date().toISOString()}}
URL: {${window.location.href}}
User Agent: {${navigator.userAgent}}
            `;
            
            alert('Error Details:\n\n' + details + '\n\nPlease provide this information to support.');
        }
        
        // Add Font Awesome
        const faScript = document.createElement('script');
        faScript.src = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js';
        document.head.appendChild(faScript);
    </script>
</body>
</html>
HTML;
    }

    /**
     * Get error statistics
     *
     * @return array
     */
    public static function getErrorStats(): array
    {
        return [
            'total_errors' => array_sum(array_column(self::$errorCounters, 'count')),
            'throttled_errors' => count(array_filter(self::$errorCounters, 
                fn($counter) => $counter['count'] > self::$throttleConfig['max']
            )),
            'unique_errors' => count(self::$errorCounters),
            'throttle_config' => self::$throttleConfig
        ];
    }

    /**
     * Reset error counters
     */
    public static function resetErrorCounters(): void
    {
        self::$errorCounters = [];
        
        if (self::$logger) {
            self::$logger->info('Error counters reset');
        }
    }

    /**
     * Dump error information for debugging
     *
     * @param \Throwable $exception
     * @return array
     */
    public static function dumpException(\Throwable $exception): array
    {
        return [
            'class' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTrace(),
            'previous' => $exception->getPrevious() ? self::dumpException($exception->getPrevious()) : null,
            'timestamp' => time(),
            'memory' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];
    }
}