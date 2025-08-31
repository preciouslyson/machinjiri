<?php

namespace Mlangeni\Machinjiri\Core\Authentication;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
class Session {
    private int $timeout = 1800;

    public function __construct() {
        if (session_status() == PHP_SESSION_NONE) {
            $this->configureSession();
            session_start();
            $this->initializeSession();
        }
    }

    private function configureSession(): void {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => Container::$appBasePath . "/../storage/session/";,
            'domain' => $_SERVER['HTTP_HOST'] ?? 'localhost',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
        ini_set('session.use_strict_mode', '1');
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
        if (preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
            $_SESSION[$key] = $value;
        }
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

    public function destroy(): void {
        session_destroy();
        $_SESSION = [];
    }
}