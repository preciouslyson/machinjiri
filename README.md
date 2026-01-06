*Machinjiri PHP Framework*

Machinjiri is a lightweight, flexible PHP framework for rapid web development. It features a modular architecture, simple routing, database abstraction, and built-in security. Designed for speed and scalability, Machinjiri empowers developers to build robust applications efficiently.

*Table of Contents*
- #Introduction
- #Features
- #Installation
- #Usage
- #Error Handling
- #Migrations
- #Dependancies
- #Contributing
- #License

*Introduction*
Machingjiri is designed to accelerate web development with a modular architecture, simple routing, and database abstraction.

*Features*

Core Architecture

· Singleton Application Container: Centralized management of application resources and configuration
· Service Container: Dependency injection with service binding and resolution
· Provider System: Modular service providers with deferred loading capabilities
· Configuration Management: Environment-aware config loading with .env support

Routing System

· Flexible HTTP Routing: RESTful routes with GET, POST, PUT, DELETE methods
· Route Groups: Middleware, prefix, and CORS configuration groups
· AJAX Handling: Built-in AJAX-only and traditional route segregation
· Named Routes: Generate URLs using route names
· CORS Support: Automatic CORS header handling and preflight requests
· Rate Limiting: Configurable request rate limiting
· Route Caching: Performance optimization through route caching

View Engine

· Template Inheritance: Blade-like template inheritance with layouts and sections
· Custom Template Tags: <% content %>, <% section() %>, <% extend() %>, etc.
· Partial Includes: Component-based development with fragment includes
· Asset Management: Automatic CSS/JS resource loading
· Data Sharing: Share data across multiple views

Database & ORM

· Database Connection: Multi-driver support with environment configuration
· Migrations: Built-in migration system for database schema management
· Seeders & Factories: Database seeding and model factories

Error Handling

· Global Error Handler: Environment-specific error reporting
· Custom Exceptions: Framework-specific exceptions with user-friendly display
· Logging: Structured logging with multiple channels (database, events, general)

HTTP Layer

· Request/Response Objects: Object-oriented HTTP request and response handling
· Middleware Support: Stack-based middleware pipeline
· Header Management: Easy header manipulation and CORS configuration


*Installation*
Use Machinjiri Installer by running the following command

```bash

composer global require preciouslyson/installer

```
After a successful installation create your project by running 

```bash

machinjiri new my-project

cd my-project

php artisan server:start

```

*Usage Examples*

Basic Routing

```php
// routes/web.php

use Mlangeni\Machinjiri\Core\Routing\Router;

// Simple GET route
Router::get('/', function($req, $res) {
    return 'Welcome to Machinjiri!';
});

// Named route with parameters
Router::get('/user/{id}', function($req, $res, $id) {
    return "User ID: {$id}";
}, 'user.profile');

// Controller route
Router::post('/login', 'AuthController@login', 'auth.login');

// Route groups
Router::group(['prefix' => '/admin', 'middleware' => 'auth'], function() {
    Router::get('/dashboard', 'AdminController@dashboard');
    Router::get('/users', 'AdminController@users');
});

// AJAX-only routes
Router::ajax('/api/data', 'ApiController@getData');

// Generate URLs
$url = Router::route('user.profile', ['id' => 123]);
```

Views & Templates

```php
// Controller or route handler
use Mlangeni\Machinjiri\Core\Views\View;

// Render a view
return View::make('welcome', ['name' => 'John'])->render();

// Or display directly
View::make('welcome', ['name' => 'John'])->display();
```

Layout Template (views/app.mg.layout.php):

```html
<!DOCTYPE html>
<html>
<head>
    <title><%= $title ?? 'Machinjiri' %></title>
    <% include 'partials/head' %>
</head>
<body>
    <% include 'partials/header' %>
    
    <% section('content') %>
        <!-- Default content if no section provided -->
    <% endsection %>
    
    <% include 'partials/footer' %>
</body>
</html>
```

View Template (views/welcome.mg.php):

```html
<% extend('app') %>

<% section('content') %>
    <h1>Welcome, <%= $name %>!</h1>
    <p>Current time: <%= date('Y-m-d H:i:s') %></p>
    
    <% if($is_admin): %>
        <p>Admin access granted</p>
    <% else: %>
        <p>Regular user</p>
    <% endif; %>
    
    <% foreach($users as $user): %>
        <li><%= $user['name'] %></li>
    <% endforeach; %>
<% endsection %>
```

Service Providers

```php
// app/Providers/AppServiceProvider.php
namespace Mlangeni\Machinjiri\App\Providers;

use Mlangeni\Machinjiri\Core\Providers\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register bindings
        $this->app->bind('mailer', function($app) {
            return new Mailer($app->config['mail']);
        });
    }
    
    public function boot(): void
    {
        // Boot logic
    }
}
```

Middleware

```php
// app/Middleware/AuthMiddleware.php
namespace Mlangeni\Machinjiri\App\Middleware;

class AuthMiddleware
{
    public function handle($request, $response, $next, $params)
    {
        if (!isset($_SESSION['user_id'])) {
            return $response->redirect('/login');
        }
        
        return $next($params);
    }
}
```

Project Structure

```
your-project/
├── app/
│   ├── Controllers/        # Application controllers
│   ├── Middleware/         # Custom Middlewares
│   ├── Models/             # Data models
│   └── Providers/          # Service Providers
├── bootstrap/
│   └── app.php             # Application bootstrap file
├── config/
│   ├── app.php             # Application configuration
│   └── providers.php       # Service providers
├── database/
│   ├── migrations/         # Database migrations
│   ├── seeders/            # Database seeders
│   └── factories/          # Model factories
├── resources/
│   └── views/              # View templates
│       ├── layouts/        # Layout Files
│       ├── partials/       # Fragments
│       └── welcome.mg.php  # Example View File
├── routes/
│   └── web.php             # Application routes
├── storage/
│   ├── cache/              # Cached files
│   ├── cookies/            # Cookie storage
│   ├── logs/               # Application logs
│   └── sessions/           # Session files
├── public/
│   ├── src/
│   │   ├── css/            # Stylesheets
│   │   └── js/             # JavaScript
│   └── index.php           # Front controller
├── vendor/                 # Composer Dependancies
├── .env                    # Environment File
├── artisan                 # Console Application Entry Point
└── phpunit.xml             # PHP Unit Testing Configuration
```

🔧 Configuration

Service Providers

```php
// config/providers.php
return [
    'providers' => [
        \Mlangeni\Machinjiri\App\Providers\AppServiceProvider::class,
        \Mlangeni\Machinjiri\App\Providers\AuthServiceProvider::class,
        \Mlangeni\Machinjiri\App\Providers\RouteServiceProvider::class,
    ],
];
```

Database

```php
// config/database.php
return [
    'driver' => env('DB_CONNECTION', 'mysql'),
    'host' => env('DB_HOST', 'localhost'),
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'database' => env('DB_DATABASE', 'machinjiri'),
    'port' => env('DB_PORT', 3306),
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ],
];
```

📚 API Reference

Container Methods

```php
// Get application instance
$app = Machinjiri::getInstance();

// Resolve a service
$service = $app->resolve('mailer');

// Check environment
$env = Machinjiri::getEnvironment(); // 'development' or 'production'
```

Router Static Methods

```php
// HTTP methods
Router::get($pattern, $handler, $name, $options);
Router::post($pattern, $handler, $name, $options);
Router::put($pattern, $handler, $name, $options);
Router::delete($pattern, $handler, $name, $options);
Router::any($pattern, $handler, $name, $options);

// Special routes
Router::ajax($pattern, $handler, $name, $options);
Router::traditional($pattern, $handler, $name, $options);

// Groups and middleware
Router::group($attributes, $callback);
Router::middleware($middleware, $callback);
Router::cors($config, $callback);

// URL generation
Router::route($name, $params);
Router::absoluteRoute($name, $params);

// Dispatch
Router::dispatch();
```

View Methods

```php
// Create views
View::make($view, $data);
View::share($key, $value);

// Template methods (used in views)
View::section($name, $content);
View::endSection();
View::yield($name);
View::extend($layout);
View::include($partial, $data);

// Asset loading
View::loadResource('css'); // Loads all CSS files
View::loadResource('js', 'app.js'); // Loads specific JS file
```

*🚨 Error Handling*

The framework provides built-in error handling with environment-specific displays:

```php
try {
    // Your code
} catch (MachinjiriException $e) {
    // Display error to user
    $e->show();
    
    // Or get error details
    $message = $e->getMessage();
    $code = $e->getCode();
}
```

*Testing*

Run unit tests:

```bash
cd tests/Unit
phpunit
```

*Migrations*

Run migrations:

```php
// Database migrations are run automatically on app initialization
// Manual migration handling is also available
```

*Dependencies*

· PHP 7.4 or higher
· PDO extension (for database support)
· Composer for dependency management

*Contributing*

1. Fork the repository
2. Create a feature branch (git checkout -b feature/amazing-feature)
3. Commit your changes (git commit -m 'Add amazing feature')
4. Push to the branch (git push origin feature/amazing-feature)
5. Open a Pull Request

*License*

This project is licensed under the MIT License - see the LICENSE file for details.

*Support*

· Documentation: [Coming Soon]
· Issues: GitHub Issues
· Discussions: GitHub Discussions

---

Built with ❤️ by Mlangeni Group
