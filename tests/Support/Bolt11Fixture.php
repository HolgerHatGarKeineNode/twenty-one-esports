<?php

namespace Tests\Support;

use function BitWasp\Bech32\convertBits;
use function BitWasp\Bech32\encode;

/**
 * Regtest BOLT11 invoices (`lnbcrt`) for the fake wallet and the fake
 * Lightning addresses: well formed and checksummed, with a payment hash,
 * a description hash and an expiry. The node signature is 65 random bytes:
 * nothing in the league checks it (the paying wallet would), and these
 * invoices never meet a real node.
 */
final class Bolt11Fixture
{
    /**
     * @return array{invoice: string, payment_hash: string, preimage: string}
     */
    public static function make(int $amountMsats, string $descriptionHash, int $expiry = 3600, ?int $timestamp = null, string $network = 'bcrt'): array
    {
        $preimage = bin2hex(random_bytes(32));
        $paymentHash = hash('sha256', (string) hex2bin($preimage));

        return ['invoice' => self::encode($amountMsats, $paymentHash, $descriptionHash, $expiry, $timestamp ?? time(), $network), 'payment_hash' => $paymentHash, 'preimage' => $preimage];
    }

    /**
     * @param  string|null  $amount  the human-readable amount as written (`15p`), instead of `$amountMsats`
     */
    public static function encode(int $amountMsats, string $paymentHash, string $descriptionHash, int $expiry, int $timestamp, string $network = 'bcrt', ?string $amount = null): string
    {
        $amount ??= $amountMsats % 100 === 0 ? intdiv($amountMsats, 100).'n' : ($amountMsats * 10).'p';
        $words = self::integerWords($timestamp, 7);
        $words = [...$words, ...self::field(1, self::bytesToWords((string) hex2bin($paymentHash)))];
        $words = [...$words, ...self::field(23, self::bytesToWords((string) hex2bin($descriptionHash)))];
        $words = [...$words, ...self::field(6, self::integerWords($expiry))];
        $words = [...$words, ...self::bytesToWords(random_bytes(65))];

        return encode('ln'.$network.$amount, $words);
    }

    /**
     * @param  list<int>  $data
     * @return list<int>
     */
    private static function field(int $type, array $data): array
    {
        return [$type, intdiv(count($data), 32), count($data) % 32, ...$data];
    }

    /**
     * @return list<int>
     */
    private static function bytesToWords(string $bytes): array
    {
        $values = array_values(unpack('C*', $bytes) ?: []);

        return convertBits($values, count($values), 8, 5, true);
    }

    /**
     * @return list<int>
     */
    private static function integerWords(int $value, int $length = 0): array
    {
        $words = [];

        do {
            array_unshift($words, $value % 32);
            $value = intdiv($value, 32);
        } while ($value > 0);

        while (count($words) < $length) {
            array_unshift($words, 0);
        }

        return $words;
    }
}
