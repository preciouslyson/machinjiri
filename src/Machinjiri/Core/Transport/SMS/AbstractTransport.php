<?php

namespace Mlangeni\Machinjiri\Core\Transport\SMS;

use Mlangeni\Machinjiri\Core\Transport\SMS\Contracts\TransportInterface;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Transport\SMS\Retry\RetryPolicy;
use Mlangeni\Machinjiri\Core\Transport\SMS\Circuit\CircuitBreaker;
use Mlangeni\Machinjiri\Core\Transport\SMS\Idempotency\IdempotencyStore;
use Mlangeni\Machinjiri\Core\Transport\SMS\RateLimit\RateLimiter;
use Mlangeni\Machinjiri\Core\Exceptions\SMSException;
use Mlangeni\Machinjiri\Core\Container;

abstract class AbstractTransport implements TransportInterface
{
    protected Container $app;
    protected Logger $logger;
    protected EventListener $events;
    protected RetryPolicy $retryPolicy;
    protected CircuitBreaker $circuitBreaker;
    protected IdempotencyStore $idempotencyStore;
    protected ?RateLimiter $rateLimiter;

    public function __construct(
        Container $app,
        RetryPolicy $retryPolicy,
        CircuitBreaker $circuitBreaker,
        IdempotencyStore $idempotencyStore,
        ?RateLimiter $rateLimiter = null
    ) {
        $this->app                = $app;
        $this->logger             = $this->app->resolve(Logger::class);
        $this->events             = $this->app->resolve(EventListener::class);
        $this->retryPolicy        = $retryPolicy;
        $this->circuitBreaker     = $circuitBreaker;
        $this->idempotencyStore   = $idempotencyStore;
        $this->rateLimiter        = $rateLimiter;
    }

    final public function send(Message $message): Response
    {
        // 1. Circuit breaker check
        if (!$this->circuitBreaker->isAvailable()) {
            throw SMSException::transportError("Circuit open for transport " . $this->getName());
        }

        // 2. Rate limiting
        if ($this->rateLimiter && !$this->rateLimiter->allow()) {
            throw SMSException::transportError("Rate limit exceeded for " . $this->getName());
        }

        // 3. Idempotency key
        $idempotencyKey = $this->generateIdempotencyKey($message);
        if ($this->idempotencyStore->isProcessed($idempotencyKey)) {
            $this->logger->info('Duplicate send prevented', ['key' => $idempotencyKey]);
            return new Response(true, 'duplicate', null, ['idempotent' => true]);
        }

        // 4. Acquire lock
        if (!$this->idempotencyStore->lock($idempotencyKey)) {
            throw SMSException::transportError("Another process is sending this message");
        }

        try {
            $this->events->trigger('sms.send', ['message' => $message, 'transport' => $this->getName()]);

            $response = $this->retryPolicy->execute(
                function () use ($message) {
                    return $this->doSend($message);
                },
                function ($result) {
                    if ($result instanceof Response) {
                        return !$result->isSuccess() && $this->isRetryableFailure($result);
                    }
                    if ($result instanceof \Throwable) {
                        return $this->isRetryableException($result);
                    }
                    return false;
                },
                ['message' => $message]
            );

            // 5. Record success for circuit breaker
            $this->circuitBreaker->recordSuccess();

            if ($response->isSuccess()) {
                $this->idempotencyStore->markProcessed($idempotencyKey);
                $this->events->trigger('sms.sent', ['response' => $response, 'transport' => $this->getName()]);
                $this->logger->info('SMS sent successfully', [
                    'messageId' => $response->getMessageId(),
                    'to' => $message->getTo(),
                ]);
            } else {
                $this->events->trigger('sms.send_failed', ['response' => $response, 'transport' => $this->getName()]);
                $this->logger->error('SMS permanent failure', ['error' => $response->getError()]);
            }

            return $response;

        } catch (\Throwable $e) {
            $this->circuitBreaker->recordFailure();
            $this->events->trigger('sms.send_failed', ['exception' => $e, 'transport' => $this->getName()]);
            throw SMSException::retryError("Transport error: " . $e->getMessage(), 0, $e);
        } finally {
            $this->idempotencyStore->unlock($idempotencyKey);
        }
    }

    protected function generateIdempotencyKey(Message $message): string
    {
        return md5($this->getName() . $message->getTo() . $message->getText() . $message->getFrom());
    }

    abstract protected function doSend(Message $message): Response;
    abstract protected function isRetryableFailure(Response $response): bool;
    abstract protected function isRetryableException(\Throwable $e): bool;
    abstract public function getName(): string;
}