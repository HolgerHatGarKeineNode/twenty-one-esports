<?php

namespace App\Support\Wallet;

use App\Models\IncomingPayment;
use App\Models\LedgerTransfer;
use App\Models\TournamentPayout;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * The league's double-entry book of the pots (P9, plan "Wallet und Töpfe"):
 * every movement of sats is one booking from account A to account B
 * ({@see LedgerTransfer}), so each booking balances by construction and the
 * sum over all accounts is always zero.
 *
 * Accounts: `outside` (the world beyond the wallet), `reserve`,
 * `tournament:<id>`. What the wallet should hold is what the pots hold:
 * the sum over every account but `outside`, which is minus the balance of
 * `outside`.
 *
 * Each booking is tied to its cause (an incoming payment, a payout, a
 * tournament's remainder) by a unique key, so booking the same cause twice
 * (a retried job, a double click) is a no-op.
 */
final class Ledger
{
    public const OUTSIDE = 'outside';

    public const RESERVE = 'reserve';

    /** A settled invoice: from outside into its pot (or the reserve, when late). */
    public function contribution(IncomingPayment $payment, string $pot): void
    {
        $this->book(self::OUTSIDE, $pot, $payment->amount_sats, 'contribution', ['incoming_payment_id' => $payment->id]);
    }

    /** A paid prize: from the tournament's pot to outside; the routing fee from the reserve. */
    public function payout(TournamentPayout $payout, int $feeSats): void
    {
        $this->book(IncomingPayment::tournamentPot($payout->tournament_id), self::OUTSIDE, $payout->amount_sats, 'payout', ['tournament_payout_id' => $payout->id]);

        if ($feeSats > 0) {
            $this->book(self::RESERVE, self::OUTSIDE, $feeSats, 'fee', ['tournament_payout_id' => $payout->id]);
        }
    }

    /** What a tournament's split leaves over goes to the reserve (NIP "Pots": "what is left goes to reserve"). */
    public function remainder(int $tournamentId, int $sats): void
    {
        $this->book(IncomingPayment::tournamentPot($tournamentId), self::RESERVE, $sats, 'remainder', ['tournament_id' => $tournamentId]);
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
