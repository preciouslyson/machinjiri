<?php
use Mlangeni\Machinjiri\Core\Machinjiri;
use Mlangeni\Machinjiri\Core\Routing\Router;
use Mlangeni\Machinjiri\Core\Views\View;

$router = new Router();

// define your routes here ...
$router->get("/", "HomeController@index");

$router->group(["middleware" => "HomeController@Auth"], function ($router) {
  $router->get("/dashboard", "HomeController@Admin");
});


// dispatching routes
$router->dispatch();