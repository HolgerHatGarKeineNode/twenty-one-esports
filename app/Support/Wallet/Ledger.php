<?php

namespace App\Support\Wallet;

use App\Models\IncomingPayment;
use App\Models\LedgerTransfer;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * The league wallet's double-entry book (P9, plan "Wallet und Töpfe"), the
 * Season-Chain's only: every movement of sats is one booking from account A
 * to account B ({@see LedgerTransfer}), so each booking balances by
 * construction and the sum over all accounts is always zero.
 *
 * Accounts: `outside` (the world beyond the wallet) and `reserve`. What the
 * wallet should hold is minus the balance of `outside`. Tournament pots are
 * never booked here: each is its tournament's own wallet (user, 2026-09-27).
 *
 * Each booking is tied to its cause by a unique key, so booking the same
 * cause twice (a retried job, a double click) is a no-op.
 */
final class Ledger
{
    public const OUTSIDE = 'outside';

    public const RESERVE = 'reserve';

    /** A settled invoice of the league wallet: from outside into the reserve. */
    public function contribution(IncomingPayment $payment, string $pot): void
    {
        if ($pot !== self::RESERVE) {
            throw new InvalidArgumentException('The league ledger books the reserve only; tournament pots are their own wallets.');
        }

        $this->book(self::OUTSIDE, $pot, $payment->amount_sats, 'contribution', ['incoming_payment_id' => $payment->id]);
    }

    /**
     * Credits minus debits of an account.
     */
    public function balance(string $account): int
    {
        return (int) LedgerTransfer::query()->where('to_account', $account)->sum('sats')
            - (int) LedgerTransfer::query()->where('from_account', $account)->sum('sats');
    }

    /**
     * What the wallet should hold: the sum of every pot.
     */
    public function pots(): int
    {
        return -$this->balance(self::OUTSIDE);
    }

    /**
     * @param  array<string, int>  $cause
     */
    private function book(string $from, string $to, int $sats, string $reason, array $cause): void
    {
        if ($sats <= 0 || $from === $to) {
            throw new InvalidArgumentException('A booking moves a positive amount between two accounts.');
        }

        if (LedgerTransfer::query()->where('reason', $reason)->where($cause)->exists()) {
            return;
        }

        try {
            LedgerTransfer::query()->create(['from_account' => $from, 'to_account' => $to, 'sats' => $sats, 'reason' => $reason, 'created_at' => now(), ...$cause]);
        } catch (UniqueConstraintViolationException) {
            // Booked already: the same cause never books twice.
        }
    }
}
