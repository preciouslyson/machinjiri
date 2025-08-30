<?php

namespace Mlangeni\Machinjiri\Core;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Exceptions\ErrorHandler;
use Mlangeni\Machinjiri\Core\Database\DatabaseConnection;
use Mlangeni\Machinjiri\Core\Database\Migrations\MigrationHandler;
use Mlangeni\Machinjiri\Core\Artisans\Process\Kameza;
use Mlangeni\Machinjiri\Core\Artisans\Process\DatabaseQueueDriver;
use Exception;

final class Machinjiri extends Container
{
    public function __construct(string $appBasePath, bool $dev = true)
    {
        parent::__construct($appBasePath);
        ErrorHandler::register($dev);
        
        try {
            $this->initialize();
            $this->dbConnect();
            $this->defaultMigrations();
        } catch (MachinjiriException $me) {
            $me->display();
        }
    }
    
    public function dbConnect(): void
    {
        try {
            DatabaseConnection::setPath($this->database);
            DatabaseConnection::setConfig($this->getConfigurations()['database']);
        } catch (Exception $e) {
            // Log the error or handle it appropriately
            error_log("Database connection error: " . $e->getMessage());
        }
    }
    
    public function init(): void
    {
        try {
            $this->boot();
            $this->loadRoutes();
        } catch (MachinjiriException $me) {
            $me->display();
        }
    }
    
    public function defaultMigrations(): void
    {
        try {
            $migration = new MigrationHandler();
            $migration->migrate();
        } catch (Exception $e) {
            error_log("Migration error: " . $e->getMessage());
        }
    }
    
    private function serviceWorker(): void
    {
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
                        error_log("Job processing error: " . $e->getMessage());
                    }
                } else {
                    sleep(5);
                }
            } catch (Exception $e) {
                error_log("Service worker error: " . $e->getMessage());
                sleep(5);
            }
        }
    }
}