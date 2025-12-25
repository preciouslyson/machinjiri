<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Auth;

use Mlangeni\Machinjiri\Core\Authentication\Session;
use Mlangeni\Machinjiri\Core\Authentication\Cookie;
use Mlangeni\Machinjiri\Core\Security\Encryption\Cipher;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Database\QueryBuilder;
use Mlangeni\Machinjiri\Core\Security\Tokens\CSRFToken;

class Auth
{
    private Session $session;
    private Cookie $cookie;
    private Cipher $cipher;
    private Logger $logger;
    private QueryBuilder $queryBuilder;
    private CSRFToken $csrfToken;
    
    private string $userTable = 'users';
    private string $rememberTokenTable = 'user_remember_tokens';
    private string $sessionKey = 'auth_user';
    private string $rememberTokenKey = 'auth_remember';
    
    public function __construct(
        Session $session, 
        Cookie $cookie, 
        Cipher $cipher, 
        Logger $logger,
        QueryBuilder $queryBuilder,
        CSRFToken $csrfToken
    ) {
        $this->session = $session;
        $this->cookie = $cookie;
        $this->cipher = $cipher;
        $this->logger = $logger;
        $this->queryBuilder = $queryBuilder;
        $this->csrfToken = $csrfToken;
    }

    /**
     * Attempt to authenticate a user
     */
    public function attempt(array $credentials, bool $remember = false): bool
    {
        $username = $credentials['username'] ?? '';
        $email = $credentials['email'] ?? '';
        $password = $credentials['password'] ?? '';
        
        if (empty($password)) {
            $this->logger->warning('Authentication attempt with empty password');
            return false;
        }
        
        // Find user by username or email
        $userQuery = $this->queryBuilder
            ->select(['*'])
            ->from($this->userTable);
            
        if (!empty($username)) {
            $userQuery->where('username', '=', $username);
        } elseif (!empty($email)) {
            $userQuery->where('email', '=', $email);
        } else {
            $this->logger->warning('Authentication attempt without username or email');
            return false;
        }
        
        $user = $userQuery->first();
        
        if (!$user || !password_verify($password, $user['password'])) {
            $this->logger->warning('Failed authentication attempt', [
                'identifier' => $username ?: $email
            ]);
            return false;
        }
        
        if ($user['is_active'] == 0) {
            $this->logger->warning('Authentication attempt for inactive account', [
                'user_id' => $user['id']
            ]);
            return false;
        }
        
        // Regenerate session ID to prevent session fixation
        $this->session->regenerateId();
        
        // Store user in session (without password)
        unset($user['password']);
        $this->session->set($this->sessionKey, $user);
        
        // Set remember token if requested
        if ($remember) {
            $this->setRememberToken($user['id']);
        }
        
        $this->logger->info('User authenticated successfully', [
            'user_id' => $user['id']
        ]);
        
        return true;
    }

    /**
     * Set remember token for persistent login
     */
    private function setRememberToken(int $userId): void
    {
        $token = bin2hex(random_bytes(32));
        $hashedToken = password_hash($token, PASSWORD_DEFAULT);
        
        // Store hashed token in database
        $expiresAt = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60)); // 30 days
        
        $this->queryBuilder
            ->insert([
                'user_id' => $userId,
                'token' => $hashedToken,
                'expires_at' => $expiresAt,
                'created_at' => date('Y-m-d H:i:s')
            ])
            ->into($this->rememberTokenTable)
            ->execute();
        
        // Set encrypted token in cookie
        $encryptedToken = $this->cipher->encrypt($userId . '|' . $token);
        $this->cookie->set(
            $this->rememberTokenKey, 
            $encryptedToken, 
            time() + (30 * 24 * 60 * 60), // 30 days
            '/', 
            '', 
            true, 
            true
        );
    }

    /**
     * Check if a user is authenticated
     */
    public function check(): bool
    {
        // Check session first
        if ($this->session->has($this->sessionKey)) {
            return true;
        }
        
        // Check remember token if session doesn't exist
        return $this->attemptRememberedLogin();
    }

    /**
     * Attempt to login using remember token
     */
    private function attemptRememberedLogin(): bool
    {
        $rememberToken = $this->cookie->get($this->rememberTokenKey);
        
        if (!$rememberToken) {
            return false;
        }
        
        try {
            $decryptedToken = $this->cipher->decrypt($rememberToken);
            list($userId, $token) = explode('|', $decryptedToken, 2);
            
            if (empty($userId) || empty($token)) {
                throw new \Exception('Invalid token format');
            }
            
            // Find valid token in database
            $tokenRecord = $this->queryBuilder
                ->select(['*'])
                ->from($this->rememberTokenTable)
                ->where('user_id', '=', $userId)
                ->where('expires_at', '>', date('Y-m-d H:i:s'))
                ->first();
                
            if (!$tokenRecord || !password_verify($token, $tokenRecord['token'])) {
                $this->clearRememberToken($userId);
                return false;
            }
            
            // Get user data
            $user = $this->queryBuilder
                ->select(['id', 'username', 'email', 'is_active'])
                ->from($this->userTable)
                ->where('id', '=', $userId)
                ->where('is_active', '=', 1)
                ->first();
                
            if (!$user) {
                $this->clearRememberToken($userId);
                return false;
            }
            
            // Regenerate session and token
            $this->session->regenerateId();
            $this->session->set($this->sessionKey, $user);
            
            // Refresh remember token
            $this->clearRememberToken($userId);
            $this->setRememberToken($userId);
            
            $this->logger->info('User authenticated via remember token', [
                'user_id' => $userId
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Remember token authentication failed', [
                'error' => $e->getMessage()
            ]);
            $this->clearRememberCookie();
            return false;
        }
    }

    /**
     * Clear remember token from database and cookie
     */
    private function clearRememberToken(int $userId): void
    {
        $this->queryBuilder
            ->delete()
            ->from($this->rememberTokenTable)
            ->where('user_id', '=', $userId)
            ->execute();
            
        $this->clearRememberCookie();
    }

    /**
     * Clear remember cookie
     */
    private function clearRememberCookie(): void
    {
        $this->cookie->delete($this->rememberTokenKey);
    }

    /**
     * Get the authenticated user
     */
    public function user(): ?array
    {
        if (!$this->check()) {
            return null;
        }
        
        return $this->session->get($this->sessionKey);
    }

    /**
     * Get the authenticated user's ID
     */
    public function id(): ?int
    {
        $user = $this->user();
        return $user ? $user['id'] : null;
    }

    /**
     * Logout the current user
     */
    public function logout(): void
    {
        $user = $this->user();
        
        if ($user) {
            // Clear remember tokens
            $this->clearRememberToken($user['id']);
            
            $this->logger->info('User logged out', [
                'user_id' => $user['id']
            ]);
        }
        
        // Clear session
        $this->session->set($this->sessionKey, null);
        
        // Regenerate session ID for security
        $this->session->regenerateId();
    }

    /**
     * Validate CSRF token
     */
    public function validateCSRF(string $token): bool
    {
        return $this->csrfToken->validateToken($token);
    }

    /**
     * Generate CSRF token
     */
    public function generateCSRF(): string
    {
        return $this->csrfToken->generateToken();
    }

    /**
     * Update user's password
     */
    public function updatePassword(int $userId, string $newPassword): bool
    {
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        
        $result = $this->queryBuilder
            ->update(['password' => $hashedPassword])
            ->table($this->userTable)
            ->where('id', '=', $userId)
            ->execute();
            
        if ($result['rowCount'] > 0) {
            $this->logger->info('User password updated', [
                'user_id' => $userId
            ]);
            
            // Invalidate all remember tokens for security
            $this->clearRememberToken($userId);
            
            return true;
        }
        
        return false;
    }

    /**
     * Check if user has a specific role/permission
     * This assumes your users table has a roles relationship
     */
    public function hasRole(int $userId, string $role): bool
    {
        // This is a simplified implementation
        // You might want to implement a more robust role/permission system
        $userRole = $this->queryBuilder
            ->select(['r.name'])
            ->from('users as u')
            ->join('user_roles as ur', 'u.id', '=', 'ur.user_id')
            ->join('roles as r', 'ur.role_id', '=', 'r.id')
            ->where('u.id', '=', $userId)
            ->first();
            
        return $userRole && $userRole['name'] === $role;
    }

    /**
     * Record login attempt for rate limiting
     */
    public function recordLoginAttempt(string $identifier, bool $success = false): void
    {
        $this->queryBuilder
            ->insert([
                'identifier' => $identifier,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'success' => $success ? 1 : 0,
                'attempted_at' => date('Y-m-d H:i:s')
            ])
            ->into('login_attempts')
            ->execute();
    }

    /**
     * Check if too many login attempts have been made
     */
    public function tooManyAttempts(string $identifier, int $maxAttempts = 5, int $decayMinutes = 15): bool
    {
        $attempts = $this->queryBuilder
            ->select(['COUNT(*) as count'])
            ->from('login_attempts')
            ->where('identifier', '=', $identifier)
            ->where('ip_address', '=', $_SERVER['REMOTE_ADDR'] ?? 'unknown')
            ->where('attempted_at', '>', date('Y-m-d H:i:s', time() - ($decayMinutes * 60)))
            ->where('success', '=', 0)
            ->first();
            
        return $attempts && $attempts['count'] >= $maxAttempts;
    }
}