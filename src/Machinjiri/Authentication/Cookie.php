<?php
namespace Mlangeni\Machinjiri\Core\Authentication;

use Mlangeni\Machinjiri\Core\Container;

class Cookie {
  
  private static $defaultPath;
  
  public function __construct () {
    self::$defaultPath = Container::$appBasePath . "/../storage/cookies/";
  }

  public function set(string $name, mixed $value, int $expire = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httponly = true): void {
    setcookie($name, (string)$value, [
      'expires' => $expire === 0 ? 0 : time() + $expire,
      'path' => !empty($path) ? $path : self::$defaultPath,
      'domain' => $domain,
      'secure' => $secure,
      'httponly' => $httponly,
      'samesite' => 'Lax'
    ]);
  }

  public function get(string $name, mixed $default = null): mixed {
    return $_COOKIE[$name] ?? $default;
  }

  public function delete(string $name, string $path = '/', string $domain = ''): void {
    $this->set($name, '', time() - 3600, $path, $domain);
  }

  public function has(string $name): bool {
    return isset($_COOKIE[$name]);
  }
}