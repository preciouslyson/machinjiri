<?php

namespace Mlangeni\Machinjiri\Core;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Routing\DotEnv;
use Mlangeni\Machinjiri\Core\Artisans\Helpers\EnvFileWriter;
use Mlangeni\Machinjiri\Core\Artisans\Helpers\Mkutumula;

class Container {
  
  public static $appBasePath;
  protected $storage;
  protected $routing;
  protected $routes;
  protected $database;
  protected $resources;
  protected $app;
  protected $config;
  
  protected function __construct (string $appBasePath) {
    self::$appBasePath = rtrim($appBasePath, DIRECTORY_SEPARATOR);
  }
  
  protected function _init () : void {
    if (!is_dir(self::$appBasePath)) {
      throw new MachinjiriException("Specify Base");
    }
    $this->routes = $this->root() . "routes/";
    $this->resources = $this->root() . "resources/";
    $this->database = $this->root() . "database/";
    $this->storage = $this->root() . "storage/";
    $this->routing = $this->root() . "public/";
    $this->app = $this->root() . "app/";
    $this->config = $this->root() . "config/";
    
    if (!is_dir($this->routes)) {
      @mkdir($this->routes, 0777);
    }
    
    if (!is_dir($this->resources)) {
      @mkdir($this->resources, 0777);
    }
    
    if (!is_dir($this->database)) {
      @mkdir($this->database, 0777);
    }
    
    if (!is_dir($this->storage)) {
      @mkdir($this->storage, 0777);
    }
    
    if (!is_dir($this->app)) {
      @mkdir($this->app, 0777);
      $this->createAppDirs();
    }
    
    if (!is_dir($this->config)) {
      @mkdir($this->config, 0777);
    }
    
  }
  
  protected function root () : string {
    return self::$appBasePath . "/../";
  }
  
  public function getConfigurations () : array {
    $dir = $this->root() . "config/";
    $app = $dir . "app.php";
    $database = $dir . "database.php";
    
    if (!is_dir($dir)) {
      @mkdir($dir, 0777);
    }
    
    if (!is_file($app) && !$this->dotEnv()) {
      throw new MachinjiriException("App configuration error. Due to empty or unreadable environment file or no <strong>app</strong> configuration script in config folder. Kindly setup.", 10110);
    }
    
    if (!is_file($database) && !$this->dotEnv()) {
      throw new MachinjiriException("Database configuration error. Due to empty or unreadable environment file or no <strong>database</strong> configuration script in config folder. Kindly setup.", 10111);
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
  
  public function dotEnv () : bool|array {
    $dotEnv = new DotEnv($this->root());
    $dotEnv->load();
    if (count($dotEnv->getVariables()) > 0) {
      return $dotEnv->getVariables();
    }
    return false;
  }
  
  protected function routes () : void {
    $dir = $this->root() . "routes/";
    $web = $dir . "web.php";
    $api = $dir . "api.php";
    
    if (!is_dir($dir)) {
      @mkdir($dir, 0777);
    }
    
    if (!is_file($web)) {
      @fopen($web, "w");
    }
    
    if (!is_file($api)) {
      @fopen($api, "w");
    }
    
    require $web; require $api;
    
  }
  
  public static function routingBase () : string {
    $a = explode(DIRECTORY_SEPARATOR, rtrim($this->root(), DIRECTORY_SEPARATOR));
    return DIRECTORY_SEPARATOR . end($a) . "/public";
  }
  
  public static function loggingBase () : string {
    return $this->root() . 'storage/logs/';
  }
  
  protected function createAppDirs () : void {
    @mkdir($this->app . "Controllers/", 0777);
    @mkdir($this->app . "Middleware/", 0777);
    @mkdir($this->app . "Model/", 0777);
  }
  
  protected function bootstrapEnv () : bool {
    return (new EnvFileWriter())->create($this->root() . ".env");
  }
  
  protected function boot () : void {
    $mkutumula = new Mkutumula();
    $mkutumula->create("HomeController");
    @mkdir($this->resources . 'views/', 0777);
    @mkdir($this->resources . 'views/layouts/', 0777);
    @mkdir($this->resources . 'views/partials/', 0777);
    if (!is_file($this->resources . 'views/welcome.php')) {
      @fopen($this->resources . 'views/welcome.php', "w");
      $template = <<<EOT
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Machinjiri</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: Arial, sans-serif; line-height: 1.6; background: #f4f4f4; color: #333; }
    header, footer { background: #222; color: #fff; padding: 20px 0; text-align: center; }
    .container { max-width: 960px; margin: auto; padding: 20px; }
    .hero { text-align: center; padding: 80px 20px; background: #eaeaea; }
    .hero h1 { font-size: 3em; margin-bottom: 10px; }
    .hero p { font-size: 1.2em; margin-bottom: 20px; }
    .btn { display: inline-block; padding: 10px 20px; background: #007bff; color: #fff; text-decoration: none; border-radius: 4px; }
    .features { display: flex; flex-wrap: wrap; gap: 20px; margin-top: 40px; }
    .feature { flex: 1; min-width: 280px; background: #fff; padding: 20px; border-radius: 6px; box-shadow: 0 0 5px rgba(0,0,0,0.1); }
    .feature h3 { margin-bottom: 10px; }
    .cta { text-align: center; margin: 60px 0; }
    @media (max-width: 600px) {
      .hero h1 { font-size: 2em; }
      .features { flex-direction: column; }
    }
  </style>
</head>
<body>

  <header>
    <div class="container">
      <h2>Mlangeni Framework</h2>
      <p>Built for clarity, speed, and scale</p>
    </div>
  </header>

  <section class="hero">
    <div class="container">
      <h1>Welcome to Mlangeni</h1>
      <p>A modular PHP framework designed for developers who demand precision and performance.</p>
      <a href="#features" class="btn">Explore Features</a>
    </div>
  </section>

  <section class="container" id="features">
    <div class="features">
      <div class="feature">
        <h3>⚙️ Modular Design</h3>
        <p>Each class lives in its own namespace and folder. Clean, scalable, and PSR-4 compliant.</p>
      </div>
      <div class="feature">
        <h3>🔌 API Integration</h3>
        <p>Built-in support for RESTful routing, request/response handling, and real-world APIs.</p>
      </div>
      <div class="feature">
        <h3>📦 Composer Autoloading</h3>
        <p>Autoloading and dependency management with zero configuration friction.</p>
      </div>
    </div>
  </section>

  <section class="cta">
    <div class="container">
      <h2>Ready to build?</h2>
      <p>Start your next PHP project with a framework that’s built for maintainability and scale.</p>
      <a href="/docs" class="btn">Read the Docs</a>
    </div>
  </section>

  <footer>
    <div class="container">
      <p>&copy; <?= date('Y') ?> Mlangeni Technologies. All rights reserved.</p>
    </div>
  </footer>

</body>
</html>
EOT;
      file_put_contents($this->resources . 'views/welcome.php' ,$template);
    }
    
  }
  
}