<?php

namespace Mlangeni\Machinjiri\Core\Console\Commands;
use Mlangeni\Machinjiri\Core\Console\Command;
use Mlangeni\Machinjiri\Core\Database\MigrationCreator;
use Mlangeni\Machinjiri\Core\Database\MigrationHandler;

class Migration extends Command
{
    public $name = 'migration';
    public $description = 'Create, Update and Delete App Migrations';
    
    public $commands = [
        'create' => [
            'description' => 'Create a new migration',
            'arguments' => [
                'name' => 'The name of the migration file'
            ]
        ],
        'remove' => [
            'description' => 'Delete a Migration',
            'arguments' => [
                'name' => 'The name of the migration file'
            ]
        ],
        'list' => [
            'description' => 'List all migrations'
        ]
    ];

    public function handle()
    {
        $subCommand = $this->arguments[0];
        
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
        $m = new MigrationCreator();
        $m->create($name);
        $this->info("Created: " . $name);
    }

}