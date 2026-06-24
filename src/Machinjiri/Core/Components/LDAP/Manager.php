<?php

namespace Mlangeni\Machinjiri\Core\Components\LDAP;

use Mlangeni\Machinjiri\Core\Exceptions\LdapException;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;
use Mlangeni\Machinjiri\Core\Container;

class Manager
{
    protected array $config;
    protected array $connections = [];
    protected Logger $logger;
    protected EventListener $events;
    protected CacheManager $cache;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->logger = resolve(Logger::class);
        $this->events = resolve(EventListener::class);
        $this->cache = resolve(CacheManager::class);
    }

    public function connection(?string $name = null): Connection
    {
        $name = $name ?: $this->config['default'] ?? 'default';

        if (!isset($this->connections[$name])) {
            $connConfig = $this->config['connections'][$name] ?? null;
            if ($connConfig === null) {
                throw new LdapException("LDAP connection [{$name}] not configured.", 500, null, ['connection' => $name]);
            }
            $this->connections[$name] = new Connection(
                $connConfig,
                $this->logger,
                $this->events
            );
        }

        return $this->connections[$name];
    }

    public function __call(string $method, array $parameters)
    {
        return $this->connection()->$method(...$parameters);
    }
}