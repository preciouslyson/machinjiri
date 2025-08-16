<?php

namespace Mlangeni\Machinjiri\Core\Console;

class Kernel
{
    protected $commands = [];

    public function __construct()
    {
        $this->commands = $this->registerCommands();
    }

    protected function registerCommands(): array
    {
        return [
            // Commands will be registered here
        ];
    }

    public function handle()
    {
        global $argv;
        $commandName = $argv[1] ?? null;

        if (!$commandName || in_array($commandName, ['--help', '-h'])) {
            $this->showHelp();
            return;
        }

        if ($commandName === 'list') {
            $this->listCommands();
            return;
        }

        // Handle group commands
        $subCommand = $argv[2] ?? null;
        $subCommandName = null;
        
        if ($subCommand && !str_starts_with($subCommand, '--')) {
            $subCommandName = $subCommand;
        }

        foreach ($this->commands as $commandClass) {
            $command = new $commandClass;
            
            if ($command->getName() === $commandName) {
                // Handle command groups
                if ($command->isGroup()) {
                    $this->handleGroupCommand($command, $subCommandName, array_slice($argv, 3));
                    return;
                }
                
                // Handle single command
                $this->runCommand($command, array_slice($argv, 2));
                return;
            }
        }

        $this->error("Command \"$commandName\" not found");
        $this->listCommands();
        exit(1);
    }

    protected function handleGroupCommand(Command $group, ?string $subCommand, array $args)
    {
        global $argv;
        
        // Show group help if no sub-command specified
        if (!$subCommand || in_array($subCommand, ['--help', '-h'])) {
            echo $group->getHelp();
            return;
        }
        
        $subCommands = $group->getSubCommands();
        
        if (!isset($subCommands[$subCommand])) {
            $this->error("Sub-command \"$subCommand\" not found for group \"{$group->getName()}\"");
            echo $group->getHelp();
            exit(1);
        }
        
        // Set up the command context
        $group->name = $group->getName() . ':' . $subCommand;
        $group->arguments = $subCommands[$subCommand]['arguments'] ?? [];
        $group->options = $subCommands[$subCommand]['options'] ?? [];
        
        $this->runCommand($group, $args);
    }

    protected function runCommand(Command $command, array $args)
    {
        try {
            $command->handle();
        } catch (\Exception $e) {
            $command->error("Error: " . $e->getMessage());
        }
    }

    protected function showHelp()
    {
        echo "Usage:\n  php artisan <command> [options]\n\n";
        echo "Options:\n";
        echo "  -h, --help\tShow this help message\n\n";
        $this->listCommands();
    }

    protected function listCommands()
    {
        echo "Available commands:\n";

        foreach ($this->commands as $commandClass) {
            $command = new $commandClass;
            
            if ($command->isGroup()) {
                echo sprintf("  \033[32m%-15s\033[0m %s\n", 
                    $command->getName(), 
                    $command->getDescription()
                );
                
                foreach ($command->getSubCommands() as $subName => $subConfig) {
                    echo sprintf("    \033[33m%-13s\033[0m %s\n", 
                        $subName, 
                        $subConfig['description']
                    );
                }
            } else {
                echo sprintf("  \033[32m%-15s\033[0m %s\n", 
                    $command->getName(), 
                    $command->getDescription()
                );
            }
        }
    }

    protected function error(string $message)
    {
        echo "\033[31mError: $message\033[0m\n";
    }
}