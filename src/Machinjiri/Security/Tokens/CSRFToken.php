<?php

namespace Mlangeni\Machinjiri\Core\Security\Tokens;

use Mlangeni\Machinjiri\Core\Authentication\Session;
use Mlangeni\Machinjiri\Core\Authentication\Cookie;

class CSRFToken
{
    private const DEFAULT_TOKEN_NAME = 'csrf_token';
    private const TOKEN_LENGTH = 32;
    private const COOKIE_NAME = 'csrf_cookie';

    private string $tokenName;
    private Session $session;
    private Cookie $cookie;

    public function __construct(
        Session $session,
        Cookie $cookie,
        ?string $tokenName = null
    ) {
        $this->session = $session;
        $this->cookie = $cookie;
        $this->tokenName = $tokenName ?? self::DEFAULT_TOKEN_NAME;
    }

    public function generateToken(): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));
        $this->session->set($this->tokenName, $token);
        return $token;
    }

    public function getToken(): string
    {
        if (!$this->session->has($this->tokenName)) {
            return $this->generateToken();
        }
        return $this->session->get($this->tokenName);
    }

    public function validateToken(string $token): bool
    {
        if (!$this->session->has($this->tokenName)) {
            return false;
        }
        
        $storedToken = $this->session->get($this->tokenName);
        return hash_equals($storedToken, $token);
    }

    public function generateTokenWithCookie(): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));
        $this->session->set($this->tokenName, $token);
        $this->cookie->set(self::COOKIE_NAME, $token, 0, '/', '', true, true);
        return $token;
    }

    public function validateTokenWithCookie(string $token): bool
    {
        $sessionToken = $this->session->get($this->tokenName);
        $cookieToken = $this->cookie->get(self::COOKIE_NAME);

        if (!$sessionToken || !$cookieToken) {
            return false;
        }

        return hash_equals($sessionToken, $token) && 
               hash_equals($cookieToken, $token);
    }
}