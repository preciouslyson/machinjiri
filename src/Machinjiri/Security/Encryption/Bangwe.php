<?php

namespace Mlangeni\Machinjiri\Core\Security\Encryption;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use DomainException;
use UnexpectedValueException;

/**
 * Bangwe - Security Encryption & JWT Manager
 * 
 * Provides symmetric encryption/decryption and JWT token handling
 * using configuration from the Container (env/config files)
 */
class Bangwe
{
    /**
     * Default encryption algorithm
     */
    private const DEFAULT_CIPHER = 'aes-256-gcm';
    
    /**
     * Default JWT algorithm
     */
    private const DEFAULT_JWT_ALGO = 'HS256';
    
    /**
     * Default token expiration in seconds (1 hour)
     */
    private const DEFAULT_JWT_EXPIRATION = 3600;
    
    /**
     * @var Container Application container instance
     */
    private Container $container;
    
    /**
     * @var string Encryption key
     */
    private string $encryptionKey;
    
    /**
     * @var string Encryption cipher method
     */
    private string $cipher;
    
    /**
     * @var string JWT secret key
     */
    private string $jwtSecret;
    
    /**
     * @var string JWT algorithm
     */
    private string $jwtAlgo;
    
    /**
     * @var int JWT expiration time in seconds
     */
    private int $jwtExpiration;
    
    /**
     * @var string JWT issuer
     */
    private string $jwtIssuer;
    
    /**
     * @var string JWT audience
     */
    private string $jwtAudience;
    
    /**
     * Constructor
     *
     * @param Container|null $container Container instance (will use singleton if null)
     * @throws MachinjiriException If required configuration is missing
     */
    public function __construct(?Container $container = null)
    {
        $this->container = $container ?? Container::getInstance();
        $this->loadConfiguration();
        $this->validateConfiguration();
    }
    
    /**
     * Load configuration from Container
     */
    private function loadConfiguration(): void
    {
        $config = $this->getConfiguration();
        
        // Encryption configuration
        $this->encryptionKey = $config['encryption_key'] ?? '';
        $this->cipher = $config['encryption_cipher'] ?? self::DEFAULT_CIPHER;
        
        // JWT configuration
        $this->jwtSecret = $config['jwt_secret'] ?? '';
        $this->jwtAlgo = $config['jwt_algo'] ?? self::DEFAULT_JWT_ALGO;
        $this->jwtExpiration = (int)($config['jwt_expiration'] ?? self::DEFAULT_JWT_EXPIRATION);
        $this->jwtIssuer = $config['jwt_issuer'] ?? $this->getDefaultIssuer();
        $this->jwtAudience = $config['jwt_audience'] ?? $this->getDefaultAudience();
    }
    
    /**
     * Get configuration from Container (env/config)
     *
     * @return array
     */
    private function getConfiguration(): array
    {
        $config = [];
        
        // First try to get from app config
        try {
            $appConfig = $this->container->getConfigurations()['app'] ?? [];
            $config = array_merge($config, $appConfig);
        } catch (\Exception $e) {
            // If config fails, rely on env
        }
        
        // Get environment variables
        $envVars = $_ENV;
        
        // Map environment variables to configuration keys
        $envMapping = [
            'APP_KEY' => 'encryption_key',
            'APP_CIPHER' => 'encryption_cipher',
            'JWT_SECRET' => 'jwt_secret',
            'JWT_ALGO' => 'jwt_algo',
            'JWT_EXPIRATION' => 'jwt_expiration',
            'JWT_ISSUER' => 'jwt_issuer',
            'JWT_AUDIENCE' => 'jwt_audience',
        ];
        
        foreach ($envMapping as $envKey => $configKey) {
            if (isset($envVars[$envKey])) {
                $config[$configKey] = $envVars[$envKey];
            }
        }
        
        return $config;
    }
    
    /**
     * Validate required configuration
     *
     * @throws MachinjiriException
     */
    private function validateConfiguration(): void
    {
        // Check encryption key
        if (empty($this->encryptionKey)) {
            throw new MachinjiriException(
                'Encryption key is not configured. Set APP_KEY in .env or app.encryption_key in config.',
                20101
            );
        }
        
        // Validate encryption key length for AES-256
        if ($this->cipher === 'aes-256-gcm' && strlen($this->encryptionKey) < 32) {
            throw new MachinjiriException(
                'Encryption key must be at least 32 characters for AES-256-GCM.',
                20102
            );
        }
        
        // Check JWT secret
        if (empty($this->jwtSecret)) {
            throw new MachinjiriException(
                'JWT secret is not configured. Set JWT_SECRET in .env or app.jwt_secret in config.',
                20103
            );
        }
        
        // Validate cipher method
        if (!in_array($this->cipher, openssl_get_cipher_methods())) {
            throw new MachinjiriException(
                "Unsupported encryption cipher: {$this->cipher}",
                20104
            );
        }
    }
    
    /**
     * Get default issuer from app name or URL
     *
     * @return string
     */
    private function getDefaultIssuer(): string
    {
        try {
            $appConfig = $this->container->getConfigurations()['app'] ?? [];
            if (!empty($appConfig['app_name'])) {
                return $appConfig['app_name'];
            }
            if (!empty($appConfig['app_url'])) {
                return parse_url($appConfig['app_url'], PHP_URL_HOST) ?? 'machinjiri';
            }
        } catch (\Exception $e) {
            // Fall through to default
        }
        
        return 'machinjiri';
    }
    
    /**
     * Get default audience
     *
     * @return string
     */
    private function getDefaultAudience(): string
    {
        return 'machinjiri_api';
    }
    
    /**
     * Encrypt data
     *
     * @param string $data Data to encrypt
     * @return array Encrypted data with IV and tag
     * @throws MachinjiriException If encryption fails
     */
    public function encrypt(string $data): array
    {
        $key = $this->getEncryptionKey();
        $ivLength = openssl_cipher_iv_length($this->cipher);
        
        if ($ivLength === false) {
            throw new MachinjiriException(
                "Failed to get IV length for cipher: {$this->cipher}",
                20105
            );
        }
        
        $iv = openssl_random_pseudo_bytes($ivLength);
        $tag = '';
        
        $encrypted = openssl_encrypt(
            $data,
            $this->cipher,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16 // Tag length for GCM
        );
        
        if ($encrypted === false) {
            throw new MachinjiriException(
                'Encryption failed: ' . openssl_error_string(),
                20106
            );
        }
        
        return [
            'data' => base64_encode($encrypted),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'cipher' => $this->cipher,
        ];
    }
    
    /**
     * Encrypt and return as JSON string
     *
     * @param string $data Data to encrypt
     * @return string JSON encoded encrypted data
     */
    public function encryptToJson(string $data): string
    {
        $encrypted = $this->encrypt($data);
        return json_encode($encrypted, JSON_UNESCAPED_SLASHES);
    }
    
    /**
     * Decrypt data
     *
     * @param string $encryptedData Base64 encoded encrypted data
     * @param string $iv Base64 encoded IV
     * @param string $tag Base64 encoded authentication tag
     * @return string Decrypted data
     * @throws MachinjiriException If decryption fails
     */
    public function decrypt(string $encryptedData, string $iv, string $tag): string
    {
        $key = $this->getEncryptionKey();
        
        $decrypted = openssl_decrypt(
            base64_decode($encryptedData),
            $this->cipher,
            $key,
            OPENSSL_RAW_DATA,
            base64_decode($iv),
            base64_decode($tag)
        );
        
        if ($decrypted === false) {
            throw new MachinjiriException(
                'Decryption failed: ' . openssl_error_string(),
                20107
            );
        }
        
        return $decrypted;
    }
    
    /**
     * Decrypt from JSON string
     *
     * @param string $jsonString JSON string containing encrypted data
     * @return string Decrypted data
     */
    public function decryptFromJson(string $jsonString): string
    {
        $data = json_decode($jsonString, true);
        
        if (!isset($data['data'], $data['iv'], $data['tag'])) {
            throw new MachinjiriException(
                'Invalid encrypted data format',
                20108
            );
        }
        
        return $this->decrypt($data['data'], $data['iv'], $data['tag']);
    }
    
    /**
     * Create JWT token
     *
     * @param array $payload Token payload data
     * @param array $headers Additional headers
     * @return string JWT token
     */
    public function createToken(array $payload, array $headers = []): string
    {
        $now = time();
        
        $defaultPayload = [
            'iss' => $this->jwtIssuer,
            'aud' => $this->jwtAudience,
            'iat' => $now,
            'exp' => $now + $this->jwtExpiration,
            'jti' => bin2hex(random_bytes(16)),
        ];
        
        $fullPayload = array_merge($defaultPayload, $payload);
        
        return JWT::encode($fullPayload, $this->jwtSecret, $this->jwtAlgo, null, $headers);
    }
    
    /**
     * Decode JWT token
     *
     * @param string $token JWT token
     * @return object Decoded token payload
     * @throws MachinjiriException If token is invalid
     */
    public function decodeToken(string $token): object
    {
        try {
            return JWT::decode($token, new Key($this->jwtSecret, $this->jwtAlgo));
        } catch (ExpiredException $e) {
            throw new MachinjiriException(
                'Token has expired',
                20109,
                $e
            );
        } catch (SignatureInvalidException $e) {
            throw new MachinjiriException(
                'Invalid token signature',
                20110,
                $e
            );
        } catch (DomainException|UnexpectedValueException $e) {
            throw new MachinjiriException(
                'Invalid token: ' . $e->getMessage(),
                20111,
                $e
            );
        }
    }
    
    /**
     * Validate JWT token
     *
     * @param string $token JWT token
     * @return bool True if valid
     */
    public function validateToken(string $token): bool
    {
        try {
            $this->decodeToken($token);
            return true;
        } catch (MachinjiriException $e) {
            $e->show();
            return false;
        }
    }
    
    /**
     * Refresh JWT token
     *
     * @param string $token Old token
     * @param int|null $newExpiration New expiration in seconds (null for default)
     * @return string New token
     */
    public function refreshToken(string $token, ?int $newExpiration = null): string
    {
        $decoded = $this->decodeToken($token);
        $payload = (array)$decoded;
        
        // Remove timestamps
        unset($payload['iat'], $payload['exp'], $payload['nbf']);
        
        // Set new expiration if provided
        $expiration = $newExpiration ?? $this->jwtExpiration;
        
        return $this->createToken($payload, ['exp' => time() + $expiration]);
    }
    
    /**
     * Get token payload without validation (for inspection)
     *
     * @param string $token JWT token
     * @return array Token payload
     */
    public function inspectToken(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new MachinjiriException(
                'Invalid token structure',
                20112
            );
        }
        
        $payload = base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1]));
        return json_decode($payload, true);
    }
    
    /**
     * Generate encryption key
     *
     * @param int $length Key length in bytes
     * @return string Base64 encoded key
     */
    public static function generateKey(int $length = 32): string
    {
        return base64_encode(random_bytes($length));
    }
    
    /**
     * Hash password using bcrypt
     *
     * @param string $password Plain text password
     * @param array $options Bcrypt options
     * @return string Hashed password
     */
    public function hashPassword(string $password, array $options = []): string
    {
        $cost = $options['cost'] ?? 12;
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]);
    }
    
    /**
     * Verify password against hash
     *
     * @param string $password Plain text password
     * @param string $hash Hashed password
     * @return bool True if password matches
     */
    public function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
    
    /**
     * Get encryption key (ensures proper length)
     *
     * @return string
     */
    private function getEncryptionKey(): string
    {
        $key = $this->encryptionKey;
        
        // Ensure key is proper length for the cipher
        if ($this->cipher === 'aes-256-gcm' && strlen($key) < 32) {
            $key = str_pad($key, 32, '0', STR_PAD_RIGHT);
        }
        
        return $key;
    }
    
    /**
     * Get configuration summary (without sensitive data)
     *
     * @return array
     */
    public function getConfigSummary(): array
    {
        return [
            'cipher' => $this->cipher,
            'jwt_algo' => $this->jwtAlgo,
            'jwt_expiration' => $this->jwtExpiration,
            'jwt_issuer' => $this->jwtIssuer,
            'jwt_audience' => $this->jwtAudience,
            'key_length' => strlen($this->encryptionKey),
            'jwt_secret_length' => strlen($this->jwtSecret),
        ];
    }
    
    /**
     * Create a secure random string
     *
     * @param int $length Desired length
     * @return string
     */
    public static function randomString(int $length = 32): string
    {
        $bytes = random_bytes($length);
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}