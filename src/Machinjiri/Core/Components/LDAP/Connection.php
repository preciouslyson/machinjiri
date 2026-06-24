<?php

namespace Mlangeni\Machinjiri\Core\Components\LDAP;

use Mlangeni\Machinjiri\Core\Components\LDAP\Query\Builder;
use Mlangeni\Machinjiri\Core\Exceptions\LdapException;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Container;

class Connection
{
    protected array $config;
    protected $link = null;
    protected bool $bound = false;
    protected Logger $logger;
    protected EventListener $events;

    public function __construct(array $config, ?Logger $logger = null, ?EventListener $events = null)
    {
        $this->config = $config;
        $this->logger = $logger ?? new Logger('ldap-component');
        $this->events = $events ?? new EventListener($this->logger);
    }

    public function connect(): void
    {
        if ($this->link) return;

        $this->events->trigger('ldap.connecting', $this->config);

        $hosts = $this->config['hosts'] ?? [];
        $port = $this->config['port'] ?? 389;
        $protocol = ($this->config['use_ssl'] ?? false) ? 'ldaps://' : 'ldap://';
        $connected = false;

        foreach ($hosts as $host) {
            $this->link = ldap_connect($protocol . $host . ":" . $port);
            if ($this->link) {
                $connected = true;
                break;
            }
        }

        if (!$connected) {
            throw new LdapException('Could not connect to any LDAP host', 500, null, ['hosts' => $hosts]);
        }

        // Set options
        foreach ($this->config['options'] ?? [] as $option => $value) {
            ldap_set_option($this->link, $option, $value);
        }

        // TLS
        if ($this->config['use_tls'] ?? false) {
            if (!ldap_start_tls($this->link)) {
                throw new LdapException('TLS failed: ' . ldap_error($this->link), 500, null, ['host' => $host]);
            }
        }

        // Auto-bind if credentials provided
        if (!empty($this->config['username']) && ($this->config['auto_bind'] ?? true)) {
            $this->bind($this->config['username'], $this->config['password']);
        }

        $this->events->trigger('ldap.connected', $this);
        $this->logger->info('LDAP connection established', ['host' => $host, 'port' => $port]);
    }

    public function bind(string $dn, string $password): bool
    {
        $this->connect();
        $this->events->trigger('ldap.binding', ['dn' => $dn]);

        if (!ldap_bind($this->link, $dn, $password)) {
            $error = ldap_error($this->link);
            $this->logger->error('LDAP bind failed', ['dn' => $dn, 'error' => $error]);
            throw new LdapException('LDAP bind failed: ' . $error, 401, null, ['dn' => $dn]);
        }

        $this->bound = true;
        $this->events->trigger('ldap.bound', ['dn' => $dn]);
        $this->logger->info('LDAP bind successful', ['dn' => $dn]);
        return true;
    }

    public function close(): void
    {
        if ($this->link) {
            ldap_unbind($this->link);
            $this->link = null;
            $this->bound = false;
        }
    }

    public function getLink()
    {
        $this->connect();
        return $this->link;
    }

    public function isConnected(): bool
    {
        return $this->link !== null;
    }

    public function isBound(): bool
    {
        return $this->bound;
    }

    public function getBaseDn(): string
    {
        return $this->config['base_dn'] ?? '';
    }

    public function query(): Builder
    {
        return new Builder($this, $this->logger, $this->events);
    }

    public function __destruct()
    {
        $this->close();
    }
}