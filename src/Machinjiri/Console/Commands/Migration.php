<?php

namespace Mlangeni\Machinjiri\Core\Console\Commands;

use Mlangeni\Machinjiri\Core\Console\Command;
use Mlangeni\Machinjiri\Core\Database\MigrationCreator;
use Mlangeni\Machinjiri\Core\Database\MigrationHandler;

class Migration extends Command
{
    protected $name = 'migration';
    protected $description = 'Manage Database Migrations';
    
    protected $commands = [
        'create' => [
            'description' => 'Create a new migration',
            'arguments' => [
                'name' => 'Migration name'
            ]
        ],
        'delete' => [
            'description' => 'Remove migration',
            'arguments' => [
                'name' => 'Migration name'
            ]
        ],
        'list' => [
            'description' => 'List all migrations'
        ]
    ];

    public function handle()
    {
        // The actual command handling will depend on the sub-command
        $subCommand = $this->argument(0);
        
        switch ($subCommand) {
            case 'create':
                $this->handleCreate();
                break;
            case 'delete':
                // $this->handleDelete();
                break;
            case 'list':
                // $this->handleList();
                break;
            default:
                $this->error("Unknown sub-command: $subCommand");
                echo $this->getHelp();
                exit(1);
        }
    }

    protected function handleCreate()
    {
        $name = $this->argument('name');
        $migration = new MigrationCreator();
        $migration->create($name);
        $this->info("Created successfully");
    }

}
