<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Mlangeni\Machinjiri\Core\Artisans\Generators\ResourceGenerator;
use Mlangeni\Machinjiri\Core\Routing\DotEnv;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Database\Seeder\SeederManager;
use Mlangeni\Machinjiri\Core\Database\Factory\FactoryManager;
use Mlangeni\Machinjiri\Core\Database\Migrations\MigrationCreator;
use Mlangeni\Machinjiri\Facade\UI\Bootstrap\Misc\Keywords;

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
                        $resourceGenerator = new ResourceGenerator();
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
                        $resourceGenerator = new ResourceGenerator();
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
                        $resourceGenerator = new ResourceGenerator();
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
                        $dotEnv = new DotEnv('.env');
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

            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('make:seeder');
                    $this->setDescription("Creates a Database Seeder File. (inside database/seeders)");
                }

                protected function configure(): void
                {
                    $this->addArgument('name', InputArgument::REQUIRED, 'Database Seeder Name');
                    $this->addOption('migration', null, InputOption::VALUE_NONE, 'Create a Migration file for this Seeder');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Seeder', function (SymfonyStyle $ss) use ($input) {
                        $seederName = $input->getArgument('name');

                        if (in_array(strtolower($seederName), Keywords::internal())) {
                            $ss->error("Name is Invalid! Choose a proper migration name");
                            return Command::FAILURE;
                        }

                        $makeMigration = (bool) $input->getOption('migration');
                        $listing = [];

                        if ($makeMigration) {
                            $creator = new MigrationCreator();
                            if ($creator->getMigrationFiles() > 0) {
                                foreach ($creator->getMigrationFiles() as $file) {
                                    if ($creator->getFileName($file) == ucfirst($seederName)) {
                                        $ss->error("A migration with name '" . $seederName . "' already exists");
                                        return Command::FAILURE;
                                    }
                                }
                            }
                            $listing[] = "Migration => " . basename($creator->create($seederName));
                        }

                        $container = new Container(getcwd());
                        $container->initialize();
                        $seederManager = new SeederManager($container);
                        $seederManager->registerAutoload();

                        $seederPath = $seederManager->make($seederName);

                        if ($seederManager->created) {
                            $listing[] = "Seeder => " . $seederName;
                            $listing[] = "Path => " . basename($seederPath);

                            $ss->success("Seeder Created Successfully");
                            $ss->section('Creation Information');
                            $ss->listing($listing);
                            return Command::SUCCESS;
                        } else {
                            $ss->error("Unable to create Seeder at the moment");
                            return Command::FAILURE;
                        }
                    });
                }
            },

            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('make:factory');
                    $this->setDescription("Creates a Database Factory File. (inside database/factories)");
                }

                protected function configure(): void
                {
                    $this->addArgument('name', InputArgument::REQUIRED, 'Database Factory Name');
                    $this->addOption('migration', null, InputOption::VALUE_NONE, 'Create a Migration file for this Factory');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Factory', function (SymfonyStyle $ss) use ($input) {
                        $factoryName = $input->getArgument('name');

                        if (in_array(strtolower($factoryName), Keywords::internal())) {
                            $ss->error("Name is Invalid! Choose a proper Factory name");
                            return Command::FAILURE;
                        }

                        $makeMigration = (bool) $input->getOption('migration');
                        $listing = [];

                        if ($makeMigration) {
                            $creator = new MigrationCreator();
                            if ($creator->getMigrationFiles() > 0) {
                                foreach ($creator->getMigrationFiles() as $file) {
                                    if ($creator->getFileName($file) == ucfirst($factoryName)) {
                                        $ss->error("A migration with name '" . $factoryName . "' already exists");
                                        return Command::FAILURE;
                                    }
                                }
                            }
                            $listing[] = "Migration => " . basename($creator->create($factoryName));
                        }

                        $container = new Container(getcwd());
                        $container->initialize();
                        $factoryManager = new FactoryManager($container);
                        $factoryManager->registerAutoload();
                        $factoryPath = $factoryManager->make($factoryName);

                        if ($factoryManager->created) {
                            $listing[] = "Factory : " . $factoryName;
                            $listing[] = "Path : " . basename($factoryPath);

                            $ss->success("Factory Created Successfully");
                            $ss->section('Creation Information');
                            $ss->listing($listing);
                            return Command::SUCCESS;
                        } else {
                            $ss->error("Unable to create Factory at the moment");
                            return Command::FAILURE;
                        }
                    });
                }
            },
        ];
    }
}