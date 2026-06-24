<?php

namespace Mlangeni\Machinjiri\Core\Components\Webhooks;

interface IdempotencyStore
{
    /**
     * Try to acquire a lock for the given key.
     * Returns true if lock was acquired, false if already locked or done.
     *
     * @param string $key
     * @param int $ttl seconds to hold the lock (default 1 hour)
     * @return bool
     */
    public function lock(string $key, int $ttl = 3600): bool;

    /**
     * Mark a key as successfully processed.
     *
     * @param string $key
     * @return void
     */
    public function markDone(string $key): void;

    /**
     * Check if a key was already processed successfully.
     *
     * @param string $key
     * @return bool
     */
    public function isDone(string $key): bool;
}