<?php

namespace Mlangeni\Machinjiri\Core\Components\Webhooks;

use Mlangeni\Machinjiri\Core\Exceptions\WebhookException;

class WebhookSubscriptionManager
{
    private array $config;
    public array $handlers = [];

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }
 
    public function registerHandler(WebhookHandlerInterface $handler): void
    {
        $events = (array) $handler->supportsEvent();
        foreach ($events as $event) {
            if (isset($this->handlers[$event]) && in_array($handler, $this->handlers[$event])) continue;
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
     * Get verify signature.
     */
    public function mustVerifySignature(string $provider): bool
    {
        return $this->config['providers'][$provider]['verify_signature'] ?? true;
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
        $keys = ['secret', 'async', 'handler_failure_mode', 'verify_signature', 'handler', 'verify'];
        foreach ($keys as $key) {
            $this->validateProvider($cfg, $key, $provider);
            if ($key == 'verify') {
                $this->validateProviderVerificationCfg($cfg['verify']);
            }
        }
        
    }

    private function validateProviderVerificationCfg(array $cfg): void 
    {
        $keys = ['type', 'header', 'algo','prefix'];
        foreach ($keys as $key) {
            if (empty($cfg[$key])) {
                throw new \InvalidArgumentException("Missing {$key} for provider [{$provider}] verification.");
            }
        }
    }

    private function validateProvider(array $cfg, string $key, $provider): void 
    {
        if (empty($cfg[$key])) {
            throw new \InvalidArgumentException("Missing {$key} for provider [{$provider}].");
        }
    }

    public function registerWebhookHandlers(): void
    {
        $providers = $this->config['providers'] ?? false;
        if (!$providers) {
            return;
        }
        foreach ($providers as $provider => $providerConfig) {
            $handler = $providerConfig['handler'] ?? false;
            if (!$handler || !class_exists($handler)) {
                continue;
            }
            $this->registerHandler(new $handler);
        }
    }
}