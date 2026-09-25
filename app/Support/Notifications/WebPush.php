<?php

namespace App\Support\Notifications;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Web Push (RFC 8030) with message encryption (RFC 8291, `aes128gcm` of
 * RFC 8188) and VAPID (RFC 8292), built from PHP's OpenSSL and HKDF
 * primitives only: no new dependency without the user's approval (AGENTS.md).
 * The encryption is checked byte for byte against the example of RFC 8291,
 * Section 5 and Appendix A (tests/Unit/WebPushTest.php).
 *
 * Keys: a P-256 pair, the public key as the 65-byte uncompressed point and
 * the private key as the 32-byte scalar, both base64url (as browsers and
 * other Web Push libraries print them). `php artisan esports:vapid-keys`
 * makes a pair; the keys live in `.env` only.
 */
final class WebPush
{
    /** DER prefix of a P-256 SubjectPublicKeyInfo; the 65-byte point follows. */
    private const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    private const RECORD_SIZE = 4096;

    public function __construct(
        private ?string $publicKey,
        private ?string $privateKey,
        private ?string $subject,
        private int $ttlSeconds = 86400,
        private int $timeoutSeconds = 5,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('esports.webpush.public_key') ?: null,
            config('esports.webpush.private_key') ?: null,
            config('esports.webpush.subject') ?: null,
            (int) config('esports.webpush.ttl_seconds', 86400),
            (int) config('esports.webpush.timeout_seconds', 5),
        );
    }

    public function isConfigured(): bool
    {
        return filled($this->publicKey) && filled($this->privateKey) && filled($this->subject);
    }

    public function publicKey(): ?string
    {
        return $this->publicKey;
    }

    /**
     * Encrypt and send one payload. Returns the push service's status code
     * (0 if it could not be reached). A subscription the push service no
     * longer knows (404, 410) is deleted.
     *
     * @param  array<string, mixed>  $payload
     */
    public function send(PushSubscription $subscription, array $payload): int
    {
        if (! $this->isConfigured()) {
            return 0;
        }

        try {
            $body = self::encrypt(
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                self::base64UrlDecode($subscription->public_key),
                self::base64UrlDecode($subscription->auth_token),
            );

            $response = Http::timeout($this->timeoutSeconds)
                ->withHeaders([
                    'Authorization' => $this->vapidHeader($subscription->endpoint),
                    'Content-Encoding' => 'aes128gcm',
                    'TTL' => (string) $this->ttlSeconds,
                    'Urgency' => 'normal',
                ])
                ->withBody($body, 'application/octet-stream')
                ->post($subscription->endpoint);
        } catch (Throwable $exception) {
            report($exception);

            return 0;
        }

        if (in_array($response->status(), [404, 410], true)) {
            $subscription->delete();
        }

        return $response->status();
    }

    /**
     * The `Authorization: vapid t=<JWT>, k=<public key>` header for the push
     * service that serves this endpoint (RFC 8292, Section 3).
     */
    public function vapidHeader(string $endpoint, ?int $expiresAt = null): string
    {
        $url = parse_url($endpoint);
        $audience = ($url['scheme'] ?? 'https').'://'.($url['host'] ?? '').(isset($url['port']) ? ':'.$url['port'] : '');

        $header = self::base64UrlEncode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::base64UrlEncode((string) json_encode([
            'aud' => $audience,
            'exp' => $expiresAt ?? now()->addHours(12)->getTimestamp(),
            'sub' => $this->subject,
        ], JSON_UNESCAPED_SLASHES));

        $publicKey = self::base64UrlDecode((string) $this->publicKey);
        $privateKey = openssl_pkey_get_private(self::privateKeyPem(self::base64UrlDecode((string) $this->privateKey), $publicKey));

        if ($privateKey === false || ! openssl_sign($header.'.'.$claims, $der, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('VAPID signing failed: check WEBPUSH_VAPID_PRIVATE_KEY.');
        }

        return 'vapid t='.$header.'.'.$claims.'.'.self::base64UrlEncode(self::derToRaw($der)).', k='.$this->publicKey;
    }

    /**
     * RFC 8291 message encryption: one `aes128gcm` record. `$asPrivate`,
     * `$asPublic` and `$salt` exist for the RFC's test vector; in normal use
     * they are fresh random values for every message.
     *
     * @param  string  $uaPublic  the browser's `p256dh` key, 65 raw bytes
     * @param  string  $authSecret  the browser's `auth` secret, 16 raw bytes
     */
    public static function encrypt(string $plaintext, string $uaPublic, string $authSecret, ?string $asPrivate = null, ?string $asPublic = null, ?string $salt = null): string
    {
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || strlen($authSecret) !== 16) {
            throw new RuntimeException('Not a Web Push subscription key.');
        }

        if ($asPrivate === null || $asPublic === null) {
            [$asPublic, $asPrivate] = self::generateKeyPair();
        }

        if (strlen($asPublic) !== 65 || strlen($asPrivate) !== 32) {
            throw new RuntimeException('Not a P-256 key pair.');
        }

        $salt ??= random_bytes(16);

        $ownKey = openssl_pkey_get_private(self::privateKeyPem($asPrivate, $asPublic));
        $theirKey = openssl_pkey_get_public(self::publicKeyPem($uaPublic));

        if ($ownKey === false || $theirKey === false) {
            throw new RuntimeException('Invalid P-256 key.');
        }

        $ecdhSecret = openssl_pkey_derive($theirKey, $ownKey);

        if ($ecdhSecret === false) {
            throw new RuntimeException('ECDH failed.');
        }

        $keyInfo = "WebPush: info\x00".$uaPublic.$asPublic;
        $ikm = hash_hkdf('sha256', $ecdhSecret, 32, $keyInfo, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        $tag = '';
        $ciphertext = openssl_encrypt($plaintext."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);

        if ($ciphertext === false) {
            throw new RuntimeException('AES-GCM failed.');
        }

        // keyid: the 65-byte uncompressed point, its length as one octet (RFC 8188).
        return $salt.pack('N', self::RECORD_SIZE).chr(65).$asPublic.$ciphertext.$tag;
    }

    /**
     * A fresh P-256 pair: [public 65 bytes uncompressed, private 32 bytes].
     *
     * @return array{0: string, 1: string}
     */
    public static function generateKeyPair(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $details = $key === false ? false : openssl_pkey_get_details($key);

        if (! is_array($details) || ! isset($details['ec']['x'], $details['ec']['y'], $details['ec']['d'])) {
            throw new RuntimeException('Could not create a P-256 key.');
        }

        $pad = fn (string $bytes): string => str_pad($bytes, 32, "\x00", STR_PAD_LEFT);

        return ["\x04".$pad($details['ec']['x']).$pad($details['ec']['y']), $pad($details['ec']['d'])];
    }

    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $text): string
    {
        $decoded = base64_decode(strtr($text, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }

    private static function publicKeyPem(string $point): string
    {
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin(self::SPKI_PREFIX).$point), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    /**
     * SEC 1 ECPrivateKey (RFC 5915) for P-256 from the raw scalar and point.
     */
    private static function privateKeyPem(string $scalar, string $point): string
    {
        $der = "\x30\x77\x02\x01\x01\x04\x20".$scalar
            ."\xa0\x0a\x06\x08".hex2bin('2a8648ce3d030107')
            ."\xa1\x44\x03\x42\x00".$point;

        return "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";
    }

    /**
     * OpenSSL signs ECDSA as DER `SEQUENCE { INTEGER r, INTEGER s }`; JWS
     * ES256 wants the two integers as 32 bytes each.
     */
    public static function derToRaw(string $der): string
    {
        $offset = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7F : 0);
        $parts = [];

        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $integer = ltrim(substr($der, $offset + 2, $length), "\x00");
            $parts[] = str_pad($integer, 32, "\x00", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $parts[0].$parts[1];
    }
}
