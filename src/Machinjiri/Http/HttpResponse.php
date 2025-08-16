<?php

namespace Mlangeni\Machinjiri\Core\Http;

class HttpResponse {
    private $statusCode = 200;
    private $headers = [];
    private $body = '';
    private $statusTexts = [
        200 => 'OK',
        201 => 'Created',
        204 => 'No Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        500 => 'Internal Server Error',
    ];

    public function setStatusCode(int $code): self {
        $this->statusCode = $code;
        return $this;
    }

    public function setHeader(string $name, string $value): self {
        $this->headers[$name] = $value;
        return $this;
    }

    public function setContentType(string $type): self {
        return $this->setHeader('Content-Type', $type);
    }

    public function setBody(string $content): self {
        $this->body = $content;
        return $this;
    }

    public function setJsonBody($data): self {
        $this->setHeader('Content-Type', 'application/json');
        $this->body = json_encode($data);
        return $this;
    }

    public function redirect(string $url, int $statusCode = 302): self {
        $this->setStatusCode($statusCode);
        $this->setHeader('location', $url);
        return $this;
    }

    public function withCookie(
        string $name,
        string $value = "",
        int $expire = 0,
        string $path = "",
        string $domain = "",
        bool $secure = false,
        bool $httponly = false
    ): self {
        $cookie = sprintf(
            '%s=%s; expires=%s; path=%s; domain=%s; %s%s',
            $name,
            urlencode($value),
            $expire ? gmdate('D, d M Y H:i:s T', $expire) : '',
            $path ?: '/',
            $domain,
            $secure ? 'Secure; ' : '',
            $httponly ? 'HttpOnly; ' : ''
        );
        $this->setHeader('Set-Cookie', $cookie);
        return $this;
    }

    public function send(): void {
        // Status header
        $statusText = $this->statusTexts[$this->statusCode] ?? 'Unknown Status';
        header(sprintf('HTTP/1.1 %d %s', $this->statusCode, $statusText));
        
        // Other headers
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        
        // Body
        echo $this->body;
    }
}