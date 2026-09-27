<?php

namespace App\Enums;

/**
 * State of one tournament payout (P9), the machine the payout runner keeps:
 *
 *   open    -> pending (the player now has a Lightning address)
 *   pending -> paying  (claimed by one attempt, never two)
 *   paying  -> paid | failed | paying (outcome unknown: looked up again first)
 *   failed  -> paying  (retry: a stored invoice is looked up before anything is paid)
 *
 * `paid` is final. `open`, `paying` and `failed` carry a reason the pages show.
 */
enum PayoutStatus: string
{
    case Open = 'open';
    case Pending = 'pending';
    case Paying = 'paying';
    case Paid = 'paid';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Pending => __('Ready to pay'),
            self::Paying => __('Paying'),
            self::Paid => __('Paid'),
            self::Failed => __('Failed'),
        };
    }

    /**
     * An admin may start a payment from here.
     */
    public function isPayable(): bool
    {
        return $this === self::Pending || $this === self::Failed;
    }
}
