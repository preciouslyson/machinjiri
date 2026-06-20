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
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Facade\UI\Bootstrap\Misc\Keywords;
use \PDO;

trait MigrationBootstrap {
    
    public function getContainer (): ?Container
    {
        $bootstrap = getcwd() . '/bootstrap/artisan.php';
        return (is_file($bootstrap)) ? require $bootstrap : null;
    }
    
}

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
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Migration [Create a Migration]', function (SymfonyStyle $ss) use ($input) {
                        $name = $input->getArgument('name');

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

                        $ss->success("Created: " . basename($creator->create($name)));
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
                        $files = [];
                        if (count($creator->getMigrationFiles()) > 0) {
                            foreach ($creator->getMigrationFiles() as $migration) {
                                if (is_dir($migration)) continue;
                                $files[] = basename($migration);
                            }
                            if (count($files) > 0) {
                                $ss->section("List of Migrations");
                                $ss->listing($files);
                            } else {
                                $ss->info("No Migrations available.");
                            }
                        } else {
                            $ss->info("No Migrations available.");
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
                use CommandHelper, MigrationBootstrap;

                public function __construct()
                {
                    parent::__construct('migration:get-ran');
                    $this->setDescription('Retrieve Migrations that have been ran');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Migration [List of Ran Migrations]', function (SymfonyStyle $ss) {
                        $migrationHandler = new MigrationHandler($this->getContainer(), $this->getContainer()->resolve("db.kernel.connection"));
                        $ranMigrations = $migrationHandler->getRanMigrations(true);

                        if (count($ranMigrations) > 0) {
                            $counter = 0;
                            $headers = ['No.', 'Migration', 'Created At'];
                            $rows = [];
                            foreach ($ranMigrations as $ranMigration) {
                                $counter++;
                                $rows[] = [$counter, $ranMigration['migration'], $ranMigration['created_at']];
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
                use CommandHelper, MigrationBootstrap;

                public function __construct()
                {
                    parent::__construct('migration:migrate');
                    $this->setDescription('Run migrations');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Migration [Run Available Migrations]', function (SymfonyStyle $ss) {
                        $ss->progressStart(100);
                        $ss->progressAdvance();
                        $migrationHandler = new MigrationHandler($this->getContainer(), $this->getContainer()->resolve("db.kernel.connection"));
                        $result = $migrationHandler->migrate();
                        $ss->progressFinish();
                        $ss->section("Operation Completed");
                        $ss->listing([
                            "Total successfull: " . $result['successfull'],
                            "Total failed: " . $result['failed']
                        ]);
                        if ($result['failed'] > 0 ) $ss->text("Note: check storage/logs/reports/migrations.log for failed migrations.");
                        return Command::SUCCESS;
                    });
                }
            },

            new class extends Command {
                use CommandHelper, MigrationBootstrap;

                public function __construct()
                {
                    parent::__construct('migration:rollback');
                    $this->setDescription('Rollback migrations');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Database Migrations [Rollback Migrations]', function (SymfonyStyle $ss) {
                        $ss->progressStart(100);
                        $ss->progressAdvance();
                        $migrationHandler = new MigrationHandler($this->getContainer(), $this->getContainer()->resolve("db.kernel.connection"));
                        $migrationHandler->rollback();
                        $ss->progressFinish();
                        $ss->success("Operation Complete!");
                        return Command::SUCCESS;
                    });
                }
            },
        ];
    }
}