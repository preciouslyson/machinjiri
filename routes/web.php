<?php
use Mlangeni\Machinjiri\Core\Machinjiri;
use Mlangeni\Machinjiri\Core\Routing\Router;
use Mlangeni\Machinjiri\Core\Views\View;

$router = new Router();

// define your routes here ...
$router->get("/", "HomeController@index");


// dispatching routes
$router->dispatch();