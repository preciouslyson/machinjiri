<?php

namespace Mlangeni\Machinjiri\Core\Authentication\UserProviders;

use Mlangeni\Machinjiri\Facade\Authentication\Authenticatable;
use Mlangeni\Machinjiri\Core\Components\LDAP\Manager as LdapManager;
use Mlangeni\Machinjiri\Core\Components\LDAP\Entry;
use Mlangeni\Machinjiri\Core\Exceptions\LdapException;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;
use Mlangeni\Machinjiri\Core\Container;

class LdapUserProvider implements UserProvider
{
    protected LdapManager $ldap;
    protected string $modelClass;
    protected array $syncAttributes;
    protected string $usernameAttribute = 'uid';
    protected array $searchFields = ['uid', 'mail'];
    protected Logger $logger;
    protected EventListener $events;
    protected CacheManager $cache;
    protected int $cacheTtl = 300;

    public function __construct(
        LdapManager $ldap,
        string $modelClass,
        array $syncAttributes,
        array $config = []
    ) {
        $this->ldap = $ldap;
        $this->modelClass = $modelClass;
        $this->syncAttributes = $syncAttributes;
        $this->usernameAttribute = $config['username_attribute'] ?? 'uid';
        $this->searchFields = $config['search_fields'] ?? ['uid', 'mail'];
        $this->cacheTtl = $config['cache_ttl'] ?? 300;
        $this->logger = resolve(Logger::class);
        $this->events = resolve(EventListener::class);
        $this->cache = resolve(CacheManager::class);
    }

    public function retrieveById($id): ?Authenticatable
    {
        // Not directly supported; rely on local sync.
        // We could search by a local ID mapping.
        return null;
    }

    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $username = $credentials['username'] ?? ($credentials['email'] ?? null);
        if (!$username) {
            return null;
        }

        $this->events->trigger('ldap.user.retrieving', ['username' => $username]);

        // Check cache
        $cacheKey = 'ldap:user:' . md5($username);
        $cachedEntry = $this->cache->get($cacheKey);
        if ($cachedEntry) {
            $this->logger->debug('LDAP user cache hit', ['username' => $username]);
            return $this->syncLocalUser($cachedEntry);
        }

        $entry = null;
        foreach ($this->searchFields as $field) {
            $query = $this->ldap->query()
                ->where($field, '=', $username)
                ->select(array_values($this->syncAttributes));
            $entry = $query->first();
            if ($entry) {
                break;
            }
        }

        if (!$entry) {
            $this->logger->info('LDAP user not found', ['username' => $username]);
            $this->events->trigger('ldap.user.notfound', ['username' => $username]);
            return null;
        }

        $this->cache->set($cacheKey, $entry, $this->cacheTtl);
        $localUser = $this->syncLocalUser($entry);
        // Store the LDAP entry on the user (if we add a setter)
        if (method_exists($localUser, 'setLdapEntry')) {
            $localUser->setLdapEntry($entry);
        }
        $this->events->trigger('ldap.user.retrieved', ['user' => $localUser]);
        return $localUser;
    }

    public function retrieveByRememberToken(string $token): ?Authenticatable
    {
        // Not implemented for LDAP; you could delegate to a local database.
        return null;
    }

    public function updateRememberToken(Authenticatable $user, string $token): void
    {
        // Update the local user model if needed
        if (method_exists($user, 'setRememberToken')) {
            $user->setRememberToken($token);
            if (method_exists($user, 'save')) {
                $user->save();
            }
        }
    }

    protected function syncLocalUser(Entry $entry): Authenticatable
    {
        $modelClass = $this->modelClass;
        $username = $entry->getAttribute($this->usernameAttribute);
        if (!$username) {
            throw new LdapException("Username attribute '{$this->usernameAttribute}' not found.");
        }

        $local = $modelClass::where('username', $username)->first();

        $attributes = [];
        foreach ($this->syncAttributes as $localAttr => $ldapAttr) {
            $attributes[$localAttr] = $entry->getAttribute($ldapAttr);
        }
        $attributes['username'] = $username;

        if ($local) {
            $local->fill($attributes);
            $local->save();
        } else {
            $local = new $modelClass($attributes);
            $local->save();
        }
        return $local;
    }

    public function validateCredentials($user, array $credentials): bool
    {
        $password = $credentials['password'] ?? null;
        if (!$password) {
            return false;
        }
        // Check LDAP bind using the user's DN (must be stored on the user)
        $dn = method_exists($user, 'getLdapDn') ? $user->getLdapDn() : null;
        if (!$dn) {
            return false;
        }
        try {
            return $this->ldap->connection()->bind($dn, $password);
        } catch (LdapException $e) {
            $this->logger->warning('LDAP bind failed', ['dn' => $dn, 'error' => $e->getMessage()]);
            return false;
        }
    }
}