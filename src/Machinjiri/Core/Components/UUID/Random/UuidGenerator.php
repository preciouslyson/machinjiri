<?php

namespace Mlangeni\Machinjiri\Core\Components\UUID\Random;

use Mlangeni\Machinjiri\Core\Exceptions\InvalidVersionException;

/**
 * Factory for RFC 4122 UUID versions 1, 3, and 4.
 * (Version 2 is not implemented due to its DCE-specific complexity.)
 */
class UuidGenerator
{
    /**
     * Generate a version 1 (time-based) UUID.
     *
     * Uses current timestamp, a random clock sequence, and a pseudo‑random node.
     */
    public static function v1(): Uuid
    {
        // 60-bit timestamp: 100-ns intervals since 1582-10-15 00:00:00 UTC
        $time = self::getTimeStamp();

        // Clock sequence (14 bits) – random for simplicity
        $clockSeq = random_int(0, 0x3FFF);

        // Node ID (48 bits) – use random bytes, OR with multicast bit (0x01)
        $node = random_bytes(6);
        $node[0] = $node[0] || 0x01; // set multicast bit

        return self::buildV1($time, $clockSeq, $node);
    }

    /**
     * Generate a version 3 (name-based, MD5) UUID.
     *
     * @param Uuid|string $namespace a UUID object or its string representation
     * @param string      $name      the name to hash
     */
    public static function v3($namespace, string $name): Uuid
    {
        $ns = $namespace instanceof Uuid ? $namespace : Uuid::fromString($namespace);
        $hash = md5($ns->getBytes() . $name, true); // 16 bytes MD5

        // Set version (3) and variant (RFC 4122)
        $hash[6] = chr((ord($hash[6]) & 0x0F) | 0x30); // version 3
        $hash[8] = chr((ord($hash[8]) & 0x3F) | 0x80); // variant

        return new Uuid($hash);
    }

    /**
     * Generate a version 4 (random) UUID.
     */
    public static function v4(): Uuid
    {
        $bytes = random_bytes(16);
        // Set version (4) and variant (RFC 4122)
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80); // variant
        return new Uuid($bytes);
    }

    /**
     * Generate a UUID of the given version (1, 3, or 4).
     *
     * @throws InvalidVersionException
     */
    public static function generate(int $version, ...$args): Uuid
    {
        switch ($version) {
            case 1: return self::v1();
            case 3:
                if (count($args) < 2) {
                    throw new \InvalidArgumentException('v3 requires namespace and name');
                }
                return self::v3($args[0], $args[1]);
            case 4: return self::v4();
            default:
                throw new InvalidVersionException((string)$version);
        }
    }

    // ---------- Internal helpers for v1 ----------

    private static function getTimeStamp(): int
    {
        // Current UTC time in 100-ns intervals since Gregorian reform (1582-10-15)
        // Unix epoch: 1970-01-01 => offset = 0x01B21DD213814000 (number of 100-ns intervals)
        static $gregorianOffset = 0x01B21DD213814000;

        // Get current time in microseconds (with fractional seconds)
        $microtime = microtime(true); // float seconds with microseconds
        $seconds = (int) $microtime;
        $usec = (int) (($microtime - $seconds) * 1000000);

        // Total 100-ns intervals since Unix epoch
        $intervals = ($seconds * 10000000) + ($usec * 10) + $gregorianOffset;
        return $intervals;
    }

    private static function buildV1(int $timestamp, int $clockSeq, string $node): Uuid
    {
        // Build 16-byte binary according to RFC 4122
        $bytes = '';

        // time_low (32 bits)
        $bytes .= pack('N', ($timestamp >> 32) & 0xFFFFFFFF);

        // time_mid (16 bits)
        $bytes .= pack('n', ($timestamp >> 16) & 0xFFFF);

        // time_hi_and_version (16 bits): version 1 in top nibble
        $timeHi = ($timestamp & 0x0FFF) | 0x1000; // set version 1
        $bytes .= pack('n', $timeHi);

        // clock_seq_hi_and_reserved (8 bits) + clock_seq_low (8 bits)
        $bytes .= pack('n', $clockSeq);

        // node (48 bits)
        $bytes .= $node;

        return new Uuid($bytes);
    }

    public function __toString() {
        
    }
}