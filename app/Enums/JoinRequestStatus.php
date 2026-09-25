<?php

namespace App\Enums;

/**
 * A join request through a clan link (P6b, NIP decision (a)): league data,
 * never an event.
 *
 *  pending   waiting for a captain
 *  approved  a captain said yes; the owner still has to list the player in
 *            the clan event (kind 32150, signed with the owner's key)
 *  listed    the owner listed them: a named clan invite exists, and the
 *            player joins with their own membership (12150)
 *  declined  a captain said no
 *  withdrawn the player took the request back
 */
enum JoinRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Listed = 'listed';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';

    /**
     * Still waiting for someone in the clan (a captain or the owner).
     */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Approved;
    }
}
