<?php

namespace Mlangeni\Machinjiri\Core\Components\UUID\Random;

use Mlangeni\Machinjiri\Core\Exceptions\InvalidUuidStringException;

/**
 * Immutable UUID value object (RFC 4122).
 */
class Uuid
{
    private string $bytes;   // 16-byte binary representation

    /**
     * @param string $bytes exactly 16 binary bytes
     */
    public function __construct(string $bytes)
    {
        if (strlen($bytes) !== 16) {
            throw new \InvalidArgumentException('UUID must be 16 bytes');
        }
        $this->bytes = $bytes;
    }

    /**
     * Get the binary representation (16 bytes).
     */
    public function getBytes(): string
    {
        return $this->bytes;
    }

    /**
     * Get the canonical string representation (8-4-4-4-12).
     */
    public function toString(): string
    {
        $hex = bin2hex($this->bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Get the UUID version (4-bit value at offset 6).
     */
    public function getVersion(): int
    {
        return (ord($this->bytes[6]) >> 4) & 0x0F;
    }

    /**
     * Get the UUID variant (MSB of byte 8).
     */
    public function getVariant(): int
    {
        $byte = ord($this->bytes[8]);
        if (($byte >> 7) === 0) return 0;      // NCS
        if (($byte >> 6) === 0b10) return 1;   // RFC 4122
        if (($byte >> 5) === 0b110) return 2;  // Microsoft
        return 3;                              // Reserved
    }

    /**
     * Create a Uuid instance from a canonical string.
     *
     * @throws InvalidUuidStringException
     */
    public static function fromString(string $uuid): self
    {
        $uuid = strtolower(trim($uuid));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) {
            throw new InvalidUuidStringException($uuid);
        }
        $hex = str_replace('-', '', $uuid);
        return new self(hex2bin($hex));
    }

    /**
     * Create from binary.
     */
    public static function fromBytes(string $bytes): self
    {
        return new self($bytes);
    }
}