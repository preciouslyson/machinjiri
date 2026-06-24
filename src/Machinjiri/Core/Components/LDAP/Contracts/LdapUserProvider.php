<?php

namespace Mlangeni\Machinjiri\Core\Components\LDAP\Contracts;

interface LdapUserProvider
{
    public function retrieveByCredentials(array $credentials);
    public function validateCredentials($user, array $credentials): bool;
}