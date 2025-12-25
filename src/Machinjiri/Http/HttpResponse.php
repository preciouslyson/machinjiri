<?php

namespace Mlangeni\Machinjiri\Core\Http;

class HttpResponse {
    private $statusCode = 200;
    private $headers = [];
    private $body = '';
    private $sent = false;
    private $statusTexts = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Payload Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
    ];

    public function setStatusCode(int $code): self {
        $this->statusCode = $code;
        return $this;
    }

    public function getStatusCode(): int {
        return $this->statusCode;
    }

    public function setHeader(string $name, string $value): self {
        $this->headers[$name] = $value;
        return $this;
    }

    public function getHeader(string $name): ?string {
        return $this->headers[$name] ?? null;
    }

    public function getHeaders(): array {
        return $this->headers;
    }

    public function removeHeader(string $name): self {
        unset($this->headers[$name]);
        return $this;
    }

    public function setContentType(string $type): self {
        return $this->setHeader('Content-Type', $type);
    }

    public function setBody(string $content): self {
        $this->body = $content;
        return $this;
    }

    public function getBody(): string {
        return $this->body;
    }

    public function setJsonBody($data, int $options = JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT): self {
        $this->setHeader('Content-Type', 'application/json; charset=utf-8');
        $this->body = json_encode($data, $options);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('JSON encoding failed: ' . json_last_error_msg());
        }
        
        return $this;
    }

    public function isSent(): bool {
        return $this->sent;
    }

    public function redirect(string $url, int $statusCode = 302): self {
        $this->setStatusCode($statusCode);
        $this->setHeader('Location', $url);
        return $this;
    }

    public function withCookie(
        string $name,
        string $value = "",
        int $expire = 0,
        string $path = "",
        string $domain = "",
        bool $secure = false,
        bool $httponly = false,
        string $samesite = ''
    ): self {
        $cookie = sprintf(
            '%s=%s',
            $name,
            urlencode($value)
        );

        if ($expire > 0) {
            $cookie .= sprintf('; expires=%s', gmdate('D, d M Y H:i:s T', $expire));
        }

        if ($path) {
            $cookie .= sprintf('; path=%s', $path);
        }

        if ($domain) {
            $cookie .= sprintf('; domain=%s', $domain);
        }

        if ($secure) {
            $cookie .= '; Secure';
        }

        if ($httponly) {
            $cookie .= '; HttpOnly';
        }

        if ($samesite) {
            $cookie .= sprintf('; SameSite=%s', $samesite);
        }

        // Handle multiple Set-Cookie headers
        if (!isset($this->headers['Set-Cookie'])) {
            $this->headers['Set-Cookie'] = [];
        }
        
        if (!is_array($this->headers['Set-Cookie'])) {
            $this->headers['Set-Cookie'] = [$this->headers['Set-Cookie']];
        }
        
        $this->headers['Set-Cookie'][] = $cookie;
        
        return $this;
    }

    public function send(): void {
        if ($this->sent) {
            throw new \RuntimeException('Response has already been sent');
        }

        // Status header
        $statusText = $this->statusTexts[$this->statusCode] ?? 'Unknown Status';
       header(sprintf('HTTP/1.1 %d %s', $this->statusCode, $statusText), true, $this->statusCode);
        
        // Other headers
        foreach ($this->headers as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    header("$name: $item", false);
                }
            } else {
                header("$name: $value");
            }
        }
        
        // Body
        if ($this->body) {
            echo $this->body;
        }
        
        $this->sent = true;
    }

    public function sendJson($data, int $statusCode = 200, int $options = JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT): void {
        $this->setStatusCode($statusCode)
             ->setJsonBody($data, $options)
             ->send();
    }

    public function sendError(string $message, int $statusCode = 500): void {
        $response = [
            'error' => true,
            'message' => $message,
            'code' => $statusCode,
            'timestamp' => time()
        ];

        $this->sendJson($response, $statusCode);
    }

    public function sendSuccess($data = null, string $message = 'Success', int $statusCode = 200): void {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'code' => $statusCode,
            'timestamp' => time()
        ];

        $this->sendJson($response, $statusCode);
    }

    public function clear(): self {
        $this->statusCode = 200;
        $this->headers = [];
        $this->body = '';
        $this->sent = false;
        return $this;
    }
}