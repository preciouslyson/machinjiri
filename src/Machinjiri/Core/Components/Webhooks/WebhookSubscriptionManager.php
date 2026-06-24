<?php

namespace Mlangeni\Machinjiri\Core\Components\Webhooks;

class WebhookSubscriptionManager
{
    private array $config;
    private array $handlers = [];

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function registerHandler(WebhookHandlerInterface $handler): void
    {
        $events = (array) $handler->supportsEvent();
        foreach ($events as $event) {
            $this->handlers[$event][] = $handler;
        }
    }

    public function getHandlersForEvent(string $event): array
    {
        $handlers = $this->handlers[$event] ?? [];
        if (isset($this->handlers['*'])) {
            $handlers = array_merge($handlers, $this->handlers['*']);
        }
        return $handlers;
    }

    public function getSecretForProvider(string $provider): ?string
    {
        return $this->config['providers'][$provider]['secret'] ?? null;
    }

    public function getVerificationMethod(string $provider): array
    {
        $default = [
            'type' => 'hmac',
            'header' => 'X-Signature',
            'algo' => 'sha256',
            'prefix' => ''
        ];
        return $this->config['providers'][$provider]['verify'] ?? $default;
    }

    public function isAsync(string $provider): bool
    {
        return $this->config['providers'][$provider]['async'] ?? true;
    }

    /**
     * Get event resolver config for a provider.
     */
    public function getEventResolver(string $provider): ?array
    {
        return $this->config['providers'][$provider]['event_resolver'] ?? null;
    }

    /**
     * Get handler failure mode for a provider: 'stop' (default) or 'continue'.
     */
    public function getHandlerFailureMode(string $provider): string
    {
        return $this->config['providers'][$provider]['handler_failure_mode'] ?? 'stop';
    }

    /**
     * Validate provider configuration (optional).
     */
    public function validateProviderConfig(string $provider): void
    {
        if (!isset($this->config['providers'][$provider])) {
            throw new \InvalidArgumentException("Provider [{$provider}] not configured.");
        }
        $cfg = $this->config['providers'][$provider];
        if (empty($cfg['secret'])) {
            throw new \InvalidArgumentException("Missing secret for provider [{$provider}].");
        }
        // further checks if needed
    }
}