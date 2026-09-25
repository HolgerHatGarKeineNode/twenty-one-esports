<?php

namespace App\Enums;

/**
 * State of a series match, the NIP "State machine" of docs/nips/esports.md:
 *
 *   open -> accepted | declined | withdrawn | expired
 *   accepted -> reported -> confirmed | disputed
 *   disputed -> reported (a new report) | resolved (an admin decides)
 *   accepted -> resolved (no-show: an admin forfeits or voids)
 *
 * `confirmed` and `resolved` are final here; a rated match gets its league
 * attestation (2154) on top in P7.
 */
enum SeriesStatus: string
{
    case Open = 'open';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';
    case Expired = 'expired';
    case Reported = 'reported';
    case Disputed = 'disputed';
    case Confirmed = 'confirmed';
    case Resolved = 'resolved';

    public function isFinal(): bool
    {
        return in_array($this, [self::Declined, self::Withdrawn, self::Expired, self::Confirmed, self::Resolved], true);
    }

    /**
     * Played or being played: the room is in use.
     */
    public function isRunning(): bool
    {
        return in_array($this, [self::Accepted, self::Reported, self::Disputed], true);
    }

    public function hasResult(): bool
    {
        return $this === self::Confirmed || $this === self::Resolved;
    }
}
