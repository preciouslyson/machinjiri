<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Mlangeni\Machinjiri\Core\Artisans\Helpers\Mkutumula;
use Mlangeni\Machinjiri\Core\Routing\DotEnv;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
class App
{
    public static function getCommands(): array
    {
        return [
            new class extends Command {
                protected static $defaultName = 'make:controller';
                protected static $defaultDescription = 'Creates a Controller class template inside the app/Controllers/ directory';

                protected function configure(): void
                {
                    $this
                        ->addArgument('name', InputArgument::REQUIRED, 'The class name of the controller.');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    $name = $input->getArgument('name');
                    $mkutumula = new Mkutumula();
                    if ($mkutumula->create($name, 'controller')) {
                      $output->writeln("Controller Class '" . $name . "' created successfully");
                      return Command::SUCCESS;
                    } else {
                      $output->writeln("Unable to create app controller '" . $name . "' due to: class already exists or unreadable directory");
                      return Command::FAILURE;
                    }
                }
            },
            new class extends Command {
                protected static $defaultName = 'make:model';
                protected static $defaultDescription = 'Creates a Model Class template inside the app/Model/ directory';

                protected function configure(): void
                {
                    $this
                        ->addArgument('name', InputArgument::REQUIRED, 'The class name of the controller.');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    $name = $input->getArgument('name');
                    $mkutumula = new Mkutumula();
                    if ($mkutumula->create($name, 'model')) {
                      $output->writeln("Model Class '" . $name . "' created successfully");
                      return Command::SUCCESS;
                    } else {
                      $output->writeln("Unable to create Model Class '" . $name . "' due to: class already exists or unreadable directory");
                      return Command::FAILURE;
                    }
                }
            },
            new class extends Command {
                protected static $defaultName = 'make:middleware';
                protected static $defaultDescription = 'Creates a Middleware Class template inside the app/Middleware/ directory';

                protected function configure(): void
                {
                    $this
                        ->addArgument('name', InputArgument::REQUIRED, 'The class name of the controller.');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    $name = $input->getArgument('name');
                    $mkutumula = new Mkutumula();
                    if ($mkutumula->create($name, 'middleware')) {
                      $output->writeln("Middleware Class '" . $name . "' created successfully");
                      return Command::SUCCESS;
                    } else {
                      $output->writeln("Unable to create Middleware Class '" . $name . "' due to: class already exists or unreadable directory");
                      return Command::FAILURE;
                    }
                }
            },
            new class extends Command {
                protected static $defaultName = 'get:environment';
                protected static $defaultDescription = 'Get all configurations set in the app environment file';

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    $output->writeln("Environment Configurations \n");
                    
                    try {
                      $dotEnv = new DotEnv('.env');
                      $dotEnv->load();
                      foreach ($dotEnv->getVariables() as $key => $value) {
                        $output->writeln($key . " = " . $value);
                      }
                      return Command::SUCCESS;
                    } catch (MachinjiriException $e) {
                      $output->writeln($e->getMessage());
                      return Command::FAILURE;
                    }
                    
                    
                    
                }
            },
        ];
    }
}