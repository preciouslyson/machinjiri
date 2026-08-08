<?php

namespace Mlangeni\Machinjiri\Core\Transport\SMS\Factory;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Transport\SMS\Contracts\TransportInterface;
use Mlangeni\Machinjiri\Core\Transport\SMS\Circuit\CircuitBreaker;
use Mlangeni\Machinjiri\Core\Transport\SMS\Idempotency\IdempotencyStore;
use Mlangeni\Machinjiri\Core\Transport\SMS\Retry\RetryPolicy;
use Mlangeni\Machinjiri\Core\Transport\SMS\RateLimit\RateLimiter;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;
use Mlangeni\Machinjiri\Core\Exceptions\SMSException;
use Mlangeni\Machinjiri\Core\Transport\SMS\Transports\{
    AfricasTalkingTransport
};

class TransportRegistry
{
    private Logger $logger;
    private Container $container;
    private array $drivers = [];

    public function __construct(Logger $logger, Container $container)
    {
        $this->logger = $logger;
        $this->container = $container;
        // Register built-in drivers
        $this->register('africastalking', AfricasTalkingTransport::class);
    }

    public function register(string $driver, string $class): void
    {
        $this->drivers[$driver] = $class;
    }

    public function make(array $config): TransportInterface
    {
        $driver = $config['driver'] ?? 'null';
        if (!isset($this->drivers[$driver])) {
            throw SMSException::configError("Unsupported transport driver: $driver");
        }
        $class = $this->drivers[$driver];
        if ($this->container->bound($class)) {
            return $this->container->make($class, ['config' => $config]);
        }

        $class = isset($config[$driver]['transportClass'])
            ? $config[$driver]['transportClass']
            : $class;
        
        return new $class (
            $this->container,
            new RetryPolicy(
                $config['retry']['max_attempts'] ?? 3,
                $config['retry']['base_delay_ms'] ?? 1000,
                $config['retry']['backoff_factor'] ?? (float) 2.0,
                $config['retry']['jitter_factor'] ?? (float) 0.1,
            ),
            new CircuitBreaker($this->container->resolve(CacheManager::class), $driver),
            new IdempotencyStore($this->container->resolve(CacheManager::class)),
            new RateLimiter($this->container->resolve(CacheManager::class), $driver, 10, 2)
        );
    }

    public function defaultTransport(): TransportInterface
    {
        $config = $this->container->configurations['sms'] ?? null;
        if ($config === null) throw SMSException::configError("SMS configurations not set");
        if (!isset($config['default'])) throw SMSException::configError("default transport not defined");
        $default = $config['default'];
        if (!isset($config['transports'][$default]) || count($config['transports'][$default]) === 0) {
            throw SMSException::configError("config for {$default} transport not compatible");
        }
        return $this->make($config['transports'][$default]);
    }
}