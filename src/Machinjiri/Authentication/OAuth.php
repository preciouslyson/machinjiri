<?php

namespace Mlangeni\Machinjiri\Core\Authentication;

use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;
use Mlangeni\Machinjiri\Core\Authentication\Session;
use Mlangeni\Machinjiri\Core\Authentication\Cookie;
use Mlangeni\Machinjiri\Core\Database\QueryBuilder;
use \InvalidArgumentException;
use \RuntimeException;

class OAuth
{
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private string $authorizationEndpoint;
    private string $tokenEndpoint;
    private array $scopes;
    private ?string $state = null;
    private array $httpOptions = [];
    private Session $session;
    private Cookie $cookie;
    private ?QueryBuilder $queryBuilder = null;

    public function __construct(
        string $clientId,
        string $clientSecret,
        string $redirectUri,
        string $authorizationEndpoint,
        string $tokenEndpoint,
        array $scopes = [],
        Session $session = null,
        Cookie $cookie = null
    ) {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->redirectUri = $redirectUri;
        $this->authorizationEndpoint = $authorizationEndpoint;
        $this->tokenEndpoint = $tokenEndpoint;
        $this->scopes = $scopes;
        $this->session = $session ?? new Session();
        $this->cookie = $cookie ?? new Cookie();
    }

    public function setQueryBuilder(QueryBuilder $queryBuilder): void
    {
        $this->queryBuilder = $queryBuilder;
    }

    public function setState(string $state): void
    {
        $this->state = $state;
    }

    public function setHttpOptions(array $options): void
    {
        $this->httpOptions = $options;
    }

    public function getAuthorizationUrl(): string
    {
        $state = $this->state ?? bin2hex(random_bytes(16));
        $this->session->set('oauth_state', $state);

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
        $storedState = $this->session->get('oauth_state');
        
        if ($storedState && $state !== $storedState) {
            throw new InvalidArgumentException('Invalid state parameter');
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
            throw new RuntimeException('Failed to get access token');
        }

        $tokenData = json_decode($response, true);
        
        // Store token in session
        $this->session->set('oauth_token', $tokenData);
        
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

        if (!$code) {
            throw new RuntimeException('Authorization code not found');
        }

        return $this->getAccessToken($code, $state);
    }

    public function isAuthenticated(): bool
    {
        return $this->session->has('oauth_token');
    }

    public function getStoredToken(): ?array
    {
        return $this->session->get('oauth_token');
    }

    public function logout(): void
    {
        $this->session->destroy();
        $this->cookie->delete('oauth_token');
    }

    private function httpRequest(string $url, array $data): string|false
    {
        $options = array_replace_recursive([
            'http' => [
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'method' => 'POST',
                'content' => http_build_query($data),
                'ignore_errors' => true
            ]
        ], $this->httpOptions);

        $context = stream_context_create($options);
        return file_get_contents($url, false, $context);
    }

    private function storeTokenInDatabase(array $tokenData): void
    {
        if (!$this->queryBuilder) {
            return;
        }

        $existingToken = $this->queryBuilder
            ->select(['id'])
            ->from('oauth_tokens')
            ->where('client_id', '=', $this->clientId)
            ->first();

        $tokenData['client_id'] = $this->clientId;
        $tokenData['created_at'] = date('Y-m-d H:i:s');
        $tokenData['expires_at'] = date('Y-m-d H:i:s', time() + ($tokenData['expires_in'] ?? 3600));

        if ($existingToken) {
            $this->queryBuilder
                ->update($tokenData)
                ->where('client_id', '=', $this->clientId)
                ->execute();
        } else {
            $this->queryBuilder
                ->insert($tokenData)
                ->into('oauth_tokens')
                ->execute();
        }
    }

    public function refreshToken(): ?array
    {
        $storedToken = $this->getStoredToken();
        
        if (!$storedToken || !isset($storedToken['refresh_token'])) {
            return null;
        }

        $data = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $storedToken['refresh_token'],
            'grant_type' => 'refresh_token'
        ];

        $response = $this->httpRequest($this->tokenEndpoint, $data);

        if (!$response) {
            throw new RuntimeException('Failed to refresh token');
        }

        $tokenData = json_decode($response, true);
        $this->session->set('oauth_token', $tokenData);

        if ($this->queryBuilder) {
            $this->storeTokenInDatabase($tokenData);
        }

        return $tokenData;
    }
}