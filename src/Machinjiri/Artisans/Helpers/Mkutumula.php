<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Helpers;
use Mlangeni\Machinjiri\Core\Container;
class Mkutumula
{
    private $basePath;
    
    public function __construct() {
      // Base path for all generated files
      $path = Container::$appBasePath . '/../app/';
      
      if (!is_dir($path)) {
        // try terminal path
        $path = './app/';
      }
      $this->basePath = $path;
    }
    
    public function create (string $className, string $type = "controller") : bool {
      if (!empty($className)) {
        switch (strtolower($type)) {
          case 'controller':
            return $this->createController($className);
          case 'model':
            return $this->createModel($className);
          case 'middleware':
            return $this->createMiddleware($className);
          default:
            return false;
        }
      }
      return false;
    }
      
    private function createModel(string $className): bool
    {
        $namespace = 'Mlangeni\Machinjiri\App\Model';
        $filePath = $this->basePath . 'Model/' . $className . '.php';
        $uses = 'Mlangeni\Machinjiri\Core\Database\QueryBuilder';
        
        $template = <<<EOT
<?php

namespace $namespace;

use $uses;

class $className extends QueryBuilder
{
    // Model implementation
}
EOT;

        return $this->saveFile($filePath, $template);
    }

    private function createMiddleware(string $className): bool
    {
        $namespace = 'Mlangeni\Machinjiri\App\Middleware';
        $filePath = $this->basePath . 'Middleware/' . $className . '.php';
        
        $template = <<<EOT
<?php

namespace $namespace;

use Mlangeni\Machinjiri\Core\Authentication\Session;
use Mlangeni\Machinjiri\Core\Authentication\Cookies;
use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;

class $className
{
    // Middleware implementation
    public function index (array \$params, callable \$next) 
    {
      // add logic here
      
      // return next route
      return \$next;
    }
}
EOT;

        return $this->saveFile($filePath, $template);
    }

    private function createController(string $className): bool
    {
        $namespace = 'Mlangeni\Machinjiri\App\Controllers';
        $filePath = $this->basePath . 'Controllers/' . $className . '.php';
        
        $template = <<<EOT
<?php

namespace $namespace;
use Mlangeni\Machinjiri\Core\Views\View;
class $className
{
    // Controller implementation
    public function index() : void {
      View::make('welcome')->display();
    }
}
EOT;

        return $this->saveFile($filePath, $template);
    }

    private function saveFile(string $path, string $content): bool
    {
        $directory = dirname($path);
        
        // Create directory if it doesn't exist
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        
        // Write file content
        if (!is_file($path)) {
          return (bool) file_put_contents($path, $content);
        }
        return false;
        
    }
}