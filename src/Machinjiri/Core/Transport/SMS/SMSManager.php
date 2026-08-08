<?php
namespace Mlangeni\Machinjiri\Core\Transport\SMS;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\JobDispatcherInterface;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Artisans\Logging\LoggerFactory;
use Mlangeni\Machinjiri\Core\Exceptions\SMSException;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Transport\SMS\Message;

class SMSManager
{
    /** @var TransportInterface[] */
    private array $transports;
    private Logger $logger;
    private ?JobDispatcherInterface $dispatcher;
    private bool $asyncByDefault = false;
    private Container $app;
    private static array $config;

    public function __construct(
        Container $container,
        array $transports, 
        ?Logger $logger = null, 
        ?JobDispatcherInterface $dispatcher = null
    )
    {
        $this->app = $container;
        $this->transports = $transports;
        $this->logger = $logger ?? $this->app->resolve(Logger::class);
        $this->dispatcher = $dispatcher ?? $this->app->resolve('queue.dispatcher');
    }

    public static function fromConfig(array $config, Container $container): self
    {
        $logger = $container->resolve(Logger::class);
        $registry = new Factory\TransportRegistry($logger, $container);
        $transports = [];
        foreach ($config['transports'] ?? [] as $name => $transportConfig) {
            $transports[] = $registry->make($transportConfig);
        }
        $dispatcher = $container->resolve('queue.dispatcher');
        self::$config = $config;
        return new self($container, $transports, $logger, $dispatcher);
    }

    /**
     * Send a message – either synchronously or via queue.
     */
    public function send(Message $message, bool $async = false): Response
    {
        $async = (isset(self::$config['async']) && filter_var(self::$config['async'], FILTER_VALIDATE_BOOLEAN))
            ? self::$config['async'] : $async;
        
        if ($async && $this->dispatcher) {
            return $this->dispatchAsync($message);
        }

        $lastException = null;
        foreach ($this->transports as $index => $transport) {
            try {
                $this->logger->info('Attempting send via transport', [
                    'transport' => get_class($transport),
                    'index' => $index
                ]);
                return $transport->send($message);
            } catch (SMSException $e) {
                $lastException = $e;
                $this->logger->warning('Transport failed, trying next', [
                    'transport' => get_class($transport),
                    'error' => $e->getMessage()
                ]);
            }
        }
        throw new SMSException('All transports failed', 0, $lastException);
    }

    /**
     * Queue the send job.
     */
    private function dispatchAsync(Message $message): Response
    {
        $job = new Jobs\SendSmsJob(
            $this->app,
            ["message" => $message, "transports" => $this->transports],
            ["maxRetries" => 1]
        );
        $jobId = $this->dispatcher->dispatchToQueue($job, 'sms');
        $this->logger->info('SMS send queued', [
            'to' => $message->getTo(),
            'jobId' => $jobId
        ]);
        return new Response(true, $jobId, null, ['queued' => true]);
    }

    public function addTransport(TransportInterface $transport): void
    {
        $this->transports[] = $transport;
    }
}