<?php

namespace App\Support\Wallet;

use App\Models\IncomingPayment;
use App\Models\LedgerTransfer;
use App\Models\SeasonPayout;
use App\Models\Tournament;
use App\Models\TournamentPayout;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * The league wallet's double-entry book (P9, plan "Wallet und Töpfe"): every
 * movement of sats is one booking from account A to account B
 * ({@see LedgerTransfer}), so each booking balances by construction and the
 * sum over all accounts is always zero.
 *
 * Accounts: `outside` (the world beyond the wallet), `reserve` (the
 * Season-Chain's pot) and one per tournament pot, `tournament:<id>` (user,
 * 2026-10-02: every pot is booked in the league wallet). What the wallet
 * should hold is minus the balance of `outside`; what it holds for one
 * tournament is that tournament's balance. Paid season payouts leave the
 * reserve (P37), paid tournament payouts their tournament's account; a
 * released pot moves what its account still holds to the reserve. A
 * sponsor's sats paid outside the wallet are never booked here: they are not
 * in it.
 *
 * Each booking is tied to its cause by a unique key, so booking the same
 * cause twice (a retried job, a double click) is a no-op.
 */
final class Ledger
{
    public const OUTSIDE = 'outside';

    public const RESERVE = 'reserve';

    private const TOURNAMENT_PREFIX = 'tournament:';

    /** The reason of a pot's release to the reserve: unique per tournament (`reason`, `tournament_id`). */
    private const POT_RELEASE = 'pot_release';

    /** A settled invoice of the league wallet: from outside into its pot (the reserve or a tournament's). */
    public function contribution(IncomingPayment $payment, string $pot): void
    {
        if ($pot !== self::RESERVE && preg_match('/^tournament:[1-9][0-9]*$/', $pot) !== 1) {
            throw new InvalidArgumentException('A contribution goes to the reserve or to one tournament pot.');
        }

        $this->book(self::OUTSIDE, $pot, $payment->amount_sats, 'contribution', ['incoming_payment_id' => $payment->id]);
    }

    /**
     * A paid season payout (P37): the amount, and the routing fee the wallet
     * reported (rounded up to whole sats), leave the reserve for the outside.
     */
    public function seasonPayout(SeasonPayout $payout): void
    {
        $this->book(self::RESERVE, self::OUTSIDE, $payout->amount_sats, 'season_payout', ['season_payout_id' => $payout->id]);

        $fee = (int) ceil(((int) $payout->fees_msats) / 1000);

        if ($fee > 0) {
            $this->book(self::RESERVE, self::OUTSIDE, $fee, 'season_payout_fee', ['season_payout_id' => $payout->id]);
        }
    }

    /**
     * A paid prize of a tournament pot in the league wallet: the amount and
     * its routing fee (rounded up to whole sats) leave that tournament's
     * account for the outside.
     */
    public function tournamentPayout(TournamentPayout $payout): void
    {
        $account = self::TOURNAMENT_PREFIX.$payout->tournament_id;
        $this->book($account, self::OUTSIDE, $payout->amount_sats, 'tournament_payout', ['tournament_payout_id' => $payout->id]);

        $fee = (int) ceil(((int) $payout->fees_msats) / 1000);

        if ($fee > 0) {
            $this->book($account, self::OUTSIDE, $fee, 'tournament_payout_fee', ['tournament_payout_id' => $payout->id]);
        }
    }

    /**
     * A prize the player passed on (PayoutStatus::Forwarded): its amount goes from the tournament's pot to the reserve,
     * which funds the next pots; once per payout.
     */
    public function payoutForwarded(TournamentPayout $payout): void
    {
        $this->book(self::TOURNAMENT_PREFIX.$payout->tournament_id, self::RESERVE, $payout->amount_sats, 'payout_forwarded', ['tournament_payout_id' => $payout->id]);
    }

    /**
     * An admin releases what a cancelled tournament's pot, or a pot switched
     * off, still holds to the reserve (user, 2026-10-03): the whole balance
     * of its account, once per tournament.
     */
    public function potRelease(Tournament $tournament, int $sats): void
    {
        $this->book($tournament->potAccount(), self::RESERVE, $sats, self::POT_RELEASE, ['tournament_id' => $tournament->id]);
    }

    /** Whether a tournament's pot was released to the reserve; anything paid to it later goes there too. */
    public function isReleased(int $tournamentId): bool
    {
        return LedgerTransfer::query()->where('reason', self::POT_RELEASE)->where('tournament_id', $tournamentId)->exists();
    }

    /**
     * Credits minus debits of an account.
     */
    public function balance(string $account): int
    {
        return (int) LedgerTransfer::query()->where('to_account', $account)->sum('sats')
            - (int) LedgerTransfer::query()->where('from_account', $account)->sum('sats');
    }

    /** Everything ever booked into an account (a pot's contributions, before anything was paid from it). */
    public function credited(string $account): int
    {
        return (int) LedgerTransfer::query()->where('to_account', $account)->sum('sats');
    }

    /**
     * What the league wallet holds for tournament pots, all of them or all
     * but one: never to be spent on anything else.
     */
    public function heldForTournaments(?string $except = null): int
    {
        $in = LedgerTransfer::query()->where('to_account', 'like', self::TOURNAMENT_PREFIX.'%');
        $out = LedgerTransfer::query()->where('from_account', 'like', self::TOURNAMENT_PREFIX.'%');

        if ($except !== null) {
            $in->where('to_account', '!=', $except);
            $out->where('from_account', '!=', $except);
        }

        return (int) $in->sum('sats') - (int) $out->sum('sats');
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
