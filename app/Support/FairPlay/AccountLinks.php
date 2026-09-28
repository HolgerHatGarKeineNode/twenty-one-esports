<?php

namespace App\Support\FairPlay;

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Enums\PayoutStatus;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Models\AccountLink;
use App\Models\ChessGame;
use App\Models\FairPlayVoid;
use App\Models\SeriesMatch;
use App\Models\TournamentMatch;
use App\Models\TournamentPayout;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Rating\RatingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Multi-accounts (P41, user decision 2026-09-28): an admin links the
 * accounts of one person and picks the main account. Nothing is detected
 * automatically.
 *
 * Linking an account to a main account:
 * - bars it from rated play ({@see FairPlay}, read by the trust gate) and
 *   from prizes: payout planning skips it (PayoutPlan), and its unpaid
 *   payouts (`open`, `pending`, `failed`) are withheld for an admin's
 *   review: `open` with reason {@see self::WITHHELD}, never paid and never
 *   moved to another player. A payout being paid at that moment is left to
 *   the payout runner, which refuses to start a withheld one;
 * - voids every finished result between it and the other accounts of the
 *   person: a series gets resolution `void` (no winner), a chess game ends
 *   `voided` without a result, and a tournament match they played is
 *   marked `void` in its stored result (the bracket stays as played). Rated
 *   Elo of the open season goes back through the rating correction
 *   ({@see RatingService::revert()}, delta only); casual Elo and a closed
 *   season stay as they are. Each voided result is kept with what it was
 *   and the Elo taken back ({@see FairPlayVoid}).
 *
 * Unlinking restores rated play and prizes (withheld payouts return to
 * where they can be paid again, the runner checks the address as always).
 * Voided results stay voided: unlinking does not re-rate them, and there is
 * no automatic way back; an admin who finds a result valid after all has
 * to correct it by hand.
 *
 * Audited: the link row keeps who linked, when and why, and who unlinked,
 * when and why; it is never deleted. Admins only, checked here (a direct
 * call cannot skip it), and never about their own accounts.
 */
final class AccountLinks
{
    public const REASON_MAX = 280;

    /** The payout reason of a prize withheld because its account is linked. */
    public const WITHHELD = 'linked_account';

    /** The public reason stored on a voided series. */
    public const VOID_REASON = 'Voided by the league: two accounts of the same player played each other.';

    public function __construct(private RatingService $ratings) {}

    /**
     * Link `$linkedKey` to the main account `$mainKey` (npub or hex each).
     *
     * @return array{link: AccountLink, voided: int, withheld: int}
     *
     * @throws FairPlayRefused
     */
    public function link(User $admin, string $mainKey, string $linkedKey, string $reason): array
    {
        $this->assertAdmin($admin);
        $reason = $this->reason($reason);
        $main = $this->account($mainKey);
        $linked = $this->account($linkedKey);

        if ($main->id === $linked->id) {
            throw new FairPlayRefused(__('Pick two different accounts.'));
        }

        return DB::transaction(function () use ($admin, $main, $linked, $reason): array {
            // One decision at a time for these accounts: the checks below read under the lock.
            User::query()->whereKey([$main->id, $linked->id])->orderBy('id')->lockForUpdate()->get();

            if (AccountLink::query()->active()->where('linked_pubkey', $linked->pubkey)->exists()) {
                throw new FairPlayRefused(__(':name is linked already. Unlink it first to link it to another account.', ['name' => $linked->displayName()]));
            }

            if (AccountLink::query()->active()->where('main_pubkey', $linked->pubkey)->exists()) {
                throw new FairPlayRefused(__(':name is the main account of other accounts. Pick it as the main account instead.', ['name' => $linked->displayName()]));
            }

            $mainOf = AccountLink::query()->active()->where('linked_pubkey', $main->pubkey)->first();

            if ($mainOf !== null) {
                throw new FairPlayRefused(__(':name is itself linked to another main account. Link to that main account instead.', ['name' => $main->displayName()]));
            }

            $this->assertNotOwn($admin, [$main, $linked, ...$this->group($main)]);

            $link = AccountLink::query()->create([
                'main_user_id' => $main->id,
                'main_pubkey' => $main->pubkey,
                'linked_user_id' => $linked->id,
                'linked_pubkey' => $linked->pubkey,
                'linked_by_id' => $admin->id,
                'linked_by_pubkey' => $admin->pubkey,
                'reason' => $reason,
            ]);

            $others = array_values(array_filter(array_map(fn (User $user): int => $user->id, [$main, ...$this->group($main)]), fn (int $id): bool => $id !== $linked->id));
            $voided = $this->voidSeries($link, $admin, $linked->id, $others) + $this->voidChess($link, $linked->id, $others);

            $withheld = TournamentPayout::query()->where('pubkey', $linked->pubkey)
                ->whereIn('status', [PayoutStatus::Open, PayoutStatus::Pending, PayoutStatus::Failed])
                ->update(['status' => PayoutStatus::Open, 'reason' => self::WITHHELD]);

            return ['link' => $link, 'voided' => $voided, 'withheld' => $withheld];
        }, 3);
    }

    /**
     * Undo a link: rated play and prizes come back, voided results stay void.
     *
     * @return int the payouts no longer withheld
     *
     * @throws FairPlayRefused
     */
    public function unlink(User $admin, AccountLink $link, string $reason): int
    {
        $this->assertAdmin($admin);
        $reason = $this->reason($reason);
        $users = array_values(User::query()->whereIn('pubkey', [$link->main_pubkey, $link->linked_pubkey])->get()->all());
        $this->assertNotOwn($admin, $users);

        return DB::transaction(function () use ($admin, $link, $reason): int {
            $unlinked = AccountLink::query()->whereKey($link->id)->whereNull('unlinked_at')->update([
                'unlinked_at' => now(),
                'unlinked_by_id' => $admin->id,
                'unlinked_by_pubkey' => $admin->pubkey,
                'unlink_reason' => $reason,
            ]);

            if ($unlinked !== 1) {
                throw new FairPlayRefused(__('This link was undone already.'));
            }

            // Back to where a payout can go on: an approved address is paid (the runner re-checks it), none waits for one.
            $withheld = fn () => TournamentPayout::query()->where('pubkey', $link->linked_pubkey)->where('status', PayoutStatus::Open)->where('reason', self::WITHHELD);

            return $withheld()->whereNull('lud16')->update(['reason' => 'no_lud16'])
                + $withheld()->whereNotNull('lud16')->update(['status' => PayoutStatus::Pending, 'reason' => null]);
        }, 3);
    }

    /**
     * The accounts linked to this main account now.
     *
     * @return list<User>
     */
    public function group(User $main): array
    {
        $ids = AccountLink::query()->active()->where('main_pubkey', $main->pubkey)->whereNotNull('linked_user_id')->pluck('linked_user_id')->all();

        return array_values(User::query()->whereKey($ids)->orderBy('id')->get()->all());
    }

    /**
     * Every finished series between the linked account and one of the
     * others: void, Elo reverted.
     *
     * @param  list<int>  $others
     */
    private function voidSeries(AccountLink $link, User $admin, int $linkedId, array $others): int
    {
        $count = 0;
        $query = SeriesMatch::query()->whereIn('status', [SeriesStatus::Confirmed, SeriesStatus::Resolved])
            ->where(fn (Builder $query) => $query->whereNull('resolution')->orWhere('resolution', '!=', SeriesResolution::Void))
            ->with('latestReport');

        $query->chunkById(200, function ($matches) use ($link, $admin, $linkedId, $others, &$count): void {
            foreach ($matches as $match) {
                /** @var SeriesMatch $match */
                $sides = FairPlay::seriesSides($match);
                $between = (in_array($linkedId, $sides['challenger'], true) && array_intersect($others, $sides['challenged']) !== [])
                    || (in_array($linkedId, $sides['challenged'], true) && array_intersect($others, $sides['challenger']) !== []);

                if (! $between) {
                    continue;
                }

                $previous = ['status' => $match->status->value, 'resolution' => $match->resolution?->value, 'winner' => $match->winner, 'resolution_reason' => $match->resolution_reason, 'resolved_by_id' => $match->resolved_by_id];
                $elo = $this->ratings->revert($match);

                SeriesMatch::query()->whereKey($match->id)->update([
                    'status' => SeriesStatus::Resolved,
                    'resolution' => SeriesResolution::Void,
                    'winner' => 'none',
                    'resolution_reason' => self::VOID_REASON,
                    'resolved_by_id' => $admin->id,
                ]);

                $this->markTournamentMatch($match->tournament_match_id);
                FairPlayVoid::query()->create(['account_link_id' => $link->id, 'source' => 'series', 'source_id' => $match->id, 'match_number' => $match->number, 'previous' => $previous, 'elo' => $elo]);
                $count++;
            }
        });

        return $count;
    }

    /**
     * Every finished chess game between the linked account and one of the
     * others: voided without a result, Elo reverted.
     *
     * @param  list<int>  $others
     */
    private function voidChess(AccountLink $link, int $linkedId, array $others): int
    {
        if ($others === []) {
            return 0;
        }

        $games = ChessGame::query()->where('status', ChessGameStatus::Finished)->whereNotNull('result')
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $q) => $q->where('white_id', $linkedId)->whereIn('black_id', $others))
                ->orWhere(fn (Builder $q) => $q->where('black_id', $linkedId)->whereIn('white_id', $others)))
            ->orderBy('id')->get();

        foreach ($games as $game) {
            $previous = ['status' => $game->status->value, 'result' => $game->result, 'end_reason' => $game->end_reason?->value];
            $elo = $this->ratings->revert($game);

            ChessGame::query()->whereKey($game->id)->update(['status' => ChessGameStatus::Aborted, 'result' => null, 'end_reason' => ChessEndReason::Voided]);

            $this->markTournamentMatch($game->tournament_match_id);
            FairPlayVoid::query()->create(['account_link_id' => $link->id, 'source' => 'chess', 'source_id' => $game->id, 'match_number' => $game->number, 'previous' => $previous, 'elo' => $elo]);
        }

        return $games->count();
    }

    /**
     * A tournament match played between two accounts of one person keeps
     * its place in the bracket; its stored result says it is void and
     * unrated.
     */
    private function markTournamentMatch(?int $id): void
    {
        $match = $id === null ? null : TournamentMatch::query()->find($id);

        if ($match !== null && is_array($match->result)) {
            $match->forceFill(['result' => ['void' => 'linked_accounts', 'unrated' => true] + $match->result])->save();
        }
    }

    /** @throws FairPlayRefused */
    private function account(string $key): User
    {
        $pubkey = NostrKeys::toHex(trim($key)) ?? throw new FairPlayRefused(__('Enter an npub or a 64-character hex public key.'));

        return User::query()->where('pubkey', $pubkey)->first()
            ?? throw new FairPlayRefused(__('This key has no account in the league.'));
    }

    /**
     * No admin decides about his own accounts.
     *
     * @param  list<User>  $users
     *
     * @throws FairPlayRefused
     */
    private function assertNotOwn(User $admin, array $users): void
    {
        foreach ($users as $user) {
            if ($user->pubkey === $admin->pubkey) {
                throw new FairPlayRefused(__('You cannot decide about your own accounts. Another admin has to.'));
            }
        }
    }

    /** @throws FairPlayRefused */
    private function assertAdmin(User $admin): void
    {
        if (! Gate::forUser($admin)->allows('admin')) {
            throw new FairPlayRefused(__('Only admins can link accounts.'));
        }
    }

    /** @throws FairPlayRefused */
    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw new FairPlayRefused(__('Give a reason, up to :max characters.', ['max' => self::REASON_MAX]));
        }

        return $reason;
    }
}
