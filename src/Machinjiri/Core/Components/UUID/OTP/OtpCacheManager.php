<?php

namespace Mlangeni\Machinjiri\Core\Components\UUID\OTP;

use Mlangeni\Machinjiri\Core\Artisans\Caching\CacheManager;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

/**
 * OTP Manager with Cache Storage
 *
 * Uses the application's CacheManager to store OTPs with expiration.
 */
final class OtpCacheManager
{
    private CacheManager $cache;
    private string $cachePrefix;

    /**
     * @param CacheManager|null $cache If null, will attempt to resolve from container
     * @param string $cachePrefix Prefix for all OTP cache keys
     */
    public function __construct(?CacheManager $cache = null, string $cachePrefix = 'otp_')
    {
        if ($cache === null) {
            // Assume a global resolve() function exists (e.g., from the container)
            if (function_exists('resolve')) {
                $cache = resolve(CacheManager::class);
            } else {
                throw new \RuntimeException('Unable to resolve CacheManager. Please pass an instance.');
            }
        }
        $this->cache = $cache;
        $this->cachePrefix = $cachePrefix;
    }

    /**
     * Generate and store an OTP for a given identifier (email, phone, user ID, etc.)
     *
     * @param string $identifier Unique identifier for the recipient
     * @param int $length OTP length
     * @param string $type 'numeric', 'alphanumeric', 'alphabetic', 'characterBased'
     * @param int $ttlSeconds Time-to-live in seconds (default 300)
     * @param string|null $customChars Custom character set (only used if type = 'characterBased')
     * @return string The generated OTP (plain text, not stored in response)
     * @throws MachinjiriException
     */
    public function generateAndStore(
        string $identifier,
        int $length,
        string $type = 'numeric',
        int $ttlSeconds = 300,
        ?string $customChars = null
    ): string {
        // Generate OTP using the static generator
        $otp = match ($type) {
            'numeric' => OtpGenerator::numeric($length),
            'alphanumeric' => OtpGenerator::alphanumeric($length),
            'alphabetic' => OtpGenerator::alphabetic($length),
            'characterBased' => OtpGenerator::characterBased($length, $customChars),
            default => throw new MachinjiriException("Invalid OTP type: {$type}"),
        };

        // Store in cache with TTL
        $cacheKey = $this->getCacheKey($identifier);
        $this->cache->set($cacheKey, $otp, $ttlSeconds);

        return $otp;
    }

    /**
     * Verify an OTP for a given identifier (case-sensitive)
     *
     * @param string $identifier Unique identifier
     * @param string $otp The OTP to verify
     * @return bool True if OTP exists and matches, false otherwise
     */
    public function verify(string $identifier, string $otp): bool
    {
        $cacheKey = $this->getCacheKey($identifier);
        $storedOtp = $this->cache->get($cacheKey);

        if ($storedOtp === null) {
            return false;
        }

        // Constant-time comparison for security
        return hash_equals($storedOtp, $otp);
    }

    /**
     * Delete a stored OTP (e.g., after successful verification or reset)
     *
     * @param string $identifier
     * @return bool
     */
    public function delete(string $identifier): bool
    {
        $cacheKey = $this->getCacheKey($identifier);
        return $this->cache->delete($cacheKey);
    }

    /**
     * Get the remaining TTL for an OTP (useful for user feedback)
     *
     * @param string $identifier
     * @return int|null Seconds remaining, or null if key doesn't exist / doesn't have TTL
     */
    public function getRemainingTtl(string $identifier): ?int
    {
        $cacheKey = $this->getCacheKey($identifier);
        // CacheManager does not have a direct ttl() method by default.
        // If your CacheStore implements ttl(), you can call it.
        // Here we implement a workaround using store()->getTtl() if available.
        $store = $this->cache->store();
        if (method_exists($store, 'getTtl')) {
            return $store->getTtl($cacheKey);
        }
        // Fallback: we cannot know; return null
        return null;
    }

    /**
     * Generate a cache key for a given identifier
     *
     * @param string $identifier
     * @return string
     */
    private function getCacheKey(string $identifier): string
    {
        // Sanitise identifier: replace invalid characters for cache key safety
        $safeIdentifier = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $identifier);
        return $this->cachePrefix . $safeIdentifier;
    }
}