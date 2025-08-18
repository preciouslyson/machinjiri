<?php

use Mlangeni\Machinjiri\Core\Routing\Router;
use Mlangeni\Machinjiri\App\Middleware\Auth;

$router = new Router();

// define your routes here ...
$router->get("/", "HomeController@index");


// dispatching routes
$router->dispatch();