<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Style\SymfonyStyle;
use Mlangeni\Machinjiri\Core\Artisans\Generators\ResourceGenerator;
use Mlangeni\Machinjiri\Core\Artisans\Helpers\DotEnv;

class App
{
    public static function getCommands(): array
    {
        return [
            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('make:controller');
                    $this->setDescription('Creates a Controller class template inside the app/Controllers/ directory');
                }

                protected function configure(): void
                {
                    $this->addArgument('name', InputArgument::REQUIRED, 'The class name of the controller.');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'App Controller', function (SymfonyStyle $ss) use ($input) {
                        $name = $input->getArgument('name');
                        $resourceGenerator = new ResourceGenerator($this->artisanContainer());
                        if ($resourceGenerator->create($name, 'controller')) {
                            $ss->success("Controller Class '" . $name . "' created successfully");
                            return Command::SUCCESS;
                        } else {
                            $ss->error("Unable to create app controller '" . $name . "' due to: class already exists or unreadable directory");
                            return Command::FAILURE;
                        }
                    });
                }
            },

            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('make:model');
                    $this->setDescription('Creates a Model Class template inside the app/Model/ directory');
                }

                protected function configure(): void
                {
                    $this->addArgument('name', InputArgument::REQUIRED, 'The class name of the controller.');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'App Model', function (SymfonyStyle $ss) use ($input) {
                        $name = $input->getArgument('name');
                        $resourceGenerator = new ResourceGenerator($this->artisanContainer());
                        if ($resourceGenerator->create($name, 'model')) {
                            $ss->success("Model Class '" . $name . "' created successfully");
                            return Command::SUCCESS;
                        } else {
                            $ss->error("Unable to create Model Class '" . $name . "' due to: class already exists or unreadable directory");
                            return Command::FAILURE;
                        }
                    });
                }
            },

            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('make:middleware');
                    $this->setDescription('Creates a Middleware Class template inside the app/Middleware/ directory');
                }

                protected function configure(): void
                {
                    $this->addArgument('name', InputArgument::REQUIRED, 'The class name of the controller.');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'App Middleware', function (SymfonyStyle $ss) use ($input) {
                        $name = $input->getArgument('name');
                        $resourceGenerator = new ResourceGenerator($this->artisanContainer());
                        if ($resourceGenerator->create($name, 'middleware')) {
                            $ss->success("Middleware Class '" . $name . "' created successfully");
                            return Command::SUCCESS;
                        } else {
                            $ss->error("Unable to create Middleware Class '" . $name . "' due to: class already exists or unreadable directory");
                            return Command::FAILURE;
                        }
                    });
                }
            },

            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('get:env');
                    $this->setDescription('Get all configurations set in the app environment file');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'App Environment Configurations', function (SymfonyStyle $ss) {
                        $dotEnv = new DotEnv($this->artisanContainer(), true);
                        $dotEnv->load();
                        $vars = [];
                        foreach ($dotEnv->getVariables() as $key => $value) {
                            $vars[] = $key . " = " . $value;
                        }
                        $ss->listing($vars);
                        return Command::SUCCESS;
                    });
                }
            },

            
        ];
    }
}