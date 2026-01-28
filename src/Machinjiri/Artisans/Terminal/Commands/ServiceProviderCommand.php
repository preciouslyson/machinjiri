<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\Table;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Artisans\Generators\ServiceProviderGenerator;

class ServiceProviderCommand
{
    public static function getCommands(): array
    {
        return [
            new class extends Command {
                public function __construct()
                {
                    parent::__construct('provider:make');
                    $this->setDescription("Generate an Application's Service Provider");
                }
                
                protected function configure(): void {
                    $this->addArgument('service', InputArgument::REQUIRED, 'The Service Provider name');
                    $this->addOption('deferred', null, InputOption::VALUE_OPTIONAL, 'Set Service Provider as Deferred');
                    $this->addOption('no-config', null, InputOption::VALUE_NONE, 'Do not create configuration file');
                    $this->addOption('stub', null, InputOption::VALUE_OPTIONAL, 'Use custom stub template');
                    $this->addOption('bind', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Add bindings (format: abstract=concrete)');
                    $this->addOption('singleton', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Add singleton bindings (format: abstract=concrete)');
                    $this->addOption('alias', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Add aliases (format: alias=abstract)');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    try {
                        $ss = new SymfonyStyle($input, $output);
                        $ss->title("Machinjiri - App Service Providers");
                        $generator = new ServiceProviderGenerator(getcwd());
                        $service = $input->getArgument('service');
                        
                        $options = [];
                        if ($input->getOption('deferred') !== null) {
                            $options['deferred'] = true;
                        }
                        
                        if ($input->getOption('no-config')) {
                            $options['config'] = false;
                        }
                        
                        // Process bindings
                        $bindings = [];
                        foreach ($input->getOption('bind') as $binding) {
                            list($abstract, $concrete) = explode('=', $binding, 2);
                            $bindings[$abstract] = $concrete;
                        }
                        if (!empty($bindings)) {
                            $options['bindings'] = $bindings;
                        }
                        
                        // Process singletons
                        $singletons = [];
                        foreach ($input->getOption('singleton') as $singleton) {
                            list($abstract, $concrete) = explode('=', $singleton, 2);
                            $singletons[$abstract] = $concrete;
                        }
                        if (!empty($singletons)) {
                            $options['singletons'] = $singletons;
                        }
                        
                        // Process aliases
                        $aliases = [];
                        foreach ($input->getOption('alias') as $alias) {
                            list($aliasName, $abstract) = explode('=', $alias, 2);
                            $aliases[$aliasName] = $abstract;
                        }
                        if (!empty($aliases)) {
                            $options['aliases'] = $aliases;
                        }
                        
                        // Use custom stub if provided
                        $stub = $input->getOption('stub');
                        if ($stub) {
                            $replacements = [];
                            if (!empty($bindings)) {
                                $replacements['{{bindings}}'] = var_export($bindings, true);
                            }
                            if (!empty($singletons)) {
                                $replacements['{{singletons}}'] = var_export($singletons, true);
                            }
                            if (!empty($aliases)) {
                                $replacements['{{aliases}}'] = var_export($aliases, true);
                            }
                            
                            $result = $generator->generateFromStub($service, $stub, $replacements);
                            $ss->success("Service Provider generated from stub '{$stub}' successfully");
                            return Command::SUCCESS;
                        } else {
                            $result = $generator->generate($service, $options);
                            if (count($result) > 0) {
                                $ss->success("Service Provider generated successfully \n");
                                $ss->listing($result);
                                return Command::SUCCESS;
                            } else {
                                $ss->error("Could not generate Service Provider \n");
                                return Command::FAILURE;
                            }
                        }
                    } catch (MachinjiriException $e) {
                        $output->writeln("Could not Generate due to " . $e->getMessage());
                        return Command::FAILURE;
                    }
                }
            },
            
            new class extends Command {
                public function __construct()
                {
                    parent::__construct('provider:remove');
                    $this->setDescription("Remove a Service Provider");
                }
                
                protected function configure(): void {
                    $this->addArgument('service', InputArgument::REQUIRED, 'The Service Provider name');
                    $this->addOption('config', null, InputOption::VALUE_NONE, 'Also remove its configuration file');
                    $this->addOption('force', null, InputOption::VALUE_NONE, 'Force removal without confirmation');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    try {
                        $ss = new SymfonyStyle($input, $output);
                        $ss->title("Machinjiri - Service Providers");
                        $generator = new ServiceProviderGenerator(getcwd());
                        $service = $input->getArgument('service');
                        
                        // Ask for confirmation
                        if (!$input->getOption('force')) {
                            if (!$ss->confirm("Are you sure you want to remove service provider '{$service}'?", false)) {
                                $ss->warning('Operation cancelled.');
                                return Command::SUCCESS;
                            }
                        }
                        
                        $removeConfig = $input->getOption('config');
                        $result = $generator->remove($service, $removeConfig, true);
                        
                        if ($result) {
                            $ss->success("Service Provider removed successfully \n");
                            return Command::SUCCESS;
                        } else {
                            $ss->error("Could not remove Service Provider \n");
                            return Command::FAILURE;
                        }
                    } catch (MachinjiriException $e) {
                        $ss->error("Could not remove provider due to " . $e->getMessage());
                        return Command::FAILURE;
                    }
                }
            },
            
            new class extends Command {
                public function __construct()
                {
                    parent::__construct('provider:list');
                    $this->setDescription("List all Service Providers");
                    $this->addOption('details', null, InputOption::VALUE_NONE, 'Show detailed information');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    try {
                        $ss = new SymfonyStyle($input, $output);
                        $ss->title("Machinjiri - Service Providers");
                        
                        $generator = new ServiceProviderGenerator(getcwd());
                        $providers = $generator->listProviders();
                        
                        if (empty($providers)) {
                            $ss->warning('No service providers found.');
                            return Command::SUCCESS;
                        }
                        
                        if ($input->getOption('details')) {
                            $table = new Table($output);
                            $table->setHeaders(['Name', 'File', 'Path', 'Class', 'Exists']);
                            
                            foreach ($providers as $provider) {
                                $table->addRow([
                                    $provider['name'],
                                    $provider['file'],
                                    $provider['path'],
                                    $provider['class'],
                                    $provider['exists'] ? 'Yes' : 'No',
                                ]);
                            }
                            
                            $table->render();
                        } else {
                            $providerNames = array_column($providers, 'name');
                            $ss->listing($providerNames);
                        }
                        
                        $ss->success(sprintf('Found %d service provider(s)', count($providers)));
                        return Command::SUCCESS;
                    } catch (MachinjiriException $e) {
                        $ss->error("Could not list providers due to " . $e->getMessage());
                        return Command::FAILURE;
                    }
                }
            },
            
            new class extends Command {
                public function __construct()
                {
                    parent::__construct('provider:init');
                    $this->setDescription("Initialize all basic Service Providers");
                    $this->addOption('force', null, InputOption::VALUE_NONE, 'Force re-creation of existing providers');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    try {
                        $ss = new SymfonyStyle($input, $output);
                        $ss->title("Machinjiri - Initialize Basic Service Providers");
                        
                        $generator = new ServiceProviderGenerator(getcwd());
                        $result = $generator->generateAllBasic();
                        
                        if (count($result) > 0) {
                            $ss->success("Basic service providers initialized successfully");
                            $ss->listing($result);
                            return Command::SUCCESS;
                        } else {
                            $ss->warning("All basic service providers already exist");
                            return Command::SUCCESS;
                        }
                    } catch (MachinjiriException $e) {
                        $ss->error("Could not initialize providers due to " . $e->getMessage());
                        return Command::FAILURE;
                    }
                }
            },
            
            new class extends Command {
                public function __construct()
                {
                    parent::__construct('provider:stub');
                    $this->setDescription("Create a Service Provider from custom stub");
                }
                
                protected function configure(): void {
                    $this->addArgument('service', InputArgument::REQUIRED, 'The Service Provider name');
                    $this->addArgument('stub', InputArgument::REQUIRED, 'The stub template name');
                    $this->addOption('replace', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Custom replacements (format: key=value)');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    try {
                        $ss = new SymfonyStyle($input, $output);
                        $ss->title("Machinjiri - Create Service Provider from Stub");
                        
                        $generator = new ServiceProviderGenerator(getcwd());
                        $service = $input->getArgument('service');
                        $stubName = $input->getArgument('stub');
                        
                        // Process custom replacements
                        $replacements = [];
                        foreach ($input->getOption('replace') as $replacement) {
                            list($key, $value) = explode('=', $replacement, 2);
                            $replacements['{{' . $key . '}}'] = $value;
                        }
                        
                        $result = $generator->generateFromStub($service, $stubName, $replacements);
                        
                        $ss->success("Service Provider created from stub '{$stubName}' successfully");
                        $ss->text("File: {$result}");
                        return Command::SUCCESS;
                    } catch (MachinjiriException $e) {
                        $ss->error("Could not create provider from stub due to " . $e->getMessage());
                        return Command::FAILURE;
                    }
                }
            },
            
            new class extends Command {
                public function __construct()
                {
                    parent::__construct('provider:register');
                    $this->setDescription("Register a Service Provider in providers.php config");
                }
                
                protected function configure(): void {
                    $this->addArgument('service', InputArgument::REQUIRED, 'The Service Provider name');
                    $this->addOption('deferred', null, InputOption::VALUE_NONE, 'Register as deferred provider');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    try {
                        $ss = new SymfonyStyle($input, $output);
                        $ss->title("Machinjiri - Register Service Provider");
                        
                        $generator = new ServiceProviderGenerator(getcwd());
                        $service = $input->getArgument('service');
                        $service = $generator->normalizeName($service);
                        
                        // Manually register in providers config
                        $providersConfig = getcwd() . '/config/providers.php';
                        
                        if (!file_exists($providersConfig)) {
                            // Create default config if it doesn't exist
                            $generator->createDefaultProvidersConfig();
                            $ss->text("Created providers.php configuration file");
                        }
                        
                        $config = require $providersConfig;
                        $providerClass = "Mlangeni\\Machinjiri\\App\\Providers\\{$service}";
                        
                        $updated = false;
                        
                        // Add to providers array if not already present
                        if (!in_array($providerClass, $config['providers'] ?? [])) {
                            $config['providers'][] = $providerClass;
                            $updated = true;
                            $ss->text("Added to providers array");
                        }
                        
                        // Add to deferred array if requested
                        if ($input->getOption('deferred')) {
                            if (!isset($config['deferred'])) {
                                $config['deferred'] = [];
                            }
                            if (!in_array($providerClass, $config['deferred'])) {
                                $config['deferred'][] = $providerClass;
                                $updated = true;
                                $ss->text("Added to deferred providers array");
                            }
                        }
                        
                        if ($updated) {
                            // Write updated configuration
                            $content = "<?php\nreturn " . var_export($config, true) . ";\n";
                            file_put_contents($providersConfig, $content);
                            $ss->success("Service Provider registered successfully in providers.php");
                        } else {
                            $ss->warning("Service Provider is already registered");
                        }
                        
                        return Command::SUCCESS;
                    } catch (MachinjiriException $e) {
                        $ss->error("Could not register provider due to " . $e->getMessage());
                        return Command::FAILURE;
                    } catch (\Exception $e) {
                        $ss->error("Error: " . $e->getMessage());
                        return Command::FAILURE;
                    }
                }
            },
            
            new class extends Command {
                public function __construct()
                {
                    parent::__construct('provider:unregister');
                    $this->setDescription("Unregister a Service Provider from providers.php config");
                }
                
                protected function configure(): void {
                    $this->addArgument('service', InputArgument::REQUIRED, 'The Service Provider name');
                    $this->addOption('force', null, InputOption::VALUE_NONE, 'Force unregistration without confirmation');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    try {
                        $ss = new SymfonyStyle($input, $output);
                        $ss->title("Machinjiri - Unregister Service Provider");
                        
                        $generator = new ServiceProviderGenerator(getcwd());
                        $service = $input->getArgument('service');
                        $service = $generator->normalizeName($service);
                        
                        // Ask for confirmation
                        if (!$input->getOption('force')) {
                            if (!$ss->confirm("Are you sure you want to unregister service provider '{$service}' from providers.php?", false)) {
                                $ss->warning('Operation cancelled.');
                                return Command::SUCCESS;
                            }
                        }
                        
                        $providersConfig = getcwd() . '/config/providers.php';
                        
                        if (!file_exists($providersConfig)) {
                            $ss->error("providers.php configuration file not found");
                            return Command::FAILURE;
                        }
                        
                        $config = require $providersConfig;
                        $providerClass = "Mlangeni\\Machinjiri\\App\\Providers\\{$service}";
                        
                        $updated = false;
                        
                        // Remove from providers array
                        if (isset($config['providers']) && ($key = array_search($providerClass, $config['providers'])) !== false) {
                            unset($config['providers'][$key]);
                            $config['providers'] = array_values($config['providers']);
                            $updated = true;
                            $ss->text("Removed from providers array");
                        }
                        
                        // Remove from deferred array
                        if (isset($config['deferred']) && ($key = array_search($providerClass, $config['deferred'])) !== false) {
                            unset($config['deferred'][$key]);
                            $config['deferred'] = array_values($config['deferred']);
                            $updated = true;
                            $ss->text("Removed from deferred providers array");
                        }
                        
                        if ($updated) {
                            // Write updated configuration
                            $content = "<?php\nreturn " . var_export($config, true) . ";\n";
                            file_put_contents($providersConfig, $content);
                            $ss->success("Service Provider unregistered successfully from providers.php");
                        } else {
                            $ss->warning("Service Provider was not found in providers.php");
                        }
                        
                        return Command::SUCCESS;
                    } catch (MachinjiriException $e) {
                        $ss->error("Could not unregister provider due to " . $e->getMessage());
                        return Command::FAILURE;
                    } catch (\Exception $e) {
                        $ss->error("Error: " . $e->getMessage());
                        return Command::FAILURE;
                    }
                }
            },
            
            new class extends Command {
                public function __construct()
                {
                    parent::__construct('provider:info');
                    $this->setDescription("Show information about a Service Provider");
                }
                
                protected function configure(): void {
                    $this->addArgument('service', InputArgument::REQUIRED, 'The Service Provider name');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    try {
                        $ss = new SymfonyStyle($input, $output);
                        $ss->title("Machinjiri - Service Provider Information");
                        
                        $generator = new ServiceProviderGenerator(getcwd());
                        $service = $input->getArgument('service');
                        $service = $generator->normalizeName($service);
                        
                        $providerFile = getcwd() . '/app/Providers/' . $service . '.php';
                        
                        if (!file_exists($providerFile)) {
                            $ss->error("Service Provider not found: {$service}");
                            return Command::FAILURE;
                        }
                        
                        $providerClass = "Mlangeni\\Machinjiri\\App\\Providers\\{$service}";
                        $configName = strtolower(str_replace('ServiceProvider', '', $service));
                        $configFile = getcwd() . '/config/' . $configName . '.php';
                        
                        $info = [
                            ['Property', 'Value'],
                            ['Name', $service],
                            ['Class', $providerClass],
                            ['File', $providerFile],
                            ['Exists', file_exists($providerFile) ? 'Yes' : 'No'],
                            ['Class Exists', class_exists($providerClass) ? 'Yes' : 'No'],
                        ];
                        
                        // Check if provider file can be loaded
                        if (file_exists($providerFile)) {
                            $content = file_get_contents($providerFile);
                            
                            // Extract deferred status
                            preg_match('/protected bool \$defer = (true|false);/', $content, $deferredMatch);
                            $deferred = $deferredMatch[1] ?? 'false';
                            $info[] = ['Deferred', $deferred];
                            
                            // Extract bindings count
                            preg_match('/protected array \$bindings = (\[.*?\]);/s', $content, $bindingsMatch);
                            if ($bindingsMatch) {
                                eval('$bindings = ' . $bindingsMatch[1] . ';');
                                $info[] = ['Bindings Count', count($bindings)];
                            }
                            
                            // Extract singletons count
                            preg_match('/protected array \$singletons = (\[.*?\]);/s', $content, $singletonsMatch);
                            if ($singletonsMatch) {
                                eval('$singletons = ' . $singletonsMatch[1] . ';');
                                $info[] = ['Singletons Count', count($singletons)];
                            }
                            
                            // Extract aliases count
                            preg_match('/protected array \$aliases = (\[.*?\]);/s', $content, $aliasesMatch);
                            if ($aliasesMatch) {
                                eval('$aliases = ' . $aliasesMatch[1] . ';');
                                $info[] = ['Aliases Count', count($aliases)];
                            }
                        }
                        
                        $table = new Table($output);
                        $table->setRows($info);
                        $table->render();
                        
                        // Check if registered in providers.php
                        $providersConfig = getcwd() . '/config/providers.php';
                        if (file_exists($providersConfig)) {
                            $config = require $providersConfig;
                            $isRegistered = in_array($providerClass, $config['providers'] ?? []);
                            $isDeferred = isset($config['deferred']) && in_array($providerClass, $config['deferred']);
                            
                            $ss->text("\nRegistration Status:");
                            $ss->text(sprintf("Registered in providers.php: %s", $isRegistered ? 'Yes' : 'No'));
                            if ($isRegistered) {
                                $ss->text(sprintf("Marked as deferred: %s", $isDeferred ? 'Yes' : 'No'));
                            }
                        }
                        
                        // Check for configuration file
                        if (file_exists($configFile)) {
                            $ss->text("\nConfiguration: Found at " . $configFile);
                        }
                        
                        return Command::SUCCESS;
                    } catch (MachinjiriException $e) {
                        $ss->error("Could not get provider info due to " . $e->getMessage());
                        return Command::FAILURE;
                    } catch (\Exception $e) {
                        $ss->error("Error: " . $e->getMessage());
                        return Command::FAILURE;
                    }
                }
            },
            
            new class extends Command {
                public function __construct()
                {
                    parent::__construct('provider:thirdparty-auth');
                    $this->setDescription("Generate ThirdPartyAuthServiceProvider with configuration");
                }
                
                protected function configure(): void {
                    $this->addOption('deferred', null, InputOption::VALUE_NONE, 'Make provider deferred (lazy-loaded)');
                    $this->addOption('no-config', null, InputOption::VALUE_NONE, 'Skip configuration file creation');
                    $this->addOption('no-migration', null, InputOption::VALUE_NONE, 'Skip database migration creation');
                    $this->addOption('providers', null, InputOption::VALUE_OPTIONAL, 'Comma-separated list of providers to enable');
                    $this->addOption('force', null, InputOption::VALUE_NONE, 'Force creation even if files exist');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    try {
                        $ss = new SymfonyStyle($input, $output);
                        $ss->title("Machinjiri - Third-Party Authentication Setup");
                        
                        $generator = new ServiceProviderGenerator(getcwd());
                        
                        $options = [
                            'deferred' => $input->getOption('deferred'),
                            'config' => !$input->getOption('no-config'),
                            'database' => !$input->getOption('no-migration'),
                            'register' => true,
                        ];
                        
                        // Parse providers list
                        if ($input->getOption('providers')) {
                            $providers = array_map('trim', explode(',', $input->getOption('providers')));
                            $options['providers'] = $providers;
                        }
                        
                        // Check if files already exist
                        $providerFile = getcwd() . '/app/Providers/ThirdPartyAuthServiceProvider.php';
                        if (file_exists($providerFile) && !$input->getOption('force')) {
                            $ss->error("ThirdPartyAuthServiceProvider already exists. Use --force to overwrite.");
                            return Command::FAILURE;
                        }
                        
                        $result = $generator->generateThirdPartyAuth($options);
                        
                        if (count($result) > 0) {
                            $ss->success("Third-Party Authentication setup completed successfully!\n");
                            $ss->text("Created files:");
                            $ss->listing($result);
                            
                            // Display next steps
                            $ss->section("Next Steps");
                            $ss->text([
                                "1. Add OAuth credentials to your .env file:",
                                "   GOOGLE_CLIENT_ID=your-client-id",
                                "   GOOGLE_CLIENT_SECRET=your-client-secret",
                                "   GITHUB_CLIENT_ID=your-client-id",
                                "   GITHUB_CLIENT_SECRET=your-client-secret",
                                "",
                                "2. Run database migrations to create necessary tables",
                                "",
                                "3. Customize routes in your application to handle:",
                                "   /auth/login?provider=google",
                                "   /auth/callback",
                                "   /auth/logout",
                                "",
                                "4. Review the configuration file at: config/thirdparty_auth.php",
                            ]);
                            
                            return Command::SUCCESS;
                        } else {
                            $ss->error("Could not generate Third-Party Authentication setup");
                            return Command::FAILURE;
                        }
                    } catch (MachinjiriException $e) {
                        $ss->error("Setup failed: " . $e->getMessage());
                        return Command::FAILURE;
                    } catch (\Exception $e) {
                        $ss->error("Error: " . $e->getMessage());
                        return Command::FAILURE;
                    }
                }
            }
        ];
    }
}