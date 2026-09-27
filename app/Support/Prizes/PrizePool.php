<?php

namespace App\Support\Prizes;

use App\Enums\IncomingPaymentStatus;
use App\Enums\TournamentStatus;
use App\Models\IncomingPayment;
use App\Models\Tournament;
use App\Models\TournamentSponsor;
use App\Models\User;
use App\Support\Lightning\LightningAddress;
use App\Support\Nostr\NostrKeys;
use App\Support\PreSeason;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Tournaments\TournamentPublisher;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Wallet\NwcConnection;
use App\Support\Wallet\NwcError;
use App\Support\Wallet\ReceivingWallet;
use App\Support\Wallet\RelayGuard;
use App\Support\Wallet\WalletSetup;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * A tournament's prize pool (P9, NIP "Prize pool funding"): what it holds,
 * how it is split, and what its organizer may change.
 *
 * The pool is never a number someone types: it is the sum of the paid
 * invoices attributed to it (incoming_payments, settled before the pool
 * closed). The organizer sets a target (shown as "X of Y"), the split and
 * the sponsors; they cannot credit sats. Admins and the tournament's own
 * organizer (gate `manage-tournament`) manage a pool; paying out is for
 * admins only (App\Support\Payouts\PayoutApproval).
 *
 * The split is part of the rules players sign up under: it can change while
 * the tournament is a draft or open for sign-up, never after sign-up
 * closed. Opening the pool publishes a new 31923 version with the pool
 * key's `zap` tag; it needs the league key, the pool key's pubkey and the
 * receiving wallet connection (fail closed, the page says which is missing).
 */
final class PrizePool
{
    /** Most places a split may name. */
    public const MAX_PLACES = 8;

    /**
     * The split presets of the pot settings; any other split is custom.
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
     * Held back from an own-wallet pot for routing fees (percent, at least
     * WALLET_FEE_MIN sats): that wallet pays its own fees, so the places
     * share what is left. The league's pots take their fees from the reserve.
     */
    public const WALLET_FEE_PERCENT = 1;

    public const WALLET_FEE_MIN = 10;

    /** A balance read older than this is shown as stale (seconds). */
    public const BALANCE_STALE_AFTER = 600;

    public function __construct(private TournamentPublisher $publisher, private SponsorLogos $logos, private RelayGuard $relays) {}

    /**
     * The pool key's pubkey (hex), named by every pot's `zap` tag; null when not set up.
     */
    public static function poolPubkey(): ?string
    {
        $key = config('esports.wallet.pool_npub');

        return is_string($key) && trim($key) !== '' ? NostrKeys::toHex(trim($key)) : null;
    }

    /**
     * Whether the league's own wallet can hold a pot: zaps to the pool key
     * need its receiving connection, its LNURL key and the pool key.
     */
    public static function leagueCanHold(): bool
    {
        return WalletSetup::canReceive() && LeagueKey::lnurl() !== null && self::poolPubkey() !== null;
    }

    /**
     * The pot's sats as the league knows them: the paid invoices of a league
     * pot, the last balance read from an own wallet (null before the first
     * successful read: nothing is shown rather than a guess).
     */
    public function potSats(Tournament $tournament): ?int
    {
        return $tournament->hasOwnWallet() ? $tournament->pot_balance_sats : $this->fundedSats($tournament);
    }

    /**
     * What the places share: the pot, less the fee reserve of an own wallet.
     */
    public function payableSats(Tournament $tournament): ?int
    {
        $pot = $this->potSats($tournament);

        return $pot === null || ! $tournament->hasOwnWallet() ? $pot : self::afterFeeReserve($pot);
    }

    public static function afterFeeReserve(int $sats): int
    {
        return max(0, $sats - max(self::WALLET_FEE_MIN, intdiv($sats * self::WALLET_FEE_PERCENT + 99, 100)));
    }

    /**
     * Whether the last balance read of an own wallet is old or failed since.
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
     * Sats in the pool: its settled invoices, none that came after the close.
     */
    public function fundedSats(Tournament $tournament): int
    {
        return (int) IncomingPayment::query()
            ->where('pot', IncomingPayment::tournamentPot($tournament->id))
            ->where('status', IncomingPaymentStatus::Settled)
            ->where('late', false)
            ->sum('amount_sats');
    }

    /**
     * Whether the league's wallet holds, or may still receive, sats for this
     * pot: a paid invoice, or one that is still unpaid (re-gate 2026-09-27:
     * a zap pending while the pot moved to an own wallet settled into the
     * league's books, where the pot no longer looked). Such a pot stays a
     * league pot.
     */
    public function holdsLeagueZaps(Tournament $tournament): bool
    {
        return IncomingPayment::query()->where('pot', IncomingPayment::tournamentPot($tournament->id))
            ->whereIn('status', [IncomingPaymentStatus::Settled, IncomingPaymentStatus::Pending])->exists();
    }

    public function contributions(Tournament $tournament): int
    {
        return IncomingPayment::query()->where('pot', IncomingPayment::tournamentPot($tournament->id))
            ->where('status', IncomingPaymentStatus::Settled)->where('late', false)->count();
    }

    /**
     * What each place would get from `$poolSats` now, before ties and rosters.
     *
     * @return list<array{place: int, percent: int, sats: int}>
     */
    public function projection(Tournament $tournament, int $poolSats): array
    {
        return array_map(fn (int $percent, int $index): array => ['place' => $index + 1, 'percent' => $percent, 'sats' => intdiv($poolSats * $percent, 100)],
            $tournament->prizeSplit(), array_keys($tournament->prizeSplit()));
    }

    /**
     * Why the split is not valid, or null: 1 to 8 places, whole percents
     * from 1 to 100, first place first (never more for a lower place), 100
     * in total.
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

    public function canChangeSplit(Tournament $tournament): bool
    {
        return $tournament->status === TournamentStatus::Draft || $tournament->isSignupOpen();
    }

    /**
     * What keeps the pool from opening, or null.
     */
    public function openBlocker(Tournament $tournament): ?string
    {
        $league = ! $tournament->hasOwnWallet();
        $reasons = [
            [$tournament->pool_opened_at !== null, 'The pool is open already.'],
            [! in_array($tournament->status, [TournamentStatus::Signup, TournamentStatus::Drawing, TournamentStatus::Running], true), 'A pool opens once the tournament is published and before it ends.'],
            [LeagueKey::fromConfig() === null, 'The league key is not set up, so nothing can be published yet.'],
            [! $league && $tournament->pot_nwc_uri === null, 'The connection of this pot’s own wallet is missing, so nothing can be paid out.'],
            [$league && self::poolPubkey() === null, 'The pool key is not set up (ESPORTS_POOL_NPUB), so nobody could zap the pool.'],
            [$league && (! WalletSetup::canReceive() || LeagueKey::lnurl() === null), 'The league wallet cannot take payments yet (ESPORTS_NWC_RECEIVE_URI, ESPORTS_LNURL_NSEC).'],
        ];

        foreach ($reasons as [$applies, $reason]) {
            if ($applies) {
                return __($reason);
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $split
     *
     * @throws TournamentRuleViolation
     */
    public function saveSettings(Tournament $tournament, User $user, ?int $targetSats, array $split): Tournament
    {
        $this->authorize($tournament, $user);

        if ($targetSats !== null && ($targetSats < 0 || $targetSats > 2_100_000_000_000_000)) {
            throw new TournamentRuleViolation('target', __('The target is a whole number of sats.'));
        }

        if (in_array($tournament->status, [TournamentStatus::Finished, TournamentStatus::Cancelled], true) || $tournament->pool_closed_at !== null) {
            throw new TournamentRuleViolation('closed', __('This tournament has ended; its pool can no longer change.'));
        }

        $split = array_map(intval(...), array_values($split));
        $splitChanged = $split !== $tournament->prizeSplit();

        if ($splitChanged && ! $this->canChangeSplit($tournament)) {
            throw new TournamentRuleViolation('split_frozen', __('The split is part of the rules players signed up under; it cannot change after sign-up closed.'));
        }

        if ($splitChanged && ($error = self::splitError($split)) !== null) {
            throw new TournamentRuleViolation('split', $error);
        }

        return DB::transaction(function () use ($tournament, $targetSats, $split, $splitChanged): Tournament {
            $tournament->forceFill([
                'prize_target_sats' => $targetSats === 0 ? null : $targetSats,
                'prize_split' => $split === Tournament::DEFAULT_SPLIT ? null : $split,
            ])->save();

            // The published rules name the split in words: a new version says the new one.
            if ($splitChanged && $tournament->pool_opened_at !== null) {
                $this->publisher->republish($tournament);
            }

            return $tournament;
        });
    }

    /**
     * @throws TournamentRuleViolation
     */
    public function open(Tournament $tournament, User $user): Tournament
    {
        $this->authorize($tournament, $user);

        if (($blocker = $this->openBlocker($tournament)) !== null) {
            throw new TournamentRuleViolation('pool_blocked', $blocker);
        }

        return DB::transaction(function () use ($tournament): Tournament {
            $locked = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();

            if ($locked->pool_opened_at !== null) {
                return $locked;
            }

            $locked->forceFill(['pool_opened_at' => now(), 'pot_source' => $locked->pot_source ?? Tournament::POT_LEAGUE])->save();

            $this->publisher->republish($locked);

            return $locked;
        });
    }

    /**
     * Check a wallet connection string live, as the pot settings do before
     * saving it: it parses, the wallet answers `get_balance`, and it does not
     * deny `pay_invoice` (the winners are paid from it). The league's own
     * wallet is refused here: that is the "league pot" source. The string is
     * never part of a message.
     *
     * @return array{balance: int, lud16: string|null}
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
                throw new TournamentRuleViolation('pot_league_wallet', __('This is the league’s own wallet. Pick “League pot” instead.'));
            }
        }

        try {
            $balance = $wallet->balanceSats();
        } catch (NwcError $error) {
            throw new TournamentRuleViolation('pot_wallet', $error->isTimeout()
                ? __('The wallet did not answer. Check that it is online and that the connection string is current.')
                : __('The wallet did not tell its balance (:code). Allow “get balance” for this connection.', ['code' => PotBalances::code($error)]));
        }

        if ($wallet->permits('pay_invoice') === false) {
            throw new TournamentRuleViolation('pot_pay', __('This connection may not pay invoices, so the winners could not be paid from it. Allow “pay invoice” for it in the wallet.'));
        }

        parse_str((string) parse_url(trim($uri), PHP_URL_QUERY), $query);
        $lud16 = is_string($query['lud16'] ?? null) && LightningAddress::target($query['lud16']) !== null ? strtolower($query['lud16']) : null;

        return ['balance' => $balance, 'lud16' => $lud16];
    }

    /**
     * The prize pot of the tournament create and edit pages (P9 scope
     * addition): none (`$source` null), the league's pot (zaps to the pool
     * key, the league wallet) or the tournament's own NWC wallet. A new
     * connection string is checked live ({@see checkWallet()}) and stored
     * encrypted; null keeps the stored one. A published tournament's pot is
     * open at once (a league pot once the league wallet can take zaps), and
     * the rules on Nostr get a new version when the pot or its split changed.
     *
     * A pot with a paid or still unpaid league invoice stays a league pot; a split changes only
     * until sign-up closes; after the payouts were approved nothing changes.
     *
     * @param  array<int, mixed>  $split
     *
     * @throws TournamentRuleViolation
     */
    public function configurePot(Tournament $tournament, User $user, ?string $source, #[\SensitiveParameter] ?string $uri, ?int $targetSats, array $split): Tournament
    {
        $this->authorize($tournament, $user);

        if (in_array($tournament->status, [TournamentStatus::Finished, TournamentStatus::Cancelled], true) || $tournament->pool_closed_at !== null || $tournament->payouts_approved_at !== null) {
            throw new TournamentRuleViolation('closed', __('This tournament has ended; its pool can no longer change.'));
        }

        if ($source !== null && ! in_array($source, [Tournament::POT_LEAGUE, Tournament::POT_WALLET], true)) {
            throw new TournamentRuleViolation('pot_source', __('Pick where the pot is held.'));
        }

        if ($targetSats !== null && ($targetSats < 0 || $targetSats > 2_100_000_000_000_000)) {
            throw new TournamentRuleViolation('target', __('The target is a whole number of sats.'));
        }

        $current = $tournament->pot_source ?? ($tournament->pool_opened_at !== null ? Tournament::POT_LEAGUE : null);
        $split = array_map(fn ($value): int => is_numeric($value) ? (int) $value : 0, array_values($split));
        $splitChanged = $source !== null && $split !== $tournament->prizeSplit();

        if ($splitChanged && ! $this->canChangeSplit($tournament)) {
            throw new TournamentRuleViolation('split_frozen', __('The split is part of the rules players signed up under; it cannot change after sign-up closed.'));
        }

        if ($splitChanged && ($error = self::splitError($split)) !== null) {
            throw new TournamentRuleViolation('split', $error);
        }

        if ($current === Tournament::POT_LEAGUE && $source !== Tournament::POT_LEAGUE && $this->holdsLeagueZaps($tournament)) {
            throw new TournamentRuleViolation('pot_funded', __('Zaps into this pot were paid or are still waiting to be paid, so it stays with the league wallet.'));
        }

        if ($source === Tournament::POT_LEAGUE && $current !== Tournament::POT_LEAGUE && ! self::leagueCanHold()) {
            throw new TournamentRuleViolation('pot_league', __('The league wallet cannot take payments yet. Connect the tournament’s own wallet instead.'));
        }

        $uri = is_string($uri) && trim($uri) !== '' ? trim($uri) : null;

        if ($source === Tournament::POT_WALLET && $uri === null && $tournament->pot_nwc_uri === null) {
            throw new TournamentRuleViolation('pot_uri', __('Paste the connection string of the pot’s wallet.'));
        }

        $check = $source === Tournament::POT_WALLET && $uri !== null ? $this->checkWallet($uri) : null;

        return DB::transaction(function () use ($tournament, $source, $uri, $targetSats, $split, $splitChanged, $current, $check): Tournament {
            $locked = Tournament::query()->whereKey($tournament->id)->lockForUpdate()->firstOrFail();

            // The checks above read a row that may have moved during the live wallet check: again, under the lock.
            if (in_array($locked->status, [TournamentStatus::Finished, TournamentStatus::Cancelled], true) || $locked->pool_closed_at !== null || $locked->payouts_approved_at !== null) {
                throw new TournamentRuleViolation('closed', __('This tournament has ended; its pool can no longer change.'));
            }

            if ($splitChanged && ($split !== $locked->prizeSplit()) && ! $this->canChangeSplit($locked)) {
                throw new TournamentRuleViolation('split_frozen', __('The split is part of the rules players signed up under; it cannot change after sign-up closed.'));
            }

            $lockedSource = $locked->pot_source ?? ($locked->pool_opened_at !== null ? Tournament::POT_LEAGUE : null);

            if ($lockedSource === Tournament::POT_LEAGUE && $source !== Tournament::POT_LEAGUE && $this->holdsLeagueZaps($locked)) {
                throw new TournamentRuleViolation('pot_funded', __('Zaps into this pot were paid or are still waiting to be paid, so it stays with the league wallet.'));
            }

            $published = in_array($locked->status, [TournamentStatus::Signup, TournamentStatus::Drawing, TournamentStatus::Running], true);
            $wasOpen = $locked->pool_opened_at !== null;

            $fill = [
                'pot_source' => $source,
                'prize_target_sats' => $source === null || $targetSats === 0 ? null : $targetSats,
                'prize_split' => $source === null || $split === Tournament::DEFAULT_SPLIT ? null : $split,
            ];

            if ($source !== Tournament::POT_WALLET) {
                $fill += ['pot_nwc_uri' => null, 'pot_lud16' => null, 'pot_balance_sats' => null, 'pot_balance_at' => null, 'pot_balance_error' => null];
            } elseif ($check !== null) {
                $fill += ['pot_nwc_uri' => $uri, 'pot_lud16' => $check['lud16'], 'pot_balance_sats' => $check['balance'], 'pot_balance_at' => now(), 'pot_balance_error' => null];
            }

            if ($source === null) {
                $fill['pool_opened_at'] = null;
            } elseif ($published && ! $wasOpen && self::canOpen($source)) {
                $fill['pool_opened_at'] = now();
            }

            $locked->forceFill($fill)->save();

            // The published rules name the pot and its split; the zap tag is the league pot's only.
            if ($published && ($splitChanged || $source !== $current || $wasOpen !== ($locked->pool_opened_at !== null))) {
                $this->publisher->republish($locked);
            }

            return $locked;
        });
    }

    /**
     * Whether a pot of this source can open now (at publishing, or when set
     * on a published tournament).
     */
    public static function canOpen(?string $source): bool
    {
        return match ($source) {
            Tournament::POT_WALLET => LeagueKey::fromConfig() !== null,
            Tournament::POT_LEAGUE => LeagueKey::fromConfig() !== null && self::leagueCanHold(),
            default => false,
        };
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
     * A sponsor without a paid invoice can be removed; a paid one stays (it is in the pool).
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
     * @throws TournamentRuleViolation
     */
    private function authorize(Tournament $tournament, User $user): void
    {
        if (! Gate::forUser($user)->allows('manage-tournament', $tournament)) {
            throw new TournamentRuleViolation('not_manager', __('Only the organizer of this tournament or an admin can change its prize pool.'));
        }
    }
}
