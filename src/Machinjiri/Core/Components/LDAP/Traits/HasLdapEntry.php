<?php

namespace Mlangeni\Machinjiri\Core\Components\LDAP\Traits;

trait HasLdapEntry
{
    protected $ldapEntry;

    public function setLdapEntry($entry): void
    {
        $this->ldapEntry = $entry;
    }

    public function getLdapEntry()
    {
        return $this->ldapEntry;
    }
}