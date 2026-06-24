<?php

namespace Mlangeni\Machinjiri\Core\Components\Webhooks;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Exceptions\WebhookException;
use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;

class WebhookManager
{
    private Container $app;
    private WebhookSubscriptionManager $subscriptionManager;
    private IdempotencyStore $idempotencyStore;
    private Logger $logger;

    public function __construct(
        Container $app,
        WebhookSubscriptionManager $subscriptionManager,
        CacheManager $cacheManager
    ) {
        $this->app = $app;
        $this->subscriptionManager = $subscriptionManager;
        $this->idempotencyStore = new CacheIdempotencyStore($cacheManager);
        $this->logger = new Logger('webhook-manager');
    }

    /**
     * Process an incoming webhook request.
     */
    public function process(WebhookPayload $payload): WebhookResponse
    {
        $provider = $payload->getProvider();
        $eventType = $payload->getEventType();
        $idempotencyKey = $payload->getIdempotencyKey();

        // 1. Signature verification (before idempotency)
        try {
            $this->verifySignature($payload);
        } catch (WebhookException $e) {
            $this->logger->error('Webhook signature verification failed', [
                'provider' => $provider,
                'error' => $e->getMessage()
            ]);
            return WebhookResponse::unauthorized('Invalid signature');
        }

        // 2. Idempotency check – already completed?
        $cacheKey = $this->getIdempotencyCacheKey($provider, $idempotencyKey);
        if ($idempotencyKey && $this->idempotencyStore->isDone($cacheKey)) {
            $this->logger->info('Duplicate webhook (already processed) ignored', [
                'provider' => $provider,
                'key' => $idempotencyKey,
                'event' => $eventType
            ]);
            return WebhookResponse::accepted();
        }

        // 3. Try to acquire lock for this key (prevents concurrent processing)
        if ($idempotencyKey && !$this->idempotencyStore->lock($cacheKey)) {
            $this->logger->info('Webhook currently being processed by another request', [
                'provider' => $provider,
                'key' => $idempotencyKey
            ]);
            return WebhookResponse::accepted();
        }

        // 4. Decide sync vs async
        $async = $this->subscriptionManager->isAsync($provider);

        if ($async) {
            return $this->dispatchAsync($payload);
        }

        // 5. Sync processing
        $response = $this->dispatchToHandlers($payload);
        // Mark as done (releases lock)
        if ($idempotencyKey) {
            $this->idempotencyStore->markDone($cacheKey);
        }
        return $response;
    }

    /**
     * Dispatch synchronously to all registered handlers.
     */
    public function dispatchToHandlers(WebhookPayload $payload): WebhookResponse
    {
        $handlers = $this->subscriptionManager->getHandlersForEvent($payload->getEventType());
        if (empty($handlers)) {
            $this->logger->warning('No handler for webhook event', [
                'event' => $payload->getEventType(),
                'provider' => $payload->getProvider()
            ]);
            return WebhookResponse::notFound("No handler for event: {$payload->getEventType()}");
        }

        $provider = $payload->getProvider();
        $failureMode = $this->subscriptionManager->getHandlerFailureMode($provider);
        $responses = [];

        foreach ($handlers as $handler) {
            try {
                $responses[] = $handler->handle($payload);
                $this->logger->debug('Webhook handled', [
                    'handler' => get_class($handler),
                    'event' => $payload->getEventType()
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('Webhook handler threw exception', [
                    'handler' => get_class($handler),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                if ($failureMode === 'stop') {
                    throw WebhookException::processingFailed($e->getMessage());
                }
                // else continue to next handler
            }
        }

        // If no handler returned a response (all skipped due to 'continue'), return 200
        if (empty($responses)) {
            return WebhookResponse::ok();
        }

        // Return the first non-2xx response, or the last response
        foreach ($responses as $response) {
            if ($response->getStatusCode() >= 400) {
                return $response;
            }
        }
        return end($responses);
    }

    private function dispatchAsync(WebhookPayload $payload): WebhookResponse
    {
        $job = new WebhookJob(
            $this->app,
            $payload   // pass full payload
        );

        /** @var \Mlangeni\Machinjiri\Core\Artisans\Contracts\JobDispatcherInterface $dispatcher */
        $dispatcher = $this->app->resolve('queue.dispatcher');
        $dispatcher->dispatchToQueue($job, 'webhooks');

        $this->logger->info('Webhook queued for async processing', [
            'provider' => $payload->getProvider(),
            'event' => $payload->getEventType(),
            'key' => $payload->getIdempotencyKey()
        ]);

        return WebhookResponse::accepted();
    }

    private function verifySignature(WebhookPayload $payload): void
    {
        $provider = $payload->getProvider();
        $secret = $this->subscriptionManager->getSecretForProvider($provider);
        if (!$secret) {
            throw WebhookException::missingSecret($provider);
        }

        $method = $this->subscriptionManager->getVerificationMethod($provider);
        $headers = $payload->getHeaders();

        switch ($method['type']) {
            case 'hmac':
                $signatureHeader = $headers[$method['header']] ?? null;
                if (!$signatureHeader) {
                    throw WebhookException::invalidSignature($provider);
                }
                $valid = WebhookSignatureVerifier::verifyHmac(
                    $payload->getRawBody(),
                    $secret,
                    $signatureHeader,
                    $method['algo'] ?? 'sha256',
                    $method['prefix'] ?? ''
                );
                if (!$valid) {
                    throw WebhookException::invalidSignature($provider);
                }
                break;

            case 'hmac_timestamp':
                $signatureHeader = $headers[$method['header']] ?? null;
                if (!$signatureHeader) {
                    throw WebhookException::invalidSignature($provider);
                }
                $valid = WebhookSignatureVerifier::verifyHmacTimestamp(
                    $payload->getRawBody(),
                    $secret,
                    $signatureHeader,
                    $method['algo'] ?? 'sha256',
                    $method['prefix'] ?? '',
                    $method['tolerance'] ?? 300
                );
                if (!$valid) {
                    throw WebhookException::invalidSignature($provider);
                }
                break;

            case 'custom':
                $valid = WebhookSignatureVerifier::verifyCustom(
                    $payload->getRawBody(),
                    $headers,
                    $method['callback'],
                    $secret
                );
                if (!$valid) {
                    throw WebhookException::invalidSignature($provider);
                }
                break;

            default:
                throw WebhookException::unsupportedAlgorithm($method['type']);
        }

        $this->logger->debug('Webhook signature verified', ['provider' => $provider]);
    }

    private function getIdempotencyCacheKey(string $provider, ?string $key): string
    {
        return "webhook_{$provider}_{$key}";
    }
}