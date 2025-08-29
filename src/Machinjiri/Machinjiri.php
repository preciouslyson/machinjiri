<?php

namespace Mlangeni\Machinjiri\Core;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Exceptions\ErrorHandler;
use Mlangeni\Machinjiri\Core\Database\DatabaseConnection;
use Mlangeni\Machinjiri\Core\Database\Migrations\MigrationHandler;
use Mlangeni\Machinjiri\Core\Artisans\Process\Kameza;
use Mlangeni\Machinjiri\Core\Artisans\Process\DatabaseQueueDriver;

final class Machinjiri extends Container {
  
  public function __construct (string $appBasePath, bool $dev = true) {
    parent::__construct($appBasePath);
    ErrorHandler::register($dev);
    try {
      $this->_init();
      $this->dbConnect();
      $this->defaultMigrations();
    } catch (MachinjiriException $me) {
      $me->display();
    }
  }
  
  public function dbConnect () : void {
    try {
      DatabaseConnection::setPath($this->database);
      DatabaseConnection::setConfig($this->getConfigurations()['database']);
    } catch (MlangeniException $me) {
      $me->display();
    }
  }
  
  public function init() : void {
    try {
      $this->boot();
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