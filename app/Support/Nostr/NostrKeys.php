<?php

namespace App\Support\Nostr;

use Throwable;

use function BitWasp\Bech32\convertBits;
use function BitWasp\Bech32\decode;
use function BitWasp\Bech32\encode;

/**
 * NIP-19 conversions for public keys, strict on purpose.
 *
 * swentel/nostr-php's Key::convertToHex() ignores the human-readable part and
 * the length, so it happily turns an nsec into a "pubkey". Everything here
 * checks both, and answers null instead of throwing, so a caller cannot
 * forget to fail closed.
 */
final class NostrKeys
{
    public static function isHexPubkey(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/', $value) === 1;
    }

    /**
     * Decode an npub to a lowercase 64-character hex pubkey, or null.
     */
    public static function npubToHex(string $npub): ?string
    {
        try {
            [$hrp, $data] = decode(trim($npub));
            $bytes = convertBits($data, count($data), 5, 8, false);
        } catch (Throwable) {
            return null;
        }

        if ($hrp !== 'npub' || count($bytes) !== 32) {
            return null;
        }

        return vsprintf(str_repeat('%02x', 32), $bytes);
    }

    /**
     * Accept either an npub or a 64-character hex pubkey and return hex.
     */
    public static function toHex(string $key): ?string
    {
        $key = trim($key);

        if (self::isHexPubkey(strtolower($key))) {
            return strtolower($key);
        }

        return self::npubToHex($key);
    }

    public static function hexToNpub(string $hex): string
    {
        $bytes = array_values(unpack('C*', (string) hex2bin($hex)) ?: []);

        return encode('npub', convertBits($bytes, count($bytes), 8, 5, true));
    }

    /**
     * NIP-19 `naddr` of an addressable event: TLV 0 = d (UTF-8), 2 = author
     * (32 bytes), 3 = kind (4 bytes, big-endian). No relay hints.
     */
    public static function naddr(int $kind, string $pubkey, string $d): string
    {
        $length = strlen($d);

        // One length byte per TLV entry: a longer `d` cannot be encoded.
        if ($length > 255) {
            throw new \InvalidArgumentException('A d tag longer than 255 bytes has no naddr.');
        }

        $tlv = chr(0).chr($length).$d
            .chr(2).chr(32).hex2bin($pubkey)
            .chr(3).chr(4).pack('N', $kind);

        $bytes = array_values(unpack('C*', $tlv) ?: []);

        return encode('naddr', convertBits($bytes, count($bytes), 8, 5, true));
    }
}
