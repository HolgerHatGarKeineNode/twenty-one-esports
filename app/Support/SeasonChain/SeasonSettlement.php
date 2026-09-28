<?php

namespace App\Support\SeasonChain;

use App\Enums\NotificationKind;
use App\Enums\PayoutStatus;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\SeasonBlockVoid;
use App\Models\SeasonPayout;
use App\Models\User;
use App\Support\Badges\BadgeCopy;
use App\Support\Board;
use App\Support\FairPlay\AccountLinks;
use App\Support\FairPlay\FairPlay;
use App\Support\Lightning\LightningAddress;
use App\Support\Notifications\Notice;
use App\Support\Notifications\Notifier;
use App\Support\PreSeason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * The season settlement (P37; NIP "Review and corrections", "Payout",
 * "Season end"), after a season's `ends`:
 *
 * 1. **Review** ({@see review()}): the chain is replayed from its stored
 *    attestations, as /mining replays it (SeasonChains::chain()), with the
 *    review's voids applied; per player the rewards of their blocks minus
 *    the voided ones. No fees: players never pay fees (user, 2026-09-28),
 *    so Settlement is given none.
 * 2. **Corrections** ({@see void()}): an admin voids a block with a public
 *    reason; the league signs a `void-block` label (1985). Only until the
 *    list is approved (NIP: a label counts only before the first payout).
 * 3. **Approval** ({@see approve()}): an admin approves the list; one
 *    payout per player with sats is written with its fixed key, to the
 *    Lightning address the player's profile shows then. Nothing is paid.
 * 4. **Payment**: per admin click, through PayoutRunner and the league
 *    wallet's paying connection. No balance check first (user,
 *    2026-09-28): a payment the wallet cannot make fails with "top up the
 *    payout wallet" and is retried.
 *
 * Address rules: a payout waits (`open`) without a valid address
 * (`no_lud16`), for a linked second account (P41, withheld), and while the
 * profile's address changed less than {@see FREEZE_HOURS} h ago
 * (`lud16_frozen`, user 2026-09-28). An admin approves a new address
 * ({@see approveAddress()}) once its freeze has passed. The address is
 * never shown as text: the page shows whether there is one, and an
 * approval names it by {@see fingerprint()}.
 *
 * Claim window (NIP "Payout"): a payout still waiting for an address
 * `claim` seconds after the season's first paid payout is over; its sats
 * stay in the reserve. A payout that has an approved address but failed at
 * the wallet is the league's to retry and does not run out.
 *
 * Voiding a block and approving the list are the board's (the public
 * admin list, Board), like the other chain actions (P39); approving an
 * address and paying are any admin's (gate `admin`), as for tournament
 * payouts (PayoutApproval).
 * Fail closed: without the league key nothing is voided or approved, and a
 * replay that differs from the stored chain blocks the approval.
 */
final class SeasonSettlement
{
    /** How long a payout waits after the profile's Lightning address changed (user, 2026-09-28). */
    public const FREEZE_HOURS = 72;

    /** The public reason of a void. */
    public const REASON_MAX = 500;

    public const VOID_LABEL = 'void-block';

    public function __construct(private SeasonChains $chains, private Notifier $notifier) {}

    /** The replayed chain of a season with the review's voids applied. */
    public function chain(Season $season): BlockChain
    {
        $chain = $this->chains->chain($season);

        foreach ($season->blockVoids()->orderBy('height')->get() as $void) {
            try {
                $chain->void($void->height, $void->reason);
            } catch (ChainViolation) {
                // A void of a block the replay does not have: reported by mismatch(), never applied.
            }
        }

        return $chain;
    }

    /**
     * Who gets how much: per player the blocks, the mined sats, the voided
     * sats and the payout, with whether their profile has a valid Lightning
     * address (never the address itself).
     *
     * @return array{rows: list<array{pubkey: string, user: User|null, name: string, blocks: int, voided_blocks: int, mined: int, voided: int, payout: int, heights: list<int>, has_address: bool, linked: bool}>, blocks: int, mined: int, voided: int, payout: int, voids: Collection<int, SeasonBlockVoid>, mismatch: string|null}
     */
    public function review(Season $season): array
    {
        $chain = $this->chain($season);
        // Players never pay fees (user, 2026-09-28): the settlement is given no fee receipt at all.
        $settlement = Settlement::compute($chain, []);
        $heights = [];
        $voidedBlocks = [];

        foreach ($chain->blocks() as $block) {
            foreach ($block->candidate->winners as $pubkey) {
                if ($chain->isVoided($block->height)) {
                    $voidedBlocks[$pubkey] = ($voidedBlocks[$pubkey] ?? 0) + 1;
                } else {
                    $heights[$pubkey][] = $block->height;
                }
            }
        }

        $users = User::query()->whereIn('pubkey', array_keys($settlement['players']))->get()->keyBy('pubkey');
        $rows = [];

        foreach ($settlement['players'] as $pubkey => $player) {
            $user = $users->get($pubkey);
            $rows[] = [
                'pubkey' => (string) $pubkey,
                'user' => $user,
                'name' => $user?->displayName() ?? substr((string) $pubkey, 0, 8),
                'blocks' => $player['blocks'],
                'voided_blocks' => $voidedBlocks[$pubkey] ?? 0,
                'mined' => $player['subsidy'],
                'voided' => $player['voided'],
                'payout' => $player['payout'],
                'heights' => $heights[$pubkey] ?? [],
                'has_address' => self::address($user) !== null,
                'linked' => FairPlay::isLinked((string) $pubkey),
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$b['payout'], $a['name']] <=> [$a['payout'], $b['name']]);

        return [
            'rows' => $rows,
            'blocks' => count($chain->blocks()),
            'mined' => $chain->mined(),
            'voided' => array_sum(array_column($rows, 'voided')),
            'payout' => array_sum(array_column($rows, 'payout')),
            'voids' => $season->blockVoids()->with('voidedBy')->orderBy('height')->get(),
            'mismatch' => $this->mismatch($season, $chain),
        ];
    }

    /**
     * Where the replay differs from the stored chain (a height the replay
     * gives another result, or does not give at all), or null. A settlement
     * on such a chain would pay what nobody can check.
     */
    public function mismatch(Season $season, BlockChain $chain): ?string
    {
        $stored = $season->attestations()->whereNotNull('height')->orderBy('height')->pluck('label', 'height')->all();
        $replayed = [];

        foreach ($chain->blocks() as $block) {
            $replayed[$block->height] = $block->candidate->label;
        }

        foreach ($stored + $replayed as $height => $label) {
            if (($stored[$height] ?? null) !== ($replayed[$height] ?? null)) {
                return __('The replay of the chain differs from the stored blocks at block :height. Nothing can be settled until that is checked.', ['height' => $height]);
            }
        }

        foreach ($season->blockVoids()->pluck('height') as $height) {
            if (! isset($replayed[$height])) {
                return __('A correction names block :height, which the replay of the chain does not have.', ['height' => $height]);
            }
        }

        return null;
    }

    /** Whether the season is past its end and not settled yet: the review may still void blocks. */
    public static function reviewOpen(Season $season): bool
    {
        return $season->ends_at->getTimestamp() <= now()->getTimestamp() && $season->settlement_approved_at === null;
    }

    /**
     * An admin voids block `$height` of an ended season for a public reason
     * (NIP "Review and corrections"): the league signs a `void-block` label
     * (1985) with the block's attestation as `e` and the reason as content.
     *
     * @throws SeasonSettlementRefused
     */
    public function void(Season $season, User $admin, int $height, string $reason): SeasonBlockVoid
    {
        self::assertBoard($admin);
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw new SeasonSettlementRefused(__('Say why the block is void, up to :max characters. The reason is published with the correction.', ['max' => self::REASON_MAX]));
        }

        $league = LeagueKey::fromConfig() ?? throw new SeasonSettlementRefused(__('The league key is not set up, so nothing can be published yet.'));

        return DB::transaction(function () use ($season, $admin, $height, $reason, $league): SeasonBlockVoid {
            $locked = Season::query()->whereKey($season->id)->lockForUpdate()->firstOrFail();

            if (! self::reviewOpen($locked)) {
                throw new SeasonSettlementRefused($locked->settlement_approved_at !== null
                    ? __('The settlement list is approved: the review is closed and no block can be voided any more.')
                    : __('Blocks are voided in the review, after the season has ended.'));
            }

            $chain = $this->chain($locked);
            $attestation = SeasonAttestation::query()->where('season_id', $locked->id)->where('height', $height)->first();

            if ($attestation === null || ! isset($chain->blocks()[$height - 1])) {
                throw new SeasonSettlementRefused(__('This season has no block :height.', ['height' => $height]));
            }

            if ($chain->isVoided($height)) {
                throw new SeasonSettlementRefused(__('Block :height is void already.', ['height' => $height]));
            }

            $relay = (string) (config('esports.relays')[0] ?? '');
            $event = $league->publish(1985, [
                ['L', SeasonRelease::NAMESPACE],
                ['l', self::VOID_LABEL, SeasonRelease::NAMESPACE],
                ['e', $attestation->event_id, $relay],
                ['alt', 'Label: '.$locked->slug.' review voids block '.$height],
            ], $reason, now()->getTimestamp());

            return SeasonBlockVoid::query()->create([
                'season_id' => $locked->id,
                'height' => $height,
                'season_attestation_id' => $attestation->id,
                'reason' => $reason,
                'voided_by_id' => $admin->id,
                'voided_by_pubkey' => $admin->pubkey,
                'nostr_event_id' => $event->id,
            ]);
        });
    }

    /**
     * What keeps the admin from approving the list, or null.
     */
    public function blocker(Season $season): ?string
    {
        if ($season->settlement_approved_at !== null) {
            return __('The settlement list of this season is approved already.');
        }

        if ($season->ends_at->getTimestamp() > now()->getTimestamp()) {
            return __('The list is approved after the season has ended.');
        }

        if (LeagueKey::fromConfig() === null) {
            return __('The league key is not set up, so nothing can be published yet.');
        }

        return $this->mismatch($season, $this->chain($season));
    }

    /**
     * An admin approves the list: one payout per player with sats, with its
     * fixed key. Nothing is paid here. Approving twice changes nothing.
     * Players without a valid address hear it (NotificationKind::SeasonPayout).
     *
     * @throws SeasonSettlementRefused
     */
    public function approve(Season $season, User $admin): Season
    {
        self::assertBoard($admin);

        if (($blocker = $this->blocker($season)) !== null) {
            throw new SeasonSettlementRefused($blocker);
        }

        /** @var list<array{user: User, sats: int}> $missing */
        $missing = [];

        $approved = DB::transaction(function () use ($season, $admin, &$missing): Season {
            $locked = Season::query()->whereKey($season->id)->lockForUpdate()->firstOrFail();

            if ($locked->settlement_approved_at !== null) {
                return $locked;
            }

            $locked->forceFill(['settlement_approved_at' => now(), 'settlement_approved_by_id' => $admin->id])->save();

            foreach ($this->review($locked)['rows'] as $row) {
                if ($row['payout'] <= 0) {
                    continue;
                }

                $user = $row['user'];
                $address = self::address($user);
                [$status, $reason] = match (true) {
                    $row['linked'] => [PayoutStatus::Open, AccountLinks::WITHHELD],
                    $user === null => [PayoutStatus::Open, 'account_deleted'],
                    $address === null => [PayoutStatus::Open, 'no_lud16'],
                    self::addressFrozen($user) => [PayoutStatus::Open, 'lud16_frozen'],
                    default => [PayoutStatus::Pending, null],
                };

                $payout = SeasonPayout::query()->firstOrCreate(['idempotency_key' => SeasonPayout::keyFor($locked->id, $row['pubkey'])], [
                    'season_id' => $locked->id,
                    'user_id' => $user?->id,
                    'pubkey' => $row['pubkey'],
                    'name' => $row['name'],
                    'blocks' => count($row['heights']),
                    'heights' => $row['heights'],
                    'amount_sats' => $row['payout'],
                    'lud16' => $status === PayoutStatus::Pending ? $address : null,
                    'status' => $status,
                    'reason' => $reason,
                ]);

                if ($payout->wasRecentlyCreated && $reason === 'no_lud16' && $user !== null) {
                    $missing[] = ['user' => $user, 'sats' => $row['payout']];
                }
            }

            return $locked;
        });

        foreach ($missing as $player) {
            $this->tellMissingAddress($approved, $player['user'], $player['sats']);
        }

        return $approved;
    }

    /**
     * An admin approves the address a player's profile shows now for a
     * waiting payout. `$seen` is the {@see fingerprint()} of the address the
     * page showed; if the profile names another one by now, nothing is
     * approved. Refused while the address is frozen, for a withheld payout
     * and after the claim window. Nothing is paid here.
     *
     * @throws SeasonSettlementRefused
     */
    public function approveAddress(SeasonPayout $payout, User $admin, string $seen): SeasonPayout
    {
        self::assertAdmin($admin);
        $user = $payout->user;
        $address = self::address($user);

        if ($payout->reason === AccountLinks::WITHHELD || FairPlay::isLinked($payout->pubkey)) {
            throw new SeasonSettlementRefused(__('This prize is withheld: the account is linked to another account of the same player. Unlink it first if the link was wrong.'));
        }

        if ($payout->status !== PayoutStatus::Open || $address === null) {
            throw new SeasonSettlementRefused(__('This payout has no new Lightning address to approve.'));
        }

        if (self::claimExpired($payout)) {
            throw new SeasonSettlementRefused(__('The claim window of this season is over: these sats stay in the league reserve.'));
        }

        if ($user !== null && self::addressFrozen($user)) {
            throw new SeasonSettlementRefused(__('The Lightning address changed less than 72 hours ago. It can be approved from :when.', ['when' => self::frozenUntil($user)?->setTimezone(PreSeason::timezoneFor($admin))->format('Y-m-d H:i')]));
        }

        if (! hash_equals(self::fingerprint($address), strtolower(trim($seen)))) {
            throw new SeasonSettlementRefused(__('The player’s Lightning address changed again. Check the new one and approve it.'));
        }

        SeasonPayout::query()->whereKey($payout->id)->where('status', PayoutStatus::Open)
            ->update(['status' => PayoutStatus::Pending, 'reason' => null, 'lud16' => $address, 'bolt11' => null, 'payment_hash' => null]);

        return $payout->refresh();
    }

    /**
     * The end of the claim window: `claim` seconds after the season's first
     * paid payout; null while none is paid.
     */
    public static function claimEndsAt(Season $season): ?CarbonImmutable
    {
        $first = $season->payouts()->whereNotNull('paid_at')->min('paid_at');

        return $first === null ? null : CarbonImmutable::parse((string) $first)->addSeconds($season->claim_seconds);
    }

    /** A payout that still waits for its address after the claim window: its sats stay in the reserve. */
    public static function claimExpired(SeasonPayout $payout): bool
    {
        $ends = self::claimEndsAt($payout->season);

        return $payout->status === PayoutStatus::Open && $ends !== null && $ends->getTimestamp() <= now()->getTimestamp();
    }

    /** Whether the player's Lightning address changed less than FREEZE_HOURS ago. */
    public static function addressFrozen(?User $user): bool
    {
        $until = $user === null ? null : self::frozenUntil($user);

        return $until !== null && $until->getTimestamp() > now()->getTimestamp();
    }

    /** When the freeze after the last address change ends, or null without a change. */
    public static function frozenUntil(User $user): ?CarbonImmutable
    {
        return $user->lud16_changed_at === null ? null : CarbonImmutable::instance($user->lud16_changed_at)->addHours(self::FREEZE_HOURS);
    }

    /** The player's valid Lightning address, normalized, or null. Never shown on a page. */
    public static function address(?User $user): ?string
    {
        $lud16 = $user?->lud16;

        return is_string($lud16) && LightningAddress::target($lud16) !== null ? strtolower($lud16) : null;
    }

    /** How a page names an address without showing it: the start of its SHA-256. */
    public static function fingerprint(string $address): string
    {
        return substr(hash('sha256', strtolower(trim($address))), 0, 16);
    }

    /**
     * The paid season payouts, newest first: what /mining lists. Amounts
     * paid only, never a wallet balance.
     *
     * @return Collection<int, SeasonPayout>
     */
    public static function paid(Season $season): Collection
    {
        return $season->payouts()->with(['user', 'event'])->where('status', PayoutStatus::Paid)->latest('paid_at')->orderByDesc('id')->get();
    }

    private function tellMissingAddress(Season $season, User $user, int $sats): void
    {
        $locale = $user->locale ?? (string) config('app.locale');

        try {
            $this->notifier->send($user, NotificationKind::SeasonPayout, new Notice(
                __('Add a Lightning address to get your season sats', [], $locale),
                __(':sats sats from the :season wait for you. Add a Lightning address to your Nostr profile; they wait :days days after the first season payout.', [
                    'sats' => PreSeason::formatSats($sats), 'season' => $season->slug === 'pre-season' ? __('Pre-Season', [], $locale) : BadgeCopy::season($season->slug), 'days' => intdiv($season->claim_seconds, 86400),
                ], $locale),
                route('mining').'#season-payouts',
                null,
                __('View', [], $locale),
            ));
        } catch (Throwable $exception) {
            // A notice that fails never undoes the approval.
            report($exception);
        }
    }

    /** @throws SeasonSettlementRefused */
    private static function assertBoard(User $admin): void
    {
        if (! Board::contains($admin->pubkey)) {
            throw new SeasonSettlementRefused(__('Only a board member on the public admin list can void blocks and approve the settlement list.'));
        }
    }

    /** @throws SeasonSettlementRefused */
    private static function assertAdmin(User $admin): void
    {
        if (! Gate::forUser($admin)->allows('admin')) {
            throw new SeasonSettlementRefused(__('Only an admin can settle a season.'));
        }
    }
}
