<?php
require __DIR__ . "/../vendor/autoload.php";
use Mlangeni\Machinjiri\Core\Routing\Router;
$router = new Router();

$router->get("/", "HomeController@index");


$router->dispatch();