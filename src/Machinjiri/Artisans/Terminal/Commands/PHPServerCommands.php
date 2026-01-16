<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Mlangeni\Machinjiri\Core\Network\PHPServerManager;

class PHPServerCommands 
{
  public static function getCommands(): array
  {
    return [
      new class extends Command {
          public function __construct ()
          {
            parent::__construct('server:start');
            $this->setDescription('Start a developmental Server for Machinjiri');
          }
          protected function configure (): void 
          {
            $this->addOption("port", null, InputOption::VALUE_OPTIONAL, 'Port Number. In case current port is occupied');
          }

          protected function execute(InputInterface $input, OutputInterface $output): int
          {
              $ss = new SymfonyStyle($input, $output);
              $ss->title("Machinjiri - Development Server");
              $userPort = $input->getOption('port');
              $port = ($userPort !== null) ? $userPort : 3000;
              $options = ['log_file' => getcwd() . '/php_server.log'];
              
              $serverMgr = new PHPServerManager($port, $options);
              
              $result = $serverMgr->start();
              
              if (isset($result['success']) && $result['success']) {
                $ss->success('Server Started :)');
                $ss->text($result['message']);
                $ss->section("Server Information");
                $ss->listing([
                  "PID => " .$result['pid'],
                  "address => ".$result['address'],
                  "root => ".$result['document_root']
                ]);
                $ss->text("Open your web browser and navigate to http://". $result['address'] . "/");
                return Command::SUCCESS;
              } else {
                $ss->error($result['message']);
                $ss->section("Available Commands. Choose either of the following");
                $ss->listing([
                  'php artisan server:stop - To Stop the Server',
                  'php artisan server:restart - To Restart the Server'
                  ]);
                return Command::FAILURE;
              }
          }
      },
      new class extends Command {
          
          public function __construct ()
          {
            parent::__construct('server:stop');
            $this->setDescription('Stop a developmental Server for Machinjiri');
          }

          protected function execute(InputInterface $input, OutputInterface $output): int
          {
              $ss = new SymfonyStyle($input, $output);
              $ss->title("Machinjiri - Development Server");
              $serverMgr = new PHPServerManager();
              
              $result = $serverMgr->stop();
              
              if (isset($result['success']) && $result['success']) {
                $ss->warning($result['message']);
                return Command::SUCCESS;
              } else {
                $ss->error($result['message']);
                return Command::FAILURE;
              }
          }
      },
      new class extends Command {
          
          public function __construct ()
          {
            parent::__construct('server:restart');
            $this->setDescription('Restart developmental Server for Machinjiri');
          }

          protected function execute(InputInterface $input, OutputInterface $output): int
          {
              $ss = new SymfonyStyle($input, $output);
              $ss->title("Machinjiri - Development Server");
              $serverMgr = new PHPServerManager();
              
              $result = $serverMgr->restart();
              
              if (isset($result['success']) && $result['success']) {
                $ss->success($result['message']);
                return Command::SUCCESS;
              } else {
                $ss->error($result['message']);
                return Command::FAILURE;
              }
          }
      },
      new class extends Command {
          
          public function __construct ()
          {
            parent::__construct('server:status');
            $this->setDescription('Get developmental Server Status');
          }

          protected function execute(InputInterface $input, OutputInterface $output): int
          {
              $ss = new SymfonyStyle($input, $output);
              $ss->title("Machinjiri - Development Server");
              $serverMgr = new PHPServerManager();
              
              $result = $serverMgr->status();
              $list = [];
              $ss->section('Server Status');
              foreach ($result as $key => $value) {
                $list[] = strtoupper($key) . ' => ' . $value;
              }
              $ss->listing($list);
              return Command::SUCCESS;
          }
      },
      new class extends Command {
          public function __construct ()
          {
            parent::__construct('server:logs');
            $this->setDescription('Start a developmental Server for Machinjiri');
          }
          protected function configure (): void 
          {
            $this->addOption("lines", null, InputOption::VALUE_OPTIONAL, 'Number of lines to be returned. Default is 50');
          }

          protected function execute(InputInterface $input, OutputInterface $output): int
          {
              $ss = new SymfonyStyle($input, $output);
              $ss->title("Machinjiri - Development Server");
              $userLines = $input->getOption('lines');
              $lines = ($userLines !== null || $userLines > 0) ? $userLines : 50;
              
              $serverMgr = new PHPServerManager();
              
              $result = $serverMgr->getLogs($lines);
              
              if (isset($result['success']) && $result['success']) {
                $ss->section('Server Logs');
                
                $log = [];
                foreach ($result['lines'] as $line) {
                  $log[] = $line;
                }
                $ss->listing($log);
                
                $ss->info("Log File : " . $result['file']);
                
                return Command::SUCCESS;
              } else {
                $ss->warning($result['message']);
                return Command::FAILURE;
              }
          }
      },
    ];
  }
}