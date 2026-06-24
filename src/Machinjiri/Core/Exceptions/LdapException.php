<?php

namespace Mlangeni\Machinjiri\Core\Exceptions;

class LdapException extends MachinjiriException
{
    public function __construct(
        string $message = "",
        int $code = 500,
        ?\Throwable $previous = null,
        array $context = [],
        string $category = 'ldap'
    ) {
        parent::__construct($message, $code, $previous, $context, $category);
    }
}