<?php

namespace Mlangeni\Machinjiri\Core\Kernel\Http;

/**
 * RequestInterface defines the contract for HTTP request handling
 * 
 * All HTTP request implementations must follow this contract to ensure
 * consistency across the application.
 */
interface RequestInterface
{
    /**
     * Get the HTTP method
     * 
     * @return string HTTP method (GET, POST, PUT, DELETE, PATCH, etc.)
     */
    public function getMethod(): string;

    /**
     * Get the request URI
     * 
     * @return string The request URI
     */
    public function getUri(): string;

    /**
     * Get query parameters
     * 
     * @return array Query parameters from URL
     */
    public function getQueryParams(): array;

    /**
     * Get a specific query parameter
     * 
     * @param string $key Parameter key
     * @param mixed $default Default value if not found
     * @return mixed The parameter value
     */
    public function getQuery(string $key, $default = null);

    /**
     * Get POST data
     * 
     * @return array POST data
     */
    public function getPostData(): array;

    /**
     * Get a specific POST parameter
     * 
     * @param string $key Parameter key
     * @param mixed $default Default value if not found
     * @return mixed The parameter value
     */
    public function getPost(string $key, $default = null);

    /**
     * Get all cookies
     * 
     * @return array Cookies
     */
    public function getCookies(): array;

    /**
     * Get a specific cookie
     * 
     * @param string $key Cookie key
     * @param mixed $default Default value if not found
     * @return mixed The cookie value
     */
    public function getCookie(string $key, $default = null);

    /**
     * Get all headers
     * 
     * @return array Headers
     */
    public function getHeaders(): array;

    /**
     * Get a specific header
     * 
     * @param string $key Header key
     * @param mixed $default Default value if not found
     * @return mixed The header value
     */
    public function getHeader(string $key, $default = null);

    /**
     * Get request body
     * 
     * @return string Request body
     */
    public function getBody(): string;

    /**
     * Get server variables
     * 
     * @return array Server variables
     */
    public function getServer(): array;

    /**
     * Get a specific server variable
     * 
     * @param string $key Server variable key
     * @param mixed $default Default value if not found
     * @return mixed The server variable value
     */
    public function getServerVar(string $key, $default = null);

    /**
     * Set a request attribute
     * 
     * @param string $key Attribute key
     * @param mixed $value Attribute value
     * @return void
     */
    public function setAttribute(string $key, $value): void;

    /**
     * Get a request attribute
     * 
     * @param string $key Attribute key
     * @param mixed $default Default value if not found
     * @return mixed The attribute value
     */
    public function getAttribute(string $key, $default = null);

    /**
     * Get all request attributes
     * 
     * @return array All attributes
     */
    public function getAttributes(): array;

    /**
     * Check if request is AJAX
     * 
     * @return bool True if AJAX request
     */
    public function isAjax(): bool;

    /**
     * Check if request is secure (HTTPS)
     * 
     * @return bool True if secure
     */
    public function isSecure(): bool;

    /**
     * Check if request method is GET
     * 
     * @return bool True if GET request
     */
    public function isGet(): bool;

    /**
     * Check if request method is POST
     * 
     * @return bool True if POST request
     */
    public function isPost(): bool;

    /**
     * Check if request method is PUT
     * 
     * @return bool True if PUT request
     */
    public function isPut(): bool;

    /**
     * Check if request method is DELETE
     * 
     * @return bool True if DELETE request
     */
    public function isDelete(): bool;

    /**
     * Check if request method is PATCH
     * 
     * @return bool True if PATCH request
     */
    public function isPatch(): bool;

    /**
     * Get client IP address
     * 
     * @return string Client IP address
     */
    public function getClientIp(): string;

    /**
     * Get the full request URL
     * 
     * @return string Full URL
     */
    public function getFullUrl(): string;
}
