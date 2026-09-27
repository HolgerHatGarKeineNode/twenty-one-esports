<?php

namespace App\Support\Prizes;

use App\Enums\PayoutStatus;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentSponsor;
use App\Models\User;
use App\Support\Lightning\LightningAddress;
use App\Support\PreSeason;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Tournaments\TournamentPublisher;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Wallet\NwcConnection;
use App\Support\Wallet\NwcError;
use App\Support\Wallet\ReceivingWallet;
use App\Support\Wallet\RelayGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * A tournament's prize pot (P9, NIP "Prize pool funding"): what it holds,
 * what each place wins, and what its organizer may change.
 *
 * A pot is always the tournament's own NWC wallet (user, 2026-09-27: "Jedes
 * Turnier bekommt seine eigene NWC und eigenen Pot. niemals einen fremden
 * oder von der Season"). Its sats are that wallet's balance, read over
 * NIP-47 ({@see PotBalances}); the league's wallet and ledger belong to the
 * Season-Chain and are never touched here. The organizer or an admin
 * (gate `manage-tournament`) connects the wallet and sets the prizes;
 * paying out is for admins only (App\Support\Payouts\PayoutApproval).
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

    /** A balance read older than this is shown as stale (seconds). */
    public const BALANCE_STALE_AFTER = 600;

    public function __construct(private TournamentPublisher $publisher, private SponsorLogos $logos, private RelayGuard $relays) {}

    /**
     * The pot's sats: the last balance read from its wallet (null without a
     * pot, or before the first successful read: nothing is shown rather
     * than a guess).
     */
    public function potSats(Tournament $tournament): ?int
    {
        if (! $tournament->hasOwnWallet()) {
            return null;
        }

        // The pot as the tournament sets it, not the wallet's balance (user,
        // 2026-09-28: „nicht die Zahl nehmen, die in der Wallet als Balance
        // ist, sondern den Pot, wie er im Turnier eingestellt ist“): the
        // fixed prizes' sum, else the target; the balance only without either.
        if ($tournament->prizeMode() === Tournament::PRIZES_FIXED) {
            return self::fixedTotal($tournament);
        }

        return $tournament->prize_target_sats ?? $tournament->pot_balance_sats;
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
     * reserve. Null in percent mode (any balance splits). A warning on the
     * pot form and the payouts page when the balance is short, never a
     * block: the admin is responsible (user, 2026-09-27).
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
     * What the places share at the check, from a balance of `$balance`:
     * percent mode splits the balance less the fee reserve; fixed mode pays
     * exactly the fixed sum, whatever the balance (a short balance is a
     * warning; a payment the wallet cannot make fails and can be retried).
     */
    public static function payable(Tournament $tournament, int $balance): int
    {
        return self::requiredSats($tournament) === null ? self::afterFeeReserve($balance) : self::fixedTotal($tournament);
    }

    /**
     * How many sats `$balance` lacks for the fixed prizes and their fee
     * reserve; 0 when it covers them, and always 0 in percent mode.
     */
    public static function shortfall(Tournament $tournament, int $balance): int
    {
        return max(0, (int) self::requiredSats($tournament) - $balance);
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
     * without one); `have` what the pot can pay towards it (the balance less
     * the fee reserve); `leftover` what stays in the wallet after fixed
     * prizes and their reserve.
     *
     * @return array{goal: int|null, have: int, funded: bool, leftover: int|null}
     */
    public function funding(Tournament $tournament): array
    {
        $balance = (int) $tournament->pot_balance_sats;

        if ($tournament->prizeMode() === Tournament::PRIZES_FIXED) {
            $total = self::fixedTotal($tournament);
            $required = (int) self::requiredSats($tournament);

            return ['goal' => $total, 'have' => max(0, $balance - self::feeReserve($total)), 'funded' => $balance >= $required, 'leftover' => max(0, $balance - $required)];
        }

        $target = $tournament->prize_target_sats;

        return ['goal' => $target, 'have' => $balance, 'funded' => $target !== null && $balance >= $target, 'leftover' => null];
    }

    /**
     * Whether the last balance read is old or failed since.
     */
    public static function isBalanceStale(Tournament $tournament): bool
    {
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
     * Check a wallet connection string live, as the pot settings do before
     * saving it: its relays are public `wss://` hosts ({@see RelayGuard}),
     * the wallet answers `get_balance`, and it does not deny `pay_invoice`
     * (the winners are paid from it). Whether it may `make_invoice` decides
     * whether anyone can add sats through the tournament page. The league's
     * own wallet is refused: it belongs to the Season-Chain. Several
     * tournaments may share one wallet (user, 2026-09-27): the organizer caps
     * each connection's budget in the wallet or uses a sub-wallet, and the
     * balance is a visual check only. The string is never part of a message.
     *
     * @return array{balance: int, lud16: string|null, can_receive: bool, receive_missing: list<string>|null}
     *
     * @throws TournamentRuleViolation
     */
    public function checkWallet(#[\SensitiveParameter] string $uri): array
    {
        $connection = NwcConnection::fromUri($uri);
        $wallet = ReceivingWallet::fromUri($uri);

        if ($connection === null || $wallet === null) {
            throw new TournamentRuleViolation('pot_uri', __('That is not a wallet connection string. It starts with nostr+walletconnect:// and names a relay and a secret.'));
        }

        // Its relays make the server connect: checked before anything is sent (security gate F1).
        foreach ($connection->relays as $relay) {
            if ($this->relays->target($relay) === null) {
                throw new TournamentRuleViolation('pot_relay', __('The relay of this connection string cannot be used: it has to be a wss:// address on a public host name.'));
            }
        }

        foreach ([config('esports.wallet.nwc_uri'), config('esports.wallet.nwc_receive_uri')] as $league) {
            if (NwcConnection::fromUri($league)?->walletPubkey === $connection->walletPubkey) {
                throw new TournamentRuleViolation('pot_league_wallet', __('This is the league’s own wallet. A tournament pot needs a wallet of its own.'));
            }
        }

        try {
            $balance = $wallet->balanceSats();
        } catch (NwcError $error) {
            throw new TournamentRuleViolation('pot_wallet', $error->isTimeout()
                ? __('The wallet did not answer. Check that it is online and that the connection string is current.')
                : __('The wallet did not tell its balance (:code). Allow “get balance” for this connection.', ['code' => PotBalances::code($error)]));
        }

        $methods = $wallet->methods();

        if ($methods !== null && ! in_array('pay_invoice', $methods, true)) {
            throw new TournamentRuleViolation('pot_pay', __('This connection may not pay invoices, so the winners could not be paid from it. Allow “pay invoice” for it in the wallet.'));
        }

        parse_str((string) parse_url(trim($uri), PHP_URL_QUERY), $query);
        $lud16 = is_string($query['lud16'] ?? null) && LightningAddress::target($query['lud16']) !== null ? strtolower($query['lud16']) : null;

        $missing = $methods === null ? null : array_values(array_diff(['make_invoice', 'lookup_invoice'], $methods));

        return ['balance' => $balance, 'lud16' => $lud16, 'can_receive' => $missing === [], 'receive_missing' => $missing];
    }

    /**
     * The prize pot of the tournament create and edit pages and the pool
     * page: off (`$enabled` false) or the tournament's own wallet with its
     * prizes. A new connection string is checked live ({@see checkWallet()})
     * and stored encrypted; null keeps the stored one. A published
     * tournament's pot is open at once, and the rules on Nostr get a new
     * version when the pot or its prizes changed.
     *
     * The prizes (mode, split, fixed amounts) change only until sign-up
     * closes; after the payouts were approved nothing changes.
     *
     * @param  array<int, mixed>  $split  percents per place (percent mode)
     * @param  array<int, mixed>  $fixed  sats per place (fixed mode)
     * @param  array{balance: int, lud16: string|null, can_receive: bool, receive_missing?: list<string>|null}|null  $checked  the result of {@see checkWallet()} for this very
     *                                                                                                                         `$uri`, when the caller ran it before a transaction of its own (a
     *                                                                                                                         wallet call inside one holds SQLite's write lock, re-gate O1)
     *
     * @throws TournamentRuleViolation
     */
    public function configurePot(Tournament $tournament, User $user, bool $enabled, #[\SensitiveParameter] ?string $uri, ?int $targetSats, string $mode, array $split, array $fixed = [], ?array $checked = null): Tournament
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

        $uri = is_string($uri) && trim($uri) !== '' ? trim($uri) : null;

        if ($enabled && $uri === null && $tournament->pot_nwc_uri === null) {
            throw new TournamentRuleViolation('pot_uri', __('Paste the connection string of the pot’s wallet.'));
        }

        $check = $enabled && $uri !== null ? ($checked ?? $this->checkWallet($uri)) : null;

        return DB::transaction(function () use ($tournament, $enabled, $uri, $targetSats, $mode, $split, $fixed, $prizesChanged, $check): Tournament {
            $locked = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();

            // The checks above read a row that may have moved during the live wallet check: again, under the lock.
            $this->refuseWhenEnded($locked);
            $this->refuseFrozenToggle($locked, $enabled);

            if ($prizesChanged && $this->prizesDiffer($locked, $mode, $split, $fixed) && ! $this->canChangeSplit($locked)) {
                throw new TournamentRuleViolation('split_frozen', __('The prizes are part of the rules players signed up under; they cannot change after sign-up closed.'));
            }

            $published = in_array($locked->status, [TournamentStatus::Signup, TournamentStatus::Drawing, TournamentStatus::Running], true);
            $had = $locked->hasOwnWallet();
            $wasOpen = $locked->pool_opened_at !== null;
            $fixedMode = $mode === Tournament::PRIZES_FIXED;

            $fill = $enabled ? [
                'pot_source' => Tournament::POT_WALLET,
                'prize_mode' => $fixedMode ? Tournament::PRIZES_FIXED : null,
                'prize_fixed' => $fixedMode ? $fixed : null,
                'prize_split' => $fixedMode || $split === Tournament::DEFAULT_SPLIT ? null : $split,
                'prize_target_sats' => $fixedMode || $targetSats === 0 ? null : $targetSats,
            ] : [
                'pot_source' => null, 'prize_mode' => null, 'prize_fixed' => null, 'prize_split' => null, 'prize_target_sats' => null,
                'pot_nwc_uri' => null, 'pot_lud16' => null, 'pot_balance_sats' => null, 'pot_balance_at' => null, 'pot_balance_error' => null,
                'pot_can_receive' => null, 'pool_opened_at' => null,
            ];

            if ($check !== null) {
                $fill += ['pot_nwc_uri' => $uri, 'pot_lud16' => $check['lud16'], 'pot_balance_sats' => $check['balance'], 'pot_balance_at' => now(),
                    'pot_balance_error' => null, 'pot_can_receive' => $check['can_receive']];
            }

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
     * prizes) nor added. Its wallet can still be replaced.
     *
     * @throws TournamentRuleViolation
     */
    private function refuseFrozenToggle(Tournament $tournament, bool $enabled): void
    {
        if ($enabled !== $tournament->hasOwnWallet() && ! $this->canChangeSplit($tournament)) {
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
