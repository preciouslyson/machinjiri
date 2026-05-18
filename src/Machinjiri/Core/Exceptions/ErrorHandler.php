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
     * Render detailed error page for development (cozy style)
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

        // Cozy color palette (matching welcome page)
        $primaryColor = '#E68A5E';
        $primaryDark = '#C4633A';
        $bgColor = '#FCF7F0';
        $cardBg = '#FFFFFFDD';
        $textColor = '#2E2C2A';
        $subtleBorder = '#F2E5D8';
        $errorHighlight = '#FDE8E8';
        $errorBorder = '#F5C6C6';

        // Enhanced cozy HTML with Laravel-like design but warm
        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$appName} • Cozy Error</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: {$primaryColor};
            --primary-dark: {$primaryDark};
            --danger: #D9735A;
            --warning: #E8A87C;
            --info: #7F9EB5;
            --bg: {$bgColor};
            --card-bg: {$cardBg};
            --text: {$textColor};
            --text-light: #6B5E53;
            --border: {$subtleBorder};
            --shadow: 0 12px 28px -8px rgba(0, 0, 0, 0.08);
            --radius: 28px;
            --radius-sm: 20px;
            --transition: all 0.2s ease;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: var(--bg);
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', 'Poppins', sans-serif;
            color: var(--text);
            line-height: 1.5;
            min-height: 100vh;
            padding: 2rem 1.5rem;
            position: relative;
        }
        
        /* soft background pattern */
        body::before {
            content: "";
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: radial-gradient(#E8DCCC 1px, transparent 1px);
            background-size: 28px 28px;
            opacity: 0.2;
            pointer-events: none;
            z-index: 0;
        }
        
        .error-wrapper {
            max-width: 1400px;
            margin: 0 auto;
            position: relative;
            z-index: 2;
        }
        
        .error-header {
            background: var(--card-bg);
            backdrop-filter: blur(2px);
            border-radius: var(--radius);
            padding: 1.5rem 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .app-info h1 {
            color: var(--primary-dark);
            margin-bottom: 0.25rem;
            font-size: 1.6rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .app-info .environment {
            display: inline-block;
            background: #FFF3E6;
            padding: 0.2rem 0.8rem;
            border-radius: 40px;
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--primary-dark);
            border: 1px solid #FFE2CC;
        }
        
        .error-badge {
            background: #FDE8E8;
            color: var(--danger);
            padding: 0.5rem 1.2rem;
            border-radius: 60px;
            font-weight: 600;
            font-size: 0.85rem;
            border: 1px solid #F5C6C6;
        }
        
        .error-container {
            display: flex;
            gap: 1.8rem;
            flex-wrap: wrap;
        }
        
        .main-error {
            flex: 2;
            min-width: 280px;
        }
        
        .error-section {
            background: var(--card-bg);
            backdrop-filter: blur(2px);
            border-radius: var(--radius);
            padding: 1.6rem;
            margin-bottom: 1.8rem;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
        }
        
        .section-title {
            color: var(--primary-dark);
            font-size: 1.3rem;
            margin-bottom: 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.7rem;
            font-weight: 600;
        }
        
        .error-message {
            background: #FEF6F0;
            border-left: 4px solid var(--primary);
            padding: 1rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1.5rem;
        }
        
        .error-message h3 {
            color: var(--primary-dark);
            margin-bottom: 0.5rem;
            font-size: 1.1rem;
        }
        
        .error-location {
            background: #F5F0EA;
            padding: 0.8rem 1rem;
            border-radius: var(--radius-sm);
            font-family: 'SF Mono', 'Fira Code', monospace;
            font-size: 0.85rem;
            margin-bottom: 1.5rem;
            color: #5E4B3A;
        }
        
        .code-snippet {
            background: #2D2A27;
            border-radius: var(--radius-sm);
            overflow: hidden;
            margin-bottom: 1.5rem;
            color: #F5E6D3;
        }
        
        .code-header {
            background: #3A3632;
            padding: 0.7rem 1rem;
            font-size: 0.8rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #E6D5C0;
        }
        
        .code-line-numbers {
            display: flex;
            background: #2D2A27;
            padding: 0.8rem 0;
            font-family: monospace;
            font-size: 0.75rem;
        }
        
        .code-content {
            padding: 0.8rem;
            font-family: monospace;
            font-size: 0.75rem;
            overflow-x: auto;
        }
        
        .line-number {
            padding: 0 0.8rem;
            text-align: right;
            min-width: 45px;
            color: #A48E78;
        }
        
        .line-error {
            background: #5E3A2E;
            color: #FFC9A5;
        }
        
        .trace-item {
            background: #FEF9F4;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 1rem;
            margin-bottom: 0.8rem;
        }
        
        .trace-location {
            color: var(--primary-dark);
            font-family: monospace;
            font-size: 0.8rem;
            margin-bottom: 0.3rem;
        }
        
        .trace-file {
            color: var(--text-light);
            font-size: 0.7rem;
        }
        
        .sidebar {
            flex: 1;
            min-width: 280px;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        
        .info-card {
            background: var(--card-bg);
            backdrop-filter: blur(2px);
            border-radius: var(--radius);
            padding: 1.4rem;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
        }
        
        .info-item {
            margin-bottom: 0.8rem;
            padding-bottom: 0.8rem;
            border-bottom: 1px solid var(--border);
        }
        
        .info-item:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }
        
        .info-label {
            font-size: 0.7rem;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.2rem;
        }
        
        .info-value {
            font-weight: 500;
            word-break: break-all;
            font-size: 0.85rem;
        }
        
        .tabs {
            display: flex;
            gap: 0.2rem;
            border-bottom: 1px solid var(--border);
            margin-bottom: 1rem;
        }
        
        .tab-button {
            padding: 0.5rem 1.2rem;
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            cursor: pointer;
            font-weight: 500;
            color: var(--text-light);
            transition: var(--transition);
            font-size: 0.85rem;
        }
        
        .tab-button:hover {
            color: var(--primary-dark);
        }
        
        .tab-button.active {
            color: var(--primary-dark);
            border-bottom-color: var(--primary);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .preformatted {
            background: #FEF9F4;
            padding: 0.8rem;
            border-radius: var(--radius-sm);
            font-family: monospace;
            font-size: 0.7rem;
            overflow-x: auto;
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid var(--border);
        }
        
        .actions {
            display: flex;
            gap: 0.8rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: 60px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition);
            font-size: 0.85rem;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #FFF3E6;
            color: var(--primary-dark);
            border: 1px solid #FFE2CC;
        }
        
        .btn-secondary:hover {
            background: #FDE5D4;
            transform: translateY(-2px);
        }
        
        .copy-btn {
            background: var(--info);
            color: white;
            border: none;
            padding: 0.2rem 0.6rem;
            border-radius: 30px;
            cursor: pointer;
            font-size: 0.7rem;
        }
        
        .expand-btn {
            background: none;
            border: none;
            color: var(--primary-dark);
            cursor: pointer;
            font-size: 0.7rem;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            margin-top: 0.3rem;
        }
        
        .expandable {
            max-height: 70px;
            overflow: hidden;
            transition: max-height 0.2s ease;
        }
        
        .expandable.expanded {
            max-height: none;
        }
        
        @media (max-width: 860px) {
            .error-container {
                flex-direction: column;
            }
            .error-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
        
        /* dark mode toggle (optional, keeping cozy but with dark variant) */
        body.dark-mode {
            --bg: #2A2622;
            --card-bg: #3A3530DD;
            --text: #F0E6DC;
            --text-light: #CBBBA8;
            --border: #5B4F42;
        }
        body.dark-mode .error-message { background: #3E332A; }
        body.dark-mode .error-location { background: #3E3530; }
        body.dark-mode .preformatted { background: #3A332C; }
        body.dark-mode .btn-secondary { background: #4A4038; color: #F0DCC0; border-color: #6B5A4A; }
    </style>
</head>
<body>
    <div class="error-wrapper">
        <div class="error-header">
            <div class="app-info">
                <h1><i class="fas fa-couch"></i> {$appName} <span style="font-size:0.8rem;">v{$appVersion}</span></h1>
                <div>
                    <span class="environment">{$environment}</span>
                    <span class="environment" style="background: #FDE8E8; color: var(--danger);">Error #{$errorCode}</span>
                </div>
            </div>
            <div class="error-badge">
                <i class="fas fa-puzzle-piece"></i> {$errorClass}
            </div>
        </div>
        
        <div class="error-container">
            <div class="main-error">
                <div class="error-section">
                    <h2 class="section-title"><i class="fas fa-feather-alt"></i> Oops! Something went wrong</h2>
                    
                    <div class="error-message">
                        <h3><i class="fas fa-exclamation-triangle"></i> {$errorMessage}</h3>
                        <p>Exception thrown in <code>{$errorClass}</code></p>
                    </div>
                    
                    <div class="error-location">
                        <i class="fas fa-map-marker-alt"></i> {$errorFile} <strong>on line {$errorLine}</strong>
                    </div>
                    
                    {$codeSnippet}
                    
HTML;

        if ($showTrace && !empty($errorTrace)) {
            echo <<<HTML
                    <div>
                        <h2 class="section-title"><i class="fas fa-list-ul"></i> Stack Trace</h2>
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
                    <div>
                        <h2 class="section-title"><i class="fas fa-cogs"></i> Cozy Debug Info</h2>
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
                <div>
                    <div class="actions">
                        <button class="btn btn-primary" onclick="copyErrorDetails()">
                            <i class="far fa-copy"></i> Copy Error
                        </button>
                        <button class="btn btn-secondary" onclick="reloadPage()">
                            <i class="fas fa-redo"></i> Reload
                        </button>
                        <button class="btn btn-secondary" onclick="goHome()">
                            <i class="fas fa-home"></i> Home
                        </button>
                        <button class="btn btn-secondary" onclick="toggleDarkMode()">
                            <i class="fas fa-moon"></i> Dark
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="sidebar">
                <div class="info-card">
                    <h3 class="section-title"><i class="fas fa-info-circle"></i> Error Details</h3>
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
                    <h3 class="section-title"><i class="fas fa-globe"></i> Request</h3>
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
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.6rem;">
                        <button class="btn btn-secondary" style="font-size: 0.7rem;" onclick="clearCache()">
                            <i class="fas fa-broom"></i> Clear Cache
                        </button>
                        <button class="btn btn-secondary" style="font-size: 0.7rem;" onclick="runDiagnostics()">
                            <i class="fas fa-stethoscope"></i> Diagnostics
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
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
        
        function copyErrorDetails() {
            const details = `Error: {$errorMessage}\\nFile: {$errorFile}\\nLine: {$errorLine}\\nCode: {$errorCode}\\nClass: {$errorClass}\\nTime: {$context['timestamp']}\\nURL: {$_SERVER['REQUEST_URI']}\\nIP: {$_SERVER['REMOTE_ADDR']}`;
            navigator.clipboard.writeText(details).then(() => {
                alert('📋 Error details copied!');
            });
        }
        
        function reloadPage() { window.location.reload(); }
        function goHome() { window.location.href = '/'; }
        
        function toggleDarkMode() {
            document.body.classList.toggle('dark-mode');
        }
        
        function clearCache() {
            if (confirm('Clear cache? Might log you out.')) {
                fetch('/api/clear-cache', { method: 'POST' }).catch(() => alert('Cache clear endpoint not configured.'));
            }
        }
        
        function runDiagnostics() {
            fetch('/api/diagnostics')
                .then(res => res.json())
                .then(data => alert('Diagnostics complete. Check console.'))
                .catch(() => alert('Diagnostics not available.'));
        }
        
        // Highlight error line in code
        document.querySelectorAll('.code-content div').forEach(line => {
            if (line.textContent.includes('{$errorLine}')) {
                line.classList.add('line-error');
            }
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
     * Render generic error page for production (cozy style)
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
    <title>{$appName} • Cozy Error</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #E68A5E;
            --primary-dark: #C4633A;
            --bg: #FCF7F0;
            --card: #FFFFFFDD;
            --text: #2E2C2A;
            --text-light: #6B5E53;
            --border: #F2E5D8;
            --radius: 32px;
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            background: var(--bg);
            font-family: 'Inter', system-ui, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            position: relative;
        }
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background-image: radial-gradient(#E8DCCC 1px, transparent 1px);
            background-size: 28px 28px;
            opacity: 0.2;
            pointer-events: none;
        }
        .cozy-error {
            max-width: 550px;
            width: 100%;
            background: var(--card);
            backdrop-filter: blur(2px);
            border-radius: var(--radius);
            padding: 2rem;
            text-align: center;
            border: 1px solid var(--border);
            box-shadow: 0 20px 35px -12px rgba(0,0,0,0.08);
            position: relative;
            z-index: 2;
        }
        .cozy-icon {
            font-size: 4rem;
            color: var(--primary);
            margin-bottom: 1rem;
        }
        h1 {
            font-size: 2rem;
            color: var(--primary-dark);
            margin-bottom: 0.8rem;
        }
        .message {
            color: var(--text-light);
            margin-bottom: 1.5rem;
            line-height: 1.5;
        }
        .error-id {
            background: #FEF3EA;
            padding: 0.5rem 1rem;
            border-radius: 60px;
            font-family: monospace;
            font-size: 0.8rem;
            display: inline-block;
            margin-bottom: 1.8rem;
            color: var(--primary-dark);
        }
        .actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
            margin-bottom: 1.8rem;
        }
        .btn {
            padding: 0.6rem 1.3rem;
            border-radius: 60px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: 0.2s;
            font-size: 0.9rem;
            cursor: pointer;
            border: none;
        }
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }
        .btn-secondary {
            background: #FFF3E6;
            color: var(--primary-dark);
            border: 1px solid #FFE2CC;
        }
        .contact {
            font-size: 0.75rem;
            color: var(--text-light);
            border-top: 1px solid var(--border);
            padding-top: 1.2rem;
        }
        a { color: var(--primary-dark); text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="cozy-error">
    <div class="cozy-icon"><i class="fas fa-mug-hot"></i></div>
    <h1>Something feels off</h1>
    <p class="message">We've encountered a small hiccup. Our team has been notified, and we'll get it fixed as soon as possible.</p>
    <div class="error-id"><i class="fas fa-fingerprint"></i> {$errorId}</div>
    <div class="actions">
        <button class="btn btn-primary" onclick="window.location.href='/'"><i class="fas fa-home"></i> Home</button>
        <button class="btn btn-secondary" onclick="window.location.reload()"><i class="fas fa-redo"></i> Retry</button>
    </div>
    <div class="contact">
        <i class="fas fa-envelope"></i> Need help? <a href="mailto:{$supportEmail}">{$supportEmail}</a> • Reference: <strong>{$errorId}</strong>
    </div>
</div>
<script>
    // Optional: log error ID to console
    console.log('Error ID: {$errorId}');
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