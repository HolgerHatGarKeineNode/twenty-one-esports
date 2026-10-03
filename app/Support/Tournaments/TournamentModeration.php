<?php

namespace App\Support\Tournaments;

use App\Enums\NotificationKind;
use App\Events\TournamentChanged;
use App\Models\LineupSeat;
use App\Models\Tournament;
use App\Models\TournamentBan;
use App\Models\TournamentModerationEntry;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Chess\Broadcasts;
use App\Support\Notifications\Notice;
use App\Support\Notifications\Notifier;
use App\Support\Rating\Ratings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Moderating the entries of a tournament before its draw (the edit page): an
 * organizer of their own tournament or an admin removes an entry with a
 * reason, optionally blocks its players from signing up again, and unblocks
 * them. Every step goes to the append-only moderation log.
 *
 * A removed entry keeps its row and its signed Tournament Consent (`22150`):
 * the consent is the evidence that the player entered (NIP "Tournament
 * Consent"), so it stays as history; the row is marked removed and no longer
 * counts for capacity, the draw or one-entry-per-person. Nothing is
 * published: the NIP keeps registrations off the relays, and the draw
 * (`2155`) is their only public trace, so a removal before the draw has no
 * public trace either.
 *
 * The players of a removed entry hear about it through the Notifier (the
 * bell, and a DM per their settings) once the removal is committed.
 *
 * After the draw nothing here works: the entries are frozen into the
 * participants and the bracket (TournamentDraws).
 */
final class TournamentModeration
{
    public function __construct(private Notifier $notifier) {}

    /**
     * @throws TournamentRuleViolation
     */
    public function remove(Tournament $tournament, User $actor, int $signupId, string $reason, bool $block = false): TournamentSignup
    {
        $this->authorize($tournament, $actor);
        $reason = $this->reason($reason);

        [$signup, $notify] = DB::transaction(function () use ($tournament, $actor, $signupId, $reason, $block): array {
            $locked = $this->lockBeforeDraw($tournament);
            $signup = TournamentSignup::query()->where('tournament_id', $locked->id)->active()->lockForUpdate()->find($signupId)
                ?? throw new TournamentRuleViolation('not_entered', __('This entry is no longer in the tournament.'));

            $this->markRemoved($locked, $actor, $signup, $reason);

            if ($block) {
                // The entry's players and, for a lineup, every active seat: any of them could play for it.
                $seats = $signup->lineup === null ? [] : array_map(fn (LineupSeat $seat): int => $seat->user_id, $signup->lineup->activeSeats());

                foreach (User::query()->whereKey([...$signup->members, ...$seats])->get() as $player) {
                    $this->block($locked, $actor, $player, $reason);
                }
            }

            return [$signup, $this->recipients($signup)];
        });

        $this->notifyRemoved($tournament, $notify, $reason);

        return $signup;
    }

    /**
     * Remove several entries at once inside the caller's transaction (a game
     * or mode correction, TournamentEditor). Returns who to notify once the
     * caller has committed ({@see notifyRemoved()}).
     *
     * @param  iterable<TournamentSignup>  $signups
     * @return list<int> user ids
     */
    public function removeLocked(Tournament $locked, User $actor, iterable $signups, string $reason): array
    {
        $notify = [];

        foreach ($signups as $signup) {
            $this->markRemoved($locked, $actor, $signup, $reason);
            $notify = [...$notify, ...$this->recipients($signup)];
        }

        return array_values(array_unique($notify));
    }

    /**
     * Withdraw, inside the caller's transaction on the locked tournament, the
     * entries whose players no longer have an account (prod 2026-10-03: a
     * deleted account left its solo entry active, and creating its
     * participant broke the draw on the foreign key every minute). A solo
     * entry goes; a lineup drops the players gone and keeps its entry while
     * it still fields a team (the sign-up rule: at least the team size),
     * else it goes too. Each step is a league line in the moderation log,
     * and open pages hear of it by push (TournamentChanged) after the commit.
     * Returns how many entries were withdrawn.
     */
    public function withdrawOrphaned(Tournament $locked, string $reason): int
    {
        $signups = TournamentSignup::query()->where('tournament_id', $locked->id)->active()->orderBy('id')->lockForUpdate()->get();
        $present = User::query()->whereKey($signups->pluck('members')->flatten()->map(intval(...))->unique()->all())->pluck('id')->all();
        $withdrawn = 0;
        $changed = false;

        foreach ($signups as $signup) {
            $members = array_map(intval(...), $signup->members);
            $left = array_values(array_intersect($members, $present));

            if (count($left) === count($members) && $members !== [] && ($signup->lineup_id !== null || $signup->user_id !== null)) {
                continue;
            }

            if ($signup->lineup_id !== null && count($left) >= $locked->teamSize()) {
                $signup->forceFill(['members' => $left])->save();
                $this->logLeague($locked, 'left', $signup, $reason);
                $changed = true;

                continue;
            }

            $signup->forceFill(['withdrawn_at' => now()])->save();
            $this->logLeague($locked, 'withdrawn', $signup, $reason);
            $withdrawn++;
            $changed = true;
        }

        // Open pages draw again without the entry, as for a player's own withdrawal (after the commit, never on a rollback).
        if ($changed) {
            Broadcasts::send(new TournamentChanged($locked->id, 'withdrawn'));
        }

        return $withdrawn;
    }

    /**
     * @throws TournamentRuleViolation
     */
    public function unblock(Tournament $tournament, User $actor, int $banId): void
    {
        $this->authorize($tournament, $actor);

        DB::transaction(function () use ($tournament, $actor, $banId): void {
            $locked = $this->lockBeforeDraw($tournament);
            $ban = TournamentBan::query()->where('tournament_id', $locked->id)->with('user')->find($banId)
                ?? throw new TournamentRuleViolation('not_blocked', __('This player is not blocked.'));

            $ban->delete();
            $this->log($locked, $actor, 'unblocked', subject: $ban->user->displayName());
        });
    }

    /**
     * Append one line to the moderation log.
     *
     * @param  'edited'|'removed'|'blocked'|'unblocked'|'result'|'disqualified'|'paused'|'resumed'|'round_restarted'|'aborted'|'messaged'|'reminded'  $action
     * @param  array<string, array{0: mixed, 1: mixed}>|null  $details
     */
    public function log(Tournament $tournament, User $actor, string $action, ?string $subject = null, ?string $reason = null, ?array $details = null, ?int $signupId = null): void
    {
        TournamentModerationEntry::query()->create([
            'tournament_id' => $tournament->id,
            'user_id' => $actor->id,
            'user_name' => mb_substr($actor->displayName(), 0, 80),
            'action' => $action,
            'tournament_signup_id' => $signupId,
            'subject' => $subject === null ? null : mb_substr($subject, 0, 80),
            'reason' => $reason,
            'details' => $details,
            'created_at' => now(),
        ]);
    }

    /**
     * The Elo each entry would be seeded with if the draw ran now, as
     * TournamentDraws seeds it; null for a solo player of a team mode (a
     * mix team is seeded by draw order, not by Elo).
     *
     * @param  Collection<int, TournamentSignup>  $signups
     * @return array<int, int|null> signup id => rating
     */
    public static function seedRatings(Tournament $tournament, Collection $signups): array
    {
        $pool = Ratings::pool($tournament->openLadder() !== null);
        $teams = $tournament->profile()->entersTeams();
        $lineupRatings = Ratings::forLineups($signups->pluck('lineup_id')->filter()->all(), $tournament->game, $tournament->mode, $pool);
        $userRatings = Ratings::forUsers($signups->pluck('members')->flatten()->all(), $tournament->game, $tournament->mode, $pool);
        $start = (int) config('season.rating.start', 1000);
        $ratings = [];

        foreach ($signups as $signup) {
            $ratings[$signup->id] = match (true) {
                $signup->lineup_id !== null && $tournament->teamSize() === 1 => $userRatings[$signup->members[0] ?? 0]['rating'] ?? $start,
                $signup->lineup_id !== null => $lineupRatings[$signup->lineup_id]['rating'] ?? $start,
                ! $teams => $userRatings[$signup->members[0] ?? 0]['rating'] ?? $start,
                default => null,
            };
        }

        return $ratings;
    }

    /**
     * Tell each player of removed entries, in their language.
     *
     * @param  list<int>  $userIds
     */
    public function notifyRemoved(Tournament $tournament, array $userIds, string $reason): void
    {
        foreach (User::query()->whereKey($userIds)->get() as $player) {
            $locale = $player->locale ?? (string) config('app.locale');

            $this->notifier->send($player, NotificationKind::TournamentEntryRemoved, new Notice(
                __('Your entry in :tournament was removed', ['tournament' => $tournament->name], $locale),
                // A league reason is an English key and reaches the player in their language; typed text stays as typed.
                __('Reason: :reason', ['reason' => __($reason, [], $locale)], $locale),
                route('tournaments.show', $tournament),
                null,
                __('Open tournament', [], $locale),
            ));
        }
    }

    /**
     * The players of an entry and the captain who entered it.
     *
     * @return list<int> user ids
     */
    public static function entrants(TournamentSignup $signup): array
    {
        return array_values(array_unique(array_map(intval(...), [...$signup->members, ...array_filter([$signup->user_id])])));
    }

    /**
     * @throws TournamentRuleViolation
     */
    public static function assertBeforeDraw(Tournament $tournament): void
    {
        if (! $tournament->isBeforeDraw()) {
            throw new TournamentRuleViolation('drawn', __('The draw has run: entries can no longer be changed.'));
        }
    }

    private function markRemoved(Tournament $locked, User $actor, TournamentSignup $signup, string $reason): void
    {
        $signup->forceFill(['removed_at' => now(), 'removed_by_id' => $actor->id, 'removal_reason' => $reason])->save();
        $this->log($locked, $actor, 'removed', subject: $signup->name, reason: $reason, signupId: $signup->id);
        // Open pages draw again without the entry (TournamentSignups does the same for a sign-up or a withdrawal).
        Broadcasts::send(new TournamentChanged($locked->id, 'removed'));
    }

    /**
     * A line of the league itself (no actor). A solo entry is named "Deleted
     * player", as its games are: the log does not keep the name of an account
     * that asked to be deleted.
     *
     * @param  'left'|'withdrawn'  $action
     */
    private function logLeague(Tournament $locked, string $action, TournamentSignup $signup, string $reason): void
    {
        TournamentModerationEntry::query()->create([
            'tournament_id' => $locked->id,
            'user_id' => null,
            'user_name' => 'League',
            'action' => $action,
            'tournament_signup_id' => $signup->id,
            'subject' => $signup->lineup_id === null ? 'Deleted player' : mb_substr($signup->name, 0, 80),
            'reason' => $reason,
            'details' => null,
            'created_at' => now(),
        ]);
    }

    private function block(Tournament $locked, User $actor, User $player, string $reason): void
    {
        $ban = TournamentBan::query()->firstOrCreate(
            ['tournament_id' => $locked->id, 'user_id' => $player->id],
            ['added_by_id' => $actor->id, 'reason' => $reason],
        );

        if ($ban->wasRecentlyCreated) {
            $this->log($locked, $actor, 'blocked', subject: $player->displayName(), reason: $reason);
        }
    }

    /**
     * The players of the entry and the captain who entered it.
     *
     * @return list<int> user ids
     */
    private function recipients(TournamentSignup $signup): array
    {
        return self::entrants($signup);
    }

    private function lockBeforeDraw(Tournament $tournament): Tournament
    {
        $locked = Tournament::query()->lockForUpdate()->findOrFail($tournament->id);
        self::assertBeforeDraw($locked);

        return $locked;
    }

    private function authorize(Tournament $tournament, User $actor): void
    {
        if (! Gate::forUser($actor)->allows('manage-tournament', $tournament)) {
            throw new TournamentRuleViolation('not_manager', __('Only the organizer of this tournament or an admin can do this.'));
        }
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new TournamentRuleViolation('reason', __('Give a reason of 3 to 500 characters. The player reads it.'));
        }

        return $reason;
    }
}
