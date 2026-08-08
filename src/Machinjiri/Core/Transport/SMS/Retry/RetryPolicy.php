<?php

namespace Mlangeni\Machinjiri\Core\Transport\SMS\Retry;

class RetryPolicy
{
    private int $maxAttempts;
    private int $baseDelayMs;
    private float $backoffFactor;
    private float $jitterFactor;

    public function __construct(int $maxAttempts = 3, int $baseDelayMs = 1000, float $backoffFactor = 2.0, float $jitterFactor = 0.1)
    {
        $this->maxAttempts = $maxAttempts;
        $this->baseDelayMs = $baseDelayMs;
        $this->backoffFactor = $backoffFactor;
        $this->jitterFactor = $jitterFactor;
    }

    /**
     * Execute a callable with retries. Accepts a predicate to decide retryability.
     */
    public function execute(callable $operation, callable $isRetryable, array $context = []): mixed
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxAttempts) {
            try {
                $result = $operation();
                // If result is a Response, we can check success
                if ($result instanceof \Mlangeni\Machinjiri\Core\Transport\SMS\Response) {
                    if ($result->isSuccess() || !$isRetryable($result)) {
                        return $result;
                    }
                    // Otherwise retry
                } else {
                    return $result; // non-Response, assume success
                }
            } catch (\Throwable $e) {
                $lastException = $e;
                if (!$isRetryable($e)) {
                    throw $e;
                }
            }

            $attempt++;
            if ($attempt < $this->maxAttempts) {
                $this->backoff($attempt);
            }
        }

        throw new \Mlangeni\Machinjiri\Core\Exceptions\SMSException(
            "Max retries ({$this->maxAttempts}) exceeded",
            0,
            $lastException
        );
    }

    private function backoff(int $attempt): void
    {
        $delay = (int) ($this->baseDelayMs * pow($this->backoffFactor, $attempt - 1));
        $jitter = (int) ($delay * $this->jitterFactor * (mt_rand(-100, 100) / 100));
        $sleepMs = max(50, $delay + $jitter);
        usleep($sleepMs * 1000);
    }
}