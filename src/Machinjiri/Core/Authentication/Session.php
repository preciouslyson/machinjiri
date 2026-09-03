<?php

namespace Mlangeni\Machinjiri\Core\Authentication;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class Session {

    private int $timeout;
    private string $cookieName;
    private ?string $domain;
    private bool $secure;

    private Container $container;

    public function __construct(?Container $container = null) {

        $this->container = $container ?? (Container::instancePresent()) ? Container::getInstance() : null;
        // Load environment-based configurations
        $this->loadSessionConfig();
        
        if (session_status() == PHP_SESSION_NONE) {
            $this->configureSession();
            @session_start();
            $this->initializeSession();
        }

    }

    private function loadSessionConfig(): void {
        // Convert minutes to seconds for timeout
        $lifetimeMinutes = (int)(env('SESSION_LIFETIME') ?? 120);
        $this->timeout = $lifetimeMinutes * 60;
        
        $this->cookieName = env('SESSION_COOKIE') ?? 'machinjiri_session';
        $this->domain = env('SESSION_DOMAIN') ?? null;
        $this->secure = filter_var(env('SESSION_SECURE_COOKIE') ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function configureSession(): void {
        @session_name($this->cookieName);
        
        @session_set_cookie_params([
            'lifetime' => $this->timeout,
            'path' => $this->container->cookies,
            'domain' => $this->domain ?? ($_SERVER['HTTP_HOST'] ?? 'localhost'),
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
        @session_save_path($this->container->session);
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.gc_maxlifetime', $this->timeout);
    }

    private function initializeSession(): void {
        if (!isset($_SESSION['_created'])) {
            $_SESSION['_created'] = time();
            $_SESSION['_fingerprint'] = $this->generateFingerprint();
        }
        $this->validateSession();
    }

    private function validateSession(): void {
        $this->checkTimeout();
        $this->validateFingerprint();
    }

    public function set(string $key, mixed $value): void {
      if (str_starts_with($key, '_')) {
          throw new MachinjiriException("Session key cannot start with underscore (reserved)");
      }
      $_SESSION[$key] = $value;
    }
    
    public function get(string $key, mixed $default = null): mixed {
        return isset($_SESSION[$key]) ? $this->sanitize($_SESSION[$key]) : $default;
    }

    private function sanitize(mixed $data): mixed {
        return is_array($data) 
            ? array_map([$this, 'sanitize'], $data) 
            : htmlspecialchars((string)$data, ENT_QUOTES, 'UTF-8');
    }

    private function generateFingerprint(): string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return hash('sha256', $agent . $ip);
    }
    
    private function validateFingerprint(): void {
        if ($_SESSION['_fingerprint'] !== $this->generateFingerprint()) {
            $this->destroy();
            throw new MachinjiriException('Session validation failed');
        }
    }
    
    public function regenerateId(): void {
        session_regenerate_id(true);
        $_SESSION['_created'] = time();
    }
  
    private function checkTimeout(): void {
        if (isset($_SESSION['_created']) && time() - $_SESSION['_created'] > $this->timeout) {
            $this->destroy();
            throw new MachinjiriException('Session timeout');
        }
    }
    
    public function has(string $key): bool {
        return isset($_SESSION[$key]);
    }
    
    public function destroy(bool $restart = false): void {
      session_destroy();
      $_SESSION = [];
      if ($restart) {
          session_start();
          $this->initializeSession();
      }
    }

}