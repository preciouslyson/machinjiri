<?php

namespace Mlangeni\Machinjiri\Core\Debug;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;

/**
 * Debugger
 *
 * Comprehensive debugging utility for the Machinjiri framework.
 * Provides:
 * - Environment-aware enable/disable
 * - Logging via framework Logger
 * - Execution timing
 * - Memory usage tracking
 * - Variable dumping (with Dumper integration)
 * - Backtrace generation
 * - Event triggering for debugging hooks
 * - Query logging (placeholder for future database integration)
 */
class Debugger
{
    /**
     * Whether debugging is enabled globally.
     *
     * @var bool
     */
    protected bool $enabled = true;

    /**
     * Logger instance.
     *
     * @var Logger|null
     */
    protected ?Logger $logger = null;

    /**
     * Event listener instance.
     *
     * @var EventListener|null
     */
    protected ?EventListener $events = null;

    /**
     * Container instance.
     *
     * @var Container|null
     */
    protected ?Container $app = null;

    /**
     * Timers storage.
     *
     * @var array<string, float>
     */
    protected array $timers = [];

    /**
     * Memory snapshots.
     *
     * @var array<string, int>
     */
    protected array $memorySnapshots = [];

    /**
     * Collected query log (placeholder).
     *
     * @var array
     */
    protected array $queryLog = [];

    /**
     * Whether to log queries.
     *
     * @var bool
     */
    protected bool $logQueries = false;

    /**
     * Singleton instance.
     *
     * @var self|null
     */
    protected static ?self $instance = null;

    /**
     * Constructor.
     *
     * @param Container|null $app
     */
    public function __construct(?Container $app = null)
    {
        $this->app = $app ?? (Container::instancePresent() ? Container::getInstance() : null);

        // Auto-enable based on environment if app available
        if ($this->app && method_exists($this->app, 'isDevelopment')) {
            $this->enabled = $this->app->isDevelopment();
        }

        // Try to resolve logger and events from container if available
        if ($this->app) {
            try {
                if ($this->app->bound(Logger::class) || $this->app->bound('logger')) {
                    $this->logger = $this->app->make(Logger::class);
                }
                if ($this->app->bound(EventListener::class) || $this->app->bound('events')) {
                    $this->events = $this->app->make(EventListener::class);
                }
            } catch (\Throwable $e) {
                // Silently fail; we'll create fallbacks
            }
        }

        // Fallback logger if none provided
        if (!$this->logger) {
            $this->logger = new Logger('debug');
        }

        // Store singleton
        if (self::$instance === null) {
            self::$instance = $this;
        }
    }

    /**
     * Get singleton instance.
     *
     * @return self
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Enable debugging.
     *
     * @return self
     */
    public function enable(): self
    {
        $this->enabled = true;
        $this->log('Debugger enabled', 'info');
        return $this;
    }

    /**
     * Disable debugging.
     *
     * @return self
     */
    public function disable(): self
    {
        $this->log('Debugger disabled', 'info');
        $this->enabled = false;
        return $this;
    }

    /**
     * Check if debugging is enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Set the logger instance.
     *
     * @param Logger $logger
     * @return self
     */
    public function setLogger(Logger $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * Set the event listener.
     *
     * @param EventListener $events
     * @return self
     */
    public function setEventListener(EventListener $events): self
    {
        $this->events = $events;
        return $this;
    }

    /**
     * Log a debug message.
     *
     * @param mixed $message
     * @param string $level
     * @param array $context
     * @return self
     */
    public function log($message, string $level = 'debug', array $context = []): self
    {
        if (!$this->enabled) {
            return $this;
        }

        // Convert non-string messages
        if (!is_string($message)) {
            $message = $this->stringify($message);
        }

        // Add caller information to context
        $context = array_merge($this->getCallerInfo(), $context);

        // Log via logger
        if ($this->logger) {
            $this->logger->{$level}($message, $context);
        }

        // Trigger debug event
        $this->triggerEvent('debug.log', [
            'message' => $message,
            'level' => $level,
            'context' => $context,
        ]);

        return $this;
    }

    /**
     * Dump one or more variables (if enabled) and continue.
     *
     * @param mixed ...$args
     * @return self
     */
    public function dump(...$args): self
    {
        if (!$this->enabled) {
            return $this;
        }

        Dumper::dump(...$args);

        $this->triggerEvent('debug.dump', ['args' => $args]);

        return $this;
    }

    /**
     * Dump variables and die (if enabled).
     *
     * @param mixed ...$args
     * @return never
     */
    public function dd(...$args): never
    {
        if (!$this->enabled) {
            // In production, just die with minimal output
            die('Application error.');
        }

        Dumper::dd(...$args);
    }

    /**
     * Start a timer with a given name.
     *
     * @param string $name
     * @return self
     */
    public function startTimer(string $name = 'default'): self
    {
        if (!$this->enabled) {
            return $this;
        }

        $this->timers[$name] = microtime(true);
        $this->log("Timer '{$name}' started", 'debug', ['timer' => $name]);

        $this->triggerEvent('debug.timer.start', ['name' => $name]);

        return $this;
    }

    /**
     * Stop a timer and return elapsed time in seconds.
     *
     * @param string $name
     * @param bool $log Whether to log the elapsed time
     * @return float|null
     */
    public function stopTimer(string $name = 'default', bool $log = true): ?float
    {
        if (!$this->enabled || !isset($this->timers[$name])) {
            return null;
        }

        $elapsed = microtime(true) - $this->timers[$name];
        unset($this->timers[$name]);

        if ($log) {
            $this->log(
                "Timer '{$name}' stopped: {$elapsed} seconds",
                'debug',
                ['timer' => $name, 'elapsed' => $elapsed]
            );
        }

        $this->triggerEvent('debug.timer.stop', [
            'name' => $name,
            'elapsed' => $elapsed,
        ]);

        return $elapsed;
    }

    /**
     * Measure execution time of a callable.
     *
     * @param callable $callback
     * @param string $label
     * @param bool $log
     * @return mixed The result of the callback
     */
    public function measure(callable $callback, string $label = 'anonymous', bool $log = true)
    {
        if (!$this->enabled) {
            return $callback();
        }

        $this->startTimer($label);
        $result = $callback();
        $this->stopTimer($label, $log);

        return $result;
    }

    /**
     * Take a memory usage snapshot.
     *
     * @param string $label
     * @return self
     */
    public function memorySnapshot(string $label = 'default'): self
    {
        if (!$this->enabled) {
            return $this;
        }

        $usage = memory_get_usage(true);
        $this->memorySnapshots[$label] = $usage;

        $this->log(
            "Memory snapshot '{$label}': " . $this->formatBytes($usage),
            'debug',
            ['label' => $label, 'bytes' => $usage]
        );

        $this->triggerEvent('debug.memory.snapshot', [
            'label' => $label,
            'bytes' => $usage,
        ]);

        return $this;
    }

    /**
     * Get memory usage difference between two snapshots.
     *
     * @param string $startLabel
     * @param string $endLabel
     * @return int|null Difference in bytes
     */
    public function memoryDiff(string $startLabel, string $endLabel): ?int
    {
        if (!isset($this->memorySnapshots[$startLabel], $this->memorySnapshots[$endLabel])) {
            return null;
        }

        return $this->memorySnapshots[$endLabel] - $this->memorySnapshots[$startLabel];
    }

    /**
     * Log current memory usage.
     *
     * @param string $label
     * @return self
     */
    public function logMemory(string $label = 'Current memory'): self
    {
        if (!$this->enabled) {
            return $this;
        }

        $usage = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);

        $this->log(
            "{$label}: " . $this->formatBytes($usage) . " (Peak: " . $this->formatBytes($peak) . ")",
            'debug'
        );

        return $this;
    }

    /**
     * Generate and return a backtrace.
     *
     * @param int $limit
     * @param int $options
     * @return array
     */
    public function backtrace(int $limit = 0, int $options = DEBUG_BACKTRACE_PROVIDE_OBJECT): array
    {
        $trace = debug_backtrace($options, $limit);
        // Remove the call to this method itself
        array_shift($trace);

        if ($this->enabled) {
            $this->log('Backtrace generated', 'debug', ['trace' => $trace]);
            $this->triggerEvent('debug.backtrace', ['trace' => $trace]);
        }

        return $trace;
    }

    /**
     * Print a formatted backtrace (if enabled).
     *
     * @param int $limit
     * @return self
     */
    public function printBacktrace(int $limit = 0): self
    {
        if (!$this->enabled) {
            return $this;
        }

        $trace = $this->backtrace($limit + 1);
        $this->dump($trace);

        return $this;
    }

    /**
     * Log a database query (placeholder for future integration).
     *
     * @param string $query
     * @param array $bindings
     * @param float|null $time
     * @return self
     */
    public function logQuery(string $query, array $bindings = [], ?float $time = null): self
    {
        if (!$this->enabled || !$this->logQueries) {
            return $this;
        }

        $entry = [
            'query' => $query,
            'bindings' => $bindings,
            'time' => $time,
            'timestamp' => microtime(true),
        ];

        $this->queryLog[] = $entry;

        $this->log("Query executed: {$query}", 'debug', [
            'bindings' => $bindings,
            'time_ms' => $time ? round($time * 1000, 2) : null,
        ]);

        $this->triggerEvent('debug.query', $entry);

        return $this;
    }

    /**
     * Enable query logging.
     *
     * @return self
     */
    public function enableQueryLog(): self
    {
        $this->logQueries = true;
        return $this;
    }

    /**
     * Disable query logging.
     *
     * @return self
     */
    public function disableQueryLog(): self
    {
        $this->logQueries = false;
        return $this;
    }

    /**
     * Get the query log.
     *
     * @return array
     */
    public function getQueryLog(): array
    {
        return $this->queryLog;
    }

    /**
     * Clear the query log.
     *
     * @return self
     */
    public function clearQueryLog(): self
    {
        $this->queryLog = [];
        return $this;
    }

    /**
     * Register a debug event listener.
     *
     * @param string $event
     * @param callable $listener
     * @param int $priority
     * @return self
     */
    public function on(string $event, callable $listener, int $priority = 0): self
    {
        if ($this->events) {
            $this->events->on($event, $listener, $priority);
        }
        return $this;
    }

    /**
     * Trigger a debug event.
     *
     * @param string $event
     * @param mixed $payload
     * @return void
     */
    protected function triggerEvent(string $event, $payload = null): void
    {
        if ($this->events && $this->enabled) {
            $this->events->trigger($event, $payload);
        }
    }

    /**
     * Get caller information for logging context.
     *
     * @return array
     */
    protected function getCallerInfo(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
        // Skip internal calls: this method, log(), and the public method that called log()
        $caller = $trace[3] ?? $trace[2] ?? $trace[1] ?? null;

        if (!$caller) {
            return [];
        }

        return [
            'file' => $caller['file'] ?? 'unknown',
            'line' => $caller['line'] ?? 0,
            'function' => ($caller['class'] ?? '') . ($caller['type'] ?? '') . ($caller['function'] ?? ''),
        ];
    }

    /**
     * Convert a value to a string representation.
     *
     * @param mixed $value
     * @return string
     */
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

        if (is_array($value)) {
            return '[array]';
        }

        if (is_resource($value)) {
            return '[resource ' . get_resource_type($value) . ']';
        }

        return '[unknown]';
    }

    /**
     * Format bytes to human-readable string.
     *
     * @param int $bytes
     * @param int $precision
     * @return string
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Magic method to forward calls to logger for convenience.
     *
     * @param string $method
     * @param array $args
     * @return mixed
     */
    public function __call(string $method, array $args)
    {
        // Forward emergency, alert, critical, error, warning, notice, info, debug to log()
        $levels = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];
        if (in_array($method, $levels)) {
            return $this->log($args[0] ?? '', $method, $args[1] ?? []);
        }

        throw new \BadMethodCallException("Method {$method} not found in " . __CLASS__);
    }
}