<?php

namespace App\Support\Prizes;

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentModerationEntry;
use App\Models\User;
use App\Support\PreSeason;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Wallet\Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Release a pot's sats to the league reserve (plan "Restposten nach TMNF",
 * P2; user, 2026-10-03: an admin button, logged, nothing automatic). A
 * cancelled tournament, or a pot switched off while its account in the
 * league ledger still holds sats, would otherwise keep those sats in
 * {@see Ledger::heldForTournaments()} for good: no payout is ever approved
 * for it.
 *
 * Admins only (gate `admin`), with a note, and only while no payouts are
 * approved. The whole balance of `tournament:<id>` moves to the reserve in
 * one booking, unique per tournament, and the pot closes (`pool_closed_at`),
 * so whatever is paid to it later goes to the reserve as a late payment
 * ({@see IncomingPayments::settle()}). One line in the moderation log says
 * who, when, how much and why. There is no undo.
 *
 * It runs in a transaction holding the tournament row, the lock the payout
 * approval and every settle of a payment into this pot take: neither can
 * interleave with it. Releasing twice books nothing the second time.
 */
final class PotRelease
{
    public const NOTE_MAX = 200;

    public function __construct(private Ledger $ledger) {}

    /**
     * The sats an admin may release now, or 0: a cancelled tournament or a
     * pot switched off, its account holding sats, no payouts approved.
     */
    public function releasableSats(Tournament $tournament): int
    {
        if ($tournament->payouts_approved_at !== null || $tournament->payouts()->exists()) {
            return 0;
        }

        if ($tournament->status !== TournamentStatus::Cancelled && $tournament->hasPot()) {
            return 0;
        }

        return max(0, $this->ledger->balance($tournament->potAccount()));
    }

    /**
     * Release the pot's whole balance to the reserve. `$seen` is the amount
     * the admin was shown and confirmed: if the pot holds another amount by
     * now, nothing is released. Returns the sats released, 0 when it was
     * released already.
     *
     * @throws TournamentRuleViolation
     */
    public function release(Tournament $tournament, User $admin, int $seen, string $note): int
    {
        if (! Gate::forUser($admin)->allows('admin')) {
            throw new TournamentRuleViolation('not_admin', __('Only an admin can release a pot to the league reserve.'));
        }

        $note = mb_substr(trim(preg_replace('/\s+/u', ' ', $note) ?? ''), 0, self::NOTE_MAX);

        if ($note === '') {
            throw new TournamentRuleViolation('release_note', __('Say why the pot is released: the note goes into the moderation log.'));
        }

        return DB::transaction(function () use ($tournament, $admin, $seen, $note): int {
            $locked = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();

            // A second click (or a second admin) books nothing and logs nothing.
            if ($this->ledger->isReleased($locked->id)) {
                return 0;
            }

            if ($locked->payouts_approved_at !== null || $locked->payouts()->exists()) {
                throw new TournamentRuleViolation('paid_out', __('The payouts of this tournament are approved; its pot pays its winners and cannot be released.'));
            }

            if ($locked->status !== TournamentStatus::Cancelled && $locked->hasPot()) {
                throw new TournamentRuleViolation('pot_live', __('Only the pot of a cancelled tournament, or a pot switched off, can be released to the league reserve.'));
            }

            $sats = $this->ledger->balance($locked->potAccount());

            if ($sats <= 0) {
                throw new TournamentRuleViolation('pot_empty', __('This pot holds nothing in the league wallet.'));
            }

            if ($sats !== $seen) {
                throw new TournamentRuleViolation('pot_moved', __('The pot holds :sats sats by now. Check the amount and release again.', ['sats' => PreSeason::formatSats($sats)]));
            }

            $this->ledger->potRelease($locked, $sats);

            if ($locked->pool_closed_at === null) {
                $locked->forceFill(['pool_closed_at' => now()])->save();
            }

            TournamentModerationEntry::query()->create([
                'tournament_id' => $locked->id,
                'user_id' => $admin->id,
                'user_name' => mb_substr($admin->displayName(), 0, 80),
                'action' => 'pot_released',
                'subject' => mb_substr(__('Prize pot'), 0, 80),
                'reason' => $note,
                'details' => ['pot_sats' => [$sats, 0]],
                'created_at' => now(),
            ]);

            return $sats;
        });
    }
}
