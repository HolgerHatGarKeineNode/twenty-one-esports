<?php

namespace App\Support\Wallet;

use Elliptic\EC;
use RuntimeException;
use swentel\nostr\Encryption\Nip44;
use Throwable;

/**
 * The two encryptions NIP-47 allows for request and response content:
 * `nip44_v2` (NIP-44, via swentel/nostr-php) and the legacy `nip04`, which a
 * wallet without an `encryption` tag in its info event (13194) expects.
 *
 * NIP-04 is AES-256-CBC keyed with the x coordinate of the ECDH point. It is
 * not taken from swentel/nostr-php: its Nip04::deriveSharedSecret() takes
 * `substr($shared->toString(16), 0, 64)` without padding, so for about one
 * key pair in sixteen (an x coordinate with a leading zero nibble) it keys
 * AES with a shorter string and neither side can read the other. Here the
 * coordinate is left-padded to 32 bytes, as NIP-04 and nostr-tools do
 * (tests/Unit/Wallet/NwcCipherTest.php checks a pair that hits the bug).
 *
 * Every failure is a new RuntimeException without the previous one, so no
 * trace of a library frame (whose arguments are the secret) travels on.
 */
final class NwcCipher
{
    public const NIP44 = 'nip44_v2';

    public const NIP04 = 'nip04';

    public static function encrypt(string $scheme, string $plaintext, #[\SensitiveParameter] string $secret, string $peer): string
    {
        try {
            return $scheme === self::NIP44
                ? Nip44::encrypt($plaintext, Nip44::getConversationKey($secret, $peer))
                : self::nip04Encrypt($plaintext, $secret, $peer);
        } catch (Throwable) {
            throw new RuntimeException('NWC encryption failed.');
        }
    }

    public static function decrypt(string $scheme, string $payload, #[\SensitiveParameter] string $secret, string $peer): string
    {
        try {
            return $scheme === self::NIP44
                ? Nip44::decrypt($payload, Nip44::getConversationKey($secret, $peer))
                : self::nip04Decrypt($payload, $secret, $peer);
        } catch (Throwable) {
            throw new RuntimeException('NWC decryption failed.');
        }
    }

    private static function nip04Encrypt(string $plaintext, #[\SensitiveParameter] string $secret, string $peer): string
    {
        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', self::sharedX($secret, $peer), OPENSSL_RAW_DATA, $iv);

        if ($ciphertext === false) {
            throw new RuntimeException('NIP-04 encryption failed.');
        }

        return base64_encode($ciphertext).'?iv='.base64_encode($iv);
    }

    private static function nip04Decrypt(string $payload, #[\SensitiveParameter] string $secret, string $peer): string
    {
        [$body, $iv] = array_pad(explode('?iv=', $payload, 2), 2, '');
        $ciphertext = base64_decode($body, true);
        $iv = base64_decode($iv, true);

        if ($ciphertext === false || $iv === false || strlen($iv) !== 16) {
            throw new RuntimeException('NIP-04 payload is malformed.');
        }

        $plaintext = openssl_decrypt($ciphertext, 'aes-256-cbc', self::sharedX($secret, $peer), OPENSSL_RAW_DATA, $iv);

        if ($plaintext === false) {
            throw new RuntimeException('NIP-04 decryption failed.');
        }

        return $plaintext;
    }

    /**
     * The ECDH x coordinate as 32 raw bytes.
     */
    private static function sharedX(#[\SensitiveParameter] string $secret, string $peer): string
    {
        $ec = new EC('secp256k1');
        $point = $ec->keyFromPrivate($secret, 'hex')->derive($ec->keyFromPublic('02'.$peer, 'hex')->getPublic());

        return (string) hex2bin(str_pad($point->toString(16), 64, '0', STR_PAD_LEFT));
    }
}
