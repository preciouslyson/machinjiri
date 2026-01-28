<?php

namespace Mlangeni\Machinjiri\Core\Kernel\Security;

/**
 * EncryptionInterface defines the contract for encryption/decryption
 * 
 * All encryption implementations must follow this contract to ensure
 * consistent encryption and decryption across the application.
 */
interface EncryptionInterface
{
    /**
     * Encrypt a string
     * 
     * @param string $data Data to encrypt
     * @return string Encrypted data
     */
    public function encrypt(string $data): string;

    /**
     * Decrypt a string
     * 
     * @param string $data Data to decrypt
     * @return string Decrypted data
     */
    public function decrypt(string $data): string;

    /**
     * Hash a value
     * 
     * @param string $value Value to hash
     * @param array $options Hash options
     * @return string Hashed value
     */
    public function hash(string $value, array $options = []): string;

    /**
     * Check if a value matches a hash
     * 
     * @param string $value Value to check
     * @param string $hash Hash to verify against
     * @return bool True if value matches hash
     */
    public function verify(string $value, string $hash): bool;

    /**
     * Get hash info
     * 
     * @param string $hash Hash to analyze
     * @return array Hash information
     */
    public function info(string $hash): array;

    /**
     * Check if hash needs rehashing
     * 
     * @param string $hash Hash to check
     * @param array $options Hash options
     * @return bool True if hash should be regenerated
     */
    public function needsRehash(string $hash, array $options = []): bool;

    /**
     * Generate random bytes
     * 
     * @param int $length Number of bytes to generate
     * @return string Random bytes
     */
    public function randomBytes(int $length): string;

    /**
     * Generate random string
     * 
     * @param int $length Length of string
     * @return string Random string
     */
    public function randomString(int $length): string;

    /**
     * Generate a token
     * 
     * @param int $length Token length
     * @return string Generated token
     */
    public function token(int $length = 32): string;

    /**
     * Set encryption algorithm
     * 
     * @param string $algorithm Algorithm name
     * @return self Fluent interface
     */
    public function setAlgorithm(string $algorithm): self;

    /**
     * Get encryption algorithm
     * 
     * @return string Algorithm name
     */
    public function getAlgorithm(): string;

    /**
     * Set encryption key
     * 
     * @param string $key Encryption key
     * @return self Fluent interface
     */
    public function setKey(string $key): self;

    /**
     * Get encryption key
     * 
     * @return string Encryption key
     */
    public function getKey(): string;
}
