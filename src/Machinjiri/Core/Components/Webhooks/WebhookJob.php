<?php

namespace Mlangeni\Machinjiri\Core\Components\Webhooks;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\BaseJob;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Components\Webhooks\WebhookPayload;
use Mlangeni\Machinjiri\Core\Components\Webhooks\WebhookManager;
use Mlangeni\Machinjiri\Core\Components\Webhooks\CacheIdempotencyStore;
use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;

/**
 * Asynchronous job for processing webhooks.
 */
class WebhookJob extends BaseJob
{
    private WebhookPayload $webhookPayload;

    public function __construct(Container $app, WebhookPayload $webhookPayload)
    {
        parent::__construct($app, $webhookPayload->getParsedData() ?? [], [
            'name'        => 'webhook.' . $webhookPayload->getProvider(),
            'queue'       => 'webhooks',
            'maxAttempts' => 3,
            'retryDelay'  => 60,
            'timeout'     => 120,
        ]);
        $this->webhookPayload = $webhookPayload;
    }

    public function handle(): void
    {
        /** @var WebhookManager $manager */
        $manager = $this->app->resolve(WebhookManager::class);
        /** @var CacheManager $cacheManager */
        $cacheManager = $this->app->resolve(CacheManager::class);

        $idempotencyKey = $this->webhookPayload->getIdempotencyKey();
        $cacheKey = "webhook_{$this->webhookPayload->getProvider()}_{$idempotencyKey}";
        $idempotencyStore = new CacheIdempotencyStore($cacheManager);

        // Check if already processed (duplicate job)
        if ($idempotencyKey && $idempotencyStore->isDone($cacheKey)) {
            $this->logger->info('Async webhook already processed, skipping', [
                'provider' => $this->webhookPayload->getProvider(),
                'key' => $idempotencyKey
            ]);
            return;
        }

        // Acquire lock (ensures at-most-one processing across retries)
        if ($idempotencyKey && !$idempotencyStore->lock($cacheKey)) {
            $this->release(30); // wait and retry
            return;
        }

        try {
            $manager->dispatchToHandlers($this->webhookPayload);
            if ($idempotencyKey) {
                $idempotencyStore->markDone($cacheKey);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Async webhook failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function release(int $delay): void 
    {
        // TODO: implement release logic
    }
}