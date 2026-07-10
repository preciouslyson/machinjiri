<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Logging;

use Mlangeni\Machinjiri\Core\FileSystem\Filesystem;
use Mlangeni\Machinjiri\Core\FileSystem\Adapters\LocalAdapter;
use Mlangeni\Machinjiri\Core\FileSystem\Adapters\FtpAdapter;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;;

class LoggerFactory
{
    protected array $config;
    protected array $loggers = [];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Get the default logger instance.
     */
    public function logger(?string $channel = null): Logger
    {
        $channel = $channel ?? $this->config['default'];
        if (!isset($this->loggers[$channel])) {
            $this->loggers[$channel] = $this->build($channel);
        }
        return $this->loggers[$channel];
    }

    protected function build(string $channel): Logger
    {
        $cfg = $this->config['channels'][$channel] ?? [];
        if (empty($cfg)) {
            throw new \InvalidArgumentException("Logger channel [{$channel}] not defined.");
        }

        // 1. Resolve the Filesystem based on the disk
        $diskName = $cfg['disk'] ?? 'local';
        $diskConfig = $this->config['disks'][$diskName] ?? null;
        if (!$diskConfig) {
            throw new MachinjiriException("Disk [{$diskName}] not configured.");
        }

        $adapter = $this->createAdapter($diskConfig);
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

    protected function createAdapter(array $diskConfig)
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
}