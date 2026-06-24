<?php

namespace Mlangeni\Machinjiri\Core\Exceptions;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class WebhookException extends MachinjiriException
{
    public static function invalidSignature(string $provider): self
    {
        return new self("Invalid webhook signature for provider: {$provider}", 60030);
    }

    public static function missingSecret(string $provider): self
    {
        return new self("No secret configured for webhook provider: {$provider}", 60031);
    }

    public static function unsupportedAlgorithm(string $algo): self
    {
        return new self("Unsupported signature algorithm: {$algo}", 60032);
    }

    public static function handlerNotFound(string $event): self
    {
        return new self("No webhook handler registered for event: {$event}", 60033);
    }

    public static function processingFailed(string $reason): self
    {
        return new self("Webhook processing failed: {$reason}", 60034);
    }
}