<?php

namespace Mlangeni\Machinjiri\Core\Transport\Mail;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Artisans\Contracts\JobDispatcherInterface;
use Mlangeni\Machinjiri\App\Jobs\SendMailJob;
use Mlangeni\Machinjiri\Core\Artisans\Generators\QueueJobGenerator;

class MailManager
{
    private Container $app;
    private array $transports = [];
    private ?MailerInterface $defaultTransport = null;
    private Logger $logger;
    private ?EventListener $eventListener = null;
    private ?TemplateRendererInterface $renderer = null;
    private array $config;
    private ?JobDispatcherInterface $dispatcher = null;

    /**
     * MailManager constructor.
     *
     * @param Container $app
     * @param array|null $config
     * @param Logger|null $logger
     * @param EventListener|null $eventListener
     * @param TemplateRendererInterface|null $renderer
     * @param JobDispatcherInterface|null $dispatcher
     * @throws MachinjiriException
     */
    public function __construct(
        Container $app,
        ?array $config = null,
        ?Logger $logger = null,
        ?EventListener $eventListener = null,
        ?TemplateRendererInterface $renderer = null,
        ?JobDispatcherInterface $dispatcher = null
    ) {
        $this->app = $app;

        // Load configuration
        $this->config = $config ?? $this->loadConfigFromContainer();
        
        // Resolve dependencies from Container if possible
        $this->logger = $logger ?? ($app->bound(Logger::class) 
            ? $app->make(Logger::class) 
            : new Logger());
            
        $this->eventListener = $eventListener ?? ($app->bound(EventListener::class) 
            ? $app->make(EventListener::class) 
            : null);
            
        $this->renderer = $renderer;
        $this->dispatcher = $dispatcher;
        
        // Build transports from config
        foreach ($this->config['transports'] as $name => $transportConfig) {
            $this->registerTransport($name, $transportConfig);
        }
        
        $default = $this->config['default'] ?? 'phpmailer';
        if (!isset($this->transports[$default])) {
            throw new MachinjiriException(
                "Default mail transport '{$default}' not registered",
                500,
                null,
                ['default' => $default],
                'mail_config'
            );
        }
        $this->defaultTransport = $this->transports[$default];
    }
    
    /**
     * Load mail configuration from Container's config directory.
     *
     * @return array
     * @throws MachinjiriException if config file not found or invalid.
     */
    private function loadConfigFromContainer(): array
    {
        $configPath = $this->app->config . 'mail.php';
        
        if (!file_exists($configPath)) {
            throw new MachinjiriException(
                "Mail configuration file not found at: {$configPath}",
                500,
                null,
                ['path' => $configPath],
                'mail_config'
            );
        }
        
        $config = include $configPath;
        
        if (!is_array($config)) {
            throw new MachinjiriException(
                'Mail configuration file must return an array.',
                500,
                null,
                ['path' => $configPath],
                'mail_config'
            );
        }
        
        return $config;
    }

    /**
     * Register a transport.
     *
     * @param string $name
     * @param array $config
     * @throws MachinjiriException
     */
    private function registerTransport(string $name, array $config): void
    {
        $type = $config['type'] ?? $name;
        $transport = null;
        
        switch ($type) {
            case 'phpmailer':
                $transport = new Transport\PhpMailerTransport($config['options'] ?? [], $this->logger);
                break;
            default:
                throw new MachinjiriException(
                    "Unknown transport type: {$type}",
                    500,
                    null,
                    ['type' => $type],
                    'mail_config'
                );
        }
        $this->transports[$name] = $transport;
    }

    /**
     * Send an email using the default transport (or a named one).
     */
    public function send(MailMessage $message, ?string $transportName = null): MailResponse
    {
        $transport = $transportName ? ($this->transports[$transportName] ?? null) : $this->defaultTransport;
        if (!$transport) {
            throw new MachinjiriException(
                "Transport '{$transportName}' not found",
                500,
                null,
                ['transport' => $transportName],
                'mail_config'
            );
        }

        // Dispatch mail.sending event
        if ($this->eventListener) {
            $this->eventListener->trigger('mail.sending', [
                'message' => $message,
                'transport' => $transportName ?? 'default'
            ]);
        }

        $this->logger->info('Sending email', [
            'subject' => $message->getSubject(),
            'to' => $message->getTo(),
            'transport' => $transportName ?? 'default'
        ]);

        try {
            $response = $transport->send($message);

            $this->logger->info('Email sent successfully', [
                'message_id' => $response->getMessageId(),
                'subject' => $message->getSubject(),
                'transport' => $transportName ?? 'default'
            ]);

            // Dispatch mail.sent event
            if ($this->eventListener) {
                $this->eventListener->trigger('mail.sent', [
                    'message' => $message,
                    'response' => $response,
                    'transport' => $transportName ?? 'default'
                ]);
            }

            return $response;
        } catch (MachinjiriException $e) {
            $this->logger->error('Mail send failed', [
                'error' => $e->getMessage(),
                'subject' => $message->getSubject(),
                'transport' => $transportName ?? 'default',
                'context' => $e->getContext()
            ]);

            // Dispatch mail.failed event
            if ($this->eventListener) {
                $this->eventListener->trigger('mail.failed', [
                    'message' => $message,
                    'exception' => $e,
                    'transport' => $transportName ?? 'default'
                ]);
            }

            throw $e;
        }
    }

    /**
     * Send an email using a template.
     */
    public function sendTemplate(string $templateName, array $data, callable $callback, ?string $transportName = null): MailResponse
    {
        if (!$this->renderer) {
            throw new MachinjiriException(
                'Template renderer not set. Configure a renderer before using sendTemplate().',
                500,
                null,
                [],
                'mail_config'
            );
        }
        $html = $this->renderer->render($templateName . '.html.php', $data);
        $text = $this->renderer->render($templateName . '.text.php', $data);

        $message = new MailMessage();
        $message->html($html, $text);
        $callback($message);

        return $this->send($message, $transportName);
    }

    /**
     * Ensure the SendMailJob class exists; if not, generate it.
     *
     * @throws MachinjiriException
     */
    private function ensureSendMailJobExists(): void
    {
        if (class_exists(SendMailJob::class)) {
            return;
        }

        $this->logger->warning('SendMailJob not found, attempting to generate it...');

        // Determine application base path from container
        // The container may have a basePath property or method; adjust as needed.
        // In many frameworks, the container holds the base path. Here we assume a method getBasePath().
        // If not available, we fall back to a reasonable default based on the current script location.
        $basePath = null;
        if (method_exists($this->app, 'getBasePath')) {
            $basePath = $this->app->getBasePath();
        } elseif (property_exists($this->app, 'basePath')) {
            $basePath = $this->app->basePath;
        } else {
            // Fallback: assume the application root is two levels above the core directory.
            $basePath = dirname(__DIR__, 4);
        }

        if (!is_dir($basePath)) {
            throw new MachinjiriException(
                'Unable to determine application base path for generating SendMailJob.',
                500,
                null,
                ['basePath' => $basePath],
                'mail_config'
            );
        }

        try {
            $generator = new QueueJobGenerator($basePath);
            $generator->generateJob('SendMail', [
                'type' => 'email',
                'queue' => 'emails',
                'max_attempts' => 3,
                'timeout' => 60,
                'delay' => 0,
            ]);
        } catch (\Exception $e) {
            throw new MachinjiriException(
                'Failed to generate SendMailJob: ' . $e->getMessage(),
                500,
                $e,
                [],
                'mail_config'
            );
        }

        // Verify class now exists
        if (!class_exists(SendMailJob::class)) {
            throw new MachinjiriException(
                'SendMailJob was generated but class still not found. Check autoloading.',
                500,
                null,
                [],
                'mail_config'
            );
        }

        $this->logger->info('SendMailJob generated successfully.');
    }

    /**
     * Queue an email for asynchronous sending.
     *
     * @throws MachinjiriException if job dispatcher not configured or job generation fails.
     */
    public function queue(MailMessage $message, ?string $transportName = null, array $jobOptions = []): string
    {
        if (!$this->dispatcher) {
            throw new MachinjiriException(
                'Job dispatcher not configured. Bind a JobDispatcherInterface implementation.',
                500,
                null,
                [],
                'mail_config'
            );
        }
    
        // Ensure the job class exists (generate if missing)
        $this->ensureSendMailJobExists();
    
        // Build the payload for the job
        $payload = [
            'message'   => $message->jsonSerialize(),
            'transport' => $transportName,
        ];
    
        $job = new SendMailJob($this->app, $payload, $jobOptions);
    
        // Dispatch to the queue
        $jobId = $this->dispatcher->dispatch($job);
    
        $this->logger->info('Email queued', [
            'job_id'    => $jobId,
            'subject'   => $message->getSubject(),
            'transport' => $transportName ?? 'default',
        ]);
    
        // Trigger event
        if ($this->eventListener) {
            $this->eventListener->trigger('mail.queued', [
                'message'   => $message,
                'job_id'    => $jobId,
                'transport' => $transportName,
            ]);
        }
    
        return $jobId;
    }

    public function getTransport(string $name): MailerInterface
    {
        if (!isset($this->transports[$name])) {
            throw new MachinjiriException(
                "Transport '{$name}' not found",
                500,
                null,
                ['transport' => $name],
                'mail_config'
            );
        }
        return $this->transports[$name];
    }
}