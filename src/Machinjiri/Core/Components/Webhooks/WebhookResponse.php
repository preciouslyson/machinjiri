<?php

namespace Mlangeni\Machinjiri\Core\Components\Webhooks;

class WebhookResponse
{
    private int $statusCode;
    private array $headers;
    private ?array $body;

    public function __construct(int $statusCode = 200, array $headers = [], ?array $body = null)
    {
        $this->statusCode = $statusCode;
        $this->headers = $headers;
        $this->body = $body;
    }

    public static function ok(?array $body = null): self
    {
        return new self(200, [], $body);
    }

    public static function accepted(): self
    {
        return new self(202);
    }

    public static function badRequest(string $message = 'Bad request'): self
    {
        return new self(400, [], ['error' => $message]);
    }

    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return new self(401, [], ['error' => $message]);
    }

    public static function notFound(string $message = 'Endpoint not found'): self
    {
        return new self(404, [], ['error' => $message]);
    }

    public function getStatusCode(): int { return $this->statusCode; }
    public function getHeaders(): array { return $this->headers; }
    public function getBody(): ?array { return $this->body; }

    public function toHttpResponse(): \Mlangeni\Machinjiri\Core\Http\HttpResponse
    {
        $httpResponse = new \Mlangeni\Machinjiri\Core\Http\HttpResponse();
        $httpResponse->setStatusCode($this->statusCode);
        foreach ($this->headers as $name => $value) {
            $httpResponse->setHeader($name, $value);
        }
        if ($this->body !== null) {
            $httpResponse->setJsonBody($this->body);
        }
        return $httpResponse;
    }
}