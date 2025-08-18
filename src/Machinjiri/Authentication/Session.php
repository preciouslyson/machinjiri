<?php

namespace Mlangeni\Machinjiri\Core\Authentication;

class Session {
    private $timeout = 1800; // 30 minutes

    public function __construct() {
        if (session_status() == PHP_SESSION_NONE) {
            $this->configureSession();
            session_start();
            $this->initializeSession();
        }
    }

    private function configureSession() {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => $_SERVER['HTTP_HOST'],
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
        ini_set('session.use_strict_mode', '1');
    }

    private function initializeSession() {
        if (!isset($_SESSION['_created'])) {
            $_SESSION['_created'] = time();
            $_SESSION['_fingerprint'] = $this->generateFingerprint();
        }
        $this->validateSession();
    }

    private function validateSession() {
        $this->checkTimeout();
        $this->validateFingerprint();
    }

    public function set($key, $value) {
      if (preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
          $_SESSION[$key] = $value;
      }
    }
    
    public function get($key, $default = null) {
        if (isset($_SESSION[$key])) {
            return $this->sanitize($_SESSION[$key]);
        }
        return $default;
    }

    private function sanitize($data) {
        return is_array($data) 
            ? array_map([$this, 'sanitize'], $data) 
            : htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }
    

    // Helper methods
    private function generateFingerprint() {
        return hash('sha256', $_SERVER['HTTP_USER_AGENT'] . ip2long($_SERVER['REMOTE_ADDR']));
    }
    
    private function validateFingerprint() {
        if ($_SESSION['_fingerprint'] !== $this->generateFingerprint()) {
            $this->destroy();
            throw new \Exception('Session validation failed');
        }
    }
    
    public function regenerateId() {
        session_regenerate_id(true);
        $_SESSION['_created'] = time(); // Track regeneration time
    }
  
}