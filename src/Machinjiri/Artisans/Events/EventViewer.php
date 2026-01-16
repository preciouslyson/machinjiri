<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Events;

use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Container;

class EventViewer
{
    /**
     * @var Logger Logger instance
     */
    protected $logger;

    /**
     * @var string Path to log files
     */
    protected $logPath;

    /**
     * Constructor
     *
     * @param Logger $logger Logger instance
     */
    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->logPath = Container::$appBasePath . "/../storage/logs/";
    }

    /**
     * Get all available log files
     *
     * @return array List of log files
     */
    public function getLogFiles(): array
    {
        $files = [];
        
        if (!is_dir($this->logPath)) {
            return $files;
        }
        
        $items = scandir($this->logPath);
        
        foreach ($items as $item) {
            if (is_file($this->logPath . $item) && pathinfo($item, PATHINFO_EXTENSION) === 'log') {
                $files[] = $item;
            }
        }
        
        return $files;
    }

    /**
     * Read and parse log file content
     *
     * @param string $filename Log file name
     * @param int $limit Number of entries to return (0 for all)
     * @param string $level Filter by log level
     * @return array Parsed log entries
     */
    public function readLogFile(string $filename, int $limit = 0, string $level = ''): array
    {
        $filepath = $this->logPath . $filename;
        
        if (!file_exists($filepath)) {
            return [];
        }
        
        $content = file_get_contents($filepath);
        $lines = explode(PHP_EOL, $content);
        $entries = [];
        
        // Parse each line
        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }
            
            $entry = $this->parseLogEntry($line);
            
            if ($entry && (empty($level) || $entry['level'] === $level)) {
                $entries[] = $entry;
            }
        }
        
        // Reverse to show newest first
        $entries = array_reverse($entries);
        
        // Apply limit if specified
        if ($limit > 0) {
            $entries = array_slice($entries, 0, $limit);
        }
        
        return $entries;
    }

    /**
     * Parse a single log entry
     *
     * @param string $entry Log entry string
     * @return array|null Parsed entry or null if invalid format
     */
    protected function parseLogEntry(string $entry): ?array
    {
        // Match the log format: [timestamp] [LEVEL] message
        $pattern = '/^\[(.*?)\] \[(.*?)\] (.*)$/';
        
        if (!preg_match($pattern, $entry, $matches)) {
            return null;
        }
        
        return [
            'timestamp' => $matches[1],
            'level' => $matches[2],
            'message' => $matches[3],
            'raw' => $entry
        ];
    }

    /**
     * Get log statistics
     *
     * @param string $filename Log file name
     * @return array Statistics
     */
    public function getStats(string $filename): array
    {
        $entries = $this->readLogFile($filename);
        $stats = [
            'total' => count($entries),
            'levels' => [],
            'first_entry' => null,
            'last_entry' => null
        ];
        
        if (count($entries) > 0) {
            $stats['first_entry'] = end($entries)['timestamp'];
            $stats['last_entry'] = $entries[0]['timestamp'];
            
            foreach ($entries as $entry) {
                $level = $entry['level'];
                
                if (!isset($stats['levels'][$level])) {
                    $stats['levels'][$level] = 0;
                }
                
                $stats['levels'][$level]++;
            }
        }
        
        return $stats;
    }

    /**
     * Filter log entries by date range
     *
     * @param string $filename Log file name
     * @param string $startDate Start date (Y-m-d)
     * @param string $endDate End date (Y-m-d)
     * @return array Filtered entries
     */
    public function filterByDate(string $filename, string $startDate, string $endDate): array
    {
        $entries = $this->readLogFile($filename);
        $filtered = [];
        
        $startTimestamp = strtotime($startDate);
        $endTimestamp = strtotime($endDate . ' 23:59:59');
        
        foreach ($entries as $entry) {
            $entryTimestamp = strtotime($entry['timestamp']);
            
            if ($entryTimestamp >= $startTimestamp && $entryTimestamp <= $endTimestamp) {
                $filtered[] = $entry;
            }
        }
        
        return $filtered;
    }

    /**
     * Search log entries for specific text
     *
     * @param string $filename Log file name
     * @param string $searchText Text to search for
     * @return array Matching entries
     */
    public function search(string $filename, string $searchText): array
    {
        $entries = $this->readLogFile($filename);
        $results = [];
        
        foreach ($entries as $entry) {
            if (stripos($entry['message'], $searchText) !== false || 
                stripos($entry['level'], $searchText) !== false) {
                $results[] = $entry;
            }
        }
        
        return $results;
    }

    /**
     * Clear a log file
     *
     * @param string $filename Log file name
     * @return bool Success status
     */
    public function clearLog(string $filename): bool
    {
        $filepath = $this->logPath . $filename;
        
        if (file_exists($filepath)) {
            return file_put_contents($filepath, '') !== false;
        }
        
        return false;
    }

    /**
     * Render log entries in HTML format
     *
     * @param array $entries Log entries
     * @return string HTML content
     */
    public function renderAsHtml(array $entries): string
    {
        if (empty($entries)) {
            return '<div class="log-empty">No log entries found.</div>';
        }
        
        $html = '<table class="log-table">';
        $html .= '<thead><tr><th>Timestamp</th><th>Level</th><th>Message</th></tr></thead>';
        $html .= '<tbody>';
        
        foreach ($entries as $entry) {
            $levelClass = strtolower($entry['level']);
            $html .= sprintf(
                '<tr class="log-entry log-%s">' .
                '<td class="log-timestamp">%s</td>' .
                '<td class="log-level">%s</td>' .
                '<td class="log-message">%s</td>' .
                '</tr>',
                $levelClass,
                htmlspecialchars($entry['timestamp']),
                htmlspecialchars($entry['level']),
                htmlspecialchars($entry['message'])
            );
        }
        
        $html .= '</tbody></table>';
        
        return $html;
    }

    /**
     * Render log entries in plain text format
     *
     * @param array $entries Log entries
     * @return string Plain text content
     */
    public function renderAsText(array $entries): string
    {
        if (empty($entries)) {
            return 'No log entries found.';
        }
        
        $text = '';
        
        foreach ($entries as $entry) {
            $text .= sprintf(
                "[%s] [%s] %s\n",
                $entry['timestamp'],
                $entry['level'],
                $entry['message']
            );
        }
        
        return $text;
    }

    /**
     * Get CSS styles for HTML rendering
     *
     * @return string CSS styles
     */
    public function getStyles(): string
    {
        return '
            <style>
                .log-table {
                    width: 100%;
                    border-collapse: collapse;
                    font-family: monospace;
                    font-size: 14px;
                }
                
                .log-table th, .log-table td {
                    padding: 8px 12px;
                    border: 1px solid #ddd;
                    text-align: left;
                }
                
                .log-table th {
                    background-color: #f5f5f5;
                    font-weight: bold;
                }
                
                .log-entry:hover {
                    background-color: #f9f9f9;
                }
                
                .log-emergency {
                    background-color: #ffcccc;
                }
                
                .log-alert {
                    background-color: #ffd9cc;
                }
                
                .log-critical {
                    background-color: #ffe6cc;
                }
                
                .log-error {
                    background-color: #fff2cc;
                }
                
                .log-warning {
                    background-color: #ffffcc;
                }
                
                .log-notice {
                    background-color: #f2ffcc;
                }
                
                .log-info {
                    background-color: #e6ffcc;
                }
                
                .log-debug {
                    background-color: #ccffcc;
                }
                
                .log-empty {
                    padding: 20px;
                    text-align: center;
                    color: #999;
                    font-style: italic;
                }
            </style>
        ';
    }
}