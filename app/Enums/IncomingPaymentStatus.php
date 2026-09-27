<?php

namespace App\Enums;

/**
 * An invoice the league's wallet made for a pot (P9): waiting for payment,
 * paid (counted in its pot), or expired unpaid.
 */
enum IncomingPaymentStatus: string
{
    case Pending = 'pending';
    case Settled = 'settled';
    case Expired = 'expired';
}
