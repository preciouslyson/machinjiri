<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class TestCommand extends Command
{
    public static getCommands(): array 
    {
      return [
        use CommandHelper;
        public function __construct()
        {
            parent::__construct('test');
            $this->setDescription('Run tests with optional parallel execution, coverage, and watch mode');
        }
    
        protected function configure(): void
        {
            $this->addOption('parallel', 'p', InputOption::VALUE_NONE, 'Run tests in parallel using Paratest');
            $this->addOption('coverage', 'c', InputOption::VALUE_NONE, 'Generate code coverage report');
            $this->addOption('group', 'g', InputOption::VALUE_REQUIRED, 'Only run tests from a specific group (e.g., --group=slow)');
            $this->addOption('filter', 'f', InputOption::VALUE_REQUIRED, 'Filter tests by name (e.g., --filter=testUser)');
            $this->addOption('watch', 'w', InputOption::VALUE_NONE, 'Watch for file changes and automatically rerun tests');
            $this->addOption('testsuite', 's', InputOption::VALUE_REQUIRED, 'Run a specific test suite (Unit, Feature, Integration)');
        }
    
        protected function execute(InputInterface $input, OutputInterface $output): int
        {
            return $this->executeWithStyle($input, $output, 'Test Runner', function (SymfonyStyle $ss) use ($input) {
                // Build the base command
                if ($input->getOption('parallel')) {
                    $command = $this->getParallelCommand($input);
                } else {
                    $command = $this->getStandardCommand($input);
                }
    
                // Handle watch mode (non‑blocking, re‑runs on file changes)
                if ($input->getOption('watch')) {
                    $ss->note('Watch mode is not fully implemented in this version.');
                    $ss->writeln('Please use an external tool like `entr` or `nodemon`.');
                    return Command::SUCCESS;
                }
    
                $ss->writeln('Running: ' . implode(' ', $command));
                $ss->newLine();
    
                $process = new Process($command);
                $process->setTty(true);
                $process->setTimeout(null);
                $process->run(function ($type, $buffer) use ($ss) {
                    $ss->write($buffer);
                });
    
                return $process->getExitCode() === 0 ? Command::SUCCESS : Command::FAILURE;
            });
        }
    
        /**
         * Build the standard PHPUnit command.
         */
        private function getStandardCommand(InputInterface $input): array
        {
            $cmd = [PHP_BINARY, 'vendor/bin/phpunit'];
    
            if ($input->getOption('coverage')) {
                $cmd[] = '--coverage-html=build/coverage';
            }
    
            if ($group = $input->getOption('group')) {
                $cmd[] = "--group={$group}";
            }
    
            if ($filter = $input->getOption('filter')) {
                $cmd[] = "--filter={$filter}";
            }
    
            if ($suite = $input->getOption('testsuite')) {
                $cmd[] = "--testsuite={$suite}";
            }
    
            return $cmd;
        }
    
        /**
         * Build the parallel test command using Paratest.
         */
        private function getParallelCommand(InputInterface $input): array
        {
            $cmd = [PHP_BINARY, 'vendor/bin/paratest', '--processes=auto'];
    
            if ($input->getOption('coverage')) {
                $cmd[] = '--coverage-html=build/coverage';
            }
    
            if ($group = $input->getOption('group')) {
                $cmd[] = "--group={$group}";
            }
    
            if ($filter = $input->getOption('filter')) {
                $cmd[] = "--filter={$filter}";
            }
    
            if ($suite = $input->getOption('testsuite')) {
                $cmd[] = "--testsuite={$suite}";
            }
    
            return $cmd;
        }
      ];
    }
}