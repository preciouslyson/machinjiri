<?php

namespace Mlangeni\Machinjiri\Core\Transport\Mail;

class MailMessage
{
    private array $from = [];
    private array $to = [];
    private array $cc = [];
    private array $bcc = [];
    private array $replyTo = [];
    private string $subject = '';
    private string $htmlBody = '';
    private string $textBody = '';
    private array $attachments = []; // each: ['path' => string, 'name' => ?string, 'content' => ?string, 'type' => ?string]
    private array $embeddedImages = []; // each: ['cid' => string, 'path' => string]
    private array $headers = [];
    private int $priority = 3; // 1 highest, 3 normal, 5 lowest

    public function from(string $email, ?string $name = null): self
    {
        $this->from = ['email' => $email, 'name' => $name];
        return $this;
    }

    public function to(string $email, ?string $name = null): self
    {
        $this->to[] = ['email' => $email, 'name' => $name];
        return $this;
    }

    public function toMany(array $recipients): self
    {
        foreach ($recipients as $recipient) {
            if (is_string($recipient)) {
                $this->to($recipient);
            } elseif (is_array($recipient) && isset($recipient['email'])) {
                $this->to($recipient['email'], $recipient['name'] ?? null);
            }
        }
        return $this;
    }

    public function cc(string $email, ?string $name = null): self
    {
        $this->cc[] = ['email' => $email, 'name' => $name];
        return $this;
    }

    public function bcc(string $email, ?string $name = null): self
    {
        $this->bcc[] = ['email' => $email, 'name' => $name];
        return $this;
    }

    public function replyTo(string $email, ?string $name = null): self
    {
        $this->replyTo = ['email' => $email, 'name' => $name];
        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    public function html(string $html, ?string $text = null): self
    {
        $this->htmlBody = $html;
        $this->textBody = $text ?? strip_tags($html);
        return $this;
    }

    public function text(string $text): self
    {
        $this->textBody = $text;
        return $this;
    }

    public function attachFile(string $path, ?string $name = null, ?string $contentType = null): self
    {
        $this->attachments[] = [
            'path' => $path,
            'name' => $name,
            'content' => null,
            'type' => $contentType,
        ];
        return $this;
    }

    public function attachContent(string $content, string $name, ?string $contentType = null): self
    {
        $this->attachments[] = [
            'path' => null,
            'name' => $name,
            'content' => $content,
            'type' => $contentType,
        ];
        return $this;
    }

    public function embedImage(string $path, string $cid): self
    {
        $this->embeddedImages[] = ['path' => $path, 'cid' => $cid];
        return $this;
    }

    public function header(string $key, string $value): self
    {
        $this->headers[$key] = $value;
        return $this;
    }

    public function priority(int $level): self
    {
        $this->priority = $level;
        return $this;
    }

    // Getters
    public function getFrom(): array { return $this->from; }
    public function getTo(): array { return $this->to; }
    public function getCc(): array { return $this->cc; }
    public function getBcc(): array { return $this->bcc; }
    public function getReplyTo(): array { return $this->replyTo; }
    public function getSubject(): string { return $this->subject; }
    public function getHtmlBody(): string { return $this->htmlBody; }
    public function getTextBody(): string { return $this->textBody; }
    public function getAttachments(): array { return $this->attachments; }
    public function getEmbeddedImages(): array { return $this->embeddedImages; }
    public function getHeaders(): array { return $this->headers; }
    public function getPriority(): int { return $this->priority; }
}