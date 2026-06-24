<?php

namespace Mlangeni\Machinjiri\Core\Authentication\Middleware;

use Mlangeni\Machinjiri\Core\Artisans\Base\AbstractMiddleware;
use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;
use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;

class Throttle extends AbstractMiddleware
{
    protected CacheManager $cache;
    protected int $maxAttempts;
    protected int $decaySeconds;

    public function __construct(CacheManager $cache, int $maxAttempts = 5, int $decaySeconds = 60)
    {
        $this->cache = $cache;
        $this->maxAttempts = $maxAttempts;
        $this->decaySeconds = $decaySeconds;
    }

    public function handle(HttpRequest $request, HttpResponse $response, callable $next, array $params = [])
    {
        $key = $this->resolveKey($request);
        $attempts = (int) $this->cache->get($key, 0);

        if ($attempts >= $this->maxAttempts) {
            return $this->tooManyAttempts($response);
        }

        $response = $next($request, $response);

        // Increment attempts (only if the response is not successful?)
        // For login attempts, we usually increment after a failed attempt.
        // We'll let the caller increment manually, or we can check if request is a login.
        // Simpler: increment after the request is processed.
        $this->cache->set($key, $attempts + 1, $this->decaySeconds);

        return $response;
    }

    protected function resolveKey(HttpRequest $request): string
    {
        $ip = $request->getClientIp() ?? '0.0.0.0';
        $username = $request->input('email') ?? $request->input('username') ?? '';
        return 'throttle:' . md5($ip . '|' . $username);
    }

    protected function tooManyAttempts(HttpResponse $response): HttpResponse
    {
        return $response->setStatusCode(429)->setJsonBody(['error' => 'Too many attempts.']);
    }

    public function resetAttempts(HttpRequest $request): void
    {
        $this->cache->delete($this->resolveKey($request));
    }
}