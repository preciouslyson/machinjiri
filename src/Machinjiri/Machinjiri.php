<?php

namespace Mlangeni\Machinjiri\Core;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Database\DatabaseConnection;
use Mlangeni\Machinjiri\Core\Database\MigrationHandler;


final class Machinjiri extends Container {
  
  public function __construct () {
    $this->dbConnect();
    $this->defaultMigrations();
  }
  
  public function dbConnect () : void {
    try {
      DatabaseConnection::setConfig($this->getConfigurations()['database']);
    } catch (MlangeniException $me) {
      $me->display();
    }
  }
  
  public function init() : void {
    try {
      $this->routes();
    } catch (MachinjiriException $me) {
      $me->display();
    }
  }
  
  public function defaultMigrations () : void {
    $migration = new MigrationHandler();
    $migration->migrate();
  }
  
}