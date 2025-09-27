<?php

namespace Mlangeni\Machinjiri\Core;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Exceptions\ErrorHandler;
use Mlangeni\Machinjiri\Core\Database\DatabaseConnection;
use Mlangeni\Machinjiri\Core\Database\Migrations\MigrationHandler;
use Mlangeni\Machinjiri\Core\Artisans\Process\Kameza;
use Mlangeni\Machinjiri\Core\Artisans\Process\DatabaseQueueDriver;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Views\View;

final class Machinjiri extends Container
{
    private static $instance = null;
    private static $environment;
    
    private Logger $logger;
    private EventListener $listener;
    
    public static function App (string $appBasePath, bool $dev = true) : self
    {
       if (self::$instance === null) {
           self::$environment = $dev;
           self::$instance = new self ($appBasePath, self::$environment);
           return self::$instance;
       }
       return self::$instance;
    }
    
    public static function getInstance () {
        if (self::$instance === null) {
            throw new MachinjiriException("App not initialised. Call App() first!", 80001);
        }
        return self::$instance;
    }
    
    public static function getEnvironment () : string {
        return (self::$environment) ? 'development' : 'production';
    }
    
    private function __construct(string $appBasePath, bool $dev = true)
    {
        parent::__construct($appBasePath);
        ErrorHandler::register($dev);
        $this->listener = new EventListener(new Logger('events'));
        
        try {
            $this->listener->trigger('app.initialize');
            $this->initialize();
            
            $this->listener->trigger('db.connected.driver.');
            $this->dbConnect();
            $this->defaultMigrations();
            
        } catch (MachinjiriException $me) {
            $me->show();
        }
    }
    
    public function init () : self {
      try {
        $this->listener->trigger('app.load.resources');
        //create all necessary app folders
        $this->boot();
        // load designated route containers
        $this->loadRoutes();
        // share app data across views
        View::share($this->getConfigurations()['app']);
        
        // return self instance
        return $this;
      } catch (MachinjiriException $e) {
        $e->show();
      }
    }
    
    private function dbConnect(): void
    {
        $dbLogger = new Logger('database');
        try {
            DatabaseConnection::setPath($this->database);
            DatabaseConnection::setConfig($this->getConfigurations()['database']);
        } catch (MachinjiriException $e) {
            $dbLogger->critical('connection failed', [
                'driver' => DatabaseConnection::getDriver(),
                'message' => $e->getMessage()
                ]);
            $e->show();
        }
    }
    
    private function defaultMigrations(): void
    {
        try {
            $migration = new MigrationHandler();
            $migration->migrate();
        } catch (MachinjiriException $e) {
            $e->show();
        }
    }
    
}