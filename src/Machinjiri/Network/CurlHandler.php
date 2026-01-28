<?php

namespace Mlangeni\Machinjiri\Core\Network;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Authentication\Session;
use Mlangeni\Machinjiri\Core\Authentication\Cookie;
use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;
use Mlangeni\Machinjiri\Core\Kernel\Network\HttpClientInterface;

class CurlHandler implements HttpClientInterface
{
    private $ch;
    private $baseUrl;
    private $options;
    private $session;
    private $cookie;
    private $timeout = 30;
    private $maxRedirects = 10;
    private $retryCount = 0;
    private $maxRetries = 3;
    private $retryDelay = 1000;
    private $responseHeaders = [];

    public function __construct($baseUrl = '', Session $session = null, Cookie $cookie = null)
    {
        $this->baseUrl = $baseUrl;
        $this->session = $session;
        $this->cookie = $cookie;
        $this->initializeCurl();
    }

    private function initializeCurl()
    {
        $this->ch = curl_init();
        $this->setDefaultOptions();
    }

    private function setDefaultOptions()
    {
        $this->options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => $this->maxRedirects,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Machinjiri-CurlHandler/1.0',
            CURLOPT_HEADER => false,
            CURLOPT_FAILONERROR => false
        ];
    }

    public function setOption($option, $value)
    {
        $this->options[$option] = $value;
        return $this;
    }

    public function setHeaders(array $headers)
    {
        $this->options[CURLOPT_HTTPHEADER] = $headers;
        return $this;
    }

    public function setBasicAuth($username, $password)
    {
        $this->options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        $this->options[CURLOPT_USERPWD] = "{$username}:{$password}";
        return $this;
    }

    public function setBearerToken($token)
    {
        $this->setHeaders(['Authorization: Bearer ' . $token]);
        return $this;
    }

    public function setTimeout($timeout)
    {
        $this->timeout = $timeout;
        $this->options[CURLOPT_TIMEOUT] = $timeout;
        return $this;
    }

    public function setMaxRedirects($maxRedirects)
    {
        $this->maxRedirects = $maxRedirects;
        $this->options[CURLOPT_MAXREDIRS] = $maxRedirects;
        return $this;
    }

    public function setUserAgent($userAgent)
    {
        $this->options[CURLOPT_USERAGENT] = $userAgent;
        return $this;
    }

    public function setReferer($referer)
    {
        $this->options[CURLOPT_REFERER] = $referer;
        return $this;
    }

    public function enableCookies($cookieFile = null)
    {
        if ($cookieFile) {
            $this->options[CURLOPT_COOKIEFILE] = $cookieFile;
            $this->options[CURLOPT_COOKIEJAR] = $cookieFile;
        } else {
            $this->options[CURLOPT_COOKIEFILE] = '';
            $this->options[CURLOPT_COOKIEJAR] = '';
        }
        return $this;
    }

    public function setCookie($name, $value)
    {
        $cookie = "{$name}={$value}";
        if (isset($this->options[CURLOPT_COOKIE])) {
            $this->options[CURLOPT_COOKIE] .= "; {$cookie}";
        } else {
            $this->options[CURLOPT_COOKIE] = $cookie;
        }
        return $this;
    }

    public function setProxy($proxy, $port = null, $username = null, $password = null)
    {
        $this->options[CURLOPT_PROXY] = $proxy;
        if ($port) {
            $this->options[CURLOPT_PROXYPORT] = $port;
        }
        if ($username && $password) {
            $this->options[CURLOPT_PROXYUSERPWD] = "{$username}:{$password}";
        }
        return $this;
    }

    public function setSslOptions($verifyPeer = true, $verifyHost = 2, $certPath = null, $keyPath = null)
    {
        $this->options[CURLOPT_SSL_VERIFYPEER] = $verifyPeer;
        $this->options[CURLOPT_SSL_VERIFYHOST] = $verifyHost;
        
        if ($certPath) {
            $this->options[CURLOPT_SSLCERT] = $certPath;
        }
        if ($keyPath) {
            $this->options[CURLOPT_SSLKEY] = $keyPath;
        }
        return $this;
    }

    public function setRetryOptions($maxRetries = 3, $retryDelay = 1000)
    {
        $this->maxRetries = $maxRetries;
        $this->retryDelay = $retryDelay;
        return $this;
    }

    public function enableCompression()
    {
        $this->options[CURLOPT_ENCODING] = '';
        return $this;
    }

    public function setCustomRequest($method)
    {
        $this->options[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
        return $this;
    }

    private function execute()
    {
        // Reset response headers before each request
        $this->responseHeaders = [];
        
        curl_setopt_array($this->ch, $this->options);
        
        $response = curl_exec($this->ch);
        $error = curl_error($this->ch);
        $httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($this->ch, CURLINFO_CONTENT_TYPE);
        $totalTime = curl_getinfo($this->ch, CURLINFO_TOTAL_TIME);

        // Retry logic for server errors (5xx) or connection issues
        if (($httpCode >= 500 || $error) && $this->retryCount < $this->maxRetries) {
            $this->retryCount++;
            usleep($this->retryDelay * 1000); // Convert to microseconds
            return $this->execute();
        }

        if ($error && $this->retryCount >= $this->maxRetries) {
            throw new MachinjiriException('cURL Error after ' . $this->maxRetries . ' retries: ' . $error);
        }

        return [
            'data' => $response,
            'http_code' => $httpCode,
            'content_type' => $contentType,
            'total_time' => $totalTime,
            'error' => $error ?: null,
            'retry_count' => $this->retryCount,
            'headers' => $this->responseHeaders // Include captured headers
        ];
    }

    public function withHeaderCapture(): self {
        // Store the existing HEADER setting
        $wasHeaderEnabled = $this->options[CURLOPT_HEADER] ?? false;
        
        // Enable header capture
        $this->options[CURLOPT_HEADER] = false; // We'll handle headers separately
        $this->options[CURLOPT_HEADERFUNCTION] = function($ch, $header) {
            $length = strlen($header);
            $header = trim($header);
            
            if (!empty($header)) {
                if (strpos($header, ':') !== false) {
                    list($name, $value) = explode(':', $header, 2);
                    $name = trim($name);
                    $value = trim($value);
                    
                    // Handle multiple headers with same name
                    if (isset($this->responseHeaders[$name])) {
                        if (is_array($this->responseHeaders[$name])) {
                            $this->responseHeaders[$name][] = $value;
                        } else {
                            $this->responseHeaders[$name] = [$this->responseHeaders[$name], $value];
                        }
                    } else {
                        $this->responseHeaders[$name] = $value;
                    }
                } else if (preg_match('/HTTP\/\d(\.\d)?\s+(\d+)/', $header, $matches)) {
                    // Store HTTP status line
                    $this->responseHeaders['Status-Line'] = $header;
                }
            }
            return $length;
        };
        
        return $this;
    }

    public function get($endpoint = '', $queryParams = [])
    {
        $url = $this->buildUrl($endpoint, $queryParams);
        
        $this->options[CURLOPT_URL] = $url;
        $this->options[CURLOPT_CUSTOMREQUEST] = 'GET';
        $this->options[CURLOPT_POSTFIELDS] = null;
        $this->options[CURLOPT_HTTPGET] = true;

        $this->retryCount = 0; // Reset retry counter for new request
        return $this->execute();
    }

    public function post($endpoint = '', $data = [], $isJson = true)
    {
        $this->options[CURLOPT_URL] = $this->buildUrl($endpoint);
        $this->options[CURLOPT_CUSTOMREQUEST] = 'POST';
        $this->options[CURLOPT_POST] = true;
        
        if ($isJson) {
            $this->options[CURLOPT_POSTFIELDS] = json_encode($data);
            $this->setHeaders(['Content-Type: application/json']);
        } else {
            $this->options[CURLOPT_POSTFIELDS] = is_array($data) ? http_build_query($data) : $data;
        }

        $this->retryCount = 0;
        return $this->execute();
    }

    public function put($endpoint = '', $data = [], $isJson = true)
    {
        $this->options[CURLOPT_URL] = $this->buildUrl($endpoint);
        $this->options[CURLOPT_CUSTOMREQUEST] = 'PUT';
        
        if ($isJson) {
            $this->options[CURLOPT_POSTFIELDS] = json_encode($data);
            $this->setHeaders(['Content-Type: application/json']);
        } else {
            $this->options[CURLOPT_POSTFIELDS] = is_array($data) ? http_build_query($data) : $data;
        }

        $this->retryCount = 0;
        return $this->execute();
    }

    public function patch($endpoint = '', $data = [], $isJson = true)
    {
        $this->options[CURLOPT_URL] = $this->buildUrl($endpoint);
        $this->options[CURLOPT_CUSTOMREQUEST] = 'PATCH';
        
        if ($isJson) {
            $this->options[CURLOPT_POSTFIELDS] = json_encode($data);
            $this->setHeaders(['Content-Type: application/json']);
        } else {
            $this->options[CURLOPT_POSTFIELDS] = is_array($data) ? http_build_query($data) : $data;
        }

        $this->retryCount = 0;
        return $this->execute();
    }

    public function delete($endpoint = '', $data = [])
    {
        $this->options[CURLOPT_URL] = $this->buildUrl($endpoint);
        $this->options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        
        if (!empty($data)) {
            $this->options[CURLOPT_POSTFIELDS] = json_encode($data);
            $this->setHeaders(['Content-Type: application/json']);
        }

        $this->retryCount = 0;
        return $this->execute();
    }

    public function head($endpoint = '')
    {
        $this->options[CURLOPT_URL] = $this->buildUrl($endpoint);
        $this->options[CURLOPT_CUSTOMREQUEST] = 'HEAD';
        $this->options[CURLOPT_NOBODY] = true;

        $this->retryCount = 0;
        return $this->execute();
    }

    public function options($endpoint = '')
    {
        $this->options[CURLOPT_URL] = $this->buildUrl($endpoint);
        $this->options[CURLOPT_CUSTOMREQUEST] = 'OPTIONS';

        $this->retryCount = 0;
        return $this->execute();
    }

    public function uploadFile($endpoint, $fieldName, $filePath, $additionalData = [])
    {
        if (!file_exists($filePath)) {
            throw new MachinjiriException("File not found: {$filePath}");
        }

        $this->options[CURLOPT_URL] = $this->buildUrl($endpoint);
        $this->options[CURLOPT_POST] = true;

        $postData = $additionalData;
        $postData[$fieldName] = new \CURLFile($filePath);

        $this->options[CURLOPT_POSTFIELDS] = $postData;

        $this->retryCount = 0;
        return $this->execute();
    }

    public function downloadFile($endpoint, $savePath)
    {
        $this->options[CURLOPT_URL] = $this->buildUrl($endpoint);
        $this->options[CURLOPT_RETURNTRANSFER] = false;
        $this->options[CURLOPT_FILE] = fopen($savePath, 'w+');

        if ($this->options[CURLOPT_FILE] === false) {
            throw new MachinjiriException("Cannot open file for writing: {$savePath}");
        }

        $this->retryCount = 0;
        $result = $this->execute();

        fclose($this->options[CURLOPT_FILE]);
        $this->options[CURLOPT_RETURNTRANSFER] = true;
        unset($this->options[CURLOPT_FILE]);

        return $result;
    }

    public function multiRequest(array $requests)
    {
        $mh = curl_multi_init();
        $handles = [];
        $results = [];

        foreach ($requests as $key => $request) {
            $ch = curl_init();
            curl_setopt_array($ch, array_merge($this->options, $request['options']));
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running > 0);

        foreach ($handles as $key => $ch) {
            $results[$key] = [
                'data' => curl_multi_getcontent($ch),
                'http_code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'error' => curl_error($ch)
            ];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);
        return $results;
    }

    private function buildUrl($endpoint, $queryParams = [])
    {
        $url = $this->baseUrl . $endpoint;
        
        if (!empty($queryParams)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($queryParams);
        }
        
        return $url;
    }

    public function getInfo($option = null)
    {
        return $option ? curl_getinfo($this->ch, $option) : curl_getinfo($this->ch);
    }

    public function getError()
    {
        return curl_error($this->ch);
    }

    public function getErrorCode()
    {
        return curl_errno($this->ch);
    }

    public function getResponseHeaders(): array
    {
        return $this->responseHeaders;
    }

    public function getResponseHeader(string $name): ?string
    {
        return $this->responseHeaders[$name] ?? null;
    }

    public function reset()
    {
        curl_close($this->ch);
        $this->options = [];
        $this->responseHeaders = [];
        $this->retryCount = 0;
        $this->initializeCurl();
        return $this;
    }

    public function close()
    {
        if (is_resource($this->ch)) {
            curl_close($this->ch);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    // Integration with existing Session and Cookie classes
    public function useSessionCookies()
    {
        if ($this->session) {
            $sessionId = $this->session->get('session_id');
            if ($sessionId) {
                $this->setCookie('PHPSESSID', $sessionId);
            }
        }
        return $this;
    }

    public function useApplicationCookies()
    {
        if ($this->cookie) {
            foreach ($_COOKIE as $name => $value) {
                $this->setCookie($name, $value);
            }
        }
        return $this;
    }

    // Convenience method to mimic HttpRequest behavior
    public function createFromHttpRequest(HttpRequest $request)
    {
        $this->setHeaders($request->getHeaders());
        
        if ($request->getCookie('auth_token')) {
            $this->setBearerToken($request->getCookie('auth_token'));
        }
        
        return $this;
    }
    
    public function toHttpResponse($response)
    {
        $httpResponse = new HttpResponse();
        $httpResponse->setStatusCode($response['http_code']);
        
        // Handle headers if available
        if (isset($response['headers'])) {
            foreach ($response['headers'] as $name => $value) {
                if ($name !== 'Status-Line') { // Skip status line
                    $httpResponse->setHeader($name, $value);
                }
            }
        }
        
        // Set content-type header
        if (!empty($response['content_type'])) {
            $httpResponse->setHeader('Content-Type', $response['content_type']);
        }
        
        // Handle body based on content type
        if (strpos($response['content_type'] ?? '', 'application/json') !== false) {
            $decodedData = json_decode($response['data'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $httpResponse->setJsonBody($decodedData);
            } else {
                $httpResponse->setBody($response['data']);
            }
        } else {
            $httpResponse->setBody($response['data']);
        }
        
        return $httpResponse;
    }
}