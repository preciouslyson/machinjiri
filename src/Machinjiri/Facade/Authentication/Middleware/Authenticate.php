<?php

namespace Mlangeni\Machinjiri\Core\Facade\Authentication\Middleware;

use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;
use Mlangeni\Machinjiri\Core\Facade\Authentication\Auth;

class Authenticate
{
    private ?string $redirectTo = null;
    private array $guards = [];

    public function __construct(array $guards = [])
    {
        $this->guards = $guards;
    }

    public function handle(HttpRequest $request, HttpResponse $response, callable $next, ...$guards)
    {
        $guards = empty($guards) ? $this->guards : $guards;
        
        foreach ($guards as $guard) {
            if (Auth::guard($guard)->check()) {
                return $next($request, $response);
            }
        }
        
        return $this->unauthenticated($request, $response);
    }

    protected function unauthenticated(HttpRequest $request, HttpResponse $response)
    {
        if ($request->expectsJson()) {
            return $response->sendError('Unauthenticated', 401);
        }
        
        return $response->redirect($this->redirectTo ?? '/login');
    }

    public function redirectTo(string $path): self
    {
        $this->redirectTo = $path;
        return $this;
    }
}