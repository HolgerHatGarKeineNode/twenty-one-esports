<?php

/*
|--------------------------------------------------------------------------
| A fast stand-in for swentel\nostr\Sign\Sign, for the test processes only
|--------------------------------------------------------------------------
|
| The vendor class signs through paragonie/ecc's ConstantTimeMath: two
| constant-time secp256k1 point multiplications in pure PHP, ~0.1-0.3 s per
| signature (measured 2026-10-01 with the Xdebug profiler on one tournament
| test: 6 signatures were 37 % of the test's wall time; the whole default suite
| signs thousands of events). Constant time matters for a production secret,
| not for the throwaway keys of a test, so here the same BIP-340 signature is
| made with simplito/elliptic-php, the library Key::getPublicKey() already
| uses for every pubkey, which is ~25 times faster.
|
| tests/Pest.php requires this file before anything can autoload the vendor
| class, so every `new Sign` in the app (TwentyOneSigner, NotificationDm,
| NwcClient) and in the test helpers (TestSigner, FakeNwcWallet) lands here
| inside a test process. The output is a real BIP-340 signature: the app's own
| SignedEvent::fromInput() still verifies it, and
| tests/Unit/FastSignTest.php checks it against the official test vector and
| against the vendor verifier. The real stack (tests/Integration, a separate
| app server process) keeps the vendor signer.
*/

namespace Tests\Support {
    use BN\BN;
    use Elliptic\EC;

    final class FastSchnorr
    {
        private const ORDER = 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141';

        private static ?EC $curve = null;

        /**
         * BIP-340 signature (128 hex chars) of the 32-byte message $messageHex.
         *
         * @param  string  $aux  32 bytes of auxiliary randomness, raw; fresh random bytes by default
         */
        public static function sign(string $secretHex, string $messageHex, ?string $aux = null): string
        {
            self::$curve ??= new EC('secp256k1');
            $n = gmp_init(self::ORDER, 16);
            $d0 = gmp_init($secretHex, 16);

            if (gmp_cmp($d0, 0) <= 0 || gmp_cmp($d0, $n) >= 0) {
                throw new \InvalidArgumentException('Private key must be in the range [1, n - 1]');
            }

            $point = self::$curve->g->mul(new BN($secretHex, 16));
            $px = str_pad($point->getX()->toString(16), 64, '0', STR_PAD_LEFT);
            $d = $point->getY()->isOdd() ? gmp_sub($n, $d0) : $d0;

            $message = hex2bin($messageHex);
            $t = gmp_xor($d, gmp_init(self::tagged('BIP0340/aux', $aux ?? random_bytes(32)), 16));
            $nonce = gmp_mod(gmp_init(self::tagged('BIP0340/nonce', hex2bin(self::pad($t)).hex2bin($px).$message), 16), $n);

            if (gmp_cmp($nonce, 0) === 0) {
                throw new \RuntimeException('Failure. This happens only with negligible probability.');
            }

            $rPoint = self::$curve->g->mul(new BN(gmp_strval($nonce, 16), 16));
            $rx = str_pad($rPoint->getX()->toString(16), 64, '0', STR_PAD_LEFT);
            $k = $rPoint->getY()->isOdd() ? gmp_sub($n, $nonce) : $nonce;

            $e = gmp_mod(gmp_init(self::tagged('BIP0340/challenge', hex2bin($rx).hex2bin($px).$message), 16), $n);

            return $rx.self::pad(gmp_mod(gmp_add($k, gmp_mul($e, $d)), $n));
        }

        private static function tagged(string $tag, string $data): string
        {
            $hash = hash('sha256', $tag, true);

            return hash('sha256', $hash.$hash.$data);
        }

        private static function pad(\GMP $number): string
        {
            return str_pad(gmp_strval($number, 16), 64, '0', STR_PAD_LEFT);
        }
    }
}

namespace swentel\nostr\Sign {
    use swentel\nostr\EventInterface;
    use swentel\nostr\Key\Key;
    use Tests\Support\FastSchnorr;

    // Same contract as the vendor class (vendor/swentel/nostr-php/src/Sign/Sign.php);
    // only the signature maths differs.
    class Sign
    {
        public function signEvent(EventInterface $event, string $private_key): void
        {
            $key = new Key;

            if (str_starts_with($private_key, 'nsec') === true) {
                $private_key = $key->convertToHex($private_key);
            }

            $event->setPublicKey($key->getPublicKey($private_key));

            $hashContent = self::serializeEvent($event);

            if ($hashContent) {
                if ($event->getId() === '') {
                    $event->setId(hash('sha256', $hashContent));
                }

                $event->setSignature(FastSchnorr::sign($private_key, $event->getId()));
            }
        }

        public static function serializeEvent(EventInterface $event): bool|string
        {
            return json_encode([
                0,
                $event->getPublicKey(),
                $event->getCreatedAt(),
                $event->getKind(),
                $event->getTags(),
                $event->getContent(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }
}
