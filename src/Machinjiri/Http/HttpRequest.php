<?php

namespace Mlangeni\Machinjiri\Core\Http;

class HttpRequest {
    private $method;
    private $uri;
    private $queryParams;
    private $postData;
    private $cookies;
    private $server;
    private $headers;
    private $body;

    public function __construct(
        string $method,
        string $uri,
        array $queryParams = [],
        array $postData = [],
        array $cookies = [],
        array $server = [],
        array $headers = [],
        string $body = ''
    ) {
        $this->method = strtoupper($method);
        $this->uri = $uri;
        $this->queryParams = $queryParams;
        $this->postData = $postData;
        $this->cookies = $cookies;
        $this->server = $server;
        $this->headers = $headers;
        $this->body = $body;
    }

    public static function createFromGlobals(): self {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $queryParams = $_GET;
        $postData = $_POST;
        $cookies = $_COOKIE;
        $server = $_SERVER;
        
        $headers = [];
        foreach ($server as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
                $headers[$header] = $value;
            }
        }
        
        $body = file_get_contents('php://input');

        return new self(
            $method,
            $uri,
            $queryParams,
            $postData,
            $cookies,
            $server,
            $headers,
            $body
        );
    }

    public function getMethod(): string {
        return $this->method;
    }

    public function getUri(): string {
        return $this->uri;
    }

    public function getQueryParams(): array {
        return $this->queryParams;
    }

    public function getQueryParam(string $key, $default = null) {
        return $this->queryParams[$key] ?? $default;
    }

    public function getPostData(): array {
        return $this->postData;
    }

    public function getPostParam(string $key, $default = null) {
        return $this->postData[$key] ?? $default;
    }

    public function getCookies(): array {
        return $this->cookies;
    }

    public function getCookie(string $key, $default = null) {
        return $this->cookies[$key] ?? $default;
    }

    public function getServer(): array {
        return $this->server;
    }

    public function getServerParam(string $key, $default = null) {
        return $this->server[$key] ?? $default;
    }

    public function getHeaders(): array {
        return $this->headers;
    }

    public function getHeader(string $name, $default = null) {
        $name = strtolower($name);
        foreach ($this->headers as $header => $value) {
            if (strtolower($header) === $name) {
                return $value;
            }
        }
        return $default;
    }

    public function getBody(): string {
        return $this->body;
    }

    public function getJsonBody(bool $assoc = true) {
        return json_decode($this->body, $assoc);
    }
}