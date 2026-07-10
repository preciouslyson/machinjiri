<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Logging;

use Mlangeni\Machinjiri\Core\FileSystem\Filesystem;
use DateTime;
use InvalidArgumentException;

class LogViewer
{
    protected Filesystem $filesystem;
    protected string $basePath; 

    public function __construct(Filesystem $filesystem, string $basePath = '')
    {
        $this->filesystem = $filesystem;
        $this->basePath = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR;
    }

    /**
     * Get a list of all .log files recursively with metadata.
     *
     * @param string $subpath Optional subdirectory to scan (relative to basePath)
     * @param bool $recursive Whether to scan recursively
     * @return array List of file metadata (path, basename, size, lastModified, type)
     */
    public function getLogFiles(string $subpath = '', bool $recursive = true): array
    {
        $items = $this->filesystem->listContents($subpath, $recursive, false);
        $logs = [];
        foreach ($items as $item) {
            if (preg_match('/\.log$/', $item['basename'])) {
                $logs[] = $item;
            }
        }
        return $logs;
    }

    /**
     * Read log entries from a specific file, applying filters.
     *
     * @param string $filePath Relative path to the log file (from basePath)
     * @param array $filters Available filters:
     *   - level: string (exact match, case-insensitive)
     *   - levels: array (list of levels)
     *   - date_from: string (ISO 8601 or any format accepted by DateTime)
     *   - date_to: string
     *   - search: string (substring match in message + context)
     *   - context_key: string (must exist in context)
     *   - context_value: mixed (must match)
     *   - limit: int (max entries to return, default 1000)
     * @return array Decoded log entries
     * @throws InvalidArgumentException if file not found
     */
    public function readLogs(string $filePath, array $filters = []): array
    {
        $this->assertFileExists($filePath);
        $stream = $this->filesystem->readStream($this->resolvePath($filePath));
        $logs = [];
        $count = 0;
        $limit = $filters['limit'] ?? 1000;

        while (!feof($stream) && $count < $limit) {
            $line = fgets($stream);
            if ($line === false) break;
            $line = trim($line);
            if (empty($line)) continue;

            $entry = json_decode($line, true);
            if ($entry === null) continue;

            if ($this->passesFilters($entry, $filters)) {
                $logs[] = $entry;
                $count++;
            }
        }
        fclose($stream);
        return $logs;
    }

    /**
     * Stream log entries one by one (memory efficient).
     *
     * @param string $filePath
     * @param array $filters Same as readLogs
     * @return \Generator yields decoded log entries
     */
    public function streamLogs(string $filePath, array $filters = []): \Generator
    {
        $this->assertFileExists($filePath);
        $stream = $this->filesystem->readStream($this->resolvePath($filePath));
        $limit = $filters['limit'] ?? PHP_INT_MAX;
        $count = 0;

        while (!feof($stream) && $count < $limit) {
            $line = fgets($stream);
            if ($line === false) break;
            $line = trim($line);
            if (empty($line)) continue;

            $entry = json_decode($line, true);
            if ($entry === null) continue;

            if ($this->passesFilters($entry, $filters)) {
                yield $entry;
                $count++;
            }
        }
        fclose($stream);
    }

    /**
     * Get the last N lines from a log file (efficient tail).
     *
     * @param string $filePath
     * @param int $lines Number of lines to retrieve
     * @return array Decoded log entries (chronological order)
     */
    public function tail(string $filePath, int $lines = 50): array
    {
        $this->assertFileExists($filePath);
        $fullPath = $this->resolvePath($filePath);
        $stream = $this->filesystem->readStream($fullPath);

        // Try to seek to end (may not work on FTP)
        if (fseek($stream, 0, SEEK_END) === -1) {
            fclose($stream);
            return $this->tailFallback($filePath, $lines);
        }

        $buffer = '';
        $lineCount = 0;
        $blockSize = 8192;
        $pos = ftell($stream);
        $linesArray = [];

        while ($lineCount < $lines && $pos > 0) {
            $readSize = min($blockSize, $pos);
            $pos -= $readSize;
            fseek($stream, $pos);
            $chunk = fread($stream, $readSize);
            $buffer = $chunk . $buffer;
            while (($newlinePos = strrpos($buffer, "\n")) !== false) {
                $line = substr($buffer, $newlinePos + 1);
                if (trim($line) !== '') {
                    $entry = json_decode(trim($line), true);
                    if ($entry !== null) {
                        $linesArray[] = $entry;
                        $lineCount++;
                        if ($lineCount >= $lines) break;
                    }
                }
                $buffer = substr($buffer, 0, $newlinePos);
            }
        }
        fclose($stream);
        return array_reverse($linesArray);
    }

    /**
     * Fallback for streams that don't support seeking.
     */
    protected function tailFallback(string $filePath, int $lines): array
    {
        $content = $this->filesystem->read($this->resolvePath($filePath));
        $linesArray = explode("\n", $content);
        $linesArray = array_filter($linesArray, fn($line) => trim($line) !== '');
        $lastLines = array_slice($linesArray, -$lines);
        $entries = [];
        foreach ($lastLines as $line) {
            $entry = json_decode(trim($line), true);
            if ($entry !== null) $entries[] = $entry;
        }
        return $entries;
    }

    
    /**
     * Get statistics about a log file.
     *
     * @param string $filePath
     * @param string|null $dateFrom (optional)
     * @param string|null $dateTo (optional)
     * @return array ['total' => int, 'levels' => ['level' => count], 'first_timestamp' => string, 'last_timestamp' => string]
     */
    public function stats(string $filePath, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $filters = [];
        if ($dateFrom) $filters['date_from'] = $dateFrom;
        if ($dateTo) $filters['date_to'] = $dateTo;
        $filters['limit'] = PHP_INT_MAX;

        $stats = [
            'total' => 0,
            'levels' => [],
            'first_timestamp' => null,
            'last_timestamp' => null,
        ];

        foreach ($this->streamLogs($filePath, $filters) as $entry) {
            $stats['total']++;
            $level = $entry['level'] ?? 'unknown';
            $stats['levels'][$level] = ($stats['levels'][$level] ?? 0) + 1;

            if ($stats['first_timestamp'] === null || $entry['timestamp'] < $stats['first_timestamp']) {
                $stats['first_timestamp'] = $entry['timestamp'];
            }
            if ($stats['last_timestamp'] === null || $entry['timestamp'] > $stats['last_timestamp']) {
                $stats['last_timestamp'] = $entry['timestamp'];
            }
        }
        return $stats;
    }

    /**
     * Delete a single log file.
     *
     * @param string $filePath Relative path to the file
     * @param bool $force If false, ask for confirmation (always returns true if confirmed)
     * @return bool True on success
     * @throws InvalidArgumentException if file not found
     */
    public function deleteFile(string $filePath, bool $force = false): bool
    {
        $this->assertFileExists($filePath);
        $fullPath = $this->resolvePath($filePath);

        if (!$force) {
            $confirm = $this->confirm("Delete log file '{$filePath}'? (y/N) ");
            if (strtolower($confirm) !== 'y') {
                return false;
            }
        }

        return $this->filesystem->delete($fullPath);
    }

    /**
     * Delete multiple log files based on a pattern or date range.
     *
     * @param string $subpath Directory to search (relative to basePath)
     * @param array $criteria:
     *   - pattern: regex to match filenames (e.g., '/^app_.*\.log$/')
     *   - older_than: int (days) – delete files older than this many days
     *   - newer_than: int (days) – delete files newer than this many days
     *   - recursive: bool (default true)
     * @param bool $force If false, list files to be deleted and ask for confirmation
     * @return array List of deleted file paths
     */
    public function deleteFiles(string $subpath = '', array $criteria = [], bool $force = false): array
    {
        $files = $this->getLogFiles($subpath, $criteria['recursive'] ?? true);
        $toDelete = [];

        foreach ($files as $file) {
            $path = $file['path'];
            // Apply pattern filter
            if (isset($criteria['pattern']) && !preg_match($criteria['pattern'], $path)) {
                continue;
            }
            // Apply age filters
            if (isset($criteria['older_than'])) {
                $mtime = $file['lastModified'] ?? 0;
                $ageDays = (time() - $mtime) / 86400;
                if ($ageDays <= $criteria['older_than']) {
                    continue;
                }
            }
            if (isset($criteria['newer_than'])) {
                $mtime = $file['lastModified'] ?? 0;
                $ageDays = (time() - $mtime) / 86400;
                if ($ageDays >= $criteria['newer_than']) {
                    continue;
                }
            }
            $toDelete[] = $path;
        }

        if (empty($toDelete)) {
            return [];
        }

        if (!$force) {
            echo "The following files will be deleted:\n";
            foreach ($toDelete as $path) {
                echo "  - {$path}\n";
            }
            $confirm = $this->confirm("Proceed with deletion? (y/N) ");
            if (strtolower($confirm) !== 'y') {
                return [];
            }
        }

        $deleted = [];
        foreach ($toDelete as $path) {
            if ($this->filesystem->delete($this->resolvePath($path))) {
                $deleted[] = $path;
            }
        }
        return $deleted;
    }

    /**
     * Delete all log files (use with caution).
     *
     * @param string $subpath
     * @param bool $force
     * @return array Deleted paths
     */
    public function clearAll(string $subpath = '', bool $force = false): array
    {
        return $this->deleteFiles($subpath, [], $force);
    }

    // -------------------------------------------------------------------------
    // INTERNAL HELPERS
    // -------------------------------------------------------------------------

    protected function passesFilters(array $entry, array $filters): bool
    {
        // Level filter (exact)
        if (isset($filters['level'])) {
            if (strtoupper($entry['level']) !== strtoupper($filters['level'])) {
                return false;
            }
        }
        // Multiple levels
        if (isset($filters['levels'])) {
            if (!in_array(strtoupper($entry['level']), array_map('strtoupper', $filters['levels']))) {
                return false;
            }
        }
        // Date range
        if (isset($filters['date_from'])) {
            $from = new DateTime($filters['date_from']);
            $entryTime = new DateTime($entry['timestamp']);
            if ($entryTime < $from) return false;
        }
        if (isset($filters['date_to'])) {
            $to = new DateTime($filters['date_to']);
            $entryTime = new DateTime($entry['timestamp']);
            if ($entryTime > $to) return false;
        }
        // Full‑text search (case‑insensitive)
        if (isset($filters['search'])) {
            $haystack = $entry['message'] . ' ' . json_encode($entry['context']);
            if (stripos($haystack, $filters['search']) === false) {
                return false;
            }
        }
        // Context key/value matching
        if (isset($filters['context_key']) && isset($filters['context_value'])) {
            $key = $filters['context_key'];
            $value = $filters['context_value'];
            if (!isset($entry['context'][$key]) || $entry['context'][$key] != $value) {
                return false;
            }
        }
        return true;
    }

    protected function resolvePath(string $path): string
    {
        // If path starts with the base, leave as is; otherwise prepend base
        $full = $this->basePath . ltrim($path, '/\\');
        // Convert any directory separators to the adapter's expected format
        return str_replace(DIRECTORY_SEPARATOR, '/', $full);
    }

    protected function assertFileExists(string $filePath): void
    {
        if (!$this->filesystem->exists($this->resolvePath($filePath))) {
            throw new InvalidArgumentException("Log file not found: {$filePath}");
        }
    }

    /**
     * Simple CLI confirmation (overridable for web usage).
     */
    protected function confirm(string $prompt): string
    {
        if (PHP_SAPI !== 'cli') {
            // In a web environment, you might want to throw an exception or return 'y' if force is true.
            return 'n';
        }
        echo $prompt;
        $handle = fopen('php://stdin', 'r');
        $line = fgets($handle);
        fclose($handle);
        return trim($line);
    }

    // -------------------------------------------------------------------------
    // ACCESSORS
    // -------------------------------------------------------------------------

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    public function getFilesystem(): Filesystem
    {
        return $this->filesystem;
    }
}