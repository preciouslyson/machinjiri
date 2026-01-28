<?php

namespace Mlangeni\Machinjiri\Core\Kernel\Http;

/**
 * ResponseInterface defines the contract for HTTP response handling
 * 
 * All HTTP response implementations must follow this contract to ensure
 * consistent response building and sending across the application.
 */
interface ResponseInterface
{
    /**
     * Set HTTP status code
     * 
     * @param int $code HTTP status code (200, 404, 500, etc.)
     * @return self Fluent interface
     */
    public function setStatusCode(int $code): self;

    /**
     * Get HTTP status code
     * 
     * @return int The status code
     */
    public function getStatusCode(): int;

    /**
     * Get status text for code
     * 
     * @param int $code HTTP status code
     * @return string Status text
     */
    public function getStatusText(int $code): string;

    /**
     * Set a header
     * 
     * @param string $key Header name
     * @param string $value Header value
     * @param bool $replace Whether to replace existing header
     * @return self Fluent interface
     */
    public function setHeader(string $key, string $value, bool $replace = true): self;

    /**
     * Get a header value
     * 
     * @param string $key Header name
     * @return string|null Header value or null if not set
     */
    public function getHeader(string $key): ?string;

    /**
     * Get all headers
     * 
     * @return array All headers
     */
    public function getHeaders(): array;

    /**
     * Remove a header
     * 
     * @param string $key Header name
     * @return self Fluent interface
     */
    public function removeHeader(string $key): self;

    /**
     * Set response body
     * 
     * @param string $body Response body
     * @return self Fluent interface
     */
    public function setBody(string $body): self;

    /**
     * Get response body
     * 
     * @return string Response body
     */
    public function getBody(): string;

    /**
     * Append to response body
     * 
     * @param string $content Content to append
     * @return self Fluent interface
     */
    public function appendBody(string $content): self;

    /**
     * Set JSON response
     * 
     * @param array $data Data to encode as JSON
     * @param int $code HTTP status code
     * @return self Fluent interface
     */
    public function json(array $data, int $code = 200): self;

    /**
     * Redirect to URL
     * 
     * @param string $url Redirect URL
     * @param int $code HTTP status code (301, 302, etc.)
     * @return self Fluent interface
     */
    public function redirect(string $url, int $code = 302): self;

    /**
     * Send the response to client
     * 
     * @return void
     */
    public function send(): void;

    /**
     * Check if response has been sent
     * 
     * @return bool True if response sent
     */
    public function isSent(): bool;

    /**
     * Set content type
     * 
     * @param string $contentType MIME type (text/html, application/json, etc.)
     * @return self Fluent interface
     */
    public function setContentType(string $contentType): self;

    /**
     * Get content type
     * 
     * @return string MIME type
     */
    public function getContentType(): string;

    /**
     * Set content length
     * 
     * @param int $length Content length in bytes
     * @return self Fluent interface
     */
    public function setContentLength(int $length): self;

    /**
     * Set cache control
     * 
     * @param string $control Cache control directive
     * @return self Fluent interface
     */
    public function setCacheControl(string $control): self;

    /**
     * Set cookie
     * 
     * @param string $name Cookie name
     * @param string $value Cookie value
     * @param int $expires Expiration timestamp
     * @param string $path Cookie path
     * @param string $domain Cookie domain
     * @param bool $secure HTTPS only
     * @param bool $httpOnly HTTP only
     * @return self Fluent interface
     */
    public function setCookie(string $name, string $value, int $expires = 0, 
                              string $path = '/', string $domain = '', 
                              bool $secure = false, bool $httpOnly = true): self;

    /**
     * Delete a cookie
     * 
     * @param string $name Cookie name
     * @return self Fluent interface
     */
    public function deleteCookie(string $name): self;
}
