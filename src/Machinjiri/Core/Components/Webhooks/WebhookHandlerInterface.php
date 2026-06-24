<?php

namespace Mlangeni\Machinjiri\Core\Components\Webhooks;

interface WebhookHandlerInterface
{
    /**
     * Handle the incoming webhook payload.
     *
     * @param WebhookPayload $payload
     * @return WebhookResponse
     */
    public function handle(WebhookPayload $payload): WebhookResponse;

    /**
     * Return the event type(s) this handler supports (e.g. 'payment.succeeded' or '*').
     *
     * @return string|array
     */
    public function supportsEvent(): string|array;
}