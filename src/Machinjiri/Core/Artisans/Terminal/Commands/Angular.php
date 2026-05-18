<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Integrations\Angular\Angular as AngularIntegration;
use Mlangeni\Machinjiri\Core\Artisans\Generators\ServiceProviderGenerator;

/**
 * Angular CLI Integration Commands
 *
 * Provides console commands to manage Angular integration with Machinjiri.
 */
class Angular
{
    /**
     * Get all Angular commands.
     *
     * @return array<Command>
     */
    public static function getCommands(): array
    {
        return [
            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('angular:init');
                    $this->setDescription('Initialize Angular integration in the current project');
                    $this->setHelp(<<<HELP
This command will:
- Create the Angular integration configuration file (config/angular.php)
- Generate the AngularServiceProvider (app/Providers/AngularServiceProvider.php)
- Register the provider in config/providers.php
- Optionally create a default Angular app in the specified directory
HELP
                    );
                }

                protected function configure(): void
                {
                    $this->addOption('app-dir', null, InputOption::VALUE_OPTIONAL, 'Directory where Angular app will be created (relative to project root)', 'angular-app');
                    $this->addOption('build-dir', null, InputOption::VALUE_OPTIONAL, 'Build output directory (relative to public)', 'angular');
                    $this->addOption('no-ng-new', null, InputOption::VALUE_NONE, 'Skip creating a new Angular app (assume existing Angular project)');
                    $this->addOption('skip-provider', null, InputOption::VALUE_NONE, 'Skip generating the service provider');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Angular Integration Initializer', function (SymfonyStyle $ss) use ($input) {
                        $container = new Container(getcwd());
                        $container->initialize();

                        $created = [];

                        // 1. Create configuration file
                        $configPath = $this->createAngularConfig($container, $input);
                        if ($configPath) {
                            $created[] = 'Configuration: ' . $configPath;
                        }

                        // 2. Create service provider
                        if (!$input->getOption('skip-provider')) {
                            $providerPath = $this->createAngularServiceProvider($container);
                            if ($providerPath) {
                                $created[] = 'Service Provider: ' . $providerPath;
                            }
                        }

                        // 3. Register provider in config/providers.php
                        $this->registerProviderInConfig($container);

                        // 4. Optionally create a new Angular app
                        if (!$input->getOption('no-ng-new')) {
                            $appDir = $input->getOption('app-dir') ?: 'angular-app';
                            $result = $this->runNgNew($appDir);
                            if ($result) {
                                $created[] = "Angular app created in: {$appDir}";
                                // Update build path in config to point to the new app's dist
                                $this->updateBuildPathInConfig($container, $appDir, $input->getOption('build-dir') ?: 'angular');
                            } else {
                                $ss->warning("Could not create Angular app automatically. Please run 'ng new' manually.");
                            }
                        }

                        if (empty($created)) {
                            $ss->warning('Nothing was created.');
                            return Command::SUCCESS;
                        }

                        $ss->success('Angular integration initialized successfully!');
                        $ss->section('Created items');
                        $ss->listing($created);
                        $ss->note('You may need to run "composer dump-autoload" if you added new classes.');

                        return Command::SUCCESS;
                    });
                }

                private function createAngularConfig(Container $container, InputInterface $input): ?string
                {
                    $configDir = getcwd() . '/config/';
                    if (!is_dir($configDir)) {
                        mkdir($configDir, 0755, true);
                    }
                    $configFile = $configDir . 'angular.php';
                    if (file_exists($configFile)) {
                        return null; // already exists
                    }

                    $buildDir = $input->getOption('build-dir') ?: 'angular';
                    $template = <<<PHP
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Angular Build Path (relative to public directory)
    |--------------------------------------------------------------------------
    */
    'build_path' => env('ANGULAR_BUILD_PATH', '{$buildDir}'),

    /*
    |--------------------------------------------------------------------------
    | Angular Index File Name
    |--------------------------------------------------------------------------
    */
    'index_file' => 'index.html',

    /*
    |--------------------------------------------------------------------------
    | Development Server URL
    |--------------------------------------------------------------------------
    */
    'dev_server_url' => env('ANGULAR_DEV_SERVER_URL', 'http://localhost:4200'),

    /*
    |--------------------------------------------------------------------------
    | Auto-detect development server (when APP_ENV=development)
    |--------------------------------------------------------------------------
    */
    'auto_detect_dev_server' => true,

    /*
    |--------------------------------------------------------------------------
    | Force use of development server (overrides auto-detection)
    |--------------------------------------------------------------------------
    */
    'use_dev_server' => false,

    /*
    |--------------------------------------------------------------------------
    | Paths excluded from Angular fallback (regex supported)
    |--------------------------------------------------------------------------
    */
    'excluded_paths' => [
        '/api/',
        '/assets/',
        '/storage/',
        '/vendor/',
        'favicon.ico',
        'robots.txt',
    ],
];

PHP;
                    file_put_contents($configFile, $template);
                    return $configFile;
                }

                private function createAngularServiceProvider(Container $container): ?string
                {
                    $providersDir = getcwd() . '/app/Providers/';
                    if (!is_dir($providersDir)) {
                        mkdir($providersDir, 0755, true);
                    }

                    $providerFile = $providersDir . 'AngularServiceProvider.php';
                    if (file_exists($providerFile)) {
                        return null;
                    }

                    $template = <<<'PHP'
<?php

namespace Mlangeni\Machinjiri\App\Providers;

use Mlangeni\Machinjiri\Core\Providers\ServiceProvider;
use Mlangeni\Machinjiri\Integrations\Angular\Angular;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Routing\Router;

class AngularServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Angular::class, function ($app) {
            $eventListener = $app->bound(EventListener::class)
                ? $app->make(EventListener::class)
                : null;
            $logger = $app->bound(Logger::class)
                ? $app->make(Logger::class)
                : null;
            return new Angular($app, $eventListener, $logger);
        });
    }

    public function boot(): void
    {
        $angular = $this->app->make(Angular::class);

        if ($angular->isHot()) {
            Router::any('/{any}', function ($request, $response) use ($angular) {
                return $angular->proxyToDevServer($request, $response);
            })->where(['any' => '.*']);
        } else {
            Router::any('/{any}', function ($request, $response) use ($angular) {
                return $angular->serve($request, $response);
            })->where(['any' => '.*']);
        }

        // Example: Listen to Angular events
        $angular->getEventListener()->on('angular.serve.fallback', function ($payload) {
            // Log or track fallback usage
        });
    }
}

PHP;
                    file_put_contents($providerFile, $template);
                    return $providerFile;
                }

                private function registerProviderInConfig(Container $container): void
                {
                    $providersConfigFile = getcwd() . '/config/providers.php';
                    if (!file_exists($providersConfigFile)) {
                        // Create default providers.php
                        $default = <<<'PHP'
<?php
return [
    'providers' => [],
    'deferred' => [],
];
PHP;
                        file_put_contents($providersConfigFile, $default);
                    }

                    $config = require $providersConfigFile;
                    $providerClass = 'Mlangeni\\Machinjiri\\App\\Providers\\AngularServiceProvider';

                    if (!in_array($providerClass, $config['providers'] ?? [])) {
                        $config['providers'][] = $providerClass;
                        $content = "<?php\nreturn " . var_export($config, true) . ";\n";
                        file_put_contents($providersConfigFile, $content);
                    }
                }

                private function runNgNew(string $appDir): bool
                {
                    $command = "ng new {$appDir} --defaults --skip-git --package-manager=npm";
                    exec($command, $output, $returnCode);
                    return $returnCode === 0;
                }

                private function updateBuildPathInConfig(Container $container, string $appDir, string $buildDir): void
                {
                    $configFile = $container->config . 'angular.php';
                    if (!file_exists($configFile)) {
                        return;
                    }

                    // Read existing config
                    $config = require $configFile;
                    $config['build_path'] = $buildDir;
                    $content = "<?php\nreturn " . var_export($config, true) . ";\n";
                    file_put_contents($configFile, $content);

                    // Also update angular.json in the new app to output to the correct public directory
                    $angularJsonPath = getcwd() . '/' . $appDir . '/angular.json';
                    if (file_exists($angularJsonPath)) {
                        $angularConfig = json_decode(file_get_contents($angularJsonPath), true);
                        $outputPath = '../../public/' . $buildDir;
                        $angularConfig['projects'][$appDir]['architect']['build']['options']['outputPath'] = $outputPath;
                        file_put_contents($angularJsonPath, json_encode($angularConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    }
                }
            },

            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('angular:build');
                    $this->setDescription('Build the Angular application for production');
                }

                protected function configure(): void
                {
                    $this->addOption('app-dir', null, InputOption::VALUE_OPTIONAL, 'Angular app directory (if not in root)', 'angular-app');
                    $this->addOption('watch', 'w', InputOption::VALUE_NONE, 'Watch for changes and rebuild');
                    $this->addOption('prod', null, InputOption::VALUE_NONE, 'Build with production configuration');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Angular Build', function (SymfonyStyle $ss) use ($input) {
                        $appDir = $input->getOption('app-dir');
                        $watch = $input->getOption('watch') ? '--watch' : '';
                        $prod = $input->getOption('prod') ? '--configuration production' : '';

                        $command = "cd {$appDir} && ng build {$prod} {$watch}";
                        $ss->text("Running: {$command}");
                        exec($command, $outputLines, $returnCode);

                        if ($returnCode === 0) {
                            $ss->success('Angular build completed successfully.');
                        } else {
                            $ss->error('Angular build failed. Check output above.');
                        }

                        return $returnCode === 0 ? Command::SUCCESS : Command::FAILURE;
                    });
                }
            },

            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('angular:serve');
                    $this->setDescription('Run Angular development server with proxy to Machinjiri backend');
                }

                protected function configure(): void
                {
                    $this->addOption('app-dir', null, InputOption::VALUE_OPTIONAL, 'Angular app directory', 'angular-app');
                    $this->addOption('port', null, InputOption::VALUE_OPTIONAL, 'Port for Angular dev server', '4200');
                    $this->addOption('proxy-config', null, InputOption::VALUE_OPTIONAL, 'Proxy configuration file (relative to app-dir)', 'proxy.conf.json');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Angular Dev Server', function (SymfonyStyle $ss) use ($input) {
                        $appDir = $input->getOption('app-dir');
                        $port = $input->getOption('port');
                        $proxyConfig = $input->getOption('proxy-config');

                        // Generate proxy configuration if not exists
                        $proxyPath = getcwd() . '/' . $appDir . '/' . $proxyConfig;
                        if (!file_exists($proxyPath)) {
                            $this->generateProxyConfig($proxyPath);
                            $ss->info("Generated proxy config at: {$proxyPath}");
                        }

                        $command = "cd {$appDir} && ng serve --port {$port} --proxy-config {$proxyConfig}";
                        $ss->text("Starting Angular dev server: {$command}");
                        $ss->note("Press Ctrl+C to stop the server.");

                        passthru($command);

                        return Command::SUCCESS;
                    });
                }

                private function generateProxyConfig(string $path): void
                {
                    $proxy = [
                        [
                            "context" => ["/api", "/assets", "/storage"],
                            "target" => "http://localhost:8000", // default PHP server port
                            "secure" => false,
                            "changeOrigin" => true,
                            "logLevel" => "debug"
                        ]
                    ];
                    file_put_contents($path, json_encode($proxy, JSON_PRETTY_PRINT));
                }
            },

            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('angular:clear-cache');
                    $this->setDescription('Clear Angular build cache');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Clear Angular Cache', function (SymfonyStyle $ss) {
                        $publicBuild = getcwd() . '/public/angular';
                        if (is_dir($publicBuild)) {
                            $this->deleteDirectory($publicBuild);
                            $ss->success("Cleared build directory: {$publicBuild}");
                        } else {
                            $ss->comment("No build directory found.");
                        }

                        $nodeModulesCache = getcwd() . '/angular-app/.angular/cache';
                        if (is_dir($nodeModulesCache)) {
                            $this->deleteDirectory($nodeModulesCache);
                            $ss->success("Cleared Angular CLI cache.");
                        }

                        return Command::SUCCESS;
                    });
                }

                private function deleteDirectory(string $dir): void
                {
                    $files = array_diff(scandir($dir), ['.', '..']);
                    foreach ($files as $file) {
                        $path = $dir . DIRECTORY_SEPARATOR . $file;
                        is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
                    }
                    rmdir($dir);
                }
            },

            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('angular:make:component');
                    $this->setDescription('Generate an Angular component inside the Angular app');
                }

                protected function configure(): void
                {
                    $this->addArgument('name', InputArgument::REQUIRED, 'Component name');
                    $this->addOption('app-dir', null, InputOption::VALUE_OPTIONAL, 'Angular app directory', 'angular-app');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Generate Angular Component', function (SymfonyStyle $ss) use ($input) {
                        $name = $input->getArgument('name');
                        $appDir = $input->getOption('app-dir');
                        $command = "cd {$appDir} && ng generate component {$name}";
                        $ss->text("Running: {$command}");
                        exec($command, $outputLines, $returnCode);

                        if ($returnCode === 0) {
                            $ss->success("Component '{$name}' generated.");
                        } else {
                            $ss->error("Failed to generate component.");
                        }

                        return $returnCode === 0 ? Command::SUCCESS : Command::FAILURE;
                    });
                }
            },

            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('angular:make:service');
                    $this->setDescription('Generate an Angular service inside the Angular app');
                }

                protected function configure(): void
                {
                    $this->addArgument('name', InputArgument::REQUIRED, 'Service name');
                    $this->addOption('app-dir', null, InputOption::VALUE_OPTIONAL, 'Angular app directory', 'angular-app');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Generate Angular Service', function (SymfonyStyle $ss) use ($input) {
                        $name = $input->getArgument('name');
                        $appDir = $input->getOption('app-dir');
                        $command = "cd {$appDir} && ng generate service {$name}";
                        $ss->text("Running: {$command}");
                        exec($command, $outputLines, $returnCode);

                        if ($returnCode === 0) {
                            $ss->success("Service '{$name}' generated.");
                        } else {
                            $ss->error("Failed to generate service.");
                        }

                        return $returnCode === 0 ? Command::SUCCESS : Command::FAILURE;
                    });
                }
            }
        ];
    }
}