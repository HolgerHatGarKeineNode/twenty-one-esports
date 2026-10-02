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
use App\Support\Prizes\PrizePool;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Tournaments\TournamentPublisher;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Wallet\Ledger;
use App\Support\Wallet\NwcError;
use App\Support\Wallet\ReceivingWallet;
use App\Support\Wallet\WalletSetup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The admin check at a tournament's end (P9, NIP "Payout": "after a
 * tournament's `end` and an admin's check"): the pot closes, the league
 * publishes the tournament's last version with `end` at the close, the
 * places are read from the bracket, and one payout per player is written
 * with its fixed idempotency key. Nothing is paid here; the admin pays
 * afterwards, from the league wallet.
 *
 * Admins only (gate `admin`): organizers set up the pot and see the
 * payouts, but the check and the payment are the league's (decision P9).
 *
 * The pot is the tournament's account in the league ledger (user,
 * 2026-10-02). Fail closed, before anything closes:
 *
 * - without the league wallet's paying connection or the league key;
 * - when the prizes and their fee reserve exceed what came into the pot
 *   through the league wallet (percent prizes split exactly that, less the
 *   reserve, {@see PrizePool::payable()}; fixed prizes short of it are
 *   refused: a short pot would pay with other pots' sats);
 * - when the league wallet does not tell its balance now, or holds less
 *   than the prizes beyond what it holds for the other tournament pots.
 *
 * Sats a sponsor paid outside the wallet are never part of it. What is
 * left after the prizes stays in the pot's account. Approving twice changes
 * nothing: the tournament row is locked and approved only once, and the
 * idempotency keys are unique.
 */
final class PayoutApproval
{
    public function __construct(private PayoutPlan $plan, private TournamentPublisher $publisher, private PrizePool $pool, private Ledger $ledger) {}

    /**
     * What keeps the admin from approving, or null.
     */
    public function blocker(Tournament $tournament): ?string
    {
        $reasons = [
            [$tournament->payouts_approved_at !== null, 'The payouts of this tournament are approved already.'],
            [$tournament->status !== TournamentStatus::Finished, 'Payouts are approved once the tournament has finished.'],
            [$tournament->pool_opened_at === null || ! $tournament->hasLeaguePot(), 'This tournament has no prize pool.'],
            [! WalletSetup::canPay() || ! WalletSetup::canReceive(), 'The league wallet is not connected, so nothing can be paid out.'],
            [PrizePool::shortfall($tournament, $this->pool->fundedSats($tournament), $this->pool->zapSats($tournament)) > 0, 'The pot has received less than the fixed prizes need with the fee reserve. Add the missing sats to the pot first.'],
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

        // The league wallet is shared by every pot and the reserve: read now, never assumed (fail closed).
        $balance = self::walletBalance();

        if ($balance === null) {
            throw new TournamentRuleViolation('wallet_unread', __('The league wallet did not tell its balance just now, so nothing was approved. Try again in a moment.'));
        }

        return DB::transaction(function () use ($tournament, $admin, $balance): Tournament {
            $locked = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();

            if ($locked->payouts_approved_at !== null) {
                return $locked;
            }

            // Under the lock: what came in, never more (a payment settling now is booked before or after, never twice);
            // the zaps on top are those with a verified receipt from before this close.
            $funded = $this->pool->fundedSats($locked);
            $zaps = $this->pool->zapSats($locked);

            if (PrizePool::shortfall($locked, $funded, $zaps) > 0) {
                throw new TournamentRuleViolation('pot_short', __('The pot has received less than the fixed prizes need with the fee reserve. Add the missing sats to the pot first.'));
            }

            $pool = PrizePool::payable($locked, $funded, $zaps);
            $plan = $this->plan->compute($locked, $pool, $zaps) ?? throw new TournamentRuleViolation('no_places', __('The final places of this tournament cannot be read from its bracket.'));
            $total = array_sum(array_column($plan['rows'], 'amount'));

            // Never more than the pot holds in the league ledger, nor more than the wallet holds beyond the other pots.
            if ($total > $this->pool->heldSats($locked) || $total > $balance - $this->ledger->heldForTournaments($locked->potAccount())) {
                throw new TournamentRuleViolation('pot_uncovered', __('The league wallet holds less than these prizes beyond what it keeps for the other pots, so nothing was approved. Check the wallet.'));
            }

            $locked->forceFill(['pool_closed_at' => now(), 'payouts_approved_at' => now(), 'payouts_approved_by_id' => $admin->id])->save();
            $this->publisher->republish($locked);

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

    /** The league wallet's balance now, in sats; null when it does not tell (fail closed). */
    public static function walletBalance(): ?int
    {
        $wallet = ReceivingWallet::fromConfig();

        try {
            return $wallet?->balanceSats();
        } catch (NwcError) {
            return null;
        }
    }
}
