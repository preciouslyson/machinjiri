<?php

namespace Mlangeni\Machinjiri\Core\Network;


class PHPServerManager
{
    private string $address;
    private string $documentRoot;
    private ?int $pid = null;
    private string $logFile;
    private string $pidFile;
    private array $options = [];

    /**
     * Constructor
     *
     * @param string $address Server address (e.g., 'localhost:8000')
     * @param string $documentRoot Document root directory
     * @param array $options Additional options
     */
    public function __construct(int $port = 30000,
        array $options = []
    ) {
        $this->address = 'localhost:' . $port;
        $this->documentRoot = getcwd() . '/public';
        $this->options = $options;
        
        // Set default files for logging and PID storage
        $this->logFile = $options['log_file'] ?? sys_get_temp_dir() . '/php_server.log';
        $this->pidFile = $options['pid_file'] ?? sys_get_temp_dir() . '/php_server.pid';
    }

    /**
     * Start the PHP development server
     *
     * @return array Result with status and message
     */
    public function start(): array
    {
        if ($this->isRunning()) {
            return [
                'success' => false,
                'message' => 'Server is already running',
                'pid' => $this->getStoredPid()
            ];
        }

        // Build the command
        $command = sprintf(
            'php -S %s -t %s > %s 2>&1 & echo $!',
            escapeshellarg($this->address),
            escapeshellarg($this->documentRoot),
            escapeshellarg($this->logFile)
        );

        // Add router script if specified
        if (isset($this->options['router'])) {
            $command = sprintf(
                'php -S %s %s > %s 2>&1 & echo $!',
                escapeshellarg($this->address),
                escapeshellarg($this->options['router']),
                escapeshellarg($this->logFile)
            );
        }

        // Execute the command
        exec($command, $output, $returnVar);

        if (empty($output[0]) || !is_numeric($output[0])) {
            return [
                'success' => false,
                'message' => 'Failed to start server'
            ];
        }

        $this->pid = (int) $output[0];
        $this->storePid($this->pid);

        return [
            'success' => true,
            'message' => sprintf('Server started on %s (PID: %d)', $this->address, $this->pid),
            'pid' => $this->pid,
            'address' => $this->address,
            'document_root' => $this->documentRoot
        ];
    }

    /**
     * Stop (pause) the server
     *
     * @return array Result with status and message
     */
    public function stop(): array
    {
        $pid = $this->getStoredPid();
        
        if (!$pid || !$this->isProcessRunning($pid)) {
            $this->clearPid();
            return [
                'success' => false,
                'message' => 'Server is not running'
            ];
        }

        // Kill the process
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            exec(sprintf('taskkill /F /PID %d', $pid), $output, $returnVar);
        } else {
            exec(sprintf('kill %d', $pid), $output, $returnVar);
        }

        $this->clearPid();
        $this->pid = null;

        return [
            'success' => $returnVar === 0,
            'message' => $returnVar === 0 
                ? sprintf('Server stopped (PID: %d)', $pid)
                : 'Failed to stop server',
            'pid' => $pid
        ];
    }

    /**
     * Restart (continue) the server
     *
     * @return array Result with status and message
     */
    public function restart(): array
    {
        $stopResult = $this->stop();
        
        if (!$stopResult['success'] && strpos($stopResult['message'], 'not running') === false) {
            return [
                'success' => false,
                'message' => 'Failed to stop server before restart'
            ];
        }

        // Small delay before restarting
        usleep(500000); // 0.5 seconds

        return $this->start();
    }

    /**
     * Get server status
     *
     * @return array Server status information
     */
    public function status(): array
    {
        $pid = $this->getStoredPid();
        $isRunning = $pid && $this->isProcessRunning($pid);

        return [
            'running' => $isRunning,
            'pid' => $isRunning ? $pid : null,
            'address' => $this->address,
            'document_root' => $this->documentRoot,
            'log_file' => $this->logFile,
            'pid_file' => $this->pidFile
        ];
    }

    /**
     * Get server logs
     *
     * @param int $lines Number of lines to retrieve
     * @return array Log content
     */
    public function getLogs(int $lines = 50): array
    {
        if (!file_exists($this->logFile)) {
            return [
                'success' => false,
                'message' => 'Log file does not exist'
            ];
        }

        $logContent = [];
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            exec(sprintf('tail -n %d "%s"', $lines, $this->logFile), $logContent);
        } else {
            exec(sprintf('tail -n %d %s', $lines, escapeshellarg($this->logFile)), $logContent);
        }

        return [
            'success' => true,
            'lines' => $logContent,
            'file' => $this->logFile
        ];
    }

    /**
     * Check if server is running
     *
     * @return bool True if server is running
     */
    private function isRunning(): bool
    {
        $pid = $this->getStoredPid();
        return $pid && $this->isProcessRunning($pid);
    }

    /**
     * Check if a process is running
     *
     * @param int $pid Process ID
     * @return bool True if process is running
     */
    private function isProcessRunning(int $pid): bool
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            exec(sprintf('tasklist /FI "PID eq %d"', $pid), $output);
            return count($output) > 1 && strpos($output[1], $pid) !== false;
        }

        return posix_kill($pid, 0);
    }

    /**
     * Store PID to file
     *
     * @param int $pid Process ID
     */
    private function storePid(int $pid): void
    {
        file_put_contents($this->pidFile, $pid);
    }

    /**
     * Get stored PID
     *
     * @return int|null PID or null if not found
     */
    private function getStoredPid(): ?int
    {
        if (file_exists($this->pidFile)) {
            $pid = (int) file_get_contents($this->pidFile);
            return $pid > 0 ? $pid : null;
        }
        return null;
    }

    /**
     * Clear PID file
     */
    private function clearPid(): void
    {
        if (file_exists($this->pidFile)) {
            unlink($this->pidFile);
        }
    }

    /**
     * Cleanup on destruction
     */
    public function __destruct()
    {
        // Optional: Uncomment if you want server to stop when object is destroyed
        // $this->stop();
    }
}

// Usage example:
/*
$server = new PHPServerManager(
    'localhost:8080',
    __DIR__ . '/public',
    [
        'router' => __DIR__ . '/public/index.php', // Optional router script
        'log_file' => __DIR__ . '/server.log'
    ]
);

// Start server
$result = $server->start();
print_r($result);

// Check status
$status = $server->status();
print_r($status);

// Get logs
$logs = $server->getLogs(10);
print_r($logs);

// Stop (pause) server
$result = $server->stop();
print_r($result);

// Restart (continue) server
$result = $server->restart();
print_r($result);
*/