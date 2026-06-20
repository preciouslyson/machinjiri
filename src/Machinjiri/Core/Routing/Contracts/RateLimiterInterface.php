<?php

namespace Mlangeni\Machinjiri\Core\Routing\Contracts;

interface RateLimiterInterface
{
    public function attempt(string $limiterName, string $clientId, int $maxRequests, int $period): bool;
}