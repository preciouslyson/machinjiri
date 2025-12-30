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
                return new \Redis();
            case 'database':
                return new \PDO(...);
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
}