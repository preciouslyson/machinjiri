<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Logging;

use Mlangeni\Machinjiri\Core\FileSystem\Filesystem;
use Mlangeni\Machinjiri\Core\FileSystem\Adapters\LocalAdapter;
use Mlangeni\Machinjiri\Core\FileSystem\Adapters\FtpAdapter;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class LoggerFactory
{
    protected static array $config;
    protected static array $loggers = [];

    public function __construct(array $config)
    {
        self::$config = $config;
    }

    /**
     * Get the default logger instance.
     */
    public static function logger(?string $channel = null): Logger
    {
        $channel = $channel ?? self::$config['default'];
        if (!isset(self::$loggers[$channel])) {
            self::$loggers[$channel] = self::build($channel);
        }
        return self::$loggers[$channel];
    }

    protected static function build(string $channel): Logger
    {
        $cfg = self::$config['channels'][$channel] ?? [];
        if (empty($cfg)) {
            throw new \InvalidArgumentException("Logger channel [{$channel}] not defined.");
        }

        // 1. Resolve the Filesystem based on the disk
        $diskName = $cfg['disk'] ?? 'local';
        $diskConfig = self::$config['disks'][$diskName] ?? null;
        if (!$diskConfig) {
            throw new MachinjiriException("Disk [{$diskName}] not configured.");
        }

        $adapter = self::createAdapter($diskConfig);
        $filesystem = new Filesystem($adapter);

        $logger = new Logger(
            $cfg['log_file'] ?? 'log',
            $cfg['min_level'] ?? Logger::DEBUG,
            $cfg['is_event'] ?? false,
            $cfg['subdirectory'] ?? null,
            $cfg['referrer'] ?? 'app'
        );

        $logger->setFilesystem($filesystem);

        if ($cfg['include_backtrace'] ?? false) {
            $logger->setIncludeBacktrace(true);
        }

        if (!empty($cfg['default_context'])) {
            $logger->pushContext($cfg['default_context']);
        }

        return $logger;
    }

    protected static function createAdapter(array $diskConfig)
    {
        $driver = $diskConfig['driver'] ?? 'local';
        switch ($driver) {
            case 'local':
                return new LocalAdapter($diskConfig['root']);
            case 'ftp':
                return new FtpAdapter($diskConfig);
            default:
                throw new MachinjiriException("Unsupported disk driver: {$driver}");
        }
    }

    public static function system (string $log, string $process, bool $event = false): Logger
    {
        return new Logger($log, Logger::DEBUG, $event, $process, 'system');
    }
}