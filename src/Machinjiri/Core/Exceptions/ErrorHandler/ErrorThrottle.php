<?php

namespace Mlangeni\Machinjiri\Core\Exceptions\ErrorHandler;

use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;

class ErrorThrottle
{
    private static array $errorCounters = [];
    private static array $config = [
        'max'   => 10,
        'decay' => 60, // seconds
    ];
    private static ?Logger $logger = null;

    public static function setConfig(array $config): void
    {
        self::$config = array_merge(self::$config, $config);
    }

    public static function setLogger(Logger $logger): void
    {
        self::$logger = $logger;
    }

    public static function shouldReportError(string $errorKey): bool
    {
        $now = time();

        if (!isset(self::$errorCounters[$errorKey])) {
            self::$errorCounters[$errorKey] = ['count' => 1, 'last_time' => $now];
            return true;
        }

        $counter = &self::$errorCounters[$errorKey];

        // Reset if decay period passed
        if ($now - $counter['last_time'] > self::$config['decay']) {
            $counter = ['count' => 1, 'last_time' => $now];
            return true;
        }

        $counter['count']++;
        $counter['last_time'] = $now;

        if ($counter['count'] > self::$config['max']) {
            if ($counter['count'] === self::$config['max'] + 1 && self::$logger) {
                self::$logger->warning("Error throttled: {$errorKey}", [
                    'max_errors' => self::$config['max'],
                    'decay'      => self::$config['decay'],
                ]);
            }
            return false;
        }

        return true;
    }

    public static function getErrorStats(): array
    {
        return [
            'total_errors'     => array_sum(array_column(self::$errorCounters, 'count')),
            'throttled_errors' => count(array_filter(self::$errorCounters,
                fn($c) => $c['count'] > self::$config['max']
            )),
            'unique_errors'    => count(self::$errorCounters),
            'throttle_config'  => self::$config,
        ];
    }

    public static function resetErrorCounters(): void
    {
        self::$errorCounters = [];
        self::$logger && self::$logger->info('Error counters reset');
    }
}