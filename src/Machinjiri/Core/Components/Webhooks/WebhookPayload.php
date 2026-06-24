<?php

namespace Mlangeni\Machinjiri\Core\Components\Webhooks;

use Mlangeni\Machinjiri\Core\Http\HttpRequest;

class WebhookPayload
{
    private string $rawBody;
    private array $headers;
    private ?array $parsedData;
    private string $eventType;
    private string $provider;
    private ?string $idempotencyKey;

    public function __construct(
        string $rawBody,
        array $headers,
        ?array $parsedData,
        string $eventType,
        string $provider,
        ?string $idempotencyKey = null
    ) {
        $this->rawBody = $rawBody;
        $this->headers = $headers;
        $this->parsedData = $parsedData;
        $this->eventType = $eventType;
        $this->provider = $provider;
        $this->idempotencyKey = $idempotencyKey;
    }

    public static function fromHttpRequest(
        HttpRequest $request,
        string $provider,
        ?string $idempotencyHeader = 'X-Request-Id',
        ?array $providerConfig = null   // contains event_resolver etc.
    ): self {
        $rawBody = $request->getBody();
        $contentType = $request->getContentType();
        $parsedData = null;

        if (strpos($contentType, 'application/json') !== false) {
            $parsedData = $request->getJsonBody(true);
        } elseif (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
            parse_str($rawBody, $parsedData);
        }

        // Resolve event type
        $eventType = self::resolveEventType($request, $parsedData, $providerConfig);

        $idempotencyKey = $idempotencyHeader ? $request->getHeader($idempotencyHeader) : null;

        return new self($rawBody, $request->getHeaders(), $parsedData, $eventType, $provider, $idempotencyKey);
    }

    private static function resolveEventType(HttpRequest $request, ?array $parsedData, ?array $providerConfig): string
    {
        // 1. Check custom resolver from provider config
        if ($providerConfig && isset($providerConfig['event_resolver'])) {
            $resolver = $providerConfig['event_resolver'];
            if (isset($resolver['type'])) {
                // dot notation, e.g. 'type' or 'data.type'
                $keys = explode('.', $resolver['type']);
                $value = $parsedData;
                foreach ($keys as $key) {
                    if (!is_array($value) || !array_key_exists($key, $value)) {
                        break;
                    }
                    $value = $value[$key];
                }
                if (is_string($value)) {
                    return $value;
                }
            }
            if (isset($resolver['callback']) && is_callable($resolver['callback'])) {
                return $resolver['callback']($request, $parsedData);
            }
        }

        // 2. Fallback to standard headers
        return $request->getHeader('X-Event-Name') ??
               $request->getHeader('X-GitHub-Event') ??
               $request->getHeader('X-Stripe-Event') ??
               'unknown';
    }

    // Getters
    public function getRawBody(): string { return $this->rawBody; }
    public function getHeaders(): array { return $this->headers; }
    public function getParsedData(): ?array { return $this->parsedData; }
    public function getEventType(): string { return $this->eventType; }
    public function getProvider(): string { return $this->provider; }
    public function getIdempotencyKey(): ?string { return $this->idempotencyKey; }

    public function get(string $key, $default = null)
    {
        if (!$this->parsedData) {
            return $default;
        }
        $keys = explode('.', $key);
        $value = $this->parsedData;
        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }
}