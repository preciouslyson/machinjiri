<?php

namespace Mlangeni\Machinjiri\Core\Authentication;

use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;
use Mlangeni\Machinjiri\Core\Authentication\Session;
use Mlangeni\Machinjiri\Core\Authentication\Cookie;
use Mlangeni\Machinjiri\Core\Database\QueryBuilder;
use Mlangeni\Machinjiri\Core\Network\CurlHandler;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Kernel\Authentication\AuthenticationInterface;
use \InvalidArgumentException;
use \RuntimeException;

class OAuth implements AuthenticationInterface
{
    // Constants for configuration
    private const OAUTH_STATE_KEY = 'oauth_state';
    private const OAUTH_TOKEN_KEY = 'oauth_token';
    private const TOKEN_TABLE = 'oauth_tokens';
    private const TOKEN_LENGTH = 16;
    private const DEFAULT_TOKEN_EXPIRY = 3600;
    private const STATE_HASH_ALGO = 'sha256';

    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private string $authorizationEndpoint;
    private string $tokenEndpoint;
    private array $scopes;
    private ?string $state = null;
    private Session $session;
    private Cookie $cookie;
    private ?QueryBuilder $queryBuilder = null;
    private ?CurlHandler $httpClient = null;
    private ?Logger $logger = null;

    public function __construct(
        string $clientId,
        string $clientSecret,
        string $redirectUri,
        string $authorizationEndpoint,
        string $tokenEndpoint,
        array $scopes = [],
        Session $session = null,
        Cookie $cookie = null,
        CurlHandler $httpClient = null,
        Logger $logger = null
    ) {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->redirectUri = $redirectUri;
        $this->authorizationEndpoint = $authorizationEndpoint;
        $this->tokenEndpoint = $tokenEndpoint;
        $this->scopes = $scopes;
        $this->session = $session ?? new Session();
        $this->cookie = $cookie ?? new Cookie();
        $this->httpClient = $httpClient;
        $this->logger = $logger;
    }

    public function setQueryBuilder(QueryBuilder $queryBuilder): void
    {
        $this->queryBuilder = $queryBuilder;
    }

    public function setHttpClient(CurlHandler $httpClient): void
    {
        $this->httpClient = $httpClient;
    }

    public function setLogger(Logger $logger): void
    {
        $this->logger = $logger;
    }

    public function setState(string $state): void
    {
        $this->state = $state;
    }

    public function getAuthorizationUrl(): string
    {
        $state = $this->state ?? bin2hex(random_bytes(self::TOKEN_LENGTH));
        $this->session->set(self::OAUTH_STATE_KEY, $state);
        $this->log(Logger::DEBUG, 'OAuth authorization URL generated with state: {state}', ['state' => $state]);

        $query = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes),
            'state' => $state
        ]);

        return $this->authorizationEndpoint . '?' . $query;
    }

    public function handleAuthorizationRedirect(HttpResponse $response): void
    {
        $authUrl = $this->getAuthorizationUrl();
        $response->redirect($authUrl)->send();
        exit;
    }

    public function getAccessToken(string $code, ?string $state = null): array
    {
        // Validate state parameter with constant-time comparison
        $storedState = $this->session->get(self::OAUTH_STATE_KEY);
        
        if (!$storedState) {
            $this->log(Logger::WARNING, 'No stored OAuth state found in session');
            throw new InvalidArgumentException('OAuth state not found in session. CSRF attack suspected.');
        }
        
        if ($state === null) {
            $this->log(Logger::WARNING, 'State parameter missing from callback');
            throw new InvalidArgumentException('State parameter is required');
        }

        if (!hash_equals($storedState, $state)) {
            $this->log(Logger::ERROR, 'State mismatch - potential CSRF attack');
            throw new InvalidArgumentException('State parameter mismatch. CSRF attack suspected.');
        }

        $data = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code'
        ];

        $response = $this->httpRequest($this->tokenEndpoint, $data);

        if (!$response) {
            $this->log(Logger::ERROR, 'Failed to get access token from endpoint');
            throw new RuntimeException('Failed to retrieve access token from OAuth provider');
        }

        $tokenData = $this->parseJsonResponse($response);

        // Validate required fields in token response
        $this->validateTokenResponse($tokenData);
        
        // Store token in session
        $this->session->set(self::OAUTH_TOKEN_KEY, $tokenData);
        $this->log(Logger::INFO, 'OAuth token successfully stored in session');
        
        // Optionally store token in database if QueryBuilder is available
        if ($this->queryBuilder) {
            $this->storeTokenInDatabase($tokenData);
        }

        return $tokenData;
    }

    public function handleCallback(HttpRequest $request): array
    {
        $code = $request->getQueryParam('code');
        $state = $request->getQueryParam('state');
        $error = $request->getQueryParam('error');
        $errorDescription = $request->getQueryParam('error_description');

        // Check for OAuth provider errors
        if ($error) {
            $this->log(Logger::WARNING, 'OAuth error from provider: {error} - {description}', 
                ['error' => $error, 'description' => $errorDescription]);
            throw new RuntimeException("OAuth provider error: {$error}");
        }

        if (!$code) {
            $this->log(Logger::ERROR, 'Authorization code not provided in callback');
            throw new RuntimeException('Authorization code not found in callback');
        }

        return $this->getAccessToken($code, $state);
    }

    public function isAuthenticated(): bool
    {
        $token = $this->session->get(self::OAUTH_TOKEN_KEY);
        return $token !== null && !$this->isTokenExpired($token);
    }

    public function getStoredToken(): ?array
    {
        $token = $this->session->get(self::OAUTH_TOKEN_KEY);
        
        if ($token === null) {
            return null;
        }
        
        // Check if token is expired
        if ($this->isTokenExpired($token)) {
            $this->log(Logger::WARNING, 'Stored OAuth token has expired');
            return null;
        }
        
        return $token;
    }

    public function getAccessTokenValue(): ?string
    {
        $token = $this->getStoredToken();
        return $token['access_token'] ?? null;
    }

    public function logout(): void
    {
        $token = $this->getStoredToken();
        if ($token) {
            // Attempt token revocation if refresh token exists
            $this->revokeToken($token['access_token'] ?? null);
        }
        
        $this->session->destroy();
        $this->cookie->delete('oauth_token');
        $this->log(Logger::INFO, 'OAuth user logged out');
    }

    public function revokeToken(?string $token = null): bool
    {
        try {
            $tokenToRevoke = $token ?? $this->getAccessTokenValue();
            
            if (!$tokenToRevoke) {
                $this->log(Logger::WARNING, 'No token available to revoke');
                return false;
            }

            $data = [
                'token' => $tokenToRevoke,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret
            ];

            $response = $this->httpRequest($this->tokenEndpoint, $data, 'POST');
            $this->log(Logger::INFO, 'Token revocation request sent');
            
            return true;
        } catch (\Exception $e) {
            $this->log(Logger::WARNING, 'Failed to revoke token: {message}', ['message' => $e->getMessage()]);
            return false;
        }
    }

    public function getUser(string $userInfoEndpoint): ?array
    {
        $token = $this->getStoredToken();
        
        if (!$token || !isset($token['access_token'])) {
            $this->log(Logger::WARNING, 'Cannot fetch user info - no valid token available');
            return null;
        }

        try {
            $response = $this->httpRequest($userInfoEndpoint, [], 'GET', [
                'Authorization: Bearer ' . $token['access_token']
            ]);

            $userInfo = $this->parseJsonResponse($response);
            $this->log(Logger::DEBUG, 'User info retrieved successfully');
            
            return $userInfo;
        } catch (\Exception $e) {
            $this->log(Logger::ERROR, 'Failed to fetch user info: {message}', ['message' => $e->getMessage()]);
            return null;
        }
    }

    private function httpRequest(string $url, array $data, string $method = 'POST', ?array $headers = null): string
    {
        try {
            $client = $this->httpClient ?? new CurlHandler();
            
            // Set headers if provided
            if ($headers) {
                $client->setHeaders($headers);
            }

            $response = match(strtoupper($method)) {
                'GET' => $client->get($url),
                'POST' => $client->post($url, $data, false),
                'PUT' => $client->put($url, $data, false),
                default => $client->post($url, $data, false)
            };

            if (isset($response['error']) && $response['error']) {
                $this->log(Logger::ERROR, 'HTTP request error: {error}', ['error' => $response['error']]);
                throw new RuntimeException('HTTP request failed: ' . $response['error']);
            }

            if ($response['http_code'] >= 400) {
                $this->log(Logger::ERROR, 'HTTP error {code}: {body}', 
                    ['code' => $response['http_code'], 'body' => $response['data']]);
                throw new RuntimeException("HTTP {$response['http_code']}: {$response['data']}");
            }

            return $response['data'];
        } catch (\Exception $e) {
            $this->log(Logger::ERROR, 'HTTP request failed: {message}', ['message' => $e->getMessage()]);
            throw $e;
        }
    }

    private function storeTokenInDatabase(array $tokenData): void
    {
        if (!$this->queryBuilder) {
            return;
        }

        try {
            $existingToken = $this->queryBuilder
                ->select(['id'])
                ->from(self::TOKEN_TABLE)
                ->where('client_id', '=', $this->clientId)
                ->first();

            $tokenRecord = $tokenData;
            $tokenRecord['client_id'] = $this->clientId;
            $tokenRecord['created_at'] = date('Y-m-d H:i:s');
            $tokenRecord['expires_at'] = date('Y-m-d H:i:s', time() + ($tokenData['expires_in'] ?? self::DEFAULT_TOKEN_EXPIRY));

            if ($existingToken) {
                $this->queryBuilder
                    ->update($tokenRecord)
                    ->where('client_id', '=', $this->clientId)
                    ->execute();
                $this->log(Logger::DEBUG, 'OAuth token updated in database');
            } else {
                $this->queryBuilder
                    ->insert($tokenRecord)
                    ->into(self::TOKEN_TABLE)
                    ->execute();
                $this->log(Logger::DEBUG, 'OAuth token stored in database');
            }
        } catch (\Exception $e) {
            $this->log(Logger::WARNING, 'Failed to store token in database: {message}', ['message' => $e->getMessage()]);
        }
    }

    public function refreshToken(): ?array
    {
        $storedToken = $this->getStoredToken();
        
        if (!$storedToken || !isset($storedToken['refresh_token'])) {
            $this->log(Logger::WARNING, 'No refresh token available');
            return null;
        }

        $data = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $storedToken['refresh_token'],
            'grant_type' => 'refresh_token'
        ];

        try {
            $response = $this->httpRequest($this->tokenEndpoint, $data);
            $tokenData = $this->parseJsonResponse($response);
            $this->validateTokenResponse($tokenData);
            
            $this->session->set(self::OAUTH_TOKEN_KEY, $tokenData);
            $this->log(Logger::INFO, 'OAuth token refreshed successfully');

            if ($this->queryBuilder) {
                $this->storeTokenInDatabase($tokenData);
            }

            return $tokenData;
        } catch (\Exception $e) {
            $this->log(Logger::ERROR, 'Failed to refresh token: {message}', ['message' => $e->getMessage()]);
            throw $e;
        }
    }

    private function isTokenExpired(array $token): bool
    {
        if (!isset($token['expires_in']) && !isset($token['expires_at'])) {
            return false; // No expiry info, assume not expired
        }

        $expiryTime = null;
        
        if (isset($token['expires_at'])) {
            $expiryTime = strtotime($token['expires_at']);
        } elseif (isset($token['issued_at'], $token['expires_in'])) {
            $expiryTime = $token['issued_at'] + $token['expires_in'];
        } elseif (isset($token['expires_in'])) {
            // Assume it was issued recently (within last hour)
            return false;
        }

        return $expiryTime !== false && time() > $expiryTime;
    }

    private function parseJsonResponse(string $response): array
    {
        $decoded = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log(Logger::ERROR, 'JSON decode error: {error}', ['error' => json_last_error_msg()]);
            throw new RuntimeException('Invalid JSON response from OAuth provider: ' . json_last_error_msg());
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('OAuth provider response is not a valid JSON object');
        }

        return $decoded;
    }

    private function validateTokenResponse(array $tokenData): void
    {
        if (!isset($tokenData['access_token'])) {
            throw new RuntimeException('Access token missing from OAuth provider response');
        }

        // Validate that response includes all requested scopes (or at least some)
        if (!empty($this->scopes) && isset($tokenData['scope'])) {
            $grantedScopes = explode(' ', $tokenData['scope']);
            $requestedScopes = $this->scopes;
            
            $missingScopes = array_diff($requestedScopes, $grantedScopes);
            if (!empty($missingScopes)) {
                $this->log(Logger::WARNING, 'Not all requested scopes were granted. Missing: {scopes}', 
                    ['scopes' => implode(', ', $missingScopes)]);
            }
        }
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }
}