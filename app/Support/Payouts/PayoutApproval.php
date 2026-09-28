<?php

namespace App\Support\Payouts;

use App\Enums\PayoutStatus;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentPayout;
use App\Models\User;
use App\Support\FairPlay\AccountLinks;
use App\Support\FairPlay\FairPlay;
use App\Support\Lightning\LightningAddress;
use App\Support\Prizes\PotBalances;
use App\Support\Prizes\PrizePool;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Tournaments\TournamentPublisher;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Wallet\WalletSetup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The admin check at a tournament's end (P9, NIP "Payout": "after a
 * tournament's `end` and an admin's check"): the pot closes, the league
 * publishes the tournament's last version with `end` at the close, the
 * places are read from the bracket, and one payout per player is written
 * with its fixed idempotency key. Nothing is paid here; the admin pays
 * afterwards, from the pot's own wallet.
 *
 * Admins only (gate `admin`): organizers set up the pot and see the
 * payouts, but the check and the payment are the league's (decision P9).
 * Fail closed: without the pot's wallet connection or the league key
 * nothing closes, and a percent pot is what its wallet holds at this
 * moment (read now, never assumed; fixed prizes are approved even when
 * the read fails, with a warning on the page). Percent prizes split that balance less the
 * fee reserve ({@see PrizePool::payable()}); fixed prizes are approved as
 * set, and a balance short of them is only a warning on the page (user,
 * 2026-09-27: the admin is responsible). What is left stays in
 * the pot's wallet; the league wallet and ledger are never touched.
 * Approving twice changes nothing: the tournament row is locked and
 * approved only once, and the idempotency keys are unique.
 */
final class PayoutApproval
{
    public function __construct(private PayoutPlan $plan, private TournamentPublisher $publisher, private PotBalances $balances) {}

    /**
     * What keeps the admin from approving, or null.
     */
    public function blocker(Tournament $tournament): ?string
    {
        $reasons = [
            [$tournament->payouts_approved_at !== null, 'The payouts of this tournament are approved already.'],
            [$tournament->status !== TournamentStatus::Finished, 'Payouts are approved once the tournament has finished.'],
            [$tournament->pool_opened_at === null || ! $tournament->hasOwnWallet(), 'This tournament has no prize pool.'],
            [! WalletSetup::potCanPay($tournament), 'The connection of this pot’s own wallet is missing, so nothing can be paid out.'],
            [LeagueKey::fromConfig() === null, 'The league key is not set up, so nothing can be published yet.'],
        ];

        foreach ($reasons as [$applies, $reason]) {
            if ($applies) {
                return __($reason);
            }
        }

        return null;
    }

    /**
     * An admin approves the Lightning address a player's profile shows now
     * for an `open` payout (no address at the check, or one that changed
     * since, security gate F3): the payout is `pending` again, to that
     * address. `$seen` is the address the admin was shown and confirmed; if
     * the profile names another one by now, nothing is approved. Nothing is
     * paid here.
     *
     * @throws TournamentRuleViolation
     */
    public function approveAddress(TournamentPayout $payout, User $admin, string $seen): TournamentPayout
    {
        if (! Gate::forUser($admin)->allows('admin')) {
            throw new TournamentRuleViolation('not_admin', __('Only an admin can approve payouts.'));
        }

        $address = PayoutRunner::currentAddress($payout);

        // A prize withheld for a linked account (P41) is not released by an address approval.
        if ($payout->reason === AccountLinks::WITHHELD || FairPlay::isLinked($payout->pubkey)) {
            throw new TournamentRuleViolation('withheld', __('This prize is withheld: the account is linked to another account of the same player. Unlink it first if the link was wrong.'));
        }

        if ($payout->status !== PayoutStatus::Open || $address === null) {
            throw new TournamentRuleViolation('no_new_address', __('This payout has no new Lightning address to approve.'));
        }

        // Only the address the admin was shown: it may have changed again since the page was drawn (re-gate R2).
        if ($address !== strtolower(trim($seen))) {
            throw new TournamentRuleViolation('address_moved', __('The player’s Lightning address changed again. Check the new one and approve it.'));
        }

        TournamentPayout::query()->whereKey($payout->id)->where('status', PayoutStatus::Open)
            ->update(['status' => PayoutStatus::Pending, 'reason' => null, 'lud16' => $address, 'bolt11' => null, 'payment_hash' => null]);

        return $payout->refresh();
    }

    /**
     * @throws TournamentRuleViolation
     */
    public function approve(Tournament $tournament, User $admin): Tournament
    {
        if (! Gate::forUser($admin)->allows('admin')) {
            throw new TournamentRuleViolation('not_admin', __('Only an admin can approve payouts.'));
        }

        if (($blocker = $this->blocker($tournament)) !== null) {
            throw new TournamentRuleViolation('payout_blocked', $blocker);
        }

        // Percent prizes are a share of what the wallet holds now: read, never assumed (fail closed). Fixed prizes do not
        // depend on the balance: an unreadable one is a warning on the page, the admin is responsible (coordinator, 2026-09-27).
        if (! $this->balances->read($tournament) && $tournament->prizeMode() !== Tournament::PRIZES_FIXED) {
            throw new TournamentRuleViolation('pot_unread', __('The pot’s wallet did not tell its balance just now, so nothing was approved. Try again in a moment.'));
        }

        return DB::transaction(function () use ($tournament, $admin): Tournament {
            $locked = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();

            if ($locked->payouts_approved_at !== null) {
                return $locked;
            }

            $locked->forceFill(['pool_closed_at' => now(), 'payouts_approved_at' => now(), 'payouts_approved_by_id' => $admin->id])->save();
            $this->publisher->republish($locked);

            $pool = PrizePool::payable($locked, (int) $locked->pot_balance_sats);
            $plan = $this->plan->compute($locked, $pool) ?? throw new TournamentRuleViolation('no_places', __('The final places of this tournament cannot be read from its bracket.'));

            foreach ($plan['rows'] as $row) {
                $user = $row['user'];
                $lud16 = is_string($user->lud16) && LightningAddress::target($user->lud16) !== null ? strtolower($user->lud16) : null;

                TournamentPayout::query()->firstOrCreate(['idempotency_key' => TournamentPayout::keyFor($locked->id, $user->pubkey, $row['place'])], [
                    'tournament_id' => $locked->id,
                    'user_id' => $user->id,
                    'participant_id' => $row['participant']->id,
                    'pubkey' => $user->pubkey,
                    'name' => $user->displayName(),
                    'place' => $row['place'],
                    'amount_sats' => $row['amount'],
                    'lud16' => $lud16,
                    'status' => $lud16 === null ? PayoutStatus::Open : PayoutStatus::Pending,
                    'reason' => $lud16 === null ? 'no_lud16' : null,
                ]);
            }

            return $locked;
        });
    }
}
