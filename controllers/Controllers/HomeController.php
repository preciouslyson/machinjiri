<?php

namespace Mlangeni\Machinjiri\Controllers;
use Mlangeni\Machinjiri\Core\Views\View;
use Mlangeni\Machinjiri\Core\Machinjiri;

final class HomeController {
  
  public function index () : void {
    // View::make("welcome")->display();
  }
  
  public function Admin () : void {
    print "Admin";
  }
  
  public function Auth () : void {
    print "Authenticate First";
  }
  
  
} 