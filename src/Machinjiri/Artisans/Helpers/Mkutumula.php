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
        $path = Container::$terminalBase . 'app/';
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
          case 'job':
            return $this->createJob($className);
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

class $className
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

use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;

class $className
{
    // Middleware implementation
    public function handle (HttpRequest \$request, HttpResponse \$response, callable \$next, array \$params = []) 
    {
      // add logic here
      
      // return next route
      return \$next(\$params);
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

    private function createJob(string $className): bool
    {
        $namespace = 'Mlangeni\Machinjiri\App\Jobs';
        $filePath = $this->basePath . 'Jobs/' . $className . '.php';
        
        $template = <<<EOT
<?php

namespace $namespace;

use Mlangeni\Machinjiri\Core\Artisans\Queuing\JobQueue;
use Mlangeni\Machinjiri\Core\Artisans\Queuing\Progressable;

class $className implements Progressable
{
    protected \$queue;
    protected \$jobId;
    
    /**
     * Set the queue instance for progress tracking
     */
    public function setQueue(JobQueue \$queue)
    {
        \$this->queue = \$queue;
    }
    
    /**
     * Set the job ID for progress tracking
     */
    public function setJobId(int \$jobId)
    {
        \$this->jobId = \$jobId;
    }
    
    /**
     * Handle the job execution
     */
    public function handle(array \$data)
    {
        // Implement your job logic here
        // Example:
        // \$this->processData(\$data);
        
        // Update progress (if needed)
        // \$this->queue->updateProgress(\$this->jobId, 50, ['status' => 'Processing']);
        
        return true;
    }
    
    /**
     * Example method for processing data
     */
    protected function processData(array \$data)
    {
        // Add your data processing logic here
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