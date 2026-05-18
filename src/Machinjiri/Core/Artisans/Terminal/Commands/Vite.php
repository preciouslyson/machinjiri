<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Integrations\Vite\Vite as ViteIntegration;

/**
 * Production-ready Vite Integration Commands
 */
class Vite
{
    /**
     * Get all Vite commands.
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
                    parent::__construct('vite:init');
                    $this->setDescription('Initialize Vite integration in the current project');
                    $this->setHelp(<<<HELP
This command will:
- Create the Vite integration configuration file (config/vite.php)
- Generate the ViteServiceProvider (app/Providers/ViteServiceProvider.php)
- Register the provider in config/providers.php
- Optionally create a basic Vite project scaffold in the specified directory
HELP
                    );
                }

                protected function configure(): void
                {
                    $this->addOption('app-dir', null, InputOption::VALUE_OPTIONAL, 'Directory where Vite frontend source will be created (relative to project root)', 'resources/frontend');
                    $this->addOption('build-dir', null, InputOption::VALUE_OPTIONAL, 'Build output directory (relative to public)', 'build');
                    $this->addOption('template', null, InputOption::VALUE_OPTIONAL, 'Vite template (vanilla, vue, react, preact, lit, svelte)', 'vanilla');
                    $this->addOption('package-manager', null, InputOption::VALUE_OPTIONAL, 'Package manager (npm, yarn, pnpm, bun)', 'npm');
                    $this->addOption('skip-install', null, InputOption::VALUE_NONE, 'Skip npm install after scaffolding');
                    $this->addOption('skip-provider', null, InputOption::VALUE_NONE, 'Skip generating the service provider');
                    $this->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing configuration and scaffold');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Vite Integration Initializer', function (SymfonyStyle $ss) use ($input) {
                        $container = new Container(getcwd());
                        $container->initialize();

                        // Check Node.js environment
                        if (!$this->checkNodeEnvironment($ss, $input->getOption('package-manager'))) {
                            return Command::FAILURE;
                        }

                        $created = [];

                        // 1. Create configuration file (merge if exists)
                        $configPath = $this->createViteConfig($container, $input);
                        if ($configPath) {
                            $created[] = 'Configuration: ' . $configPath;
                        } elseif (!$input->getOption('force')) {
                            $ss->warning('Configuration already exists. Use --force to overwrite.');
                        }

                        // 2. Create service provider
                        if (!$input->getOption('skip-provider')) {
                            $providerPath = $this->createViteServiceProvider($container, $input);
                            if ($providerPath) {
                                $created[] = 'Service Provider: ' . $providerPath;
                            }
                        }

                        // 3. Register provider in config/providers.php (idempotent)
                        $this->registerProviderInConfig($container);

                        // 4. Create Vite project scaffold
                        $appDir = $input->getOption('app-dir') ?: 'resources/frontend';
                        $template = $input->getOption('template') ?: 'vanilla';
                        $packageManager = $input->getOption('package-manager') ?: 'npm';
                        $skipInstall = $input->getOption('skip-install');

                        $result = $this->createViteScaffold($ss, $appDir, $template, $packageManager, $skipInstall, $input->getOption('force'));
                        if ($result) {
                            $created[] = "Vite frontend scaffold created in: {$appDir} (template: {$template})";
                            // Update build path in config
                            $this->updateBuildPathInConfig($container, $appDir, $input->getOption('build-dir') ?: 'build');
                        }

                        if (empty($created)) {
                            $ss->warning('Nothing was created.');
                            return Command::SUCCESS;
                        }

                        $ss->success('Vite integration initialized successfully!');
                        $ss->section('Created items');
                        $ss->listing($created);
                        $ss->note('You may need to run "composer dump-autoload" if you added new classes.');
                        $ss->note('Don\'t forget to run "php artisan vite:install" to install npm dependencies.');

                        return Command::SUCCESS;
                    });
                }

                private function checkNodeEnvironment(SymfonyStyle $ss, ?string $packageManager): bool
                {
                    $pm = $packageManager ?? 'npm';
                    $process = new Process([$pm, '--version']);
                    $process->run();
                    if (!$process->isSuccessful()) {
                        $ss->error("{$pm} is not installed or not in PATH.");
                        return false;
                    }
                    $process = new Process(['node', '--version']);
                    $process->run();
                    if (!$process->isSuccessful()) {
                        $ss->error("Node.js is not installed.");
                        return false;
                    }
                    return true;
                }

                private function createViteConfig(Container $container, InputInterface $input): ?string
                {
                    $configDir = $container->config;
                    if (!is_dir($configDir)) {
                        mkdir($configDir, 0755, true);
                    }
                    $configFile = $configDir . 'vite.php';
                    if (file_exists($configFile) && !$input->getOption('force')) {
                        return null;
                    }

                    $buildDir = $input->getOption('build-dir') ?: 'build';
                    $useDevServer = $container->getEnvironment() === 'development' ? 'true' : 'false';
                    $autoDetect = $container->getEnvironment() === 'development' ? 'true' : 'false';

                    $template = <<<PHP
<?php

return [
    'dev_server_url' => env('VITE_DEV_SERVER_URL', 'http://localhost:5173'),
    'manifest_path' => env('VITE_MANIFEST_PATH', '{$buildDir}/manifest.json'),
    'build_directory' => env('VITE_BUILD_DIRECTORY', '{$buildDir}'),
    'index_file' => 'index.html',
    'excluded_paths' => [
        '/api/', '/assets/', '/storage/', '/vendor/',
        'favicon.ico', 'robots.txt',
    ],
    'security_headers' => true,
    'cache_control_max_age' => 31536000,
    'enable_compression' => true,
    'register_fallback_route' => true,
    'use_dev_server' => {$useDevServer},
    'auto_detect_dev_server' => {$autoDetect},
];
PHP;
                    file_put_contents($configFile, $template);
                    return $configFile;
                }

                private function createViteServiceProvider(Container $container, InputInterface $input): ?string
                {
                    $providersDir = $container->app . 'Providers/';
                    if (!is_dir($providersDir)) {
                        mkdir($providersDir, 0755, true);
                    }

                    $providerFile = $providersDir . 'ViteServiceProvider.php';
                    if (file_exists($providerFile) && !$input->getOption('force')) {
                        return null;
                    }

                    $template = <<<'PHP'
<?php

namespace Mlangeni\Machinjiri\App\Providers;

use Mlangeni\Machinjiri\Core\Providers\ServiceProvider;
use Mlangeni\Machinjiri\Integrations\Vite\Vite;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Routing\Router;

class ViteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Vite::class, function ($app) {
            $logger = $app->bound(Logger::class) ? $app->make(Logger::class) : null;
            return new Vite($app, $logger);
        });
    }

    public function boot(): void
    {
        $vite = $this->app->make(Vite::class);
        if ($vite->isHot()) {
            Router::any('/{any}', function ($request, $response) use ($vite) {
                return $vite->proxyToDevServer($request, $response);
            })->where(['any' => '.*']);
        } else {
            Router::any('/{any}', function ($request, $response) use ($vite) {
                return $vite->serve($request, $response);
            })->where(['any' => '.*']);
        }
    }
}
PHP;
                    file_put_contents($providerFile, $template);
                    return $providerFile;
                }

                private function registerProviderInConfig(Container $container): void
                {
                    $providersConfigFile = $container->config . 'providers.php';
                    if (!file_exists($providersConfigFile)) {
                        $default = "<?php\nreturn ['providers' => [], 'deferred' => []];\n";
                        file_put_contents($providersConfigFile, $default);
                    }
                    $config = require $providersConfigFile;
                    $providerClass = 'Mlangeni\\Machinjiri\\App\\Providers\\ViteServiceProvider';
                    if (!in_array($providerClass, $config['providers'] ?? [])) {
                        $config['providers'][] = $providerClass;
                        $content = "<?php\nreturn " . var_export($config, true) . ";\n";
                        file_put_contents($providersConfigFile, $content);
                    }
                }

                private function createViteScaffold(SymfonyStyle $ss, string $appDir, string $template, string $packageManager, bool $skipInstall, bool $force): bool
                {
                    $fullPath = getcwd() . DIRECTORY_SEPARATOR . $appDir;
                    if (is_dir($fullPath)) {
                        if (!$force) {
                            $ss->warning("Directory {$appDir} already exists. Use --force to overwrite.");
                            return false;
                        }
                        $this->deleteDirectory($fullPath);
                    }

                    $tempDir = $fullPath . '_tmp';
                    $this->deleteDirectory($tempDir); // clean previous temp

                    $ss->text("Creating Vite project in temporary location...");
                    $createCmd = ['npm', 'create', 'vite@latest', basename($tempDir), '--', '--template', $template];
                    $process = new Process($createCmd, dirname($tempDir));
                    $process->setTimeout(300);
                    $process->run(function ($type, $buffer) use ($ss) {
                        $ss->write($buffer);
                    });
                    if (!$process->isSuccessful()) {
                        $ss->error("Scaffolding failed: " . $process->getErrorOutput());
                        $this->deleteDirectory($tempDir);
                        return false;
                    }

                    // Move temp to final location
                    rename($tempDir, $fullPath);

                    // Update package.json scripts
                    $packageJsonPath = $fullPath . DIRECTORY_SEPARATOR . 'package.json';
                    if (file_exists($packageJsonPath)) {
                        $package = json_decode(file_get_contents($packageJsonPath), true);
                        $package['scripts']['build'] = 'vite build --outDir ../../public/build';
                        $package['scripts']['dev'] = 'vite --port 5173';
                        file_put_contents($packageJsonPath, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    }

                    // Create/override vite.config.js
                    $viteConfig = <<<JS
import { defineConfig } from 'vite';
import path from 'path';

export default defineConfig({
    server: {
        port: 5173,
        strictPort: true,
    },
    build: {
        outDir: path.resolve(__dirname, '../../public/build'),
        emptyOutDir: true,
        manifest: true,
        rollupOptions: {
            input: 'index.html',
        },
    },
});
JS;
                    file_put_contents($fullPath . DIRECTORY_SEPARATOR . 'vite.config.js', $viteConfig);

                    if (!$skipInstall) {
                        $ss->text("Installing dependencies with {$packageManager}...");
                        $installProcess = new Process([$packageManager, 'install'], $fullPath);
                        $installProcess->setTimeout(600);
                        $installProcess->run(function ($type, $buffer) use ($ss) {
                            $ss->write($buffer);
                        });
                        if (!$installProcess->isSuccessful()) {
                            $ss->error("Dependency installation failed.");
                            return false;
                        }
                    }

                    return true;
                }

                private function updateBuildPathInConfig(Container $container, string $appDir, string $buildDir): void
                {
                    $configFile = $container->config . 'vite.php';
                    if (!file_exists($configFile)) return;
                    $existing = require $configFile;
                    $existing['build_directory'] = $buildDir;
                    $existing['manifest_path'] = $buildDir . '/manifest.json';
                    $content = "<?php\nreturn " . var_export($existing, true) . ";\n";
                    file_put_contents($configFile, $content);
                }

                private function deleteDirectory(string $dir): void
                {
                    if (!is_dir($dir)) return;
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
                    parent::__construct('vite:install');
                    $this->setDescription('Install npm dependencies for the Vite frontend');
                }

                protected function configure(): void
                {
                    $this->addOption('app-dir', null, InputOption::VALUE_OPTIONAL, 'Vite frontend directory', 'resources/frontend');
                    $this->addOption('package-manager', null, InputOption::VALUE_OPTIONAL, 'Package manager (npm, yarn, pnpm, bun)');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Install Vite Dependencies', function (SymfonyStyle $ss) use ($input) {
                        $appDir = $input->getOption('app-dir');
                        $packageManager = $input->getOption('package-manager') ?: $this->detectPackageManager($appDir);
                        if (!$this->checkNodeEnvironment($ss, $packageManager)) {
                            return Command::FAILURE;
                        }
                        $ss->text("Running {$packageManager} install...");
                        $process = new Process([$packageManager, 'install'], $appDir);
                        $process->setTimeout(600);
                        $process->run(function ($type, $buffer) use ($ss) {
                            $ss->write($buffer);
                        });
                        if ($process->isSuccessful()) {
                            $ss->success('Dependencies installed.');
                            return Command::SUCCESS;
                        }
                        $ss->error('Installation failed: ' . $process->getErrorOutput());
                        return Command::FAILURE;
                    });
                }

                private function detectPackageManager(string $appDir): string
                {
                    if (file_exists($appDir . '/yarn.lock')) return 'yarn';
                    if (file_exists($appDir . '/pnpm-lock.yaml')) return 'pnpm';
                    if (file_exists($appDir . '/bun.lockb')) return 'bun';
                    return 'npm';
                }

                private function checkNodeEnvironment(SymfonyStyle $ss, string $packageManager): bool
                {
                    $process = new Process([$packageManager, '--version']);
                    $process->run();
                    if (!$process->isSuccessful()) {
                        $ss->error("{$packageManager} not found.");
                        return false;
                    }
                    $process = new Process(['node', '--version']);
                    $process->run();
                    if (!$process->isSuccessful()) {
                        $ss->error("Node.js not found.");
                        return false;
                    }
                    return true;
                }
            },

            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('vite:build');
                    $this->setDescription('Build the Vite frontend for production');
                }

                protected function configure(): void
                {
                    $this->addOption('app-dir', null, InputOption::VALUE_OPTIONAL, 'Vite frontend directory', 'resources/frontend');
                    $this->addOption('watch', 'w', InputOption::VALUE_NONE, 'Watch for changes and rebuild');
                    $this->addOption('env', null, InputOption::VALUE_OPTIONAL, 'Environment (production, staging, etc.)');
                    $this->addOption('package-manager', null, InputOption::VALUE_OPTIONAL);
                    $this->addOption('force', null, InputOption::VALUE_NONE, 'Force build even in production (for debugging)');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Vite Build', function (SymfonyStyle $ss) use ($input) {
                        $container = new Container(getcwd());
                        if ($container->getEnvironment() === 'production' && !$input->getOption('force')) {
                            $ss->error("vite:build is not allowed in production environment. Use --force to override.");
                            return Command::FAILURE;
                        }

                        $appDir = $input->getOption('app-dir');
                        $packageManager = $input->getOption('package-manager') ?: $this->detectPackageManager($appDir);
                        if (!$this->checkNodeEnvironment($ss, $packageManager)) {
                            return Command::FAILURE;
                        }

                        // Handle environment specific .env
                        if ($env = $input->getOption('env')) {
                            $envFile = $appDir . "/.env.{$env}";
                            if (file_exists($envFile)) {
                                copy($envFile, $appDir . '/.env');
                                $ss->info("Using environment: {$env}");
                            }
                        }

                        $args = ['run', 'build'];
                        if ($input->getOption('watch')) {
                            $args[] = '--watch';
                        }
                        $process = new Process([$packageManager, ...$args], $appDir);
                        $process->setTimeout(null);
                        $process->run(function ($type, $buffer) use ($ss) {
                            $ss->write($buffer);
                        });
                        if ($process->isSuccessful()) {
                            $ss->success('Build completed.');
                            return Command::SUCCESS;
                        }
                        $ss->error('Build failed: ' . $process->getErrorOutput());
                        return Command::FAILURE;
                    });
                }

                private function detectPackageManager(string $appDir): string
                {
                    if (file_exists($appDir . '/yarn.lock')) return 'yarn';
                    if (file_exists($appDir . '/pnpm-lock.yaml')) return 'pnpm';
                    if (file_exists($appDir . '/bun.lockb')) return 'bun';
                    return 'npm';
                }

                private function checkNodeEnvironment(SymfonyStyle $ss, string $packageManager): bool
                {
                    $process = new Process([$packageManager, '--version']);
                    $process->run();
                    if (!$process->isSuccessful()) {
                        $ss->error("{$packageManager} not found.");
                        return false;
                    }
                    $process = new Process(['node', '--version']);
                    $process->run();
                    if (!$process->isSuccessful()) {
                        $ss->error("Node.js not found.");
                        return false;
                    }
                    return true;
                }
            },

            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('vite:dev');
                    $this->setDescription('Run Vite development server (detached)');
                }

                protected function configure(): void
                {
                    $this->addOption('app-dir', null, InputOption::VALUE_OPTIONAL, 'Vite frontend directory', 'resources/frontend');
                    $this->addOption('port', null, InputOption::VALUE_OPTIONAL, 'Port for Vite dev server', '5173');
                    $this->addOption('package-manager', null, InputOption::VALUE_OPTIONAL);
                    $this->addOption('force', null, InputOption::VALUE_NONE, 'Allow in production (emergency only)');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Vite Dev Server', function (SymfonyStyle $ss) use ($input) {
                        $container = new Container(getcwd());
                        if ($container->getEnvironment() === 'production' && !$input->getOption('force')) {
                            $ss->error("vite:dev is not allowed in production. Use --force only in emergency.");
                            return Command::FAILURE;
                        }

                        $appDir = $input->getOption('app-dir');
                        $packageManager = $input->getOption('package-manager') ?: $this->detectPackageManager($appDir);
                        if (!$this->checkNodeEnvironment($ss, $packageManager)) {
                            return Command::FAILURE;
                        }

                        $port = $input->getOption('port');
                        $ss->note("Starting Vite dev server on port {$port}. Logs will be written to storage/logs/vite-dev.log");
                        $logFile = getcwd() . '/storage/logs/vite-dev.log';
                        $process = new Process([$packageManager, 'run', 'dev', '--', '--port', $port], $appDir);
                        $process->setTimeout(null);
                        $process->start();
                        file_put_contents($logFile, "Vite dev server started at " . date('Y-m-d H:i:s') . "\n");
                        $ss->success("Vite dev server running (PID: {$process->getPid()}). Logs: {$logFile}");
                        $ss->warning("Press Ctrl+C to stop the server.");
                        $process->wait(function ($type, $buffer) use ($ss, $logFile) {
                            file_put_contents($logFile, $buffer, FILE_APPEND);
                        });
                        return Command::SUCCESS;
                    });
                }

                private function detectPackageManager(string $appDir): string
                {
                    if (file_exists($appDir . '/yarn.lock')) return 'yarn';
                    if (file_exists($appDir . '/pnpm-lock.yaml')) return 'pnpm';
                    if (file_exists($appDir . '/bun.lockb')) return 'bun';
                    return 'npm';
                }

                private function checkNodeEnvironment(SymfonyStyle $ss, string $packageManager): bool
                {
                    $process = new Process([$packageManager, '--version']);
                    $process->run();
                    if (!$process->isSuccessful()) {
                        $ss->error("{$packageManager} not found.");
                        return false;
                    }
                    $process = new Process(['node', '--version']);
                    $process->run();
                    if (!$process->isSuccessful()) {
                        $ss->error("Node.js not found.");
                        return false;
                    }
                    return true;
                }
            },

            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('vite:clear-cache');
                    $this->setDescription('Clear Vite build cache and artifacts');
                }

                protected function configure(): void
                {
                    $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Clear Vite Cache', function (SymfonyStyle $ss) use ($input) {
                        $publicBuild = getcwd() . '/public/build';
                        $viteCache = getcwd() . '/resources/frontend/node_modules/.vite';

                        $paths = [];
                        if (is_dir($publicBuild)) $paths[] = $publicBuild;
                        if (is_dir($viteCache)) $paths[] = $viteCache;

                        if (empty($paths)) {
                            $ss->comment('No cache or build directories found.');
                            return Command::SUCCESS;
                        }

                        if (!$input->getOption('force') && !$ss->confirm('This will permanently delete build output and Vite cache. Continue?', false)) {
                            $ss->comment('Aborted.');
                            return Command::SUCCESS;
                        }

                        foreach ($paths as $path) {
                            $this->deleteDirectory($path);
                            $ss->success("Cleared: {$path}");
                        }

                        return Command::SUCCESS;
                    });
                }

                private function deleteDirectory(string $dir): void
                {
                    if (!is_dir($dir)) return;
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
                    parent::__construct('vite:make:component');
                    $this->setDescription('Generate a frontend component (Vue/React) inside the Vite project');
                }

                protected function configure(): void
                {
                    $this->addArgument('name', InputArgument::REQUIRED, 'Component name (e.g., Button, HelloWorld)');
                    $this->addOption('app-dir', null, InputOption::VALUE_OPTIONAL, 'Vite frontend directory', 'resources/frontend');
                    $this->addOption('framework', null, InputOption::VALUE_OPTIONAL, 'Framework (vue, react)', 'vue');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Generate Frontend Component', function (SymfonyStyle $ss) use ($input) {
                        $name = $input->getArgument('name');
                        $appDir = $input->getOption('app-dir');
                        $framework = $input->getOption('framework');

                        $srcDir = getcwd() . '/' . $appDir . '/src/components';
                        if (!is_dir($srcDir)) {
                            mkdir($srcDir, 0755, true);
                        }

                        $extension = $framework === 'vue' ? '.vue' : '.jsx';
                        $componentFile = $srcDir . '/' . $name . $extension;
                        if (file_exists($componentFile)) {
                            $ss->error("Component already exists: {$componentFile}");
                            return Command::FAILURE;
                        }

                        if ($framework === 'vue') {
                            $content = "<template>\n  <div class=\"" . strtolower($name) . "\">\n    <h1>{$name} Component</h1>\n  </div>\n</template>\n\n<script>\nexport default {\n  name: '{$name}',\n};\n</script>\n\n<style scoped>\n/* Add your styles here */\n</style>\n";
                        } else {
                            $content = "import React from 'react';\n\nconst {$name} = () => {\n  return (\n    <div className=\"" . strtolower($name) . "\">\n      <h1>{$name} Component</h1>\n    </div>\n  );\n};\n\nexport default {$name};\n";
                        }

                        file_put_contents($componentFile, $content);
                        $ss->success("Component created: {$componentFile}");
                        return Command::SUCCESS;
                    });
                }
            },

            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('vite:make:page');
                    $this->setDescription('Generate a frontend page component (routed)');
                }

                protected function configure(): void
                {
                    $this->addArgument('name', InputArgument::REQUIRED, 'Page name (e.g., Home, About)');
                    $this->addOption('app-dir', null, InputOption::VALUE_OPTIONAL, 'Vite frontend directory', 'resources/frontend');
                    $this->addOption('framework', null, InputOption::VALUE_OPTIONAL, 'Framework (vue, react)', 'vue');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Generate Frontend Page', function (SymfonyStyle $ss) use ($input) {
                        $name = $input->getArgument('name');
                        $appDir = $input->getOption('app-dir');
                        $framework = $input->getOption('framework');

                        $pagesDir = getcwd() . '/' . $appDir . '/src/pages';
                        if (!is_dir($pagesDir)) {
                            mkdir($pagesDir, 0755, true);
                        }

                        $extension = $framework === 'vue' ? '.vue' : '.jsx';
                        $pageFile = $pagesDir . '/' . $name . $extension;
                        if (file_exists($pageFile)) {
                            $ss->error("Page already exists: {$pageFile}");
                            return Command::FAILURE;
                        }

                        if ($framework === 'vue') {
                            $content = "<template>\n  <div>\n    <h1>{$name} Page</h1>\n  </div>\n</template>\n\n<script>\nexport default {\n  name: '{$name}',\n};\n</script>\n";
                        } else {
                            $content = "import React from 'react';\n\nconst {$name} = () => {\n  return <h1>{$name} Page</h1>;\n};\n\nexport default {$name};\n";
                        }

                        file_put_contents($pageFile, $content);
                        $ss->success("Page created: {$pageFile}");
                        return Command::SUCCESS;
                    });
                }
            },

            new class extends Command {
                use CommandHelper;

                public function __construct()
                {
                    parent::__construct('vite:version');
                    $this->setDescription('Show installed Vite version and integration info');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Vite Version', function (SymfonyStyle $ss) {
                        $viteVersion = trim(exec('npx vite --version 2>&1'));
                        $ss->table(['Component', 'Version'], [
                            ['Vite CLI', $viteVersion ?: 'not installed'],
                            ['Machinjiri Vite Integration', '1.0.0'],
                        ]);
                        return Command::SUCCESS;
                    });
                }
            }
        ];
    }
}