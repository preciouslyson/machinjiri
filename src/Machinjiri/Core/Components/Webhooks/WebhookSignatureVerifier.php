<?php

namespace Mlangeni\Machinjiri\Core\Components\Webhooks;

use Mlangeni\Machinjiri\Core\Exceptions\WebhookException;

class WebhookSignatureVerifier
{
    /**
     * Verify HMAC signature with optional timestamp tolerance
     *
     * @param string $payload
     * @param string $secret
     * @param string $header   e.g. "t=1234567890,v1=abc123,v1=def456"
     * @param string $algo
     * @param string $prefix
     * @param int $toleranceSeconds
     * @return bool
     * @throws WebhookException
     */
    public static function verifyHmacTimestamp(
        string $payload,
        string $secret,
        string $header,
        string $algo = 'sha256',
        string $prefix = '',
        int $toleranceSeconds = 300
    ): bool {
        // Parse Stripe-like format: t=timestamp,v1=signature1[,v1=signature2]
        $timestamp = null;
        $signatures = [];
        $parts = explode(',', $header);
        foreach ($parts as $part) {
            $segments = explode('=', $part, 2);
            if (count($segments) != 2) continue;
            $key = $segments[0];
            $value = $segments[1];
            if ($key === 't') {
                $timestamp = (int)$value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || empty($signatures)) {
            return false;
        }

        // Reject if timestamp is too old
        if (abs(time() - $timestamp) > $toleranceSeconds) {
            return false;
        }

        // Compute expected signature
        $signedPayload = $timestamp . '.' . $payload;
        $expected = hash_hmac($algo, $signedPayload, $secret);

        // Compare against any provided v1 signature
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Standard HMAC verification (simple header).
     */
    public static function verifyHmac(
        string $payload,
        string $secret,
        string $header,
        string $algo = 'sha256',
        string $prefix = ''
    ): bool {
        if (!in_array($algo, hash_algos())) {
            throw WebhookException::unsupportedAlgorithm($algo);
        }

        $computed = hash_hmac($algo, $payload, $secret);
        $provided = $prefix ? str_replace($prefix, '', $header) : $header;

        return hash_equals($computed, $provided);
    }

    /**
     * Custom verifier callback.
     */
    public static function verifyCustom(string $payload, array $headers, callable $verifier, string $secret): bool
    {
        return $verifier($payload, $headers, $secret);
    }
}