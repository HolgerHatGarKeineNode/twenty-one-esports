<?php

namespace App\Support\Lightning;

use function BitWasp\Bech32\convertBits;
use function BitWasp\Bech32\encode;

/**
 * LUD-01 bech32 `lnurl` of a Lightning address (LUD-16:
 * `https://<domain>/.well-known/lnurlp/<user>`). A page shows it only as a
 * QR code (`lightning:<LNURL>`), never the address as text.
 */
final class Lnurl
{
    /**
     * Lowercase bech32, or null for anything that is not `user@domain.tld`.
     */
    public static function fromAddress(?string $address): ?string
    {
        if (! is_string($address) || preg_match('/^([a-z0-9._+-]+)@([a-z0-9.-]+\.[a-z]{2,})$/i', trim($address), $parts) !== 1) {
            return null;
        }

        $url = 'https://'.strtolower($parts[2]).'/.well-known/lnurlp/'.strtolower($parts[1]);
        $bytes = array_values(unpack('C*', $url) ?: []);

        return encode('lnurl', convertBits($bytes, count($bytes), 8, 5, true));
    }
}
