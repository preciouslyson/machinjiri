<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Components\UUID\ULID;

use Mlangeni\Machinjiri\Core\Date\DateTimeHandler;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

/**
 * ULID Component – handles 80‑bit randomness as two 40‑bit parts.
 */
final class UlidComponent
{
    private const ENCODING_CHARS = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    private const ULID_LENGTH = 26;
    private const TIMESTAMP_CHARS = 10;
    private const RANDOMNESS_CHARS = 16;

    /** Maximum value for a 40‑bit integer. */
    private const MAX_40BIT = (1 << 40) - 1; // 0xFFFFFFFFFF

    /** Last timestamp (ms) used for monotonic generation. */
    private static ?int $lastTimestamp = null;

    /** Last randomness high part (first 40 bits). */
    private static ?int $lastRandomHigh = null;

    /** Last randomness low part (last 40 bits). */
    private static ?int $lastRandomLow = null;
  
    /**
     * Generate a new ULID.
     *
     * @param DateTimeHandler|null $timestamp Fixed timestamp (optional)
     * @return string
     * @throws MachinjiriException
     */
    public static function generate(?DateTimeHandler $timestamp = null):           string
    {
        $timestampMs = self::getCurrentTimestampMs($timestamp);
        [$randHigh, $randLow] = self::generateRandomnessSplit();
        return self::encode($timestampMs, $randHigh, $randLow);
    }

    /**
     * Generate a monotonic ULID.
     *
     * @param DateTimeHandler|null $timestamp Fixed timestamp (optional)
     * @return string
     * @throws MachinjiriException
     */
    public static function generateMonotonic(?DateTimeHandler $timestamp = null): string
    {
        $timestampMs = self::getCurrentTimestampMs($timestamp);

        // Reset state if timestamp moved forward
        if ($timestampMs > self::$lastTimestamp) {
            self::$lastTimestamp = $timestampMs;
            [self::$lastRandomHigh, self::$lastRandomLow] = self::generateRandomnessSplit();
        } elseif ($timestampMs === self::$lastTimestamp) {
            // Increment the 80‑bit number (low part first)
            if (self::$lastRandomLow < self::MAX_40BIT) {
                self::$lastRandomLow++;
            } else {
                // Low part overflow → increment high part, reset low
                if (self::$lastRandomHigh < self::MAX_40BIT) {
                    self::$lastRandomHigh++;
                    self::$lastRandomLow = 0;
                } else {
                    // Full 80‑bit overflow → next millisecond
                    $timestampMs++;
                    self::$lastTimestamp = $timestampMs;
                    [self::$lastRandomHigh, self::$lastRandomLow] = self::generateRandomnessSplit();
                }
            }
        } else {
            // Timestamp went backwards – reset completely
            self::$lastTimestamp = $timestampMs;
            [self::$lastRandomHigh, self::$lastRandomLow] = self::generateRandomnessSplit();
        }

        return self::encode(self::$lastTimestamp, self::$lastRandomHigh, self::$lastRandomLow);
    }

    /**
     * Validate a ULID string.
     *
     * @param string $ulid
     * @return bool
     */
    public static function validate(string $ulid): bool
    {
        if (strlen($ulid) !== self::ULID_LENGTH) {
            return false;
        }
        if (!preg_match('/^[' . preg_quote(self::ENCODING_CHARS, '/') . ']{' . self::ULID_LENGTH . '}$/', $ulid)) {
            return false;
        }
        try {
            self::decodeTimestamp($ulid);
            self::decodeRandomnessSplit($ulid);
        } catch (MachinjiriException) {
            return false;
        }
        return true;
    }

    /**
     * Parse a ULID into components.
     *
     * @param string $ulid
     * @return array{timestamp: int, randomnessHigh: int, randomnessLow: int, timestampDateTime: DateTimeHandler}
     * @throws MachinjiriException
     */
    public static function parse(string $ulid): array
    {
        if (!self::validate($ulid)) {
            throw new MachinjiriException('Invalid ULID format: ' . $ulid, 400, null, [], 'validation');
        }
        $timestampMs = self::decodeTimestamp($ulid);
        [$randHigh, $randLow] = self::decodeRandomnessSplit($ulid);
        return [
            'timestamp'        => $timestampMs,
            'randomnessHigh'   => $randHigh,
            'randomnessLow'    => $randLow,
            'timestampDateTime' => self::millisecondsToDateTimeHandler($timestampMs),
        ];
    }

    /**
     * Extract timestamp in milliseconds.
     *
     * @param string $ulid
     * @return int
     * @throws MachinjiriException
     */
    public static function extractTimestampMs(string $ulid): int
    {
        if (!self::validate($ulid)) {
            throw new MachinjiriException('Invalid ULID for timestamp extraction: ' . $ulid, 400, null, [], 'validation');
        }
        return self::decodeTimestamp($ulid);
    }

    /**
     * Extract timestamp as DateTimeHandler.
     *
     * @param string $ulid
     * @return DateTimeHandler
     * @throws MachinjiriException
     */
    public static function extractTimestampDateTime(string $ulid): DateTimeHandler
    {
        return self::parse($ulid)['timestampDateTime'];
    }

    // -------------------------------------------------------------------------
    // Encoding / Decoding
    // -------------------------------------------------------------------------

    /**
     * Encode timestamp (48 bits) and split randomness (80 bits) into a ULID string.
     *
     * @param int $timestampMs
     * @param int $randHigh  High 40 bits
     * @param int $randLow   Low 40 bits
     * @return string
     * @throws MachinjiriException
     */
    private static function encode(int $timestampMs, int $randHigh, int $randLow): string
    {
        if ($timestampMs < 0 || $timestampMs > 0xFFFFFFFFFFFF) {
            throw new MachinjiriException('Timestamp must be a 48-bit unsigned integer.', 500, null, [], 'general');
        }
        if ($randHigh < 0 || $randHigh > self::MAX_40BIT || $randLow < 0 || $randLow > self::MAX_40BIT) {
            throw new MachinjiriException('Randomness parts must be 40-bit unsigned integers.', 500, null, [], 'general');
        }

        $ulid = '';

        // Timestamp part (10 chars)
        $timePart = $timestampMs;
        for ($i = self::TIMESTAMP_CHARS - 1; $i >= 0; $i--) {
            $remainder = $timePart % 32;
            $ulid = self::ENCODING_CHARS[$remainder] . $ulid;
            $timePart = (int) ($timePart / 32);
        }

        // Combine high and low 40-bit values into a single 80-bit number for encoding.
        // Instead of using a native int (which would overflow), we encode sequentially:
        // First 8 chars from high (40 bits = 8 Base32 chars, because 40/5 = 8),
        // then remaining 8 chars from low (also 8 chars).
        // This matches the ULID spec where the 16 randomness chars are a direct Base32
        // encoding of the 80 bits (10 bytes), so the first 8 chars correspond to the
        // high 40 bits, and the last 8 chars to the low 40 bits.

        $randHighParts = self::intToBase32($randHigh, 8);   // 40 bits → 8 Base32 chars
        $randLowParts  = self::intToBase32($randLow, 8);    // 40 bits → 8 Base32 chars

        return $ulid . $randHighParts . $randLowParts;
    }

    /**
     * Convert a 40‑bit integer into a fixed‑length Base32 string.
     *
     * @param int $value  0 … 2^40-1
     * @param int $chars  Expected length (always 8 for 40 bits)
     * @return string
     */
    private static function intToBase32(int $value, int $chars): string
    {
        $result = '';
        for ($i = $chars - 1; $i >= 0; $i--) {
            $remainder = $value % 32;
            $result = self::ENCODING_CHARS[$remainder] . $result;
            $value = (int) ($value / 32);
        }
        return $result;
    }

    /**
     * Decode timestamp (first 10 chars).
     *
     * @param string $ulid
     * @return int
     * @throws MachinjiriException
     */
    private static function decodeTimestamp(string $ulid): int
    {
        $timestampStr = substr($ulid, 0, self::TIMESTAMP_CHARS);
        $value = 0;
        for ($i = 0; $i < self::TIMESTAMP_CHARS; $i++) {
            $char = $timestampStr[$i];
            $digit = strpos(self::ENCODING_CHARS, $char);
            if ($digit === false) {
                throw new MachinjiriException("Invalid character '{$char}' in timestamp part.", 400, null, [], 'validation');
            }
            $value = ($value << 5) | $digit;
        }
        return $value;
    }

    /**
     * Decode the 16 randomness chars into two 40‑bit integers (high, low).
     *
     * @param string $ulid
     * @return array{0: int, 1: int}
     * @throws MachinjiriException
     */
    private static function decodeRandomnessSplit(string $ulid): array
    {
        $randomnessStr = substr($ulid, self::TIMESTAMP_CHARS);
        if (strlen($randomnessStr) !== self::RANDOMNESS_CHARS) {
            throw new MachinjiriException('Invalid randomness length.', 400, null, [], 'validation');
        }

        $highStr = substr($randomnessStr, 0, 8); // first 8 chars → high 40 bits
        $lowStr  = substr($randomnessStr, 8, 8); // last 8 chars → low 40 bits

        return [
            self::base32ToInt($highStr),
            self::base32ToInt($lowStr),
        ];
    }

    /**
     * Convert a Base32 string (max 8 chars) back to a 40‑bit integer.
     *
     * @param string $str
     * @return int
     * @throws MachinjiriException
     */
    private static function base32ToInt(string $str): int
    {
        $value = 0;
        for ($i = 0; $i < strlen($str); $i++) {
            $char = $str[$i];
            $digit = strpos(self::ENCODING_CHARS, $char);
            if ($digit === false) {
                throw new MachinjiriException("Invalid character '{$char}' in randomness part.", 400, null, [], 'validation');
            }
            $value = ($value << 5) | $digit;
        }
        return $value;
    }

    // -------------------------------------------------------------------------
    // Randomness Generation
    // -------------------------------------------------------------------------

    /**
     * Generate 80 bits of secure randomness as two 40‑bit integers.
     *
     * @return array{0: int, 1: int}
     * @throws MachinjiriException
     */
    private static function generateRandomnessSplit(): array
    {
        try {
            $bytes = random_bytes(10); // 80 bits = 10 bytes
        } catch (\Exception $e) {
            throw new MachinjiriException('Failed to generate random bytes: ' . $e->getMessage(), 500, $e, [], 'general');
        }

        // First 5 bytes → high 40 bits
        $high = 0;
        for ($i = 0; $i < 5; $i++) {
            $high = ($high << 8) | ord($bytes[$i]);
        }

        // Next 5 bytes → low 40 bits
        $low = 0;
        for ($i = 5; $i < 10; $i++) {
            $low = ($low << 8) | ord($bytes[$i]);
        }

        return [$high, $low];
    }

    // -------------------------------------------------------------------------
    // Timestamp Helpers
    // -------------------------------------------------------------------------

    /**
     * Get current timestamp in milliseconds from a DateTimeHandler or system time.
     *
     * @param DateTimeHandler|null $timestamp
     * @return int
     * @throws MachinjiriException
     */
    private static function getCurrentTimestampMs(?DateTimeHandler $timestamp = null): int
    {
        if ($timestamp !== null) {
            $dateTime = $timestamp->getDateTime();
            $ms = (int) $dateTime->format('Uv');
            if ($ms < 0) {
                throw new MachinjiriException('Timestamp cannot be negative.', 400, null, [], 'validation');
            }
            if ($ms > 0xFFFFFFFFFFFF) {
                throw new MachinjiriException('Timestamp exceeds 48-bit ULID limit.', 400, null, [], 'validation');
            }
            return $ms;
        }

        return (int) (microtime(true) * 1000);
    }

    /**
     * Convert milliseconds since epoch to a DateTimeHandler.
     *
     * @param int $milliseconds
     * @return DateTimeHandler
     */
    private static function millisecondsToDateTimeHandler(int $milliseconds): DateTimeHandler
    {
        $seconds = (int) ($milliseconds / 1000);
        $milli = $milliseconds % 1000;
        $dateTime = \DateTime::createFromFormat('U.u', sprintf('%d.%06d', $seconds, $milli * 1000));
        if ($dateTime === false) {
            $dateTime = (new \DateTime())->setTimestamp($seconds);
            if ($milli > 0) {
                $dateTime->modify("+{$milli} milliseconds");
            }
        }
        return new DateTimeHandler($dateTime->format('Y-m-d H:i:s.u'), 'UTC');
    }
}