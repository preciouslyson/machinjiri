<?php

namespace Mlangeni\Machinjiri\Core\Exceptions\ErrorHandler;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Transport\Mail\{MailManager, MailMessage};
use Mlangeni\Machinjiri\Core\Http\{HttpRequest, HttpResponse};

class ErrorReporter
{
    private static bool $reportErrors = false;
    private static ?EventListener $eventListener = null;
    private static ?Logger $logger = null;
    private static ?MailManager $mailManager = null;

    public static function setReportErrors(bool $report): void
    {
        self::$reportErrors = $report;
    }

    public static function setEventListener(EventListener $listener): void
    {
        self::$eventListener = $listener;
    }

    public static function getEventListener(): EventListener
    {
        return self::$eventListener;
    }

    public static function setLogger(Logger $logger): void
    {
        self::$logger = $logger;
    }

    public static function setMailManager(MailManager $manager): void
    {
        self::$mailManager = $manager;
    }

    public static function reportException(\Throwable $exception, array $context = []): void
    {
        // Trigger event
        self::$eventListener?->trigger('exception.reported', [
            'exception' => $exception,
            'context'   => $context,
        ]);

        // Log
        ErrorLogger::logError($exception, $context);

        // Email notification if enabled
        if (self::$reportErrors) {
            self::sendErrorReport($exception, $context);
            self::notifyAboutError($exception, $context);
        }
    }

    private static function sendErrorReport(\Throwable $exception, array $context): void
    {
        $supportEmail = getenv('APP_SUPPORT_EMAIL') ?: getenv('SUPPORT_EMAIL');
        if (empty($supportEmail)) {
            self::$logger?->warning('Error reporting enabled but APP_SUPPORT_EMAIL is not set');
            return;
        }

        try {
            if (!self::$mailManager) {
                $container = Container::getInstance();
                if ($container && $container->bound(MailManager::class)) {
                    self::$mailManager = $container->resolve(MailManager::class);
                } else {
                    self::$logger?->warning('MailManager not available for error report');
                    return;
                }
            }

            $subject = sprintf(
                '[ERROR] %s: %s in %s on line %d',
                get_class($exception),
                substr($exception->getMessage(), 0, 100),
                basename($exception->getFile()),
                $exception->getLine()
            );

            $textBody = self::buildErrorReportText($exception, $context);
            $htmlBody = self::buildErrorReportHtml($exception, $context);

            $message = (new MailMessage())
                ->from($supportEmail, 'Error Handler')
                ->to($supportEmail)
                ->subject($subject)
                ->html($htmlBody, $textBody)
                ->priority(1);

            self::$mailManager->send($message);
            self::$logger?->info('Error report email sent to ' . $supportEmail);
        } catch (\Throwable $e) {
            self::$logger?->error("Failed to send error report email: " . $e->getMessage());
        }
    }

    private static function buildErrorReportText(\Throwable $exception, array $context): string
    {
        $request = self::request();
        
        $timestamp = date('Y-m-d H:i:s');
        $report = "============================================================\n";
        $report .= "                ERROR REPORT - {$timestamp}\n";
        $report .= "============================================================\n\n";
        $report .= "Exception Class: " . get_class($exception) . "\n";
        $report .= "Code: " . $exception->getCode() . "\n";
        $report .= "Message: " . $exception->getMessage() . "\n";
        $report .= "File: " . $exception->getFile() . "\n";
        $report .= "Line: " . $exception->getLine() . "\n\n";
        $report .= "--- Request Context ---\n";
        $report .= "URI: {$request->getMethod()} {$request->getUri()}\n";
        $report .= "IP: {$request->getServerParam("REMOTE_ADDR")}\n";
        $report .= "User Agent: {$request->getServerParam("HTTP_USER_AGENT")}\n";
        $report .= "Session ID: " . (session_id() ?: 'none') . "\n";
        $report .= "Memory: " . (memory_get_usage(true) / 1024 / 1024) . " MB (Peak: " . (memory_get_peak_usage(true) / 1024 / 1024) . " MB)\n\n";
        $report .= "--- Additional Context ---\n";
        $report .= empty($context) ? "(none)\n" : print_r($context, true) . "\n";
        $report .= "\n--- Stack Trace ---\n" . $exception->getTraceAsString() . "\n";
        $report .= "\n--- Selected Environment Variables ---\n";
        foreach (['APP_ENV', 'APP_DEBUG', 'APP_URL', 'APP_NAME'] as $key) {
            $report .= "{$key}: " . (getenv($key) ?: 'not set') . "\n";
        }
        return $report;
    }

    private static function buildErrorReportHtml(\Throwable $exception, array $context): string
    {
        $request = self::request();
        $timestamp = date('Y-m-d H:i:s');
        $class = htmlspecialchars(get_class($exception));
        $message = htmlspecialchars($exception->getMessage());
        $file = htmlspecialchars($exception->getFile());
        $line = $exception->getLine();
        $trace = htmlspecialchars($exception->getTraceAsString());
        $uri = htmlspecialchars($request->getUri());
        $method = htmlspecialchars($request->getMethod());
        $ip = htmlspecialchars($request->getServerParam("REMOTE_ADDR"));

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: monospace; background: #f7f7f7; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        h2 { color: #c0392b; }
        .details { background: #f0f0f0; padding: 10px; border-left: 4px solid #c0392b; margin: 15px 0; }
        .trace { background: #f9f9f9; padding: 10px; overflow-x: auto; font-size: 12px; }
        hr { margin: 20px 0; }
    </style>
</head>
<body>
<div class="container">
    <h2>Error Report</h2>
    <p><strong>Time:</strong> {$timestamp}</p>
    <div class="details">
        <strong>{$class}</strong><br>
        <strong>Message:</strong> {$message}<br>
        <strong>File:</strong> {$file}<br>
        <strong>Line:</strong> {$line}<br>
        <strong>Request:</strong> {$method} {$uri}<br>
        <strong>IP:</strong> {$ip}
    </div>
    <h3>Stack Trace</h3>
    <div class="trace"><pre>{$trace}</pre></div>
    <hr>
    <small>Generated by Machinjiri Error Handler</small>
</div>
</body>
</html>
HTML;
    }

    private static function notifyAboutError(\Throwable $exception, array $context): void
    {
        self::$eventListener?->trigger('error.notification', [
            'exception' => $exception,
            'level'     => ErrorLogger::getErrorLevel($exception),
            'timestamp' => time(),
        ]);
    }

    private static function request(): HttpRequest
    {
        return ErrorHandler::httpRequest();
    }
}