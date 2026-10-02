<?php

namespace App\Support\Prizes;

use App\Enums\PayoutStatus;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentModerationEntry;
use App\Models\TournamentSponsor;
use App\Models\User;
use App\Support\PreSeason;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Tournaments\TournamentPublisher;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Wallet\Ledger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * A tournament's prize pot (P9, NIP "Prize pool funding"): what it holds,
 * what each place wins, and what its organizer may change.
 *
 * A pot is booked in the league wallet (user, 2026-10-02: „nutze doch
 * einfach Zaps auf Nostr Events … bitte nicht pro Turnier Wallets fordern …
 * das landet eh alles in eine Wallet von wo aus ausgezahlt werden kann"):
 * every invoice for it is made by the league wallet and, once paid, booked
 * into the tournament's account of the league ledger ({@see Ledger}). That
 * account is what the pot holds; nothing is read from any other wallet. A
 * sponsor's sats paid outside the wallet count toward the pot but are never
 * in that account, so no payout takes them from the wallet. The organizer
 * or an admin (gate `manage-tournament`) switches the pot on and sets the
 * prizes; paying out is for admins only (App\Support\Payouts\PayoutApproval).
 * Pots of the former own-wallet design whose payouts were approved before
 * finish paying from that wallet (Tournament::hasOwnWallet()).
 *
 * Two ways to set the prizes, both part of the rules players sign up under
 * (they change while the tournament is a draft or open for sign-up, never
 * after sign-up closed):
 *
 * - `percent`: a share of the pot per place (presets or custom, 100 % in
 *   total), paid from the balance at the check less the fee reserve;
 * - `fixed`: sats per place (user, 2026-09-27: "Was ist wenn er feste
 *   Beträge pro Platzierung will?"). The pot has to cover their sum plus
 *   the fee reserve; what it holds beyond that stays in the wallet.
 */
final class PrizePool
{
    /** Most places a split may name. */
    public const MAX_PLACES = 8;

    /**
     * The percent presets of the pot settings; any other split is custom.
     *
     * @var array<string, list<int>>
     */
    public const PRESETS = [
        'winner' => [100],
        '60-30-10' => [60, 30, 10],
        '50-30-20' => [50, 30, 20],
        'top-4' => [40, 30, 20, 10],
    ];

    /**
     * Held back for routing fees (percent, at least WALLET_FEE_MIN sats): the
     * pot's wallet pays its own fees, so the places share what is left
     * (percent mode), or the pot has to hold that much on top of the fixed
     * prizes (fixed mode).
     */
    public const WALLET_FEE_PERCENT = 1;

    public const WALLET_FEE_MIN = 10;

    /** A legacy own wallet's balance read older than this is shown as stale (seconds). */
    public const BALANCE_STALE_AFTER = 600;

    public function __construct(private TournamentPublisher $publisher, private SponsorLogos $logos, private Ledger $ledger) {}

    /**
     * The pot's sats as the tournament sets it (user, 2026-09-28: „nicht die
     * Zahl nehmen, die in der Wallet als Balance ist, sondern den Pot, wie er
     * im Turnier eingestellt ist“): the fixed prizes' sum, else the target;
     * without either, what came in (booked in the league wallet, and paid
     * outside it). Null without a pot.
     */
    public function potSats(Tournament $tournament): ?int
    {
        if (! $tournament->hasPot()) {
            return null;
        }

        if ($tournament->prizeMode() === Tournament::PRIZES_FIXED) {
            return self::fixedTotal($tournament);
        }

        return $tournament->prize_target_sats ?? $this->fundedSats($tournament) + self::paidOutsideSats($tournament);
    }

    /**
     * What came into the pot through the league wallet, before anything was
     * paid from it: the paid invoices (top-ups, sponsor invoices, zaps)
     * booked into the tournament's account. A legacy own-wallet pot: its last
     * balance read.
     */
    public function fundedSats(Tournament $tournament): int
    {
        if ($tournament->hasOwnWallet()) {
            return (int) $tournament->pot_balance_sats;
        }

        return $tournament->hasLeaguePot() ? $this->ledger->credited($tournament->potAccount()) : 0;
    }

    /**
     * What the league wallet holds for the pot now: what came in, less the
     * prizes and their fees paid from it.
     */
    public function heldSats(Tournament $tournament): int
    {
        return $tournament->hasLeaguePot() ? $this->ledger->balance($tournament->potAccount()) : 0;
    }

    /**
     * The pot a public screen may show (the stream's slides): as potSats(),
     * but null for none or zero, and for a legacy own wallet whose balance
     * read is old when the tournament sets neither prizes nor a target (the
     * stream bot applies the same rule).
     */
    public function shownPotSats(Tournament $tournament): ?int
    {
        $configured = $tournament->prizeMode() === Tournament::PRIZES_FIXED || $tournament->prize_target_sats !== null;

        if ($tournament->hasOwnWallet() && ! $configured && self::isBalanceStale($tournament)) {
            return null;
        }

        $pot = $this->potSats($tournament);

        return $pot !== null && $pot > 0 ? $pot : null;
    }

    /** The prizes paid out so far. */
    public static function paidSats(Tournament $tournament): int
    {
        return (int) $tournament->payouts()->where('status', PayoutStatus::Paid)->sum('amount_sats');
    }

    /**
     * What is still to be won: the pot as set less the prizes paid so far
     * (user, 2026-09-28: „Keine Abhängigkeit zur Wallet Balance. Nur zu dem
     * was bisher ausgezahlt wurde“); null without a pot.
     */
    public function remainingSats(Tournament $tournament): ?int
    {
        $pot = $this->potSats($tournament);

        return $pot === null ? null : max(0, $pot - self::paidSats($tournament));
    }

    /** The routing-fee reserve on an amount of sats. */
    public static function feeReserve(int $sats): int
    {
        return max(self::WALLET_FEE_MIN, intdiv($sats * self::WALLET_FEE_PERCENT + 99, 100));
    }

    public static function afterFeeReserve(int $sats): int
    {
        return max(0, $sats - self::feeReserve($sats));
    }

    /** The sum of the fixed prizes (0 in percent mode). */
    public static function fixedTotal(Tournament $tournament): int
    {
        return array_sum($tournament->prizeFixed());
    }

    /**
     * What the pot should hold for its fixed prizes: their sum and its fee
     * reserve. Null in percent mode (whatever came in splits). A pot short of
     * it is not approved (coordinator, 2026-10-02): with every pot in one
     * wallet, a short pot would pay its prizes with other pots' sats.
     */
    public static function requiredSats(Tournament $tournament): ?int
    {
        if ($tournament->prizeMode() !== Tournament::PRIZES_FIXED) {
            return null;
        }

        $total = self::fixedTotal($tournament);

        return $total + self::feeReserve($total);
    }

    /**
     * What the places share at the check, from `$funded` sats that came into
     * the pot: percent mode splits them less the fee reserve; fixed mode pays
     * exactly the fixed sum (approved only when `$funded` covers it and its
     * reserve, {@see shortfall()}).
     */
    public static function payable(Tournament $tournament, int $funded): int
    {
        return self::requiredSats($tournament) === null ? self::afterFeeReserve($funded) : self::fixedTotal($tournament);
    }

    /**
     * How many sats `$funded` lacks for the fixed prizes and their fee
     * reserve; 0 when it covers them, and always 0 in percent mode.
     */
    public static function shortfall(Tournament $tournament, int $funded): int
    {
        return max(0, (int) self::requiredSats($tournament) - $funded);
    }

    /**
     * What each place wins now, before ties and rosters: per percent from the
     * pot's payable balance, or the fixed amounts.
     *
     * @return list<array{place: int, percent: int|null, sats: int}>
     */
    public function projection(Tournament $tournament): array
    {
        if ($tournament->prizeMode() === Tournament::PRIZES_FIXED) {
            return array_map(fn (int $sats, int $index): array => ['place' => $index + 1, 'percent' => null, 'sats' => $sats],
                $tournament->prizeFixed(), array_keys($tournament->prizeFixed()));
        }

        $payable = self::afterFeeReserve((int) $this->potSats($tournament));

        return array_map(fn (int $percent, int $index): array => ['place' => $index + 1, 'percent' => $percent, 'sats' => intdiv($payable * $percent, 100)],
            $tournament->prizeSplit(), array_keys($tournament->prizeSplit()));
    }

    /**
     * How far the pot is from what it is meant to pay: `goal` is the fixed
     * sum (fixed mode) or the organizer's target (percent mode, null
     * without one); `have` what the pot can pay towards it (what came into
     * the league wallet for it, less the fee reserve in fixed mode);
     * `leftover` what stays in its account after fixed prizes and their
     * reserve. Sats paid outside the wallet are not part of it.
     *
     * @return array{goal: int|null, have: int, funded: bool, leftover: int|null}
     */
    public function funding(Tournament $tournament): array
    {
        $balance = $this->fundedSats($tournament);

        if ($tournament->prizeMode() === Tournament::PRIZES_FIXED) {
            $total = self::fixedTotal($tournament);
            $required = (int) self::requiredSats($tournament);

            return ['goal' => $total, 'have' => max(0, $balance - self::feeReserve($total)), 'funded' => $balance >= $required, 'leftover' => max(0, $balance - $required)];
        }

        $target = $tournament->prize_target_sats;

        return ['goal' => $target, 'have' => $balance, 'funded' => $target !== null && $balance >= $target, 'leftover' => null];
    }

    /**
     * Legacy own wallet: whether its last balance read is old or failed
     * since. A league pot is never stale: its ledger is exact.
     */
    public static function isBalanceStale(Tournament $tournament): bool
    {
        if (! $tournament->hasOwnWallet()) {
            return false;
        }

        return $tournament->pot_balance_at === null || $tournament->pot_balance_error !== null
            || $tournament->pot_balance_at->lt(now()->subSeconds(self::BALANCE_STALE_AFTER));
    }

    /**
     * The preset a split matches, or null (custom).
     *
     * @param  array<int, mixed>  $split
     */
    public static function presetOf(array $split): ?string
    {
        $values = array_map(fn ($value): int => is_numeric($value) ? (int) $value : -1, array_values($split));

        return array_search($values, self::PRESETS, true) ?: null;
    }

    /**
     * Why the percent split is not valid, or null: 1 to 8 places, whole
     * percents from 1 to 100, first place first (never more for a lower
     * place), 100 in total.
     *
     * @param  array<int, mixed>  $percents
     */
    public static function splitError(array $percents): ?string
    {
        $values = array_values($percents);

        if ($values === [] || count($values) > self::MAX_PLACES) {
            return __('A split names between 1 and :max places.', ['max' => self::MAX_PLACES]);
        }

        foreach ($values as $index => $value) {
            if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                return __('Every share is a whole percent.');
            }

            if ((int) $value < 1 || (int) $value > 100) {
                return __('Every share is between 1 and 100 percent.');
            }

            if ($index > 0 && (int) $value > (int) $values[$index - 1]) {
                return __('A lower place never gets more than a higher one.');
            }
        }

        if (array_sum(array_map(intval(...), $values)) !== 100) {
            return __('The shares add up to 100 percent.');
        }

        return null;
    }

    /**
     * Why the fixed prizes are not valid, or null: 1 to 8 places, each a
     * whole number of sats from 1 to `esports.wallet.fixed_prize_max_sats`,
     * all together at most `esports.wallet.fixed_prizes_max_total_sats`.
     *
     * @param  array<int, mixed>  $amounts
     */
    public static function fixedError(array $amounts): ?string
    {
        $values = array_values($amounts);
        $max = (int) config('esports.wallet.fixed_prize_max_sats');
        $maxTotal = (int) config('esports.wallet.fixed_prizes_max_total_sats');

        if ($values === [] || count($values) > self::MAX_PLACES) {
            return __('Fixed prizes name between 1 and :max places.', ['max' => self::MAX_PLACES]);
        }

        foreach ($values as $value) {
            if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                return __('Every prize is a whole number of sats.');
            }

            if ((int) $value < 1 || (int) $value > $max) {
                return __('Every prize is between 1 and :max sats.', ['max' => PreSeason::formatSats($max)]);
            }
        }

        if (array_sum(array_map(intval(...), $values)) > $maxTotal) {
            return __('The prizes add up to at most :max sats.', ['max' => PreSeason::formatSats($maxTotal)]);
        }

        return null;
    }

    public function canChangeSplit(Tournament $tournament): bool
    {
        return $tournament->status === TournamentStatus::Draft || $tournament->isSignupOpen();
    }

    /**
     * The prize pot of the tournament create and edit pages and the pool
     * page: off (`$enabled` false) or on, booked in the league wallet, with
     * its prizes. No wallet is asked for (user, 2026-10-02). A published
     * tournament's pot is open at once, and the rules on Nostr get a new
     * version when the pot or its prizes changed.
     *
     * The prizes (mode, split, fixed amounts) change only until sign-up
     * closes; after the payouts were approved nothing changes.
     *
     * @param  array<int, mixed>  $split  percents per place (percent mode)
     * @param  array<int, mixed>  $fixed  sats per place (fixed mode)
     *
     * @throws TournamentRuleViolation
     */
    public function configurePot(Tournament $tournament, User $user, bool $enabled, ?int $targetSats, string $mode, array $split, array $fixed = []): Tournament
    {
        $this->authorize($tournament, $user);
        $this->refuseWhenEnded($tournament);

        if (! in_array($mode, [Tournament::PRIZES_PERCENT, Tournament::PRIZES_FIXED], true)) {
            throw new TournamentRuleViolation('prize_mode', __('Pick how the prizes are set.'));
        }

        if ($targetSats !== null && ($targetSats < 0 || $targetSats > 2_100_000_000_000_000)) {
            throw new TournamentRuleViolation('target', __('The target is a whole number of sats.'));
        }

        $split = array_map(fn ($value): int => is_numeric($value) ? (int) $value : 0, array_values($split));
        $fixed = array_map(fn ($value): int => is_numeric($value) ? (int) $value : 0, array_values($fixed));
        $prizesChanged = $enabled && $this->prizesDiffer($tournament, $mode, $split, $fixed);

        if ($prizesChanged && ! $this->canChangeSplit($tournament)) {
            throw new TournamentRuleViolation('split_frozen', __('The prizes are part of the rules players signed up under; they cannot change after sign-up closed.'));
        }

        $this->refuseFrozenToggle($tournament, $enabled);

        if ($prizesChanged && ($error = $mode === Tournament::PRIZES_FIXED ? self::fixedError($fixed) : self::splitError($split)) !== null) {
            throw new TournamentRuleViolation('split', $error);
        }

        return DB::transaction(function () use ($tournament, $enabled, $targetSats, $mode, $split, $fixed, $prizesChanged): Tournament {
            $locked = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();

            // The checks above read a row that may have moved during the live wallet check: again, under the lock.
            $this->refuseWhenEnded($locked);
            $this->refuseFrozenToggle($locked, $enabled);

            if ($prizesChanged && $this->prizesDiffer($locked, $mode, $split, $fixed) && ! $this->canChangeSplit($locked)) {
                throw new TournamentRuleViolation('split_frozen', __('The prizes are part of the rules players signed up under; they cannot change after sign-up closed.'));
            }

            $published = in_array($locked->status, [TournamentStatus::Signup, TournamentStatus::Drawing, TournamentStatus::Running], true);
            $had = $locked->hasPot();
            $wasOpen = $locked->pool_opened_at !== null;
            $fixedMode = $mode === Tournament::PRIZES_FIXED;

            $fill = $enabled ? [
                'pot_source' => Tournament::POT_LEAGUE,
                'prize_mode' => $fixedMode ? Tournament::PRIZES_FIXED : null,
                'prize_fixed' => $fixedMode ? $fixed : null,
                'prize_split' => $fixedMode || $split === Tournament::DEFAULT_SPLIT ? null : $split,
                'prize_target_sats' => $fixedMode || $targetSats === 0 ? null : $targetSats,
            ] : [
                'pot_source' => null, 'prize_mode' => null, 'prize_fixed' => null, 'prize_split' => null, 'prize_target_sats' => null,
                'pool_opened_at' => null,
            ];

            if ($enabled && $published && ! $wasOpen && self::canOpen()) {
                $fill['pool_opened_at'] = now();
            }

            $locked->forceFill($fill)->save();

            // The published rules name the pot and its prizes.
            if ($published && ($prizesChanged || $had !== $enabled || $wasOpen !== ($locked->pool_opened_at !== null))) {
                $this->publisher->republish($locked);
            }

            return $locked;
        });
    }

    /**
     * After sign-up closed the pot is part of the rules players signed up
     * under: it can be neither switched off (and on again with other
     * prizes) nor added.
     *
     * @throws TournamentRuleViolation
     */
    private function refuseFrozenToggle(Tournament $tournament, bool $enabled): void
    {
        if ($enabled !== $tournament->hasPot() && ! $this->canChangeSplit($tournament)) {
            throw new TournamentRuleViolation('pot_frozen', __('The prize pot is part of the rules players signed up under; it cannot be switched on or off after sign-up closed.'));
        }
    }

    /**
     * Whether a pot can open now (at publishing, or when set on a published
     * tournament): the new version of the tournament needs the league key.
     */
    public static function canOpen(): bool
    {
        return LeagueKey::fromConfig() !== null;
    }

    /**
     * @throws TournamentRuleViolation
     */
    public function addSponsor(Tournament $tournament, User $user, string $name, int $pledgedSats, ?UploadedFile $logo): TournamentSponsor
    {
        $this->authorize($tournament, $user);

        if ($tournament->pool_closed_at !== null || in_array($tournament->status, [TournamentStatus::Finished, TournamentStatus::Cancelled], true)) {
            throw new TournamentRuleViolation('closed', __('This tournament has ended; its pool can no longer change.'));
        }

        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if ($name === '' || mb_strlen($name) > 80 || $pledgedSats < 1 || $pledgedSats > (int) config('esports.wallet.max_sats')) {
            throw new TournamentRuleViolation('sponsor', __('A sponsor needs a name (up to 80 characters) and a pledge between 1 and :max sats.', ['max' => PreSeason::formatSats((int) config('esports.wallet.max_sats'))]));
        }

        return TournamentSponsor::query()->create([
            'tournament_id' => $tournament->id,
            'name' => $name,
            'pledged_sats' => $pledgedSats,
            'logo_path' => $logo === null ? null : $this->logos->store($logo),
            'created_by_id' => $user->id,
        ]);
    }

    /**
     * A sponsor without a paid invoice can be removed; a paid one stays (it is in the pot).
     *
     * @throws TournamentRuleViolation
     */
    public function removeSponsor(TournamentSponsor $sponsor, User $user): void
    {
        $this->authorize($sponsor->tournament, $user);

        if ($sponsor->isPaid()) {
            throw new TournamentRuleViolation('sponsor_paid', __('This sponsor has paid into the pool and stays listed.'));
        }

        $sponsor->delete();

        // Logos are named by their content: another sponsor may use the same file.
        if ($sponsor->logo_path !== null && ! TournamentSponsor::query()->where('logo_path', $sponsor->logo_path)->exists()) {
            Storage::disk('public')->delete($sponsor->logo_path);
        }
    }

    /**
     * Mark a sponsor's pledge as paid outside the pot's wallet (user,
     * 2026-10-02: "Rechnung wurde anders gezahlt"): `$sats` count toward the
     * pot like a paid invoice, and the logo shows, but nothing moves in the
     * wallet, so a payout never takes them from it. Who, when and the note
     * are kept on the sponsor and in the moderation log. One mark at a time
     * (undo it to correct it), and only until the payouts are approved.
     *
     * @throws TournamentRuleViolation
     */
    public function markPaidOutside(TournamentSponsor $sponsor, User $user, int $sats, string $note = ''): TournamentSponsor
    {
        $this->authorize($sponsor->tournament, $user);
        $note = mb_substr(trim(preg_replace('/\s+/u', ' ', $note) ?? ''), 0, 200);

        if ($sats < 1 || $sats > (int) config('esports.wallet.max_sats')) {
            throw new TournamentRuleViolation('outside_sats', __('Enter between 1 and :max sats.', ['max' => PreSeason::formatSats((int) config('esports.wallet.max_sats'))]));
        }

        return DB::transaction(function () use ($sponsor, $user, $sats, $note): TournamentSponsor {
            $tournament = Tournament::query()->whereKey($sponsor->tournament_id)->lockForUpdate()->firstOrFail();
            $this->refuseWhenPaidOut($tournament);
            $locked = TournamentSponsor::query()->whereKey($sponsor->id)->lockForUpdate()->firstOrFail();

            if ($locked->paid_outside_sats !== null) {
                throw new TournamentRuleViolation('outside_marked', __('This sponsor is marked as paid outside already. Undo that first to change it.'));
            }

            $locked->forceFill(['paid_outside_sats' => $sats, 'paid_outside_note' => $note === '' ? null : $note, 'paid_outside_by_id' => $user->id, 'paid_outside_at' => now()])->save();
            $this->logSponsor($tournament, $user, $locked, $note === '' ? null : $note, [null, $sats]);

            return $locked;
        });
    }

    /**
     * Undo a sponsor's outside payment, until the payouts are approved.
     *
     * @throws TournamentRuleViolation
     */
    public function undoPaidOutside(TournamentSponsor $sponsor, User $user): TournamentSponsor
    {
        $this->authorize($sponsor->tournament, $user);

        return DB::transaction(function () use ($sponsor, $user): TournamentSponsor {
            $tournament = Tournament::query()->whereKey($sponsor->tournament_id)->lockForUpdate()->firstOrFail();
            $this->refuseWhenPaidOut($tournament);
            $locked = TournamentSponsor::query()->whereKey($sponsor->id)->lockForUpdate()->firstOrFail();

            if ($locked->paid_outside_sats === null) {
                throw new TournamentRuleViolation('outside_unmarked', __('This sponsor is not marked as paid outside.'));
            }

            $was = $locked->paid_outside_sats;
            $locked->forceFill(['paid_outside_sats' => null, 'paid_outside_note' => null, 'paid_outside_by_id' => null, 'paid_outside_at' => null])->save();
            $this->logSponsor($tournament, $user, $locked, null, [$was, null]);

            return $locked;
        });
    }

    /**
     * The sponsors' sats marked as paid outside the pot's wallet: part of
     * the pot, never in the wallet.
     */
    public static function paidOutsideSats(Tournament $tournament): int
    {
        return (int) $tournament->sponsors()->sum('paid_outside_sats');
    }

    /**
     * @throws TournamentRuleViolation
     */
    private function refuseWhenPaidOut(Tournament $tournament): void
    {
        if ($tournament->payouts_approved_at !== null) {
            throw new TournamentRuleViolation('paid_out', __('The payouts of this tournament are approved; its sponsors’ payments can no longer change.'));
        }
    }

    /**
     * @param  array{0: int|null, 1: int|null}  $change  sats paid outside, before and after
     */
    private function logSponsor(Tournament $tournament, User $user, TournamentSponsor $sponsor, ?string $note, array $change): void
    {
        TournamentModerationEntry::query()->create([
            'tournament_id' => $tournament->id,
            'user_id' => $user->id,
            'user_name' => mb_substr($user->displayName(), 0, 80),
            'action' => 'edited',
            'subject' => mb_substr(__('Sponsor :name', ['name' => $sponsor->name]), 0, 80),
            'reason' => $note,
            'details' => ['paid_outside_sats' => $change],
            'created_at' => now(),
        ]);
    }

    /**
     * @param  list<int>  $split
     * @param  list<int>  $fixed
     */
    private function prizesDiffer(Tournament $tournament, string $mode, array $split, array $fixed): bool
    {
        if ($mode !== $tournament->prizeMode()) {
            return true;
        }

        return $mode === Tournament::PRIZES_FIXED ? $fixed !== $tournament->prizeFixed() : $split !== $tournament->prizeSplit();
    }

    /**
     * @throws TournamentRuleViolation
     */
    private function refuseWhenEnded(Tournament $tournament): void
    {
        if (in_array($tournament->status, [TournamentStatus::Finished, TournamentStatus::Cancelled], true) || $tournament->pool_closed_at !== null || $tournament->payouts_approved_at !== null) {
            throw new TournamentRuleViolation('closed', __('This tournament has ended; its pool can no longer change.'));
        }
    }

    /**
     * @throws TournamentRuleViolation
     */
    private function authorize(Tournament $tournament, User $user): void
    {
        if (! Gate::forUser($user)->allows('manage-tournament', $tournament)) {
            throw new TournamentRuleViolation('not_manager', __('Only the organizer of this tournament or an admin can change its prize pool.'));
        }
    }
}
