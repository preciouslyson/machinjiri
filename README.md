# Machinjiri PHP Framework

Machinjiri is a **lightweight, flexible, and powerful PHP framework** designed for rapid web development. Built with modern PHP 8.2+ principles, it provides a modular architecture, elegant routing system, comprehensive database abstraction, authentication & authorization, and robust security features. Designed for speed, scalability, and developer experience, Machinjiri empowers developers to build secure, maintainable applications efficiently.

## Table of Contents

- [Introduction](#introduction)
- [Features](#features)
- [System Requirements](#system-requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Project Structure](#project-structure)
- [Core Components](#core-components)
  - [Routing](#routing-system)
  - [Views & Templates](#view-engine)
  - [Database](#database--orm)
  - [Authentication & Security](#authentication--security)
  - [Forms & Validation](#forms--validation)
  - [Queues & Jobs](#queues--jobs)
  - [Components](#components)
- [Usage Examples](#usage-examples)
- [Configuration](#-configuration)
- [API Reference](#-api-reference)
- [Error Handling](#-error-handling)
- [Testing](#testing)
- [Console Commands (Artisan)](#console-commands)
- [Contributing](#contributing)
- [License](#license)
- [Support](#support)

## Introduction

Machinjiri is designed to accelerate web development with:
- **Modular architecture** with service providers and dependency injection
- **Elegant routing** with middleware, groups, and named routes
- **Powerful view templating** with inheritance and asset management
- **Multi-database support** with migrations and query builders
- **Built-in authentication** with OAuth, sessions, and cookies
- **Advanced security** with encryption, hashing, CSRF tokens, and SQL injection prevention
- **Job queues** for background processing
- **Comprehensive logging** and error handling

## Features

### Core Architecture

- **Singleton Application Container**: Centralized management of application resources and configuration
- **Service Container**: Powerful dependency injection with service binding and resolution
- **Provider System**: Modular service providers with lazy loading and bootstrapping
- **Configuration Management**: Environment-aware config loading with `.env` support
- **Service Facades**: Quick access to complex services with simple syntax

### Routing System

- **Flexible HTTP Routing**: RESTful routes with GET, POST, PUT, DELETE, PATCH methods
- **Route Groups**: Apply middleware, prefixes, and CORS to multiple routes
- **Route Parameters**: Capture and validate route parameters
- **AJAX Handling**: Built-in AJAX-only and traditional route segregation
- **Named Routes**: Generate URLs using route names and parameters
- **CORS Support**: Automatic CORS header handling and preflight requests
- **Rate Limiting**: Configurable request rate limiting per route
- **Route Caching**: Performance optimization through route caching

### View Engine

- **Template Inheritance**: Blade-like template inheritance with layouts and sections
- **Custom Template Tags**: `<% %>` syntax with PHP logic support
- **Partial Includes**: Component-based development with fragment includes
- **Asset Management**: Automatic CSS/JS resource loading
- **Data Sharing**: Share data across multiple views globally
- **Loop Directives**: Enhanced foreach loops with context variables

### Database & ORM

- **Multi-Driver Support**: MySQL, PostgreSQL, SQLite database drivers
- **Query Builder**: Expressive, fluent query construction
- **Migrations**: Built-in migration system for database schema management
- **Schema Builder**: Create, modify, and drop tables programmatically
- **Seeders & Factories**: Database seeding and model factories for testing
- **Connection Pool**: Manage multiple database connections
- **Transaction Support**: ACID compliance with transaction handling

### Authentication & Security

- **Session Management**: Secure session handling with configurable drivers
- **OAuth Integration**: Third-party authentication (Google, GitHub, etc.)
- **Cookie Management**: Secure cookie handling with encryption options
- **Password Hashing**: Built-in password hashing with bcrypt/Argon2
- **CSRF Token Protection**: Automatic CSRF token generation and validation
- **SQL Injection Prevention**: Parameterized queries via query builder
- **Encryption**: AES encryption/decryption for sensitive data
- **JWT Tokens**: JSON Web Token support for API authentication

### Forms & Validation

- **Form Validation**: Comprehensive validation rules engine
- **Rule Builder**: Fluent interface for building complex validation rules
- **Password Rules**: Special validation rules for password strength
- **Error Messages**: Custom error messages and localization support
- **Form Handler**: Server-side form processing and CSRF protection

### Queues & Jobs

- **Background Job Processing**: Defer heavy operations to background queues
- **Database Queue Driver**: Persist jobs in database for reliability
- **Job Dispatcher**: Flexible job scheduling and dispatching
- **Workers**: Process jobs with configurable retry logic
- **Event System**: Event listeners and viewers for queue events
- **Artisan Commands**: Generate jobs and manage queue processing

### Components

- **Component Factory**: Create UI components programmatically
- **Pre-built Components**: Alert, Button, Card, Form, Input, Modal, Nav, ProgressBar
- **Component Traits**: Reusable component functionality
- **Attributes Management**: Flexible attribute handling for HTML elements
- **CSS Classes Builder**: Dynamic CSS class generation

### HTTP Layer

- **Request/Response Objects**: Object-oriented HTTP request and response handling
- **Request Utilities**: Easy access to GET/POST/FILE data, headers, and server info
- **Response Types**: JSON, redirects, downloads, streaming responses
- **Middleware Support**: Stack-based middleware pipeline with arguments
- **Header Management**: Easy header manipulation and CORS configuration
- **Status Codes**: Comprehensive HTTP status code support

### Logging & Monitoring

- **Multi-Channel Logging**: Database, file, and event-based logging
- **Structured Logging**: Log levels (DEBUG, INFO, WARNING, ERROR, CRITICAL)
- **Event System**: Event listeners for application events
- **Error Logging**: Automatic logging of exceptions and errors
- **Environment-Aware**: Different logging behavior for dev/production

### Date & Time Handling

- **DateTime Handler**: Unified date/time manipulation
- **Timezone Support**: Configurable timezone handling
- **Format Support**: Multiple date format support
- **Utility Methods**: Common date operations built-in

### Network Utilities

- **HTTP Client**: cURL-based HTTP client for API calls
- **Server Management**: PHP built-in server management for development
- **Connection Handling**: Request/response HTTP utilities

## System Requirements

- **PHP**: 8.2 or higher
- **Extensions**:
  - PDO (for database support)
  - cURL (for HTTP client)
  - JSON (for API support)
  - OpenSSL (for encryption)
- **Composer**: For dependency management
- **Database**: MySQL 5.7+, PostgreSQL 10+, or SQLite 3+

## Installation

### Using Global Installer

The easiest way to create a new Machinjiri project is using the global installer:

```bash
composer global require machinjiri/installer
machinjiri new my-project
cd my-project
php artisan server:start
```

### Manual Installation

If you prefer manual installation:

```bash
composer create-project machinjiri/framework my-project
cd my-project
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan server:start
```

### Local Development Setup

To set up the framework for local development:

```bash
# Clone or extract the repository
cd machinjiri

# Install dependencies
composer install
composer dump-autoload

# Generate autoloader
composer dump-autoload --optimize

# Run example
php examples/bootstrap.php
```

## Quick Start

### 1. Create Your First Route

```php
// routes/web.php
use Mlangeni\Machinjiri\Core\Routing\Router;

Router::get('/', function($req, $res) {
    return 'Welcome to Machinjiri!';
});

Router::get('/hello/{name}', function($req, $res, $name) {
    return "Hello, {$name}!";
}, 'greeting');
```

### 2. Create a Controller

```php
// app/Controllers/HomeController.php
namespace Mlangeni\Machinjiri\App\Controllers;

class HomeController
{
    public function index($req, $res)
    {
        return view('home', ['title' => 'Home Page']);
    }
}
```

### 3. Create a View

```html
<!-- resources/views/home.mg.php -->
<% extend('layouts.app') %>

<% section('content') %>
    <div class="container">
        <h1><%= $title %></h1>
        <p>Welcome to your Machinjiri application!</p>
    </div>
<% endsection %>
```

### 4. Connect Route to Controller

```php
// routes/web.php
Router::get('/', 'HomeController@index', 'home');
```

### 5. Start Development Server

```bash
php artisan server:start
```

Visit `http://localhost:3000` in your browser.

## Project Structure

```
your-project/
├── app/
│   ├── Controllers/          # Application controllers
│   ├── Middleware/           # Custom middleware classes
│   ├── Models/               # Data models
│   ├── Providers/            # Service providers
│   └── Exceptions/           # Custom exceptions
├── bootstrap/
│   └── app.php               # Application bootstrap file
├── config/
│   ├── app.php               # Application configuration
│   ├── database.php          # Database configuration
│   ├── mail.php              # Mail service configuration
│   ├── auth.php              # Authentication configuration
│   └── providers.php         # Service providers list
├── database/
│   ├── migrations/           # Database migration files
│   ├── seeders/              # Database seeder classes
│   └── factories/            # Model factory classes
├── resources/
│   └── views/                # View templates
│       ├── layouts/          # Layout templates
│       ├── partials/         # Reusable view fragments
│       └── pages/            # Page-specific views
├── routes/
│   └── web.php               # Web application routes
├── storage/
│   ├── cache/                # Cached data files
│   ├── cookies/              # Cookie storage
│   ├── logs/                 # Application logs
│   └── sessions/             # Session files
├── public/
│   ├── assets/
│   │   ├── css/              # Stylesheets
│   │   ├── js/               # JavaScript files
│   │   └── images/           # Image assets
│   └── index.php             # Application entry point
├── vendor/                   # Composer dependencies
├── .env                      # Environment configuration
├── .env.example              # Environment template
├── artisan                   # Console application
├── composer.json             # Project dependencies
└── phpunit.xml               # PHPUnit configuration
```

## Core Components

### Routing System

The router handles all HTTP requests and directs them to appropriate controllers or callbacks.

**Basic Routing:**

```php
use Mlangeni\Machinjiri\Core\Routing\Router;

// Simple routes
Router::get('/users', 'UserController@list');
Router::post('/users', 'UserController@store');
Router::put('/users/{id}', 'UserController@update');
Router::delete('/users/{id}', 'UserController@destroy');
Router::patch('/users/{id}', 'UserController@patch');
Router::any('/path', 'Controller@method');

// Named routes
Router::get('/profile/{id}', 'UserController@show', 'user.profile');

// Route groups
Router::group(['prefix' => '/api', 'middleware' => 'api'], function() {
    Router::get('/users', 'ApiUserController@list');
    Router::post('/users', 'ApiUserController@store');
});

// AJAX routes
Router::ajax('/api/data', 'ApiController@getData');

// Traditional routes
Router::traditional('/contact', 'ContactController@show');

// Generate URLs
$url = Router::route('user.profile', ['id' => 5]);
$absoluteUrl = Router::absoluteRoute('user.profile', ['id' => 5]);
```

**Middleware:**

```php
// Apply middleware to routes
Router::group(['middleware' => 'auth'], function() {
    Router::get('/dashboard', 'DashboardController@index');
});

// Multiple middleware
Router::group(['middleware' => ['auth', 'admin']], function() {
    Router::get('/admin', 'AdminController@dashboard');
});

// Middleware with parameters
Router::group(['middleware' => 'role:admin'], function() {
    Router::delete('/users/{id}', 'UserController@destroy');
});
```

**CORS Configuration:**

```php
Router::cors([
    'allowed_origins' => ['https://example.com'],
    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],
    'allowed_headers' => ['Content-Type', 'Authorization'],
], function() {
    Router::get('/api/public', 'ApiController@public');
});
```

### View Engine

Machinjiri provides a powerful templating engine with a clean, readable syntax.

**Template Syntax:**

```html
<!-- Output variables -->
<%= $variable %>

<!-- Echo with default -->
<%= $variable ?? 'Default Value' %>

<!-- Control structures -->
<% if($condition): %>
    <p>Condition is true</p>
<% else: %>
    <p>Condition is false</p>
<% endif; %>

<!-- Loops -->
<% foreach($items as $item): %>
    <li><%= $item['name'] %></li>
<% endforeach; %>

<% for($i = 0; $i < 10; $i++): %>
    <span><%= $i %></span>
<% endfor; %>

<!-- Include partials -->
<% include 'partials.header' %>

<!-- Sections for layout inheritance -->
<% section('content') %>
    Layout content here
<% endsection %>

<!-- Extend layouts -->
<% extend('layouts.app') %>

<!-- Yield section from layout -->
<% yield('content') %>
```

**Layout Example:**

```html
<!-- resources/views/layouts/app.mg.php -->
<!DOCTYPE html>
<html>
<head>
    <title><%= $title ?? 'My App' %></title>
    <% include 'partials.stylesheets' %>
</head>
<body>
    <header>
        <% include 'partials.navbar' %>
    </header>
    
    <main>
        <% yield('content') %>
    </main>
    
    <footer>
        <% include 'partials.footer' %>
    </footer>
    
    <% include 'partials.scripts' %>
</body>
</html>
```

**Usage in Controller:**

```php
use Mlangeni\Machinjiri\Core\Views\View;

public function index($req, $res)
{
    return View::make('home', [
        'title' => 'Home Page',
        'featured' => $featured,
    ])->render();
    
    // Or display directly
    View::make('home', ['title' => 'Home'])->display();
    
    // Share data globally
    View::share('user', auth()->user());
}
```

### Database & ORM

Machinjiri provides a powerful query builder and migration system for database operations.

**Database Configuration:**

```php
// config/database.php
return [
    'driver' => env('DB_CONNECTION', 'mysql'),
    'host' => env('DB_HOST', 'localhost'),
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'database' => env('DB_DATABASE', 'machinjiri'),
    'port' => env('DB_PORT', 3306),
];
```

**Query Builder:**

```php
use Mlangeni\Machinjiri\Core\Database\QueryBuilder;

// Simple queries
$users = QueryBuilder::table('users')->get();
$user = QueryBuilder::table('users')->where('id', 5)->first();

// Complex queries
$result = QueryBuilder::table('users')
    ->select(['id', 'name', 'email'])
    ->where('active', true)
    ->where('role', 'admin')
    ->orderBy('name', 'asc')
    ->limit(10)
    ->get();

// Insert
QueryBuilder::table('users')->insert([
    'name' => 'John',
    'email' => 'john@example.com',
]);

// Update
QueryBuilder::table('users')
    ->where('id', 5)
    ->update(['name' => 'Jane']);

// Delete
QueryBuilder::table('users')->where('id', 5)->delete();

// Aggregate functions
$count = QueryBuilder::table('users')->count();
$max = QueryBuilder::table('posts')->max('views');
```

**Migrations:**

```bash
# Create migration
php artisan make:migration create_users_table

# Run migrations
php artisan migrate

# Rollback
php artisan migrate:rollback
```

```php
// database/migrations/2024_01_01_000000_create_users_table.php
class CreateUsersTable
{
    public function up()
    {
        Schema::create('users', function($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }
    
    public function down()
    {
        Schema::dropIfExists('users');
    }
}
```

**Seeders:**

```bash
php artisan make:seeder UserSeeder
php artisan db:seed
```

### Authentication & Security

Machinjiri provides comprehensive authentication and security features.

**Session-Based Authentication:**

```php
// config/auth.php
return [
    'guard' => 'web',
    'providers' => [
        'users' => [
            'driver' => 'database',
            'table' => 'users',
        ],
    ],
];
```

**Login/Logout:**

```php
// In your controller
public function login($req, $res)
{
    $credentials = $req->only(['email', 'password']);
    
    if (auth()->attempt($credentials)) {
        return $res->redirect('/dashboard');
    }
    
    return view('auth.login', ['error' => 'Invalid credentials']);
}

public function logout($req, $res)
{
    auth()->logout();
    return $res->redirect('/');
}
```

**OAuth Integration:**

```php
use Mlangeni\Machinjiri\Core\Authentication\OAuth;

$oauth = new OAuth($config);
$token = $oauth->getAccessToken($code);
$user = $oauth->getUserInfo($token);
```

**Password Security:**

```php
use Mlangeni\Machinjiri\Core\Forms\Password;

// Hash password
$hashed = Password::hash('secret123');

// Verify password
if (Password::verify('secret123', $hashed)) {
    // Password is correct
}
```

**CSRF Protection:**

```php
// Automatically handled in forms
<form method="POST" action="/users">
    <input type="hidden" name="_token" value="<%= csrf_token() %>">
    <!-- form fields -->
</form>
```

**Encryption:**

```php
use Mlangeni\Machinjiri\Core\Security\Encryption\Encrypter;

$encrypter = new Encrypter($key);
$encrypted = $encrypter->encrypt($data);
$decrypted = $encrypter->decrypt($encrypted);
```

### Forms & Validation

Create and validate forms with ease.

**Form Validation:**

```php
use Mlangeni\Machinjiri\Core\Forms\FormValidator;

public function store($req, $res)
{
    $validator = new FormValidator($req->all());
    
    $validator->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|email|unique:users',
        'password' => 'required|min:8|confirmed',
    ]);
    
    if ($validator->fails()) {
        return view('register', ['errors' => $validator->errors()]);
    }
    
    // Create user
    User::create($validator->validated());
}
```

**Custom Rules:**

```php
$validator = new FormValidator($data);

$validator->validate([
    'age' => [
        'required',
        'integer',
        function($attribute, $value, $fail) {
            if ($value < 18) {
                $fail('Must be 18 or older');
            }
        },
    ],
]);
```

### Queues & Jobs

Process background jobs asynchronously.

**Create a Job:**

```bash
php artisan make:job SendWelcomeEmail
```

```php
// app/Jobs/SendWelcomeEmail.php
namespace Mlangeni\Machinjiri\App\Jobs;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\JobInterface;

class SendWelcomeEmail implements JobInterface
{
    public $data;
    
    public function __construct($userId)
    {
        $this->data = ['user_id' => $userId];
    }
    
    public function handle()
    {
        $user = User::find($this->data['user_id']);
        Mail::to($user->email)->send(new WelcomeEmail($user));
    }
}
```

**Dispatch Job:**

```php
// In a controller or callback
dispatch(new SendWelcomeEmail($user->id));

// Or queue for later
dispatch(new SendWelcomeEmail($user->id))->onQueue('default');
```

**Process Jobs:**

```bash
php artisan queue:work
```

### Components

Create reusable UI components programmatically.

**Available Components:**

```php
use Mlangeni\Machinjiri\Components\Alert;
use Mlangeni\Machinjiri\Components\Button;
use Mlangeni\Machinjiri\Components\Card;
use Mlangeni\Machinjiri\Components\Form;
use Mlangeni\Machinjiri\Components\Input;
use Mlangeni\Machinjiri\Components\Modal;
use Mlangeni\Machinjiri\Components\Nav;
use Mlangeni\Machinjiri\Components\ProgressBar;

// Alert component
$alert = new Alert('Success!', 'success');
echo $alert->render();

// Button component
$button = new Button('Click Me', 'btn-primary');
echo $button->render();

// Form component
$form = new Form('POST', '/submit');
$form->addField('email', 'email');
$form->addField('password', 'password');
echo $form->render();

// Input component
$input = new Input('email', 'user@example.com');
echo $input->render();

// Card component
$card = new Card('Title', 'Content');
echo $card->render();
```

## ⚙️ Configuration

### Environment Configuration

Configuration is managed through `.env` files and config classes:

```env
# .env
APP_NAME=Machinjiri
APP_ENV=local
APP_DEBUG=true
APP_KEY=your-app-key-here

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=machinjiri
DB_USERNAME=root
DB_PASSWORD=

MAIL_DRIVER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=465
MAIL_USERNAME=your-username
MAIL_PASSWORD=your-password
MAIL_FROM_ADDRESS=noreply@machinjiri.com
```

### Application Configuration

```php
// config/app.php
return [
    'name' => env('APP_NAME', 'Machinjiri'),
    'env' => env('APP_ENV', 'production'),
    'debug' => env('APP_DEBUG', false),
    'key' => env('APP_KEY'),
    
    'timezone' => 'UTC',
    'locale' => 'en',
    
    'url' => env('APP_URL', 'http://localhost'),
];
```

### Service Provider Configuration

```php
// config/providers.php
return [
    'providers' => [
        Mlangeni\Machinjiri\App\Providers\AppServiceProvider::class,
        Mlangeni\Machinjiri\App\Providers\AuthServiceProvider::class,
        Mlangeni\Machinjiri\App\Providers\RouteServiceProvider::class,
    ],
    
    'aliases' => [
        'Router' => Mlangeni\Machinjiri\Core\Routing\Router::class,
        'View' => Mlangeni\Machinjiri\Core\Views\View::class,
    ],
];
```

### Mail Configuration

```php
// config/mail.php
return [
    'driver' => env('MAIL_DRIVER', 'smtp'),
    'host' => env('MAIL_HOST', 'smtp.mailtrap.io'),
    'port' => env('MAIL_PORT', 465),
    'username' => env('MAIL_USERNAME'),
    'password' => env('MAIL_PASSWORD'),
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'noreply@machinjiri.com'),
        'name' => env('MAIL_FROM_NAME', 'Machinjiri'),
    ],
];
```

## 📚 API Reference

### Application Container

```php
use Mlangeni\Machinjiri\Machinjiri\Machinjiri;

// Get application instance
$app = Machinjiri::getInstance();

// Bind service
$app->bind('key', function($app) {
    return new Service();
});

// Resolve service
$service = $app->resolve('key');

// Check environment
$isProduction = Machinjiri::getEnvironment() === 'production';
$isDevelopment = Machinjiri::getEnvironment() === 'development';

// Get configuration
$config = $app->config('app.timezone');
```

### Router API

```php
use Mlangeni\Machinjiri\Core\Routing\Router;

// HTTP Methods
Router::get($pattern, $handler, $name = null, $options = []);
Router::post($pattern, $handler, $name = null, $options = []);
Router::put($pattern, $handler, $name = null, $options = []);
Router::delete($pattern, $handler, $name = null, $options = []);
Router::patch($pattern, $handler, $name = null, $options = []);
Router::any($pattern, $handler, $name = null, $options = []);
Router::match($methods, $pattern, $handler, $name = null, $options = []);

// Special Routes
Router::ajax($pattern, $handler, $name = null, $options = []);
Router::traditional($pattern, $handler, $name = null, $options = []);

// Route Groups
Router::group($attributes, $callback);

// Middleware
Router::middleware($middleware, $callback);

// CORS
Router::cors($config, $callback);

// URL Generation
Router::route($name, $parameters = []);
Router::absoluteRoute($name, $parameters = []);

// Dispatching
Router::dispatch();
```

### View API

```php
use Mlangeni\Machinjiri\Core\Views\View;

// Create and render
View::make($view, $data = []);
View::make($view, $data)->render();
View::make($view, $data)->display();

// Share data globally
View::share($key, $value);

// Template functions (in view files)
<%= $variable %>              // Output variable
<% section('name') %>...<%endsection %>   // Define section
<% yield('name') %>           // Output section
<% extend('layout') %>        // Extend layout
<% include 'partial' %>       // Include partial
<% if($cond): %> ... <% endif; %>   // Conditionals
<% foreach($items as $item): %> ... <% endforeach; %>
```

### Request API

```php
// In route handler or controller
public function handle($request, $response)
{
    // Get data
    $all = $request->all();
    $input = $request->input('name');
    $only = $request->only(['email', 'password']);
    $except = $request->except(['password']);
    
    // Check methods
    $isPost = $request->isPost();
    $isJson = $request->isJson();
    $isAjax = $request->isAjax();
    
    // Get headers
    $auth = $request->header('Authorization');
    $headers = $request->headers();
    
    // Files
    $file = $request->file('avatar');
    $files = $request->files();
    
    // Server info
    $method = $request->method();
    $uri = $request->uri();
    $path = $request->path();
}
```

### Response API

```php
// In route handler or controller
public function handle($request, $response)
{
    // Simple responses
    return "String response";
    
    // JSON response
    return $response->json(['data' => $data]);
    
    // Redirect
    return $response->redirect('/home');
    return $response->redirectBack();
    
    // View response
    return view('page', ['data' => $data]);
    
    // File download
    return $response->download('/path/to/file');
    
    // Set headers
    $response->header('X-Custom', 'value');
    
    // Set status
    $response->status(201);
    
    // Cookies
    $response->cookie('name', 'value', 3600);
}
```

### Database API

```php
use Mlangeni\Machinjiri\Core\Database\QueryBuilder;
use Mlangeni\Machinjiri\Core\Database\DatabaseConnection;

// Get connection
$conn = DatabaseConnection::connection('mysql');

// Query builder
$result = QueryBuilder::table('users')
    ->select(['id', 'name', 'email'])
    ->where('active', true)
    ->whereIn('role', ['admin', 'moderator'])
    ->orderBy('name', 'asc')
    ->limit(10)
    ->get();

// Retrieve single
$user = QueryBuilder::table('users')->where('id', 5)->first();

// Insert
QueryBuilder::table('users')->insert([
    'name' => 'John',
    'email' => 'john@example.com',
]);

// Update
QueryBuilder::table('users')
    ->where('id', 5)
    ->update(['name' => 'Jane']);

// Delete
QueryBuilder::table('users')->where('id', 5)->delete();

// Aggregate
$count = QueryBuilder::table('users')->count();
$max = QueryBuilder::table('posts')->max('views');
$avg = QueryBuilder::table('orders')->avg('amount');

// Exists
$exists = QueryBuilder::table('users')->where('email', $email)->exists();
```

## Error Handling

Machinjiri provides comprehensive error handling with environment-aware output:

```php
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

try {
    // Your code
    if (!$user) {
        throw new MachinjiriException('User not found', 404);
    }
} catch (MachinjiriException $e) {
    // Access error details
    $message = $e->getMessage();
    $code = $e->getCode();
    
    // Display error (different in dev/prod)
    $e->show();
    
    // Or handle manually
    return view('error', ['error' => $e->getMessage()]);
}
```

**Error Handler Features:**

- Environment-specific error pages (development vs production)
- Automatic logging of uncaught exceptions
- User-friendly error messages in production
- Detailed stack traces in development
- HTTP status code mapping
- Custom error handlers per exception type

## Console Commands (Artisan)

Machinjiri includes an Artisan console for common tasks:

```bash
# Server management
php artisan server:start              # Start development server
php artisan server:stop               # Stop development server

# Database
php artisan migrate                   # Run migrations
php artisan migrate:rollback          # Rollback migrations
php artisan migrate:reset             # Reset all migrations
php artisan db:seed                   # Run seeders

# Code generation
php artisan make:controller Name      # Create controller
php artisan make:middleware Name      # Create middleware
php artisan make:migration Name       # Create migration
php artisan make:seeder Name          # Create seeder
php artisan make:job Name             # Create job

# Job queue
php artisan queue:work                # Process jobs
php artisan queue:failed              # List failed jobs

# Utilities
php artisan key:generate              # Generate application key
php artisan config:cache              # Cache configuration
php artisan route:cache               # Cache routes
php artisan view:cache                # Cache views
```

## Testing

Run tests with PHPUnit:

```bash
# Run all tests
phpunit

# Run specific test
phpunit tests/Unit/UserTest.php

# Run with coverage
phpunit --coverage-html coverage
```

**Example Test:**

```php
// tests/Unit/UserTest.php
namespace Mlangeni\Machinjiri\Tests\Unit;

use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    public function testUserCreation()
    {
        $user = User::create([
            'name' => 'John',
            'email' => 'john@example.com',
            'password' => password_hash('secret', PASSWORD_BCRYPT),
        ]);
        
        $this->assertIsNotNull($user->id);
        $this->assertEquals('John', $user->name);
    }
}
```

## Contributing

We welcome contributions! Here's how to get started:

1. **Fork the Repository**
   ```bash
   git clone https://github.com/yourusername/machinjiri.git
   cd machinjiri
   ```

2. **Create a Feature Branch**
   ```bash
   git checkout -b feature/amazing-feature
   ```

3. **Make Your Changes**
   - Follow PSR-12 coding standards
   - Add tests for new features
   - Update documentation

4. **Commit and Push**
   ```bash
   git add .
   git commit -m 'Add amazing feature'
   git push origin feature/amazing-feature
   ```

5. **Open a Pull Request**
   - Describe your changes clearly
   - Reference any related issues
   - Ensure tests pass

### Development Setup

```bash
# Install dependencies
composer install

# Run tests
composer test

# Check code standards
composer cs-check

# Fix code standards
composer cs-fix
```

## License

This project is licensed under the **MIT License** - see the [LICENSE](LICENSE) file for details.

## Support

- **Documentation**: [Coming Soon]
- **GitHub Issues**: [Report bugs and request features](https://github.com/mlangeni/machinjiri/issues)
- **Discussions**: [Ask questions and share ideas](https://github.com/mlangeni/machinjiri/discussions)
- **Email**: precious.lyson@gmail.com

---

## Authors

- **Precious Lyson** - [GitHub](https://github.com/precious-lyson) | [Email](mailto:precious.lyson@gmail.com)
- **Mlangeni Group** - [Website](https://mlangeni.com)

## Acknowledgments

- Inspired by Laravel's elegant syntax and structure
- Built on modern PHP 8.2+ features
- Community contributions and feedback

---

**Built with ❤️ by the Machinjiri Team**

*Made for developers, by developers.*
