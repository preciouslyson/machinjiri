<?php

namespace Mlangeni\Machinjiri\App\Controllers;
use Mlangeni\Machinjiri\Core\Views\View;
class HomeController {
  
  public function index () : void {
    View::make("welcome")->display();
  }
  
}