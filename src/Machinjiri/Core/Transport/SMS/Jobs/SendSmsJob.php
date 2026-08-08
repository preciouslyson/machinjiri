<?php
namespace Mlangeni\Machinjiri\Core\Transport\SMS\Jobs;

use Mlangeni\Machinjiri\Core\Artisans\Contracts\BaseJob;
use Mlangeni\Machinjiri\Core\Artisans\Logging\{LoggerFactory, Logger};
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Transport\SMS\{Message, Response};
use Mlangeni\Machinjiri\Core\Transport\SMS\Factory\TransportRegistry;
use Mlangeni\Machinjiri\Core\Transport\SMS\Contracts\TransportInterface;
use Mlangeni\Machinjiri\Core\Container;
    
class SendSmsJob extends BaseJob
{

    protected EventListener $events;
    protected Logger $logger;
    protected Response $response;

    public function __construct(Container $app, array $payload = [], array $options = [])
    {
        $defaultOptions = [
            'maxAttempts' => 1,
            'queue' => 'sms',
            'timeout' => 60,
            'delay' => 0,
            'precompressed' => false
        ];
        
        parent::__construct($app, $payload, array_merge($defaultOptions, $options));
        $this->events = $app->resolve(EventListener::class);
        $this->logger = $app->resolve(Logger::class);
        
    }

    public function handle(): void
    {
        $data = $this->getPayload();
        $app = $this->getApp();
        $message = Message::fromArray($data['message']);
        $transport = (new TransportRegistry($this->logger, $app))->defaultTransport();        

        try {
            $this->events->trigger("sms.sending", [
                "to" => $message->getTo(),
                "message" => $message->getText(),
                "transport" => $transport
            ]);
            
            $this->response = $transport->send($message);

            if ($this->response->isSuccess()) {
                $this->events->trigger("sms.sent", [
                    "messageId" => $this->response->getMessageId(),
                    "transport" => $transport
                ]);
            } else {
                $this->events->trigger("sms.send_failed", [
                    "messageId" => $this->response->getMessageId(),
                    "error" => $this->response->getError(),
                    "transport" => $transport
                ]);
            }
            return;
        } catch (\Throwable $e) {
            throw new MachinjiriException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function failed (MachinjiriException $exception): void 
    {
        $compile = [
            "messageId" => $this->response->getMessageId(),
            "error" => $exception->getMessage(),
        ];
        $this->events->trigger("sms.send_failed", $compile);
        $this->logger->warning('sms send failed', $compile);
    }
}