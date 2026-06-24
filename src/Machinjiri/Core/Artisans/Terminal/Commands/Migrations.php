<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;
use Mlangeni\Machinjiri\Core\Database\Migrations\MigrationCreator;
use Mlangeni\Machinjiri\Core\Database\Migrations\MigrationHandler;
use Mlangeni\Machinjiri\Core\Machinjiri;
use Mlangeni\Machinjiri\Facade\UI\Bootstrap\Misc\Keywords;

class Migrations
{
    public static function getCommands(): array
    {
        return [
            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('migration:create');
                    $this->setDescription('Create a Migration Template file');
                }

                protected function configure(): void
                {
                    $this->addArgument('name', InputArgument::REQUIRED, 'The name of the migration template. Note: must be lowercase and worlds must be separated by underscores. Example create_user_table ');
                    $this->addOption('blueprint', null, InputOption::VALUE_NONE, 'Create a Migration with Blueprint functionality');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Migration [Create a Migration]', function (SymfonyStyle $ss) use ($input) {
                        $name = $input->getArgument('name');
                        $bluePrint = (bool) $input->getOption('blueprint');

                        if (in_array($name, Keywords::internal())) {
                            $ss->error("Name is Invalid! Choose a proper migration name");
                            return Command::FAILURE;
                        }

                        $creator = new MigrationCreator();
                        if ($creator->getMigrationFiles() > 0) {
                            foreach ($creator->getMigrationFiles() as $file) {
                                if ($creator->getFileName($file) == ucfirst($name)) {
                                    $ss->error("A migration with name '" . $name . "' already exists");
                                    return Command::FAILURE;
                                }
                            }
                        }

                        $ss->success("Created: " . basename($creator->create($name, $bluePrint)));
                        return Command::SUCCESS;
                    });
                }
            },

            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('migration:list');
                    $this->setDescription('Get a list of migration files');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Migration [List Created Migrations]', function (SymfonyStyle $ss) {
                        $creator = new MigrationCreator();
                        $ss->section("List of Migrations");
                        $files = [];
                        if (count($creator->getMigrationFiles()) > 0) {
                            foreach ($creator->getMigrationFiles() as $migration) {
                                $files[] = basename($migration);
                            }
                            $ss->listing($files);
                        } else {
                            $ss->warning("No Migrations available.");
                        }
                        return Command::SUCCESS;
                    });
                }
            },

            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('migration:remove');
                    $this->setDescription('Remove/delete a migration file');
                }

                protected function configure(): void
                {
                    $this->addArgument('name', InputArgument::REQUIRED, 'The name of the migration. Note: must be lowercase and worlds must be separated by underscores. Example create_user_table');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Migration [Delete Migration]', function (SymfonyStyle $ss) use ($input) {
                        $name = $input->getArgument('name');
                        $creator = new MigrationCreator();
                        if ($creator->removeMigration($name)) {
                            $ss->success("Deleted successfully: " . $name);
                        } else {
                            $ss->error("Unable to delete migration: " . $name);
                        }
                        return Command::SUCCESS;
                    });
                }
            },

            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('migration:get-ran');
                    $this->setDescription('Retrieve Migrations that have been ran');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Migration [List of Ran Migrations]', function (SymfonyStyle $ss) {
                        Machinjiri::App(getcwd(), true);
                        $mh = new MigrationHandler();
                        $runMigrations = $mh->getRanMigrations(true);

                        if (count($runMigrations) > 0) {
                            $counter = 0;
                            $headers = ['No.', 'Migration', 'Created At'];
                            $rows = [];
                            foreach ($runMigrations as $runMigration) {
                                $counter++;
                                $rows[] = [$counter, $runMigration['migration'], $runMigration['created_at']];
                            }
                            $ss->table($headers, $rows);
                        } else {
                            $ss->info("No ran migrations found.");
                        }
                        return Command::SUCCESS;
                    });
                }
            },

            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('migration:migrate');
                    $this->setDescription('Run migrations');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Migration [Run Available Migrations]', function (SymfonyStyle $ss) {
                        Machinjiri::App(getcwd(), true);
                        $ss->progressStart(100);
                        $ss->progressAdvance();
                        (new MigrationHandler())->migrate();
                        $ss->progressFinish();
                        $ss->success("Operation completed successfully");
                        return Command::SUCCESS;
                    });
                }
            },

            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('migration:rollback');
                    $this->setDescription('Rollback migrations');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Migrations [Rollback Migrations]', function (SymfonyStyle $ss) {
                        Machinjiri::App(getcwd(), true);
                        $ss->progressStart(100);
                        $ss->progressAdvance();
                        (new MigrationHandler())->rollback();
                        $ss->progressFinish();
                        $ss->success("Operation Complete!");
                        return Command::SUCCESS;
                    });
                }
            },
        ];
    }
}