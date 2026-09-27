<?php

namespace App\Support\Lightning;

use RuntimeException;

/**
 * A Lightning address (LUD-16) that did not give a payable invoice. `reason`
 * is what a payout records as its reason: `lnurl_invalid`,
 * `lnurl_unreachable`, `amount_out_of_range`, `invoice_mismatch`.
 */
final class LightningAddressFailure extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
