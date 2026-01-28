<?php

namespace Mlangeni\Machinjiri\Core\Kernel\Network;

/**
 * HttpClientInterface defines the contract for HTTP clients
 * 
 * All HTTP client implementations must follow this contract to ensure
 * consistent HTTP communication across the application.
 */
interface HttpClientInterface
{
    /**
     * Perform a GET request
     * 
     * @param string $url Request URL
     * @param array $options Request options
     * @return array Response data
     */
    public function get(string $url, array $options = []): array;

    /**
     * Perform a POST request
     * 
     * @param string $url Request URL
     * @param array $data Request data
     * @param array $options Request options
     * @return array Response data
     */
    public function post(string $url, array $data = [], array $options = []): array;

    /**
     * Perform a PUT request
     * 
     * @param string $url Request URL
     * @param array $data Request data
     * @param array $options Request options
     * @return array Response data
     */
    public function put(string $url, array $data = [], array $options = []): array;

    /**
     * Perform a DELETE request
     * 
     * @param string $url Request URL
     * @param array $options Request options
     * @return array Response data
     */
    public function delete(string $url, array $options = []): array;

    /**
     * Perform a PATCH request
     * 
     * @param string $url Request URL
     * @param array $data Request data
     * @param array $options Request options
     * @return array Response data
     */
    public function patch(string $url, array $data = [], array $options = []): array;

    /**
     * Perform a HEAD request
     * 
     * @param string $url Request URL
     * @param array $options Request options
     * @return array Response data
     */
    public function head(string $url, array $options = []): array;

    /**
     * Perform any HTTP request
     * 
     * @param string $method HTTP method
     * @param string $url Request URL
     * @param array $options Request options
     * @return array Response data
     */
    public function request(string $method, string $url, array $options = []): array;

    /**
     * Set a header
     * 
     * @param string $key Header name
     * @param string $value Header value
     * @return self Fluent interface
     */
    public function setHeader(string $key, string $value): self;

    /**
     * Set multiple headers
     * 
     * @param array $headers Headers array
     * @return self Fluent interface
     */
    public function setHeaders(array $headers): self;

    /**
     * Get current headers
     * 
     * @return array Headers
     */
    public function getHeaders(): array;

    /**
     * Set authentication
     * 
     * @param string $username Username
     * @param string $password Password
     * @param string $type Authentication type (basic, digest, etc.)
     * @return self Fluent interface
     */
    public function setAuth(string $username, string $password, string $type = 'basic'): self;

    /**
     * Set timeout
     * 
     * @param int $timeout Timeout in seconds
     * @return self Fluent interface
     */
    public function setTimeout(int $timeout): self;

    /**
     * Set SSL verification
     * 
     * @param bool $verify Whether to verify SSL
     * @return self Fluent interface
     */
    public function setSSLVerify(bool $verify): self;

    /**
     * Set proxy
     * 
     * @param string $proxy Proxy URL
     * @return self Fluent interface
     */
    public function setProxy(string $proxy): self;

    /**
     * Set retry count
     * 
     * @param int $retries Number of retries
     * @return self Fluent interface
     */
    public function setRetries(int $retries): self;

    /**
     * Get last response status code
     * 
     * @return int Status code
     */
    public function getStatusCode(): int;

    /**
     * Get last response headers
     * 
     * @return array Response headers
     */
    public function getResponseHeaders(): array;

    /**
     * Get last error message
     * 
     * @return string|null Error message
     */
    public function getError(): ?string;

    /**
     * Reset client state
     * 
     * @return self Fluent interface
     */
    public function reset(): self;
}
