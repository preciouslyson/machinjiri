<?php

namespace Mlangeni\Machinjiri\Core\Database\Caching;

use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class PrefetchManager
{
    protected CacheManager $cache;
    protected array $warmers = [];

    public function __construct(CacheManager $cache)
    {
        $this->cache = $cache;
    }

    public function registerWarmer(string $name, callable $callback): void
    {
        $this->warmers[$name] = $callback;
    }

    public function warm(string $name): void
    {
        if (!isset($this->warmers[$name])) {
            throw new MachinjiriException("Warmer '$name' not registered");
        }
        $callback = $this->warmers[$name];
        $callback($this->cache);
    }

    public function warmAll(): void
    {
        foreach (array_keys($this->warmers) as $name) {
            $this->warm($name);
        }
    }
    
    /**
     * Warm cache by analysing a query log file.
     *
     * @param string $logFile Path to log file (one SQL per line)
     * @param int $limit Maximum number of queries to process
     * @param callable|null $queryExecutor Function to execute a raw SQL and return results
     */
    public function warmFromLog(string $logFile, int $limit = 1000, ?callable $queryExecutor = null): void
    {
        if (!file_exists($logFile)) {
            throw new MachinjiriException("Log file not found: {$logFile}");
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_slice($lines, 0, $limit);

        $normalizedQueries = [];
        foreach ($lines as $line) {
            $sql = $this->extractSqlFromLogLine($line);
            if ($sql) {
                $normalized = $this->normalizeQuery($sql);
                $normalizedQueries[$normalized] = true;
            }
        }

        if (!$queryExecutor) {
            $queryExecutor = function ($sql) {
                // Fallback: assume a global DB connection
                return \Mlangeni\Machinjiri\Core\Database\DB::select($sql);
            };
        }

        foreach (array_keys($normalizedQueries) as $normalizedSql) {
            $key = 'dbq:' . md5($normalizedSql);
            if (!$this->cache->has($key)) {
                $result = $queryExecutor($normalizedSql);
                $this->cache->set($key, $result, 3600);
            }
        }
    }
    
    /**
     * Extract SQL statement from a log line (e.g., "2025-01-01 12:00:00 SELECT * FROM users").
     */
    protected function extractSqlFromLogLine(string $line): ?string
    {
        // Simple heuristic: find first occurrence of SELECT/INSERT/UPDATE/DELETE
        if (preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b.+/i', $line, $matches)) {
            return trim($matches[0]);
        }
        return null;
    }

    /**
     * Normalize a query by replacing literals with placeholders.
     * e.g., "SELECT * FROM users WHERE id = 123" -> "SELECT * FROM users WHERE id = ?"
     */
    protected function normalizeQuery(string $sql): string
    {
        // Replace string literals
        $sql = preg_replace('/\'([^\'\\\\]*(?:\\\\.[^\'\\\\]*)*)\'/', '?', $sql);
        // Replace numeric literals
        $sql = preg_replace('/\b\d+\b/', '?', $sql);
        // Normalize whitespace
        $sql = preg_replace('/\s+/', ' ', $sql);
        return trim($sql);
    }

    public function warmFromArray(array $queries, ?callable $queryExecutor = null): void
    {
        if (!$queryExecutor) {
            $queryExecutor = function ($sql) {
                return \Mlangeni\Machinjiri\Core\Database\DB::select($sql);
            };
        }

        foreach ($queries as $sql) {
            $normalized = $this->normalizeQuery($sql);
            $key = 'dbq:' . md5($normalized);
            if (!$this->cache->has($key)) {
                $result = $queryExecutor($sql);
                $this->cache->set($key, $result, 3600);
            }
        }
    }
}