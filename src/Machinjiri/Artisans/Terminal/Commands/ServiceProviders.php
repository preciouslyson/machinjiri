<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Providers\ServiceProviderGenerator;

class ServiceProviders
{
    public static function getCommands(): array
    {
        return [
            new class extends Command {
                
                public function __construct ()
                {
                  parent::__construct('provider:make');
                  $this->setDescription('Creates a custom Service Provider');
                }

                protected function configure(): void
                {
                    $this
                        ->addArgument('name', InputArgument::REQUIRED, 'Service Provider Name (eg. CustomeServiceProvider).');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    $name = $input->getArgument('name');
                    $generator = new ServiceProviderGenerator('./');
                    
                }
            },
        ];
    }
}