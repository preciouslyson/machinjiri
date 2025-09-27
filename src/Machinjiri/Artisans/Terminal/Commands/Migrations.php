<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\ProgressBar;
use Mlangeni\Machinjiri\Core\Database\Migrations\MigrationCreator;
use Mlangeni\Machinjiri\Core\Database\Migrations\MigrationHandler;
use Mlangeni\Machinjiri\Core\Machinjiri;

class Migrations
{
    public static function getCommands(): array
    {
        return [
            new class extends Command {
                
                public function __construct ()
                {
                    parent::__construct('migration:create');
                    $this->setDescription('Create a Migration Template file');
                }

                protected function configure(): void
                {
                    $this
                        ->addArgument('name', InputArgument::REQUIRED, 'The name of the migration template. Note: must be lowercase and worlds must be separated by underscores. Example create_user_table ');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    $name = $input->getArgument('name');
                    
                    try {
                      $creator = new MigrationCreator();
                      $output->writeln("Created: " . basename($creator->create($name)));
                      return Command::SUCCESS;
                    } catch (MachinjiriException $e) {
                      $output->writeln("Error: " , $e->getMessage());
                      return Command::FAILURE;
                    }
                }
            },
            new class extends Command {
                
                public function __construct ()
                {
                    parent::__construct('migration:list_migrations');
                    $this->setDescription('Get a list of migration files');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    $output->writeln("List of Migrations \n");
  
                    try {
                      $creator = new MigrationCreator();
                      if (count($creator->getMigrationFiles()) > 0) {
                        $count = 0;
                        foreach ($creator->getMigrationFiles() as $migration) {
                          $count++;
                          $output->writeln($count . ". " . basename($migration));
                        }
                      } else {
                        $output->writeln("No Migrations available.");
                      }
                      
                      return Command::SUCCESS;
                    } catch (MachinjiriException $e) {
                      $output->writeln("Error: " , $e->getMessage());
                      return Command::FAILURE;
                    }
                }
            },
            new class extends Command {
                
                public function __construct ()
                {
                    parent::__construct('migration:remove');
                    $this->setDescription('Remove/delete a migration file');
                }
                
                protected function configure(): void
                {
                    $this
                        ->addArgument('name', InputArgument::REQUIRED, 'The name of the migration. Note: must be lowercase and worlds must be separated by underscores. Example create_user_table');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    $output->writeln("Deleting Migration");
                    $name = $input->getArgument('name');
                    
                    try {
                      $creator = new MigrationCreator();
                      if ($creator->removeMigration($name)) {
                        $output->writeln("Deleted successfully: " . $name);
                      } else {
                        $output->writeln("Unable to delete migration: " . $name);
                      }
                      
                      return Command::SUCCESS;
                    } catch (MachinjiriException $e) {
                      $output->writeln("Error: " , $e->getMessage());
                      return Command::FAILURE;
                    }
                }
            },
            new class extends Command {
                
                public function __construct ()
                {
                    parent::__construct('migration:get-ran');
                    $this->setDescription('Retrieve Migrations that have been ran');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    Machinjiri::App(__DIR__, true);
                    $output->writeln("Machinjiri Terminal - Ran Migrations");
                    
                    try {
                      $mh = new MigrationHandler();
                      $runMigrations = $mh->getRanMigrations(true);
                    
                      if (count($runMigrations) > 0) {
                        $counter = 0;
                        $table = new Table($output);
                        $table->setHeaders(['#', 'Migration', 'Created At']);
                        $rows = [];
                        foreach ($runMigrations as $runMigration) {
                          $counter++;
                          $rows[] = [$counter, $runMigration['migration'], $runMigration['created_at']];
                        }
                        $table->setRows($rows);
                        $table->render();
                      } else {
                        $output->writeln("No run migrations found.");
                      }
                      
                      return Command::SUCCESS;
                    } catch (MachinjiriException $e) {
                      $output->writeln("Error: " , $e->getMessage());
                      return Command::FAILURE;
                    }
                }
            },
            new class extends Command {
                
                public function __construct ()
                {
                    parent::__construct('migration:migrate');
                    $this->setDescription('Run migrations');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    Machinjiri::App(__DIR__, true);
                    $output->writeln("Machinjiri Terminal - Run Migrations");
                    
                    $progress = new ProgressBar($output, 100);
                    $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%%');
                    $progress->start();

                    
                    try {
                        $progress->advance();
                      (new MigrationHandler())->migrate();
                      $progress->finish();
                      $output->writeln('');
                      $output->writeln('migrations completed successfully. ' . date('Y-m-d h:i:s'));
                      
                      return Command::SUCCESS;
                    } catch (MachinjiriException $e) {
                      $output->writeln("Error: " , $e->getMessage());
                      return Command::FAILURE;
                    }
                }
            },
            new class extends Command {
                
                public function __construct ()
                {
                    parent::__construct('migration:rollback');
                    $this->setDescription('Rollback migrations');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    Machinjiri::App(__DIR__, true);
                    $output->writeln("Machinjiri Terminal - Rollback Migrations");
                    
                    $progress = new ProgressBar($output, 100);
                    $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%%');
                    $progress->start();

                    
                    try {
                        $progress->advance();
                      (new MigrationHandler())->rollback();
                      $progress->finish();
                      $output->writeln('');
                      $output->writeln('Rollback success. ' . date('Y-m-d h:i:s'));
                      
                      return Command::SUCCESS;
                    } catch (MachinjiriException $e) {
                      $output->writeln("Error: " , $e->getMessage());
                      return Command::FAILURE;
                    }
                }
            },
        ];
    }
} 