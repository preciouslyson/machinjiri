<?php

namespace Mlangeni\Machinjiri\Core\Exceptions;

class SMSException extends MachinjiriException 
{
    public static function transportError(string $message, int $code = 0, ?\Throwable $previous = null, array $options = []) 
    {
        throw new self("SMSTranspotError: {$message}", $code, $previous, $options);
    }

    public static function configError(string $message, int $code = 0, ?\Throwable $previous = null, array $options = []) 
    {
        throw new self("SMSConfigException: {$message}", $code, $previous, $options);
    }

    public static function retryError(string $message, int $code = 0, ?\Throwable $previous = null, array $options = []) 
    {
        throw new self("SMSRetryException: {$message}", $code, $previous, $options);
    }
    
}