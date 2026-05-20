<?php

namespace Mlangeni\Machinjiri\Core\Transport\Mail;

class MailResponse
{
    private string $messageId;
    private string $transport;
    private array $metadata;
    private ?string $rawResponse;

    public function __construct(string $messageId, string $transport, array $metadata = [], ?string $rawResponse = null)
    {
        $this->messageId = $messageId;
        $this->transport = $transport;
        $this->metadata = $metadata;
        $this->rawResponse = $rawResponse;
    }

    public function getMessageId(): string { return $this->messageId; }
    public function getTransport(): string { return $this->transport; }
    public function getMetadata(): array { return $this->metadata; }
    public function getRawResponse(): ?string { return $this->rawResponse; }
}