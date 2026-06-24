<?php

namespace Mlangeni\Machinjiri\Core\Components\LDAP;

use Mlangeni\Machinjiri\Core\Components\LDAP\Contracts\LdapUserProvider as ProviderContract;
use Mlangeni\Machinjiri\Core\Exceptions\LdapException;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;
use Mlangeni\Machinjiri\Core\Container;

class LdapUserProvider implements ProviderContract
{
    protected Manager $ldap;
    protected string $modelClass;
    protected array $syncAttributes;
    protected string $usernameAttribute = 'uid';
    protected array $searchFields = ['uid', 'mail'];
    protected Logger $logger;
    protected EventListener $events;
    protected CacheManager $cache;
    protected int $cacheTtl = 300;

    public function __construct(
        Manager $ldap,
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

    public function retrieveByCredentials(array $credentials)
    {
        $username = $credentials['username'] ?? ($credentials['email'] ?? null);
        if (!$username) {
            return null;
        }

        $this->events->trigger('ldap.user.retrieving', ['username' => $username]);

        // Check cache first
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
            $this->logger->info('LDAP user not found', ['username' => $username, 'fields' => $this->searchFields]);
            $this->events->trigger('ldap.user.notfound', ['username' => $username]);
            return null;
        }

        // Cache the entry (not the model, to avoid serialization issues)
        $this->cache->set($cacheKey, $entry, $this->cacheTtl);

        $localUser = $this->syncLocalUser($entry);
        $localUser->setLdapEntry($entry);

        $this->events->trigger('ldap.user.retrieved', ['user' => $localUser, 'entry' => $entry]);
        return $localUser;
    }

    public function validateCredentials($user, array $credentials): bool
    {
        $password = $credentials['password'] ?? null;
        if (!$password) {
            return false;
        }

        $ldapEntry = $user->getLdapEntry();
        if (!$ldapEntry) {
            $this->logger->warning('LDAP entry missing for user', ['user' => $user->getAuthIdentifier()]);
            return false;
        }

        $dn = $ldapEntry->getDn();
        if (!$dn) {
            $this->logger->warning('LDAP user has no DN', ['user' => $user->getAuthIdentifier()]);
            return false;
        }

        $this->events->trigger('ldap.user.validating', ['dn' => $dn]);

        try {
            // Use a separate connection to avoid affecting the main one
            $result = $this->ldap->connection()->bind($dn, $password);
            if ($result) {
                $this->logger->info('LDAP authentication successful', ['user' => $user->getAuthIdentifier()]);
                $this->events->trigger('ldap.user.validated', ['user' => $user]);
                return true;
            }
        } catch (LdapException $e) {
            $this->logger->warning('LDAP authentication failed', [
                'user' => $user->getAuthIdentifier(),
                'error' => $e->getMessage()
            ]);
            $this->events->trigger('ldap.user.failed', ['user' => $user, 'exception' => $e]);
            return false;
        }

        return false;
    }

    protected function syncLocalUser(Entry $entry)
    {
        $modelClass = $this->modelClass;
        $username = $entry->getAttribute($this->usernameAttribute);
        if (!$username) {
            throw new LdapException("Username attribute '{$this->usernameAttribute}' not found in LDAP entry");
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
}