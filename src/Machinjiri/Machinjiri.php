<?php

namespace Mlangeni\Machinjiri\Core;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Database\DatabaseConnection;
use Mlangeni\Machinjiri\Core\Database\MigrationHandler;
use Mlangeni\Machinjiri\Core\Artisans\Process\Kameza;
use Mlangeni\Machinjiri\Core\Artisans\Process\DatabaseQueueDriver;

final class Machinjiri extends Container {
  
  public function __construct () {
    $this->dbConnect();
    $this->defaultMigrations();
    
    // $this->serviceWorker();
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
  
  private function serviceWorker () : void {
    $driver = new DatabaseQueueDriver();
    Kameza::setDriver($driver);
    while (true) {
      try {
        $job = $driver->pop();
        if ($job) {
          try {
            Kameza::process($job['payload']);
            $driver->delete($job['id']);
          } catch (Exception $e) {
            
          }
        } else {
          sleep(5);
        }
      } catch (Exception $e) {
        sleep(5);
      }
    }
  }
  
}