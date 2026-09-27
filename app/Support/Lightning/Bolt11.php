<?php

namespace App\Support\Lightning;

use Throwable;

use function BitWasp\Bech32\convertBits;
use function BitWasp\Bech32\decodeRaw;

/**
 * The parts of a BOLT11 invoice the league checks before it pays or counts
 * one: network, amount, payment hash, description hash, time and expiry.
 *
 * Parsing only, no cryptography of its own: the bech32 checksum is checked
 * by bitwasp/bech32 (`decodeRaw`, which has no 90-character limit), and the
 * node's signature is left to the wallet that pays the invoice (the wallet
 * checks it; the league never pays from anything else). An invoice that is
 * not well formed is null, never half read.
 *
 * Amounts: the human-readable part is `ln` + network + optional amount in
 * BTC with a multiplier (m 10^-3, u 10^-6, n 10^-9, p 10^-12); a `p` amount
 * must be a multiple of 10 (BOLT11). Everything here is in millisatoshis.
 */
final readonly class Bolt11
{
    private const TAG_PAYMENT_HASH = 1;

    private const TAG_DESCRIPTION = 13;

    private const TAG_DESCRIPTION_HASH = 23;

    private const TAG_EXPIRY = 6;

    private const SIGNATURE_WORDS = 104;

    private const DEFAULT_EXPIRY = 3600;

    public function __construct(
        public string $invoice,
        public string $network,
        public ?int $amountMsats,
        public string $paymentHash,
        public ?string $descriptionHash,
        public ?string $description,
        public int $timestamp,
        public int $expiry,
    ) {}

    public static function decode(string $invoice): ?self
    {
        $invoice = strtolower(trim($invoice));

        if (str_starts_with($invoice, 'lightning:')) {
            $invoice = substr($invoice, 10);
        }

        if (strlen($invoice) > 7089 || ! str_starts_with($invoice, 'ln')) {
            return null;
        }

        try {
            [$hrp, $words] = decodeRaw($invoice);
        } catch (Throwable) {
            return null;
        }

        if (preg_match('/^ln([a-z]+?)(?:(\d+)([munp]?))?$/', $hrp, $parts) !== 1 || count($words) < 7 + self::SIGNATURE_WORDS) {
            return null;
        }

        $amount = isset($parts[2]) && $parts[2] !== '' ? self::amount($parts[2], $parts[3] ?? '') : null;

        if (isset($parts[2]) && $parts[2] !== '' && $amount === null) {
            return null;
        }

        $words = array_values(array_map(intval(...), $words));
        $timestamp = self::integer(array_slice($words, 0, 7));
        $fields = array_slice($words, 7, count($words) - 7 - self::SIGNATURE_WORDS);
        $paymentHash = null;
        $descriptionHash = null;
        $description = null;
        $expiry = self::DEFAULT_EXPIRY;

        for ($i = 0; $i + 3 <= count($fields);) {
            $type = $fields[$i];
            $length = $fields[$i + 1] * 32 + $fields[$i + 2];
            $data = array_slice($fields, $i + 3, $length);
            $i += 3 + $length;

            if (count($data) !== $length) {
                return null;
            }

            // Unknown tags and tags of an unexpected length are skipped (BOLT11 "MUST skip").
            match (true) {
                $type === self::TAG_PAYMENT_HASH && $length === 52 && $paymentHash === null => $paymentHash = self::hex($data),
                $type === self::TAG_DESCRIPTION_HASH && $length === 52 && $descriptionHash === null => $descriptionHash = self::hex($data),
                $type === self::TAG_DESCRIPTION && $description === null => $description = self::bytes($data),
                $type === self::TAG_EXPIRY && $length <= 10 => $expiry = self::integer($data),
                default => null,
            };
        }

        if ($paymentHash === null || strlen($paymentHash) !== 64) {
            return null;
        }

        return new self($invoice, $parts[1], $amount, $paymentHash, $descriptionHash, $description, $timestamp, $expiry);
    }

    public function expiresAt(): int
    {
        return $this->timestamp + $this->expiry;
    }

    public function isExpired(?int $now = null): bool
    {
        return ($now ?? time()) >= $this->expiresAt();
    }

    /**
     * Millisatoshis of an amount in BTC with a BOLT11 multiplier, null when
     * it does not come out whole.
     */
    private static function amount(string $digits, string $multiplier): ?int
    {
        if (strlen($digits) > 15 || ($digits !== '0' && str_starts_with($digits, '0'))) {
            return null;
        }

        $value = (int) $digits;

        // 1 BTC = 10^11 msat.
        return match ($multiplier) {
            '' => $value * 100_000_000_000,
            'm' => $value * 100_000_000,
            'u' => $value * 100_000,
            'n' => $value * 100,
            'p' => $value % 10 === 0 ? intdiv($value, 10) : null,
            default => null,
        };
    }

    /**
     * @param  list<int>  $words
     */
    private static function integer(array $words): int
    {
        $value = 0;

        foreach ($words as $word) {
            $value = $value * 32 + $word;
        }

        return $value;
    }

    /**
     * @param  list<int>  $words
     */
    private static function bytes(array $words): string
    {
        try {
            return pack('C*', ...convertBits($words, count($words), 5, 8, false));
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @param  list<int>  $words
     */
    private static function hex(array $words): string
    {
        return bin2hex(self::bytes($words));
    }
}
