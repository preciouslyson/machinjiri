<?php

namespace Mlangeni\Machinjiri\Core\Console;

abstract class Command
{
    // Single command configuration
    public $name;
    public $description = '';
    public $arguments = [];
    public $options = [];

    // Multi-command configuration
    public $commands = [];
    public $defaultCommand;

    abstract public function handle();

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function isGroup(): bool
    {
        return !empty($this->commands);
    }

    public function getSubCommands(): array
    {
        return $this->commands;
    }

    public function getHelp(): string
    {
        if ($this->isGroup()) {
            return $this->getGroupHelp();
        }
        return $this->getCommandHelp();
    }

    protected function getCommandHelp(): string
    {
        $help = "\033[1mDescription:\033[0m\n  {$this->description}\n\n";

        if (!empty($this->arguments)) {
            $help .= "\033[1mArguments:\033[0m\n";
            foreach ($this->arguments as $name => $description) {
                $help .= "  \033[32m{$name}\033[0m\t{$description}\n";
            }
        }

        if (!empty($this->options)) {
            $help .= "\n\033[1mOptions:\033[0m\n";
            foreach ($this->options as $name => $description) {
                $help .= "  \033[36m--{$name}\033[0m\t{$description}\n";
            }
        }

        return $help;
    }

    protected function getGroupHelp(): string
    {
        $help = "\033[1mDescription:\033[0m\n  {$this->description}\n\n";
        $help .= "\033[1mAvailable Sub-Commands:\033[0m\n";

        foreach ($this->commands as $name => $config) {
            $help .= sprintf("  \033[32m%-15s\033[0m %s\n", $name, $config['description']);
        }

        $help .= "\n\033[1mUsage:\033[0m\n";
        $help .= "  php artisan {$this->name} <sub-command> [options]\n";

        return $help;
    }

    protected function argument(string $name)
    {
        global $argv;
        
        // Find command position
        $commandIndex = array_search($this->name, $argv);
        if ($commandIndex === false) return null;
        
        $args = array_slice($argv, $commandIndex + 1);
        
        // Skip sub-command if this is a group
        if ($this->isGroup() && isset($args[0])) {
            if (isset($this->commands[$args[0]])) {
                array_shift($args);
            }
        }
        
        $position = array_search($name, array_keys($this->arguments));
        return $args[$position] ?? null;
    }

    protected function option(string $name)
    {
        global $argv;
        $search = "--{$name}";
        
        foreach ($argv as $value) {
            if (strpos($value, $search) === 0) {
                // Handle --option=value
                if (($eqPos = strpos($value, '=')) !== false) {
                    return substr($value, $eqPos + 1);
                }
                return true;
            }
        }
        
        return false;
    }

    public function line(string $string)
    {
        echo $string . PHP_EOL;
    }

    public function info(string $string)
    {
        echo "\033[32m{$string}\033[0m" . PHP_EOL;
    }

    public function error(string $string)
    {
        echo "\033[31m{$string}\033[0m" . PHP_EOL;
        exit(1);
    }
}