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
     * @var callable|null Custom exception renderer
     */
    private static $exceptionRenderer = null;

    /**
     * @var int Detail level for error reporting (0 = minimal, 1 = normal, 2 = verbose)
     */
    private static $detailLevel = 1;

    /**
     * Register the custom error handler
     *
     * @param bool $displayErrors Whether to display errors to users
     * @param string|null $logFile Path to custom log file (optional)
     * @param int $detailLevel Level of detail for error reporting (0-2)
     */
    public static function register(bool $displayErrors = false, ?string $logFile = null, int $detailLevel = 1): void
    {
        self::$displayErrors = $displayErrors;
        
        self::$logFile = $logFile ?: self::resolvePath() . 'error.log';
        self::$detailLevel = max(0, min(2, $detailLevel)); // Clamp between 0-2

        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }
    
    /**
     * @return string
     */
    private static function resolvePath () : string {
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
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ];

        $logMessage = sprintf(
            "[%s] %s: %s in %s on line %d\nCode: %d\nRequest: %s %s\nIP: %s\nUser Agent: %s\nStack Trace:\n%s\n\n",
            $context['timestamp'],
            $context['exception_class'],
            $context['message'],
            $context['file'],
            $context['line'],
            $context['code'],
            $context['request_method'],
            $context['request_uri'],
            $context['ip_address'],
            $context['user_agent'],
            $context['trace']
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

        http_response_code(500);

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
        $errorCode = $exception->getCode();
        $errorTrace = htmlspecialchars($exception->getTraceAsString(), ENT_QUOTES, 'UTF-8');

        // Show different levels of detail based on configuration
        $showTrace = self::$detailLevel >= 1;
        $showEnvironment = self::$detailLevel >= 2;

        $environmentInfo = '';
        if ($showEnvironment) {
            $environmentInfo = self::getEnvironmentInfo();
        }

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
            max-width: 1200px;
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
        .tab-container {
            margin-top: 20px;
        }
        .tab-buttons {
            display: flex;
            border-bottom: 1px solid #ddd;
        }
        .tab-button {
            padding: 10px 20px;
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-bottom: none;
            border-radius: 4px 4px 0 0;
            margin-right: 5px;
            cursor: pointer;
        }
        .tab-button.active {
            background: #fff;
            border-bottom: 1px solid #fff;
            margin-bottom: -1px;
        }
        .tab-content {
            display: none;
            padding: 20px;
            border: 1px solid #ddd;
            border-top: none;
        }
        .tab-content.active {
            display: block;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-header">Machinjiri - Application Error</div>
        <div class="error-body">
            <div class="error-detail">
                <span class="error-label">Exception Type:</span>
                <span class="error-value">$errorClass</span>
            </div>
            <div class="error-detail">
                <span class="error-label">Message:</span>
                <span class="error-value">$errorMessage</span>
            </div>
            <div class="error-detail">
                <span class="error-label">Location:</span>
                <span class="error-value">$errorFile on line $errorLine</span>
            </div>
            <div class="error-detail">
                <span class="error-label">Code:</span>
                <span class="error-value">$errorCode</span>
            </div>
HTML;

        if ($showTrace) {
            echo <<<HTML
            <div class="error-detail">
                <span class="error-label">Stack Trace:</span>
                <div class="error-trace">$errorTrace</div>
            </div>
HTML;
        }

        if ($showEnvironment) {
            echo <<<HTML
            <div class="tab-container">
                <div class="tab-buttons">
                    <button class="tab-button active" onclick="openTab(event, 'environment-tab')">Environment</button>
                    <button class="tab-button" onclick="openTab(event, 'request-tab')">Request</button>
                    <button class="tab-button" onclick="openTab(event, 'server-tab')">Server</button>
                </div>
                <div id="environment-tab" class="tab-content active">
                    <pre>$environmentInfo</pre>
                </div>
                <div id="request-tab" class="tab-content">
                    <pre>{$GLOBALS['_REQUEST']}</pre>
                </div>
                <div id="server-tab" class="tab-content">
                    <pre>{$GLOBALS['_SERVER']}</pre>
                </div>
            </div>
            <script>
                function openTab(evt, tabName) {
                    var i, tabcontent, tabbuttons;
                    tabcontent = document.getElementsByClassName("tab-content");
                    for (i = 0; i < tabcontent.length; i++) {
                        tabcontent[i].classList.remove("active");
                    }
                    tabbuttons = document.getElementsByClassName("tab-button");
                    for (i = 0; i < tabbuttons.length; i++) {
                        tabbuttons[i].classList.remove("active");
                    }
                    document.getElementById(tabName).classList.add("active");
                    evt.currentTarget.classList.add("active");
                }
            </script>
HTML;
        }

        echo <<<HTML
        </div>
    </div>
</body>
</html>
HTML;
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
        <div class="error-icon">⚠️</div>
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