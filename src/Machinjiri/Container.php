<?php

namespace Mlangeni\Machinjiri\Core;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Routing\DotEnv;

class Container {
  
  public const FRAMEWORK_BASE = __DIR__ . "/../../";
  
  protected function getConfigurations () : array {
    $dir = self::FRAMEWORK_BASE . "config/";
    $app = $dir . "app.php";
    $database = $dir . "database.php";
    
    if (!is_dir($dir)) {
      @mkdir($dir, 0777);
    }
    
    if (!is_file($app) && !$this->dotEnv()) {
      throw new MachinjiriException("App configuration error", 1001410);
    }
    
    if (!is_file($database) && !$this->dotEnv()) {
      throw new MachinjiriException("Database configuration error", 1001411);
    }
    
    $app = include $app;
    $database = include $database;
    if (!is_array($app)) {
      $app = [
        "name" => $this->dotEnv()["APP_NAME"],
        "version" => $this->dotEnv()["APP_VERSION"],
        "key" => $this->dotEnv()["APP_DEBUG"],
        "env" => $this->dotEnv()["APP_ENV"],
        "url" => $this->dotEnv()["APP_URL"],
        ];
    }
    
    if (!is_array($database) || count($database) < 1 || empty($database['driver'])) {
      $database = [
        "driver" => $this->dotEnv()["DB_CONNECTION"],
        "host" => $this->dotEnv()["DB_HOST"],
        "username" => $this->dotEnv()["DB_USERNAME"],
        "password" => $this->dotEnv()["DB_PASSWORD"],
        "database" => $this->dotEnv()["DB_DATABASE"],
        "port" => $this->dotEnv()["DB_PORT"],
        "path" => isset($this->dotEnv()["DB_PATH"]) ?$this->dotEnv()["DB_PORT"]: "",
        "dsn" => isset($this->dotEnv()["DB_DSN"]) ?$this->dotEnv()["DB_DSN"]: ""
        ];
    }
    
    return ["app" => $app, "database" => $database];

  }
  
  protected function dotEnv () : bool|array {
    $dotEnv = new DotEnv(Container::FRAMEWORK_BASE);
    $dotEnv->load();
    if (count($dotEnv->getVariables()) > 0) {
      return $dotEnv->getVariables();
    }
    return false;
  }
  
  protected function routes () : void {
    $dir = self::FRAMEWORK_BASE . "routes/";
    $web = $dir . "web.php";
    $api = $dir . "api.php";
    $console = $dir . "console.php";
    
    if (!is_dir($dir)) {
      @mkdir($dir, 0777);
    }
    
    if (!is_file($web)) {
      throw new MachinjiriException("Web Routing file not found Error", 1001413);
    }
    
    if (!is_file($api)) {
      throw new MachinjiriException("API Routing File not found", 1001414);
    }
    
    if (!is_file($console)) {
      throw new MachinjiriException("Console Routing File not found", 1001415);
    }
    
    require $web; require $api; require $console;
  }
  
  public static function routingBase () : string {
    return "/Mlangeni/public";
  }
  
  
}