<?php

namespace Mlangeni\Machinjiri\Core\Authentication;

class Session {
    public function __construct() {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function set($key, $value) {
        $_SESSION[$key] = $value;
    }

    public function get($key, $default = null) {
        return $_SESSION[$key] ?? $default;
    }

    public function remove($key) {
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }

    public function destroy() {
        session_unset();
        session_destroy();
    }

    public function regenerateId() {
        session_regenerate_id(true);
    }

    public function has($key) {
        return isset($_SESSION[$key]);
    }
}