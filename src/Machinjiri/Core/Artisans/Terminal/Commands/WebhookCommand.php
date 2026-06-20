<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Terminal\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Mlangeni\Machinjiri\Core\Artisans\Generators\ServiceProviderGenerator;
use Mlangeni\Machinjiri\Core\Container;

/**
 * Console command to generate webhook components.
 *
 * Usage:
 *   php artisan make:webhook Stripe
 *
 * Options:
 *   --controller  Generate webhook controller only
 *   --job         Generate queued job only
 *   --config      Update/add webhook provider configuration
 *   --handler     Generate an event handler class
 *   --provider    Generate a Service Provider 
 *   --all         Generate all components (default)
 */
class WebhookCommand
{
    /**
     * Get all Webhook commands.
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
                    parent::__construct('make:webhook');
                    $this->setDescription('Generates webhook components: controller, job, config, and handler');
                }

                protected function configure(): void
                {
                    $this->addArgument('name', InputArgument::REQUIRED, 'The webhook name (e.g., Stripe, GitHub)');
                    $this->addOption('controller', null, InputOption::VALUE_NONE, 'Generate webhook controller only');
                    $this->addOption('job', null, InputOption::VALUE_NONE, 'Generate queued job only');
                    $this->addOption('config', null, InputOption::VALUE_NONE, 'Add webhook provider configuration');
                    $this->addOption('handler', null, InputOption::VALUE_NONE, 'Generate an event handler class');
                    $this->addOption('provider', null, InputOption::VALUE_NONE, 'Generate a service provider for webhook registration');
                    $this->addOption('all', null, InputOption::VALUE_NONE, 'Generate all components (default)');
                }

                protected function execute(InputInterface $input, OutputInterface $output): int
                {
                    return $this->executeWithStyle($input, $output, 'Webhook Generator', function (SymfonyStyle $io) use ($input) {
                        $name = $input->getArgument('name');
                        $normalizedName = ucfirst(preg_replace('/[^a-zA-Z0-9]/', '', $name));

                        if (empty($normalizedName)) {
                            $io->error('Invalid webhook name. Use only letters and numbers.');
                            return Command::FAILURE;
                        }

                        $generateAll = $input->getOption('all') || (
                            !$input->getOption('controller') &&
                            !$input->getOption('job') &&
                            !$input->getOption('config') &&
                            !$input->getOption('handler') &&
                            !$input->getOption('provider')
                        );

                        $created = [];

                        // 1. Generate Controller
                        if ($generateAll || $input->getOption('controller')) {
                            if ($this->generateController($normalizedName, $io)) {
                                $created[] = 'Controller';
                            }
                        }

                        // 2. Generate Job
                        if ($generateAll || $input->getOption('job')) {
                            if ($this->generateJob($normalizedName, $io)) {
                                $created[] = 'Job';
                            }
                        }

                        // 3. Update Configuration
                        if ($generateAll || $input->getOption('config')) {
                            if ($this->updateConfig($normalizedName, $io)) {
                                $created[] = 'Configuration';
                            }
                        }

                        // 4. Generate Handler
                        if ($generateAll || $input->getOption('handler')) {
                            if ($this->generateHandler($normalizedName, $io)) {
                                $created[] = 'Handler';
                            }
                        }

                        // 5. Generate Service Provider
                        if ($generateAll || $input->getOption('provider')) {
                            if ($this->generateServiceProvider($normalizedName, $io)) {
                                $created[] = 'Service Provider';
                            }
                        }

                        if (empty($created)) {
                            $io->warning('No components were generated. Use --all or specify options.');
                            return Command::SUCCESS;
                        }

                        $io->success('Webhook components generated: ' . implode(', ', $created));
                        $io->note("Don't forget to:");
                        $io->text(" - Register the handler in your service provider (if not done automatically)");
                        $io->text(" - Add the route: \$router->post('/webhook/{$normalizedName}', [{$normalizedName}WebhookController::class, 'handle'])");
                        $io->text(" - Set the webhook secret in your .env file (e.g., {$normalizedName}_WEBHOOK_SECRET)");

                        return Command::SUCCESS;
                    });
                }

                private function generateController(string $name, SymfonyStyle $io): bool
                {
                    $controllerName = $name . 'WebhookController';
                    $controllerPath = $this->getControllersPath() . $controllerName . '.php';

                    if (file_exists($controllerPath)) {
                        $io->warning("Controller {$controllerName} already exists. Skipping.");
                        return false;
                    }

                    $template = $this->getControllerTemplate($name, $controllerName);
                    if ($this->writeFile($controllerPath, $template)) {
                        $io->text("✓ Created controller: app/Controllers/{$controllerName}.php");
                        return true;
                    }
                    $io->error("Failed to create controller: {$controllerName}");
                    return false;
                }

                private function generateJob(string $name, SymfonyStyle $io): bool
                {
                    $jobName = $name . 'WebhookJob';
                    $jobPath = $this->getJobsPath() . $jobName . '.php';

                    if (file_exists($jobPath)) {
                        $io->warning("Job {$jobName} already exists. Skipping.");
                        return false;
                    }

                    $template = $this->getJobTemplate($name, $jobName);
                    if ($this->writeFile($jobPath, $template)) {
                        $io->text("✓ Created job: app/Jobs/{$jobName}.php");
                        return true;
                    }
                    $io->error("Failed to create job: {$jobName}");
                    return false;
                }

                private function updateConfig(string $name, SymfonyStyle $io): bool
                {
                    $configFile = $this->getConfigPath() . 'webhooks.php';
                    $providerKey = strtolower($name);

                    // Load existing config or start fresh
                    $config = [];
                    if (file_exists($configFile)) {
                        $config = require $configFile;
                    }

                    // If provider already exists, skip
                    if (isset($config['providers'][$providerKey])) {
                        $io->warning("Provider '{$providerKey}' already exists in config/webhooks.php. Skipping.");
                        return false;
                    }

                    // Build new provider configuration with improved defaults
                    $newProvider = [
                        'secret' => '${' . strtoupper($name) . '_WEBHOOK_SECRET}',
                        'async'  => true,
                        'handler_failure_mode' => 'stop',   // 'stop' or 'continue'
                        'event_resolver' => ['type' => 'type'], // dot notation in JSON body
                        'verify' => [
                            'type'   => 'hmac',           // or 'hmac_timestamp' for Stripe-like
                            'header' => 'X-Signature',
                            'algo'   => 'sha256',
                            'prefix' => '',
                        ],
                    ];

                    // Add comment block for user to adjust
                    $config['providers'][$providerKey] = $newProvider;

                    // Write config file with pretty printed array
                    $content = "<?php\n\nreturn " . $this->varExportPretty($config) . ";\n";
                    if ($this->writeFile($configFile, $content)) {
                        $io->text("✓ Updated configuration: config/webhooks.php");
                        $io->text("  - You may want to adjust 'event_resolver' and 'verify' settings for your provider.");
                        return true;
                    }
                    $io->error("Failed to update configuration.");
                    return false;
                }

                private function generateHandler(string $name, SymfonyStyle $io): bool
                {
                    $handlerName = $name . 'WebhookHandler';
                    $handlerDir = $this->getWebhookHandlersPath();
                    $handlerPath = $handlerDir . $handlerName . '.php';

                    if (!is_dir($handlerDir)) {
                        mkdir($handlerDir, 0755, true);
                    }

                    if (file_exists($handlerPath)) {
                        $io->warning("Handler {$handlerName} already exists. Skipping.");
                        return false;
                    }

                    $template = $this->getHandlerTemplate($name, $handlerName);
                    if ($this->writeFile($handlerPath, $template)) {
                        $io->text("✓ Created handler: app/Webhooks/Handlers/{$handlerName}.php");
                        return true;
                    }
                    $io->error("Failed to create handler: {$handlerName}");
                    return false;
                }

                private function generateServiceProvider(string $name, SymfonyStyle $io): bool
                {
                    $providerName = $name . 'WebhookServiceProvider';
                    $basePath = $this->getBasePath();

                    $generator = new ServiceProviderGenerator($basePath);

                    try {
                        $options = [
                            'deferred' => false,
                            'config'   => false,
                            'register' => true,
                            'bindings' => [],
                            'singletons' => [],
                            'aliases' => [],
                        ];
                        $files = $generator->generate($providerName, $options);
                        $io->text("✓ Created service provider: app/Providers/{$providerName}.php");
                        $io->text("✓ Registered provider in config/providers.php");
                        return true;
                    } catch (\Exception $e) {
                        $io->error("Failed to create service provider: " . $e->getMessage());
                        return false;
                    }
                }

                // -------------------------------------------------------------------------
                // File system helpers
                // -------------------------------------------------------------------------

                private function getBasePath(): string
                {
                    if (class_exists(Container::class) && method_exists(Container::class, 'getBasePath')) {
                        return Container::getBasePath();
                    }
                    return getcwd();
                }

                private function getControllersPath(): string
                {
                    return $this->getBasePath() . '/app/Controllers/';
                }

                private function getJobsPath(): string
                {
                    return $this->getBasePath() . '/app/Jobs/';
                }

                private function getConfigPath(): string
                {
                    $path = $this->getBasePath() . '/config/';
                    if (!is_dir($path)) {
                        $path = $this->getBasePath() . '/../config/';
                    }
                    return $path;
                }

                private function getWebhookHandlersPath(): string
                {
                    return $this->getBasePath() . '/app/Webhooks/Handlers/';
                }

                private function writeFile(string $path, string $content): bool
                {
                    $dir = dirname($path);
                    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                        return false;
                    }
                    return file_put_contents($path, $content) !== false;
                }

                private function varExportPretty(array $expression, int $indent = 0): string
                {
                    $export = var_export($expression, true);
                    $export = preg_replace('/^array \(/', '[', $export);
                    $export = preg_replace('/\)$/', ']', $export);
                    $export = preg_replace('/\s+=>\s+/', ' => ', $export);
                    $export = preg_replace('/array \(\n/', "[\n", $export);
                    $export = str_replace('  ', '    ', $export);
                    return $export;
                }

                // -------------------------------------------------------------------------
                // Updated Templates
                // -------------------------------------------------------------------------

                private function getControllerTemplate(string $name, string $className): string
                {
                    $providerKey = strtolower($name);
                    return <<<PHP
<?php

namespace Mlangeni\Machinjiri\App\Controllers;

use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;
use Mlangeni\Machinjiri\Core\Artisans\Base\AbstractController;
use Mlangeni\Machinjiri\Core\Components\Webhooks\WebhookPayload;
use Mlangeni\Machinjiri\Core\Components\Webhooks\WebhookManager;
use Mlangeni\Machinjiri\Core\Components\Webhooks\WebhookSubscriptionManager;

/**
 * Webhook controller for {$name}
 */
class {$className} extends AbstractController
{
    private WebhookManager \$webhookManager;
    private WebhookSubscriptionManager \$subscriptionManager;

    public function __construct(
        WebhookManager \$webhookManager,
        WebhookSubscriptionManager \$subscriptionManager
    ) {
        \$this->webhookManager = \$webhookManager;
        \$this->subscriptionManager = \$subscriptionManager;
    }

    /**
     * Handle incoming {$name} webhook requests.
     */
    public function handle(HttpRequest \$request, HttpResponse \$response): HttpResponse
    {
        \$providerConfig = \$this->subscriptionManager->getEventResolver('{$providerKey}');
        \$payload = WebhookPayload::fromHttpRequest(
            \$request,
            '{$providerKey}',
            'X-Request-Id',
            \$providerConfig
        );
        \$webhookResponse = \$this->webhookManager->process(\$payload);
        return \$webhookResponse->toHttpResponse();
    }
}
PHP;
                }

                private function getJobTemplate(string $name, string $className): string
                {
                    $providerKey = strtolower($name);
                    return <<<PHP
<?php

namespace Mlangeni\Machinjiri\App\Jobs;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\BaseJob;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Components\Webhooks\WebhookPayload;
use Mlangeni\Machinjiri\Core\Components\Webhooks\WebhookManager;
use Mlangeni\Machinjiri\Core\Components\Webhooks\CacheIdempotencyStore;
use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;

/**
 * Asynchronous job for processing {$name} webhooks.
 */
class {$className} extends BaseJob
{
    private WebhookPayload \$payload;

    public function __construct(Container \$app, WebhookPayload \$payload)
    {
        parent::__construct(\$app, \$payload->getParsedData() ?? [], [
            'name'        => 'webhook.{$providerKey}',
            'queue'       => 'webhooks',
            'maxAttempts' => 3,
            'retryDelay'  => 60,
            'timeout'     => 120,
        ]);
        \$this->payload = \$payload;
    }

    public function handle(): void
    {
        /** @var WebhookManager \$manager */
        \$manager = \$this->app->resolve(WebhookManager::class);
        /** @var CacheManager \$cacheManager */
        \$cacheManager = \$this->app->resolve(CacheManager::class);

        \$idempotencyKey = \$this->payload->getIdempotencyKey();
        \$cacheKey = "webhook_{\$this->payload->getProvider()}_{\$idempotencyKey}";
        \$idempotencyStore = new CacheIdempotencyStore(\$cacheManager);

        // Check if already processed (duplicate job)
        if (\$idempotencyKey && \$idempotencyStore->isDone(\$cacheKey)) {
            \$this->logger->info('Async webhook already processed, skipping', [
                'provider' => \$this->payload->getProvider(),
                'key' => \$idempotencyKey
            ]);
            return;
        }

        // Acquire lock (ensures at-most-one processing across retries)
        if (\$idempotencyKey && !\$idempotencyStore->lock(\$cacheKey)) {
            \$this->release(30); // wait and retry
            return;
        }

        try {
            \$manager->dispatchToHandlers(\$this->payload);
            if (\$idempotencyKey) {
                \$idempotencyStore->markDone(\$cacheKey);
            }
        } catch (\Throwable \$e) {
            \$this->logger->error('Async webhook failed', ['error' => \$e->getMessage()]);
            throw \$e;
        }
    }
}
PHP;
                }

                private function getHandlerTemplate(string $name, string $className): string
                {
                    $lowerName = strtolower($name);
                    return <<<PHP
<?php

namespace Mlangeni\Machinjiri\App\Webhooks\Handlers;

use Mlangeni\Machinjiri\Core\Components\Webhooks\WebhookHandlerInterface;
use Mlangeni\Machinjiri\Core\Components\Webhooks\WebhookPayload;
use Mlangeni\Machinjiri\Core\Components\Webhooks\WebhookResponse;

/**
 * Handler for {$name} webhook events.
 *
 * Register this handler in your service provider:
 *   \$manager = \$app->resolve(WebhookSubscriptionManager::class);
 *   \$manager->registerHandler(new {$className}());
 */
class {$className} implements WebhookHandlerInterface
{
    /**
     * Process the incoming webhook payload.
     */
    public function handle(WebhookPayload \$payload): WebhookResponse
    {
        \$event = \$payload->getEventType();
        \$data = \$payload->getParsedData();

        // Example event-based routing:
        // match(\$event) {
        //     'payment.succeeded' => \$this->handlePaymentSucceeded(\$data),
        //     'payment.failed'    => \$this->handlePaymentFailed(\$data),
        //     default             => \$this->handleUnknownEvent(\$event),
        // };

        // Your business logic here...

        return WebhookResponse::ok(['received' => true, 'event' => \$event]);
    }

    /**
     * Define which event types this handler supports.
     * Return '*' for all events, or an array of specific event names.
     * If you return an array, only those events will be routed to this handler.
     */
    public function supportsEvent(): string|array
    {
        return '*'; // Change to e.g. ['payment.succeeded', 'payment.failed']
    }

    private function handlePaymentSucceeded(array \$data): void
    {
        // TODO: Implement
    }

    private function handlePaymentFailed(array \$data): void
    {
        // TODO: Implement
    }

    private function handleUnknownEvent(string \$event): void
    {
        // Log or ignore unknown events
    }
}
PHP;
                }
            },
        ];
    }
}