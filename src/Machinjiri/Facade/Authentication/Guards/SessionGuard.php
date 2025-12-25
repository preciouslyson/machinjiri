<?php

namespace Mlangeni\Machinjiri\Core\Facade\Authentication\Guards;

use Mlangeni\Machinjiri\Core\Facade\Authentication\Guard;
use Mlangeni\Machinjiri\Core\Facade\Authentication\Authenticatable;
use Mlangeni\Machinjiri\Core\Facade\Authentication\Models\User;
use Mlangeni\Machinjiri\Core\Authentication\Session;
use Mlangeni\Machinjiri\Core\Authentication\Cookie;
use Mlangeni\Machinjiri\Core\Database\QueryBuilder;
use Mlangeni\Machinjiri\Core\Security\Hashing\Hasher;

class SessionGuard implements Guard
{
    private ?Authenticatable $user = null;
    private Session $session;
    private Cookie $cookie;
    private QueryBuilder $queryBuilder;
    private Hasher $hasher;
    private string $userModel;
    private bool $loggedOut = false;

    public function __construct(
        ?Session $session = null,
        ?Cookie $cookie = null,
        ?QueryBuilder $queryBuilder = null,
        ?Hasher $hasher = null,
        string $userModel = User::class
    ) {
        $this->session = $session ?? new Session();
        $this->cookie = $cookie ?? new Cookie();
        $this->queryBuilder = $queryBuilder ?? new QueryBuilder('users');
        $this->hasher = $hasher ?? new Hasher();
        $this->userModel = $userModel;
    }

    public function check(): bool
    {
        return !is_null($this->user());
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    public function user(): ?Authenticatable
    {
        if ($this->loggedOut) {
            return null;
        }

        if (!is_null($this->user)) {
            return $this->user;
        }

        $id = $this->session->get('user_id');
        
        if (!is_null($id)) {
            $this->user = $this->retrieveById($id);
        }

        // If user is null, check remember token
        if (is_null($this->user) && $this->hasRememberToken()) {
            $this->user = $this->retrieveByRememberToken();
        }

        return $this->user;
    }

    public function id(): mixed
    {
        if ($this->user()) {
            return $this->user()->getAuthIdentifier();
        }
        
        return $this->session->get('user_id');
    }

    public function validate(array $credentials): bool
    {
        $user = $this->retrieveByCredentials($credentials);
        
        if (!is_null($user)) {
            return $this->hasher->verify(
                $credentials['password'] ?? '',
                $user->getAuthPassword()
            );
        }
        
        return false;
    }

    public function setUser(Authenticatable $user): void
    {
        $this->user = $user;
        $this->loggedOut = false;
        $this->session->set('user_id', $user->getAuthIdentifier());
    }

    public function attempt(array $credentials, bool $remember = false): bool
    {
        $user = $this->retrieveByCredentials($credentials);
        
        if (!is_null($user) && $this->hasher->verify(
            $credentials['password'] ?? '',
            $user->getAuthPassword()
        )) {
            $this->login($user, $remember);
            return true;
        }
        
        return false;
    }

    public function logout(): void
    {
        $user = $this->user();
        
        if (!is_null($user)) {
            $this->updateRememberToken($user, '');
        }
        
        $this->session->destroy();
        $this->cookie->delete('remember_token');
        
        $this->user = null;
        $this->loggedOut = true;
    }

    public function login(Authenticatable $user, bool $remember = false): void
    {
        $this->setUser($user);
        
        if ($remember) {
            $this->createRememberToken($user);
        }
    }

    private function retrieveById(mixed $id): ?Authenticatable
    {
        $userData = $this->queryBuilder
            ->where('id', '=', $id)
            ->first();
        
        if ($userData) {
            return new $this->userModel($userData);
        }
        
        return null;
    }

    private function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $query = $this->queryBuilder;
        
        foreach ($credentials as $key => $value) {
            if ($key !== 'password') {
                $query->where($key, '=', $value);
            }
        }
        
        $userData = $query->first();
        
        if ($userData) {
            return new $this->userModel($userData);
        }
        
        return null;
    }

    private function retrieveByRememberToken(): ?Authenticatable
    {
        $rememberToken = $this->cookie->get('remember_token');
        
        if (!$rememberToken) {
            return null;
        }
        
        $parts = explode('|', $rememberToken);
        
        if (count($parts) !== 2) {
            return null;
        }
        
        [$id, $token] = $parts;
        
        $userData = $this->queryBuilder
            ->where('id', '=', $id)
            ->where('remember_token', '=', $token)
            ->first();
        
        if ($userData) {
            $user = new $this->userModel($userData);
            
            // Check if token hasn't expired
            $expires = $user->getAttribute('remember_token_expires', 0);
            
            if ($expires > time()) {
                return $user;
            }
        }
        
        return null;
    }

    private function hasRememberToken(): bool
    {
        return $this->cookie->has('remember_token');
    }

    private function createRememberToken(Authenticatable $user): void
    {
        $token = bin2hex(random_bytes(32));
        $expires = time() + (30 * 24 * 60 * 60); // 30 days
        
        $user->setRememberToken($token);
        $user->setAttribute('remember_token_expires', $expires);
        
        if (method_exists($user, 'save')) {
            $user->save();
        }
        
        $cookieValue = $user->getAuthIdentifier() . '|' . $token;
        $this->cookie->set('remember_token', $cookieValue, $expires);
    }

    private function updateRememberToken(Authenticatable $user, string $token): void
    {
        $user->setRememberToken($token);
        
        if (method_exists($user, 'save')) {
            $user->save();
        }
    }
}