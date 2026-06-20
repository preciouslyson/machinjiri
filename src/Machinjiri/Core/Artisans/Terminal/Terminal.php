<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal;

use Symfony\Component\Console\Application;
use Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands\Migrations;
use Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands\App;
use Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands\PHPServerCommands;
use Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands\ServiceProviderCommand;
use Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands\QueueWorkerCommand;
use Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands\Angular;
use Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands\Vite;
use Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands\ViewCommands;
use Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands\WebhookCommand;

class Terminal extends Application
{
    protected $commandClasses = [
        Migrations::class,
        App::class,
        PHPServerCommands::class,
        ServiceProviderCommand::class,
        QueueWorkerCommand::class,
        Angular::class,
        Vite::class,
        ViewCommands::class,
        WebhookCommand::class,
    ];

    public function __construct()
    {
        parent::__construct('Machinjiri Terminal', '1.1.0');
        $this->registerCommands();
    }

    protected function registerCommands(): void
    {
        foreach ($this->commandClasses as $commandClass) {
            $commands = $commandClass::getCommands();
            foreach ($commands as $command) {
                $this->add($command);
            }
        }
    }

    public function addCommandClass(string $commandClass): void
    {
        if (!in_array($commandClass, $this->commandClasses)) {
            $this->commandClasses[] = $commandClass;
            $commands = $commandClass::getCommands();
            foreach ($commands as $command) {
                $this->add($command);
            }
        }
    }
}