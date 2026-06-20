<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Generators;

use Mlangeni\Machinjiri\Core\Container;

class ResourceGenerator
{
    private $basePath;
    
    public function __construct() 
    {
        // Base path for all generated files
        $path = Container::$appBasePath . '/../app/';
        
        if (!is_dir($path)) {
            // try terminal path
            $path = Container::$terminalBase . 'app/';
        }
        $this->basePath = $path;
    }
    
    public function create(string $className, string $type = "controller"): bool 
    {
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
    
    /**
     * Generate a Model class extending AbstractModel
     */
    private function createModel(string $className): bool
    {
        $namespace = 'Mlangeni\\Machinjiri\\App\\Models';
        $filePath = $this->basePath . 'Models/' . $className . '.php';
    
        $template = <<<EOT
<?php

namespace $namespace;

use Mlangeni\\Machinjiri\\Core\\Artisans\\Base\\AbstractModel;
use Mlangeni\\Machinjiri\\Core\\Date\\DateTimeHandler;

class $className extends AbstractModel
{
    /**
     * The table associated with the model.
     */
    protected string \$table = '{$this->tableName($className)}';

    /**
     * The primary key for the model.
     */
    protected string \$primaryKey = 'id';

    /**
     * The primary key data type (int, string, etc.)
     */
    protected string \$keyType = 'int';

    /**
     * Indicates if the primary key is auto-incrementing.
     */
    public bool \$incrementing = true;

    /**
     * The attributes that are mass assignable.
     */
    protected array \$fillable = [
        // 'column_name',
    ];

    /**
     * The attributes that are not mass assignable.
     * Default ['*'] blocks all mass assignment unless fillable is defined.
     */
    protected array \$guarded = ['*'];

    /**
     * The attributes that should be cast to native types or DateTimeHandler.
     * Supported casts: int, float, string, bool, array, json, object, datetime, date, timestamp.
     */
    protected array \$casts = [
        // 'created_at' => 'datetime',
        // 'updated_at' => 'datetime',
        // 'deleted_at' => 'datetime',
        // 'is_active'  => 'boolean',
        // 'metadata'   => 'array',
    ];

    /**
     * The storage format for datetime fields.
     */
    protected string \$dateFormat = 'Y-m-d H:i:s';

    /**
     * The timezone for datetime handling.
     */
    protected string \$timezone = 'UTC';

    /**
     * Indicates if the model should be timestamped.
     */
    protected bool \$timestamps = true;

    /**
     * Indicates if the model uses soft deletes.
     */
    protected bool \$softDelete = false;

    /**
     * Enable query caching for this model.
     */
    protected static bool \$cacheEnabled = false;

    /**
     * Cache TTL in seconds (null uses default).
     */
    protected static ?int \$cacheTtl = null;

    /**
     * Cache tags for this model.
     */
    protected static array \$cacheTags = [];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    // Example:
    // public function posts()
    // {
    //     return \$this->hasMany(Post::class, 'user_id');
    // }

    // -------------------------------------------------------------------------
    // Custom Methods
    // -------------------------------------------------------------------------
}
EOT;
    
        return $this->saveFile($filePath, $template);
    }
    
    /**
     * Generate a Middleware class extending AbstractMiddleware
     */
    private function createMiddleware(string $className): bool
    {
        $namespace = 'Mlangeni\\Machinjiri\\App\\Middleware';
        $filePath = $this->basePath . 'Middleware/' . $className . '.php';
        
        $template = <<<EOT
<?php

namespace $namespace;

use Mlangeni\\Machinjiri\\Core\\Artisans\\Base\\AbstractMiddleware;
use Mlangeni\\Machinjiri\\Core\\Http\\HttpRequest;
use Mlangeni\\Machinjiri\\Core\\Http\\HttpResponse;

class $className extends AbstractMiddleware
{
    /**
     * Process the incoming request.
     *
     * @param HttpRequest  \$request
     * @param HttpResponse \$response
     * @param callable     \$next
     * @param array        \$params
     * @return mixed
     */
    public function handle(
        HttpRequest \$request,
        HttpResponse \$response,
        callable \$next,
        array \$params = []
    ) {
        // Your middleware logic here.
        // Example: authentication, logging, CORS, etc.
        
        // Call the next middleware / controller
        return \$next(\$params);
    }
    
    /**
     * Optional: Perform actions after the response is sent.
     *
     * @param HttpRequest  \$request
     * @param HttpResponse \$response
     * @return void
     */
    public function terminate(HttpRequest \$request, HttpResponse \$response): void
    {
        // Cleanup, logging, etc.
    }
}
EOT;
        return $this->saveFile($filePath, $template);
    }
    
    /**
     * Generate a Controller class extending AbstractController
     */
    private function createController(string $className): bool
    {
        $namespace = 'Mlangeni\\Machinjiri\\App\\Controllers';
        $filePath = $this->basePath . 'Controllers/' . $className . '.php';
        
        $template = <<<EOT
<?php

namespace $namespace;

use Mlangeni\\Machinjiri\\Core\\Artisans\\Base\\AbstractController;
use Mlangeni\\Machinjiri\\Core\\Http\\HttpRequest;
use Mlangeni\\Machinjiri\\Core\\Http\\HttpResponse;

class $className extends AbstractController
{
    /**
     * Display the welcome page.
     *
     * @param HttpRequest  \$request
     * @param HttpResponse \$response
     * @return string|HttpResponse
     */
    public function index(HttpRequest \$request, HttpResponse \$response)
    {
        // Example: render a view
        return \$this->view('welcome');
    }
    
    // Add your custom methods below
}
EOT;
        return $this->saveFile($filePath, $template);
    }
    
    /**
     * Helper: convert class name to snake_case plural table name.
     *
     * @param string $className
     * @return string
     */
    private function tableName(string $className): string
    {
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $className));
        return $snake . 's';
    }
    
    /**
     * Save file content to disk.
     *
     * @param string $path
     * @param string $content
     * @return bool
     */
    private function saveFile(string $path, string $content): bool
    {
        $directory = dirname($path);
        
        // Create directory if it doesn't exist
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        
        // Write file content only if file does not already exist
        if (!is_file($path)) {
            return (bool) file_put_contents($path, $content);
        }
        return false;
    }
}