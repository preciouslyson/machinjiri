<?php

use Mlangeni\Machinjiri\Core\Routing\Router;

$router = new Router();

// define your routes here ...
$router->get("/", "HomeController@index");


// dispatching routes
$router->dispatch();