<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Logging;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\FileSystem\Filesystem;
use Mlangeni\Machinjiri\Core\FileSystem\Adapters\LocalAdapter;

class Logger
{
    public const EMERGENCY = 'emergency';
    public const ALERT     = 'alert';
    public const CRITICAL  = 'critical';
    public const ERROR     = 'error';
    public const WARNING   = 'warning';
    public const NOTICE    = 'notice';
    public const INFO      = 'info';
    public const DEBUG     = 'debug';

    protected Filesystem $filesystem;
    protected string $logFile;          // absolute path
    protected string $minLevel;
    protected string $path;
    protected array $defaultContext = [];
    protected bool $includeBacktrace = false;

    public function __construct(
        ?string $logFile = null,
        string $minLevel = self::DEBUG,
        ?bool $isEvent = null,
        ?string $subdirectory = null,
        ?string $referrer = null
    ) {
        
        $this->path = self::resolveLogPath($referrer, $isEvent, $subdirectory);

        $this->logFile = self::createLogFile($this->path, $logFile);

        $this->minLevel = $_ENV['LOG_LEVEL'] ?? $minLevel;

        $root = self::getLogsRoot();
        $adapter = new LocalAdapter($root);
        $this->filesystem = new Filesystem($adapter);

        // 5. Optionally include backtrace for DEBUG level
        $this->includeBacktrace = ($this->minLevel === self::DEBUG);
    }

    public function emergency($message, array $context = []) { $this->log(self::EMERGENCY, $message, $context); }
    public function alert($message, array $context = [])     { $this->log(self::ALERT, $message, $context); }
    public function critical($message, array $context = [])  { $this->log(self::CRITICAL, $message, $context); }
    public function error($message, array $context = [])     { $this->log(self::ERROR, $message, $context); }
    public function warning($message, array $context = [])   { $this->log(self::WARNING, $message, $context); }
    public function notice($message, array $context = [])    { $this->log(self::NOTICE, $message, $context); }
    public function info($message, array $context = [])      { $this->log(self::INFO, $message, $context); }
    public function debug($message, array $context = [])     { $this->log(self::DEBUG, $message, $context); }

    /**
     * Logs with an arbitrary level.
     */
    public function log($level, $message, array $context = [])
    {
        if ($this->shouldLog($level)) {
            $entry = $this->formatLogEntry($level, $message, $context);
            $this->writeToLog($entry);
        }
    }

    public function pushContext(array $extra): void
    {
        $this->defaultContext = array_merge($this->defaultContext, $extra);
    }

    public function resetContext(): void
    {
        $this->defaultContext = [];
    }

    public function setFilesystem(Filesystem $filesystem): void
    {
        $this->filesystem = $filesystem;
    }

    protected function createLogFile(string $path, ?string $logFile = null): string
    {
        return str_replace(['-', ' '], '_', $path . ($logFile ?? 'log') . '_' . date('Y-m-d') . '.log');
    }

    protected function resolveLogPath(?string $referrer = null, ?bool $isEvent = false, ?string $subdirectory = null): string
    {
        $path = self::getLogsRoot();
        $referrers = ['system', 'exception', getenv('APP_NAME') ?? 'app'];
        $type = $isEvent ? 'events' : 'reports';

        if ($referrer === null || !in_array($referrer, $referrers, true)) {
            $referrer = 2;
        }
        $structure[] = ($referrer !== 2) ? $referrers[array_search($referrer, $referrers, true)] : $referrers[$referrer];

        if ($subdirectory !== null && !empty($subdirectory)) {
            $subdirectories = explode(
                DIRECTORY_SEPARATOR,
                rtrim(str_replace('\\', DIRECTORY_SEPARATOR, trim($subdirectory)), DIRECTORY_SEPARATOR)
            );
            foreach ($subdirectories as $dir) {
                if (!empty($dir)) $structure[] = $dir;
            }
        }
        
        $structure[] = $type;

        return $path . implode(DIRECTORY_SEPARATOR, $structure) . DIRECTORY_SEPARATOR;
    }

    protected static function getLogsRoot(): string
    {
        $appBase = Container::$appBasePath . '/../storage/logs/';
        $artisanTerminal = Container::$terminalBase . 'storage/logs/';
        $path = is_dir($appBase) ? $appBase : $artisanTerminal;
        return is_dir($path) ? $path : Container::getSystemTempDir();
    }

    protected function shouldLog(string $level): bool
    {
        $levels = [
            self::DEBUG, self::INFO, self::NOTICE, self::WARNING,
            self::ERROR, self::CRITICAL, self::ALERT, self::EMERGENCY
        ];
        return array_search($level, $levels) >= array_search($this->minLevel, $levels);
    }

    protected function formatLogEntry(string $level, $message, array $context = []): string
    {
        $context = array_merge($this->defaultContext, $context);

        if ($this->includeBacktrace && $level === self::DEBUG) {
            $context['backtrace'] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
        }

        $entry = [
            'timestamp' => (new \DateTime())->format('Y-m-d\TH:i:s.uP'),
            'level'     => strtoupper($level),
            'message'   => $this->stringify($this->interpolate($message, $context)),
            'context'   => $this->sanitizeContext($context),
        ];

        $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            // Fallback: replace the context with an error message
            $entry['context']['_json_error'] = json_last_error_msg();
            $json = json_encode($this->sanitizeContext($entry), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return $json . PHP_EOL;
    }

    protected function sanitizeContext(array $context): array
    {
        $result = [];
        foreach ($context as $key => $value) {
            $result[$key] = $this->sanitizeValue($value);
        }
        return $result;
    }

    protected function sanitizeValue($value)
    {
        if (is_array($value)) {
            return $this->sanitizeContext($value);
        }
        return $this->stringify($value);
    }

    protected function interpolate($message, array $context = [])
    {
        if (!is_string($message)) {
            return $message;
        }
        $replace = [];
        foreach ($context as $key => $val) {
            $replace['{' . $key . '}'] = $this->stringify($val);
        }
        return strtr($message, $replace);
    }

    protected function stringify($value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string)$value;
        }
        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string)$value;
            }
            return '[object ' . get_class($value) . ']';
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'JSON encoding error';
    }

    protected function writeToLog(string $logEntry): void
    {
        // Ensure the directory exists using the Filesystem (LocalAdapter)
        $directory = dirname($this->logFile);
        $this->ensureDirectoryExists($directory);

        // Write with exclusive lock (atomic append)
        $fp = fopen($this->logFile, 'ab');
        if ($fp === false) {
            // Fallback: error_log
            error_log('Logger: cannot open log file: ' . $this->logFile);
            return;
        }
        if (flock($fp, LOCK_EX)) {
            fwrite($fp, $logEntry);
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    /**
     * Ensures a directory exists, creating it recursively if needed.
     * Uses the Filesystem's LocalAdapter to leverage its directory creation logic.
     */
    protected function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new MachinjiriException("Unable to create log directory: {$directory}");
            }
        }
    }

    public function getFilesystem(): Filesystem
    {
        return $this->filesystem;
    }

    public function setIncludeBacktrace(bool $enable): void
    {
        $this->includeBacktrace = $enable;
    }
    
}