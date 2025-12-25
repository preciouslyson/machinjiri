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
    private $attributes = [];

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
        
        $headers = self::getAllHeaders();
        
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

    private static function getAllHeaders(): array {
        if (function_exists('getallheaders')) {
            return getallheaders();
        }

        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$name] = $value;
            } elseif ($name == 'CONTENT_TYPE') {
                $headers['Content-Type'] = $value;
            } elseif ($name == 'CONTENT_LENGTH') {
                $headers['Content-Length'] = $value;
            }
        }
        return $headers;
    }

    public function getMethod(): string {
        return $this->method;
    }

    public function getUri(): string {
        return $this->uri;
    }

    public function getPath(): string {
        return parse_url($this->uri, PHP_URL_PATH) ?: '/';
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
        $contentType = $this->getHeader('Content-Type', '');
        
        // Check if content type is JSON
        if (strpos($contentType, 'application/json') === false) {
            return null;
        }

        $data = json_decode($this->body, $assoc);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('JSON decoding failed: ' . json_last_error_msg());
        }
        
        return $data;
    }

    public function isAjax(): bool {
        return $this->getHeader('X-Requested-With') === 'XMLHttpRequest' ||
               $this->getHeader('X-Requested-With') === 'Fetch' ||
               strpos($this->getHeader('Accept', ''), 'application/json') !== false;
    }

    public function expectsJson(): bool {
        $accept = $this->getHeader('Accept', '');
        return strpos($accept, 'application/json') !== false ||
               strpos($accept, '*/*') !== false ||
               $this->isAjax();
    }

    public function isGet(): bool {
        return $this->method === 'GET';
    }

    public function isPost(): bool {
        return $this->method === 'POST';
    }

    public function isPut(): bool {
        return $this->method === 'PUT';
    }

    public function isDelete(): bool {
        return $this->method === 'DELETE';
    }

    public function isPatch(): bool {
        return $this->method === 'PATCH';
    }

    public function isOptions(): bool {
        return $this->method === 'OPTIONS';
    }

    public function isSecure(): bool {
        return (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off') ||
               (!empty($this->server['HTTP_X_FORWARDED_PROTO']) && $this->server['HTTP_X_FORWARDED_PROTO'] === 'https') ||
               (!empty($this->server['HTTP_X_FORWARDED_SSL']) && $this->server['HTTP_X_FORWARDED_SSL'] === 'on') ||
               (!empty($this->server['SERVER_PORT']) && $this->server['SERVER_PORT'] == 443);
    }

    public function getIp(): string {
        $ipKeys = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($ipKeys as $key) {
            if (!empty($this->server[$key])) {
                $ip = $this->server[$key];
                
                // Handle multiple IPs in X-Forwarded-For
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $this->server['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    public function getUserAgent(): string {
        return $this->getHeader('User-Agent', '');
    }

    public function getReferer(): string {
        return $this->getHeader('Referer', '');
    }

    public function getContentType(): string {
        return $this->getHeader('Content-Type', '');
    }

    public function getContentLength(): int {
        return (int) $this->getHeader('Content-Length', 0);
    }

    public function getAccept(): string {
        return $this->getHeader('Accept', '');
    }

    public function getAcceptLanguage(): string {
        return $this->getHeader('Accept-Language', '');
    }

    public function getAcceptEncoding(): string {
        return $this->getHeader('Accept-Encoding', '');
    }

    public function setAttribute(string $name, $value): self {
        $this->attributes[$name] = $value;
        return $this;
    }

    public function getAttribute(string $name, $default = null) {
        return $this->attributes[$name] ?? $default;
    }

    public function getAttributes(): array {
        return $this->attributes;
    }

    public function hasAttribute(string $name): bool {
        return isset($this->attributes[$name]);
    }

    public function removeAttribute(string $name): self {
        unset($this->attributes[$name]);
        return $this;
    }

    public function all(): array {
        return array_merge($this->queryParams, $this->postData);
    }

    public function input(string $key, $default = null) {
        return $this->postData[$key] ?? $this->queryParams[$key] ?? $default;
    }

    public function only(array $keys): array {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->input($key);
        }
        return $results;
    }

    public function except(array $keys): array {
        $all = $this->all();
        foreach ($keys as $key) {
            unset($all[$key]);
        }
        return $all;
    }

    public function has(string $key): bool {
        return isset($this->postData[$key]) || isset($this->queryParams[$key]);
    }

    public function filled(string $key): bool {
        $value = $this->input($key);
        return !empty($value);
    }
}