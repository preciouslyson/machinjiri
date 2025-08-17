<?php
namespace Mlangeni\Machinjiri\Core\Authentication;

class Cookie {
  
  private const defaultPath = __DIR__ ."/../../../storage/cookies/";

  public function set($name, $value, $expire = 0, $path = '/', $domain = '', $secure = false, $httponly = true) {
    setcookie($name, $value, [
      'expires' => $expire === 0 ? 0 : time() + $expire,
      'path' => $path = (empty($path)) ? self::defaultPath : $path,
      'domain' => $domain,
      'secure' => $secure,
      'httponly' => $httponly,
      'samesite' => 'Lax' // Strict or Lax
    ]);
  }

  public function get($name, $default = null) {
    return $_COOKIE[$name] ?? $default;
  }

  public function delete($name, $path = '/', $domain = '') {
    $this->set($name, '', time() - 3600, $path, $domain);
  }

  public function has($name) {
    return isset($_COOKIE[$name]);
  }
}