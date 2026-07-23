<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Adapters\Redis;

use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Artisans\Logging\LoggerFactory;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Predis\Client;
use Predis\ClientInterface;
use Predis\Connection\ConnectionException;

/**
 * Production‑ready Redis adapter using Predis.
 * Provides serialization, logging, event hooks, and robust error handling.
 */
class RedisAdapter implements RedisAdapterInterface
{
    /**
     * @var ClientInterface Underlying Predis client.
     */
    protected ClientInterface $client;

    /**
     * @var Logger PSR‑3 style logger.
     */
    protected Logger $logger;

    /**
     * @var EventListener|null Optional event dispatcher.
     */
    protected ?EventListener $eventListener = null;

    /**
     * @var bool Enable/disable automatic JSON serialization.
     */
    protected bool $serialize = true;

    /**
     * @var string Prefix applied to all keys.
     */
    protected string $prefix = '';

    /**
     * @var array Connection parameters.
     */
    protected array $config;

    /**
     * Constructor.
     *
     * @param array $config Connection parameters:
     *     - host (string): Redis host
     *     - port (int): Redis port
     *     - database (int): Database index
     *     - timeout (float): Connection timeout (seconds)
     *     - read_write_timeout (float): Read/write timeout
     *     - retry_interval (int): Retry interval (ms)
     *     - prefix (string): Key prefix
     *     - serialize (bool): Enable JSON serialization
     *     - replication (bool|string): Replication setup
     *     - cluster (string): Cluster configuration
     * @param Logger $logger Logger instance.
     * @param EventListener|null $eventListener Optional event dispatcher.
     */
    public function __construct(
        array $config,
        ?Logger $logger = null,
        ?EventListener $eventListener = null
    ) {
        $this->config = $this->validateConfig($config);
        $this->logger = $logger ?? LoggerFactory::system("redis-adapter", "redis", false);
        $this->eventListener = $eventListener ?? new EventListener(LoggerFactory::system("redis-adapter", "redis", true));
        $this->prefix = $this->config['prefix'] ?? '';
        $this->serialize = $this->config['serialize'] ?? true;

        $this->client = $this->createClient();
    }

    /**
     * Validate redis config.
     */
    protected function validateConfig(array $config): array
    {
        $defaults = [
            'timeout'  => 2.5,
            'read_write_timeout' => 2.5,
            'retry_interval' => 100,
            'prefix'   => '',
            'serialize' => true,
            'replication' => false,
            'cluster'   => null,
        ];

        $defaultConn = $config['default'] ?? false;
        $connections = $config['connections'] ?? false;

        if (!$defaultConn && !$connections)
            throw new MachinjiriException("Redis error: incomplete configuration");    

        if (empty($defaultConn)) 
            throw new MachinjiriException('Redis error: default connection not set in config');

        if (!isset($connections[$defaultConn]) || count($connections[$defaultConn]) === 0)
            throw new MachinjiriException("Redis error: configuration for connection [{$defaultConn}] is not set");
        
        $connection = $connections[$defaultConn];
        $required = ['host', 'port', 'database'];
        
        foreach ($required as $value) {
            if (!array_key_exists($value, $connection)) {
                throw new MachinjiriException("Redis error: {$value} is missing");
            }
        }

        return array_merge($defaults, $connection);
    }

    /**
     * Build Predis client with parameters and options.
     */
    protected function createClient(): ClientInterface
    {
        $parameters = [
            'host' => $this->config['host'],
            'port' => $this->config['port'],
            'database' => $this->config['database'],
            'timeout' => $this->config['timeout'],
            'read_write_timeout' => $this->config['read_write_timeout'],
            'retry_interval' => $this->config['retry_interval'],
        ];

        if (!empty($this->config['password'])) {
            $parameters['password'] = $this->config['password'];
        }

        $options = [
            'reconnect' => 'retry',
        ];

        if ($this->config['replication']) {
            $options['replication'] = $this->config['replication'];
        }

        if ($this->config['cluster']) {
            $options['cluster'] = $this->config['cluster'];
        }

        if ($this->prefix) {
            $options['prefix'] = $this->prefix . ':';
        }

        return new Client($parameters, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key)
    {
        $key = $this->applyPrefix($key);
        try {
            $value = $this->client->get($key);
            $this->emitEvent('redis.hit', ['key' => $key, 'value' => $value]);
            return $this->serialize ? json_decode($value, true) : $value;
        } catch (ConnectionException $e) {
            $this->handleConnectionError($e, 'GET', $key);
        } catch (\Exception $e) {
            $this->handleError($e, 'GET', $key);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, $value, ?int $ttl = null): bool
    {
        $key = $this->applyPrefix($key);
        $value = $this->serialize ? json_encode($value) : $value;
        try {
            if ($ttl !== null) {
                $result = (bool) $this->client->setex($key, $ttl, $value);
            } else {
                $result = (bool) $this->client->set($key, $value);
            }
            $this->emitEvent('redis.set', ['key' => $key, 'ttl' => $ttl]);
            return $result;
        } catch (ConnectionException $e) {
            $this->handleConnectionError($e, 'SET', $key);
        } catch (\Exception $e) {
            $this->handleError($e, 'SET', $key);
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string ...$keys): int
    {
        $keys = array_map([$this, 'applyPrefix'], $keys);
        try {
            return $this->client->del($keys);
        } catch (ConnectionException $e) {
            $this->handleConnectionError($e, 'DEL', implode(',', $keys));
        } catch (\Exception $e) {
            $this->handleError($e, 'DEL', implode(',', $keys));
        }
        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function exists(string $key): bool
    {
        $key = $this->applyPrefix($key);
        try {
            return (bool) $this->client->exists($key);
        } catch (ConnectionException $e) {
            $this->handleConnectionError($e, 'EXISTS', $key);
        } catch (\Exception $e) {
            $this->handleError($e, 'EXISTS', $key);
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function increment(string $key, int $by = 1): int
    {
        $key = $this->applyPrefix($key);
        try {
            return $this->client->incrby($key, $by);
        } catch (ConnectionException $e) {
            $this->handleConnectionError($e, 'INCRBY', $key);
        } catch (\Exception $e) {
            $this->handleError($e, 'INCRBY', $key);
        }
        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function decrement(string $key, int $by = 1): int
    {
        $key = $this->applyPrefix($key);
        try {
            return $this->client->decrby($key, $by);
        } catch (ConnectionException $e) {
            $this->handleConnectionError($e, 'DECRBY', $key);
        } catch (\Exception $e) {
            $this->handleError($e, 'DECRBY', $key);
        }
        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function expire(string $key, int $seconds): bool
    {
        $key = $this->applyPrefix($key);
        try {
            return (bool) $this->client->expire($key, $seconds);
        } catch (ConnectionException $e) {
            $this->handleConnectionError($e, 'EXPIRE', $key);
        } catch (\Exception $e) {
            $this->handleError($e, 'EXPIRE', $key);
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function persist(string $key): bool
    {
        $key = $this->applyPrefix($key);
        try {
            return (bool) $this->client->persist($key);
        } catch (ConnectionException $e) {
            $this->handleConnectionError($e, 'PERSIST', $key);
        } catch (\Exception $e) {
            $this->handleError($e, 'PERSIST', $key);
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function ttl(string $key): ?int
    {
        $key = $this->applyPrefix($key);
        try {
            $ttl = $this->client->ttl($key);
            return $ttl >= 0 ? $ttl : null;
        } catch (ConnectionException $e) {
            $this->handleConnectionError($e, 'TTL', $key);
        } catch (\Exception $e) {
            $this->handleError($e, 'TTL', $key);
        }
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function ping(): bool
    {
        try {
            return $this->client->ping() === 'PONG';
        } catch (\Exception $e) {
            $this->logger->warning('Redis ping failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function pipeline(callable $callback): array
    {
        try {
            return $this->client->pipeline($callback);
        } catch (\Exception $e) {
            $this->handleError($e, 'PIPELINE');
            throw $e; // rethrow as we cannot recover
        }
    }

    /**
     * {@inheritdoc}
     */
    public function transaction(callable $callback): array
    {
        try {
            return $this->client->transaction($callback);
        } catch (\Exception $e) {
            $this->handleError($e, 'TRANSACTION');
            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getClient(): ClientInterface
    {
        return $this->client;
    }

    /**
     * {@inheritdoc}
     */
    public function disconnect(): void
    {
        $this->client->disconnect();
    }

    /**
     * {@inheritdoc}
     */
    public function __call(string $method, array $args)
    {
        // Forward any unknown method to Predis client (with error handling)
        try {
            return $this->client->$method(...$args);
        } catch (\Exception $e) {
            $this->handleError($e, $method, implode(',', $args));
            throw $e;
        }
    }

    protected function applyPrefix(string $key): string
    {
        return $this->prefix ? $this->prefix . ':' . $key : $key;
    }

    protected function handleError(\Exception $e, string $command, string $key = ''): void
    {
        $context = [
            'command' => $command,
            'key' => $key,
            'error' => $e->getMessage(),
        ];
        $this->logger->error("Redis operation failed", $context);
        $this->emitEvent('redis.error', $context);

        throw new MachinjiriException(
            "Redis error: {$e->getMessage()}",
            500,
            $e,
            $context,
            'redis'
        );
    }

    protected function handleConnectionError(ConnectionException $e, string $command, string $key = ''): void
    {
        $context = [
            'command' => $command,
            'key' => $key,
            'error' => $e->getMessage(),
        ];
        $this->logger->critical("Redis connection lost", $context);
        $this->emitEvent('redis.connection_lost', $context);

        throw MachinjiriException::serviceUnavailable(
            "Redis service unavailable: {$e->getMessage()}",
            60
        )->setContext($context);
    }

    protected function emitEvent(string $event, array $payload = []): void
    {
        if ($this->eventListener) {
            $this->eventListener->trigger($event, $payload);
        }
    }

    /**
     * Cleanup on destruction.
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}