<?php

namespace Mlangeni\Machinjiri\Core;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Exceptions\Catcher;
use Mlangeni\Machinjiri\Core\Exceptions\ErrorHandler;
use Mlangeni\Machinjiri\Core\Database\DatabaseConnection;
use Mlangeni\Machinjiri\Core\Database\Migrations\MigrationHandler;
use Mlangeni\Machinjiri\Core\Artisans\Process\Kameza;
use Mlangeni\Machinjiri\Core\Artisans\Process\DatabaseQueueDriver;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;

final class Machinjiri extends Container
{
    public static function App (string $appBasePath, bool $dev = true) : self
    {
       return new self($appBasePath, $dev);
    }
    
    private function __construct(string $appBasePath, bool $dev = true)
    {
        parent::__construct($appBasePath);
        ErrorHandler::register($dev);
        
        try {
            $this->initialize();
            $this->dbConnect();
            $this->defaultMigrations();
        } catch (MachinjiriException $me) {
            $me->show();
        }
    }
    
    private function dbConnect(): void
    {
        try {
            DatabaseConnection::setPath($this->database);
            DatabaseConnection::setConfig($this->getConfigurations()['database']);
        } catch (MachinjiriException $e) {
            // Log the error or handle it appropriately
            $e->show();
        }
    }
    
    public function init(): void
    {
        try {
            $this->boot();
            $this->loadRoutes();
        } catch (MachinjiriException $me) {
            $me->show();
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