<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Mlangeni\Machinjiri\Core\Database\Migrations\MigrationCreator;

class Migrations
{
    public static function getCommands(): array
    {
        return [
            new class extends Command {
                protected static $defaultName = 'migration:create';
                protected static $defaultDescription = 'Create a Migration Template file';

                protected function configure(): void
                {
                    $this
                        ->addArgument('name', InputArgument::REQUIRED, 'The name of the migration template. Note: must be lowercase and worlds must be separated by underscores. Example create_user_table ');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    $output->writeln("Creating Migration Template");
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
                protected static $defaultName = 'migration:list';
                protected static $defaultDescription = 'Get Migrations';

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
                protected static $defaultName = 'migration:remove';
                protected static $defaultDescription = 'Delete a Migration';

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
            }
        ];
    }
}