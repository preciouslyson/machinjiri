<?php

namespace Mlangeni\Machinjiri\Core\Transport\Mail;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Artisans\Contracts\JobDispatcherInterface;
use Mlangeni\Machinjiri\App\Jobs\SendMailJob;

class MailManager
{
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
     * @param array|null $config Optional configuration array. If null, loads from Container's config/mail.php.
     * @param Logger|null $logger Optional Logger instance. If null, tries to resolve from Container or creates default.
     * @param EventListener|null $eventListener Optional EventListener. If null, tries to resolve from Container.
     * @param TemplateRendererInterface|null $renderer Optional template renderer.
     * @param MailQueueInterface|null $queue Optional queue implementation.
     * @throws MachinjiriException
     */
    public function __construct(
        ?array $config = null,
        ?Logger $logger = null,
        ?EventListener $eventListener = null,
        ?TemplateRendererInterface $renderer = null,
        ?JobDispatcherInterface $dispatcher = null
    ) {
        // Load configuration
        $this->config = $config ?? $this->loadConfigFromContainer();
        
        // Resolve dependencies from Container if possible
        $container = Container::instancePresent() ? Container::getInstance() : null;
        
        $this->logger = $logger ?? ($container && $container->bound(Logger::class) 
            ? $container->make(Logger::class) 
            : new Logger());
            
        $this->eventListener = $eventListener ?? ($container && $container->bound(EventListener::class) 
            ? $container->make(EventListener::class) 
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
        if (!Container::instancePresent()) {
            throw new MachinjiriException(
                'Container not initialized and no config array provided to MailManager.',
                500,
                null,
                [],
                'mail_config'
            );
        }
        
        $container = Container::getInstance();
        $configPath = $container->config . 'mail.php';
        
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
     * Queue an email for asynchronous sending.
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

        // Create the mail job
        $job = new SendMailJob($this->app, $message, $transportName, $jobOptions);
        
        // Dispatch to the queue
        $jobId = $this->dispatcher->dispatch($job);
        
        $this->logger->info('Email queued', [
            'job_id' => $jobId,
            'subject' => $message->getSubject(),
            'transport' => $transportName ?? 'default',
        ]);
        
        // Trigger event
        if ($this->eventListener) {
            $this->eventListener->trigger('mail.queued', [
                'message' => $message,
                'job_id' => $jobId,
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