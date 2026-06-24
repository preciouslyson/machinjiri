<?php

namespace Mlangeni\Machinjiri\Core\Components\LDAP;

use Mlangeni\Machinjiri\Core\Exceptions\LdapException;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Container;

class EntryManager
{
    protected Connection $connection;
    protected Logger $logger;
    protected EventListener $events;

    public function __construct(
        Connection $connection,
        ?Logger $logger = null,
        ?EventListener $events = null
    ) {
        $this->connection = $connection;
        $this->logger = $logger ?? resolve(Logger::class);
        $this->events = $events ?? resolve(EventListener::class);
    }

    /**
     * Create a new LDAP entry.
     *
     * @param string $dn      Distinguished Name (e.g., "uid=johndoe,ou=people,dc=example,dc=com")
     * @param array  $attrs   Associative array of attributes (e.g., ['cn' => 'John Doe', 'sn' => 'Doe', ...])
     * @return bool
     * @throws LdapException
     */
    public function create(string $dn, array $attrs): bool
    {
        $this->events->trigger('ldap.entry.creating', ['dn' => $dn, 'attrs' => $attrs]);
        $this->logger->info('Creating LDAP entry', ['dn' => $dn]);

        $link = $this->connection->getLink();
        if (!ldap_add($link, $dn, $attrs)) {
            $error = ldap_error($link);
            $this->logger->error('LDAP add failed', ['dn' => $dn, 'error' => $error]);
            throw new LdapException("Failed to create entry: $error", 500, null, ['dn' => $dn]);
        }

        $this->events->trigger('ldap.entry.created', ['dn' => $dn]);
        $this->logger->info('LDAP entry created', ['dn' => $dn]);
        return true;
    }

    /**
     * Read an LDAP entry by DN.
     *
     * @param string $dn
     * @param array  $attributes  List of attributes to fetch (empty = all)
     * @return Entry|null
     */
    public function read(string $dn, array $attributes = []): ?Entry
    {
        $link = $this->connection->getLink();
        $result = ldap_read($link, $dn, '(objectClass=*)', $attributes);
        if (!$result) {
            $this->logger->warning('LDAP read failed', ['dn' => $dn, 'error' => ldap_error($link)]);
            return null;
        }
        $entries = ldap_get_entries($link, $result);
        if ($entries['count'] === 0) {
            return null;
        }
        return new Entry($entries[0], $this->connection->getBaseDn());
    }

    /**
     * Update an LDAP entry.
     *
     * @param string $dn
     * @param array  $newAttrs  Attributes to replace (full replacement) or use modify for incremental?
     * @param bool   $replace   If true, replace all attributes; if false, use ldap_modify (add/delete/replace)
     * @return bool
     * @throws LdapException
     */
    public function update(string $dn, array $newAttrs, bool $replace = true): bool
    {
        $this->events->trigger('ldap.entry.updating', ['dn' => $dn, 'attrs' => $newAttrs]);
        $this->logger->info('Updating LDAP entry', ['dn' => $dn]);

        $link = $this->connection->getLink();
        if ($replace) {
            // Replace all attributes (ldap_modify with full replacement)
            if (!ldap_modify($link, $dn, $newAttrs)) {
                $error = ldap_error($link);
                $this->logger->error('LDAP modify failed', ['dn' => $dn, 'error' => $error]);
                throw new LdapException("Failed to update entry: $error", 500, null, ['dn' => $dn]);
            }
        } else {
            // For partial updates, you would build a $mods array with add/delete/replace operations
            // Simplification: we treat $newAttrs as replacements for existing attributes.
            // For advanced partial updates, use ldap_mod_del/add/replace.
            if (!ldap_modify($link, $dn, $newAttrs)) {
                $error = ldap_error($link);
                $this->logger->error('LDAP modify failed', ['dn' => $dn, 'error' => $error]);
                throw new LdapException("Failed to update entry: $error", 500, null, ['dn' => $dn]);
            }
        }

        $this->events->trigger('ldap.entry.updated', ['dn' => $dn]);
        $this->logger->info('LDAP entry updated', ['dn' => $dn]);
        return true;
    }

    /**
     * Delete an LDAP entry.
     *
     * @param string $dn
     * @return bool
     * @throws LdapException
     */
    public function delete(string $dn): bool
    {
        $this->events->trigger('ldap.entry.deleting', ['dn' => $dn]);
        $this->logger->info('Deleting LDAP entry', ['dn' => $dn]);

        $link = $this->connection->getLink();
        if (!ldap_delete($link, $dn)) {
            $error = ldap_error($link);
            $this->logger->error('LDAP delete failed', ['dn' => $dn, 'error' => $error]);
            throw new LdapException("Failed to delete entry: $error", 500, null, ['dn' => $dn]);
        }

        $this->events->trigger('ldap.entry.deleted', ['dn' => $dn]);
        $this->logger->info('LDAP entry deleted', ['dn' => $dn]);
        return true;
    }
}