<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Logging;
use Mlangeni\Machinjiri\Core\Container;
class Logger
{
    const EMERGENCY = 'emergency';
    const ALERT     = 'alert';
    const CRITICAL  = 'critical';
    const ERROR     = 'error';
    const WARNING   = 'warning';
    const NOTICE    = 'notice';
    const INFO      = 'info';
    const DEBUG     = 'debug';

    protected $logFile;
    protected $minLevel;
    protected $path;

    public function __construct(?string $logFile = null, string $minLevel = self::DEBUG)
    {
        $this->path = self::resolvePath();
        
        $this->logFile = ($logFile === null) ? $this->path . 'logger.log' : $this->path . $logFile . '.log';
        $this->minLevel = $minLevel;
    }
    
    private static function resolvePath () : string {
      $appBase = Container::$appBasePath . '/../storage/logs/';
      $artisanTerminal = Container::$terminalBase . 'storage/logs/';
      $path = is_dir($appBase) ? $appBase : $artisanTerminal;
      return !is_dir($path) ? Container::getSystemTempDir() : $path;
    }

    public function emergency($message, array $context = [])
    {
        $this->log(self::EMERGENCY, $message, $context);
    }

    public function alert($message, array $context = [])
    {
        $this->log(self::ALERT, $message, $context);
    }

    public function critical($message, array $context = [])
    {
        $this->log(self::CRITICAL, $message, $context);
    }

    public function error($message, array $context = [])
    {
        $this->log(self::ERROR, $message, $context);
    }

    public function warning($message, array $context = [])
    {
        $this->log(self::WARNING, $message, $context);
    }

    public function notice($message, array $context = [])
    {
        $this->log(self::NOTICE, $message, $context);
    }

    public function info($message, array $context = [])
    {
        $this->log(self::INFO, $message, $context);
    }

    public function debug($message, array $context = [])
    {
        $this->log(self::DEBUG, $message, $context);
    }

    public function log($level, $message, array $context = [])
    {
        if ($this->shouldLog($level)) {
            $logEntry = $this->formatLogEntry($level, $message, $context);
            $this->writeToLog($logEntry);
        }
    }

    protected function shouldLog(string $level): bool
    {
        $levels = [
            self::DEBUG,
            self::INFO,
            self::NOTICE,
            self::WARNING,
            self::ERROR,
            self::CRITICAL,
            self::ALERT,
            self::EMERGENCY
        ];

        $currentLevelIndex = array_search($level, $levels);
        $minLevelIndex = array_search($this->minLevel, $levels);

        return $currentLevelIndex >= $minLevelIndex;
    }

    protected function formatLogEntry(string $level, $message, array $context = []): string
    {
        $timestamp = date('[Y-m-d H:i:s]');
        $level = strtoupper($level);
        
        $message = $this->interpolate($message, $context);
        $message = $this->stringify($message);
        
        return "{$timestamp} [{$level}] {$message}" . PHP_EOL;
    }

    protected function interpolate($message, array $context = []): string
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

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function writeToLog(string $logEntry)
    {
        file_put_contents(
            $this->logFile, 
            $logEntry, 
            FILE_APPEND
        );
    }
}
