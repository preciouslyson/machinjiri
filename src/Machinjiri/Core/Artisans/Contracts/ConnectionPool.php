<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Contracts;

class ConnectionPool
{
    protected array $connections = [];
    protected array $configs = [];
    protected int $maxConnections = 10;
    protected int $maxIdleTime = 300; // 5 minutes
    
    public function getConnection(string $driver, array $config): mixed
    {
        $key = $this->getConnectionKey($driver, $config);
        
        if (isset($this->connections[$key]) && $this->isConnectionValid($key)) {
            return $this->connections[$key]['connection'];
        }
        
        $connection = $this->createConnection($driver, $config);
        $this->connections[$key] = [
            'connection' => $connection,
            'last_used' => time(),
            'usage_count' => 0,
        ];
        
        return $connection;
    }
    
    public function releaseConnection(string $driver, array $config): void
    {
        $key = $this->getConnectionKey($driver, $config);
        if (isset($this->connections[$key])) {
            $this->connections[$key]['last_used'] = time();
        }
    }
    
    protected function createConnection(string $driver, array $config): mixed
    {
        // Factory method to create connections
        switch ($driver) {
            case 'redis':
                if (!extension_loaded('redis')) {
                    throw new \RuntimeException('Redis extension is not installed');
                }
                $redis = new \Redis();
                $redis->connect($config['host'] ?? 'localhost', $config['port'] ?? 6379);
                if (isset($config['password'])) {
                    $redis->auth($config['password']);
                }
                if (isset($config['database'])) {
                    $redis->select($config['database']);
                }
                return $redis;
            case 'database':
                if (!isset($config['dsn'])) {
                    throw new \InvalidArgumentException('Database DSN is required');
                }
                $dsn = $config['dsn'];
                $username = $config['username'] ?? null;
                $password = $config['password'] ?? null;
                $options = $config['options'] ?? [];
                return new \PDO($dsn, $username, $password, $options);
            default:
                throw new \InvalidArgumentException("Unsupported driver: {$driver}");
        }
    }
    
    protected function cleanupIdleConnections(): void
    {
        $now = time();
        foreach ($this->connections as $key => $data) {
            if ($now - $data['last_used'] > $this->maxIdleTime) {
                unset($this->connections[$key]);
            }
        }
    }
    
    /**
     * Get connection count
     */
    public function getConnectionCount(): int
    {
        return count($this->connections);
    }
    
    /**
     * Close a specific connection
     */
    public function closeConnection(string $driver, array $config): void
    {
        $key = $this->getConnectionKey($driver, $config);
        if (isset($this->connections[$key])) {
            $connection = $this->connections[$key]['connection'];
            if ($connection instanceof \Redis) {
                $connection->close();
            } elseif ($connection instanceof \PDO) {
                // PDO connections are closed by unsetting
            }
            unset($this->connections[$key]);
        }
    }
    
    /**
     * Close all connections
     */
    public function closeAll(): void
    {
        foreach ($this->connections as $key => $data) {
            $connection = $data['connection'];
            if ($connection instanceof \Redis) {
                $connection->close();
            }
        }
        $this->connections = [];
    }
    
    /**
     * Check if connection exists in pool
     */
    protected function isConnectionValid(string $key): bool
    {
        if (!isset($this->connections[$key])) {
            return false;
        }
        
        $data = $this->connections[$key];
        $connection = $data['connection'];
        
        // Test Redis connection
        if ($connection instanceof \Redis) {
            try {
                return $connection->ping() === true || $connection->ping() === '+PONG';
            } catch (\Throwable $e) {
                return false;
            }
        }
        
        // Test PDO connection
        if ($connection instanceof \PDO) {
            try {
                $connection->query('SELECT 1');
                return true;
            } catch (\Throwable $e) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get unique key for connection
     */
    protected function getConnectionKey(string $driver, array $config): string
    {
        return $driver . ':' . md5(json_encode($config));
    }
}