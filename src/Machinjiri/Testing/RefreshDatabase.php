<?php

namespace Mlangeni\Machinjiri\Testing\Traits;

use Mlangeni\Machinjiri\Core\Database\DatabaseConnection;

trait RefreshDatabase
{
    protected function setUpDatabase(): void
    {
        parent::setUpDatabase();
        $this->beginDatabaseTransaction();
    }

    protected function beginDatabaseTransaction(): void
    {
        DatabaseConnection::beginTransaction();
        $this->beforeApplicationDestroyed(function () {
            DatabaseConnection::rollback();
        });
    }

    protected function beforeApplicationDestroyed(callable $callback): void
    {
        // Register shutdown function
        register_shutdown_function($callback);
    }
}