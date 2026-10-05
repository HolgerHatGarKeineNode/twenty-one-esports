<?php

namespace App\Support\Series;

use App\Enums\Platform;
use App\Events\LookingToPlayChanged;
use App\Models\ChessQueueEntry;
use App\Models\SeriesMatch;
use App\Models\SeriesQueueEntry;
use App\Models\User;
use App\Support\Chess\Broadcasts;
use App\Support\Chess\ChessInvites;
use App\Support\Chess\ChessModes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The casual 1v1 queue (P23): players searching a game right now, one row
 * each (pattern: App\Support\Chess\ChessQueue). Two entries pair when they
 * search the same game and mode and their platforms can play each other
 * (CasualMatches::compatible()); the longest waiting fitting opponent
 * comes first. Pairing is tried when a player joins and whenever a waiting
 * player's page asks again.
 *
 * One intent at a time: joining withdraws the player's open invites (casual
 * and blitz) and leaves the blitz queue; a live chess game, a running
 * casual 1v1 or a no-show lock refuses to join.
 *
 * "Looking to play" (`users.looking_to_play`) is its own switch, as for
 * blitz: `<game>/1v1` makes a player invitable ({@see setLooking()}).
 */
final class CasualQueue
{
    public function __construct(
        private CasualMatches $matches,
        private CasualInvites $invites,
        private ChessInvites $chessInvites,
    ) {}

    /**
     * Join (or stay in) the queue and try to pair at once. Joining again
     * for the same game keeps the place and takes the new platform choice.
     *
     * @throws SeriesRuleViolation
     */
    public function join(User $user, string $game, Platform $platform, bool $crossplay = true): ?SeriesMatch
    {
        $this->matches->assertGame($game);
        $this->matches->assertMayPlay($user);

        $this->invites->withdrawOutgoing($user);

        if (($chessInvite = $this->chessInvites->outgoing($user)) !== null) {
            $this->chessInvites->close($chessInvite, $user);
        }

        ChessQueueEntry::query()->where('user_id', $user->id)->delete();

        $mode = CasualMatches::mode();
        $entry = $this->entryOf($user);

        if ($entry !== null && ($entry->game !== $game || $entry->mode !== $mode)) {
            $entry->delete();
            $entry = null;
        }

        if ($entry === null) {
            SeriesQueueEntry::query()->create(['user_id' => $user->id, 'game' => $game, 'mode' => $mode, 'platform' => $platform, 'crossplay' => $crossplay, 'joined_at' => now()]);
        } else {
            $entry->update(['platform' => $platform, 'crossplay' => $crossplay]);
        }

        return $this->pair($user);
    }

    public function leave(User $user): void
    {
        SeriesQueueEntry::query()->where('user_id', $user->id)->delete();
    }

    public function entryOf(User $user): ?SeriesQueueEntry
    {
        return SeriesQueueEntry::query()->where('user_id', $user->id)->first();
    }

    /**
     * Pair this player with the longest-waiting fitting opponent, if any.
     * Returns the new match, or the running match a pairing already gave
     * them. A waiting player who is busy by now (a chess game started from
     * an invite) leaves the queue instead of being paired. A pairing that
     * lost the race for a player to another pairing (CasualMatches::claim())
     * is rolled back whole and tried again on the next poll.
     */
    public function pair(User $user): ?SeriesMatch
    {
        try {
            return $this->pairNow($user);
        } catch (SeriesRuleViolation $refused) {
            return $refused->reason === 'already_playing' ? null : throw $refused;
        }
    }

    private function pairNow(User $user): ?SeriesMatch
    {
        return DB::transaction(function () use ($user): ?SeriesMatch {
            $entry = SeriesQueueEntry::query()->where('user_id', $user->id)->lockForUpdate()->first();

            if ($entry === null) {
                return $this->matches->activeMatchOf($user);
            }

            if ($this->matches->busyReason($user) !== null) {
                $entry->delete();

                return null;
            }

            $candidates = SeriesQueueEntry::query()
                ->where('user_id', '!=', $user->id)
                ->where('game', $entry->game)
                ->where('mode', $entry->mode)
                ->orderBy('joined_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->with('user')
                ->get();

            foreach ($candidates as $candidate) {
                if (! CasualMatches::compatible($entry->game, $entry->platform, $entry->crossplay, $candidate->platform, $candidate->crossplay)) {
                    continue;
                }

                if ($this->matches->busyReason($candidate->user) !== null) {
                    $candidate->delete();

                    continue;
                }

                SeriesQueueEntry::query()->whereIn('id', [$entry->id, $candidate->id])->delete();

                // The one who waited is the challenger; the host is drawn anyway.
                return $this->matches->create($candidate->user, $user, $entry->game, SeriesMatch::ORIGIN_QUEUE, [
                    'challenger' => ['platform' => $candidate->platform->value, 'crossplay' => $candidate->crossplay],
                    'challenged' => ['platform' => $entry->platform->value, 'crossplay' => $entry->crossplay],
                ]);
            }

            return null;
        });
    }

    /**
     * The ready player of a match the other side let run out of its ready
     * check goes back into the queue ahead of everyone waiting, with the
     * platform choice they paired with, and is paired again at once if
     * someone fits. Nothing happens for a player who is locked, busy or
     * already searching again.
     */
    public function requeueAtFront(User $user, string $game, Platform $platform, bool $crossplay): ?SeriesMatch
    {
        if ($this->matches->lockedUntil($user) !== null || $this->matches->busyReason($user) !== null || $this->entryOf($user) !== null) {
            return null;
        }

        $first = SeriesQueueEntry::query()->where('game', $game)->where('mode', CasualMatches::mode())->min('joined_at');
        $joinedAt = $first === null ? now() : Carbon::parse($first)->subSecond();

        SeriesQueueEntry::query()->create(['user_id' => $user->id, 'game' => $game, 'mode' => CasualMatches::mode(), 'platform' => $platform, 'crossplay' => $crossplay, 'joined_at' => $joinedAt]);

        return $this->pair($user);
    }

    /**
     * "Looking to play" for a casual 1v1 of `$game`, or off (null). Invites
     * that no longer fit are declined: blitz ones once it leaves
     * `chess/blitz`, casual ones once it leaves their game. Returns the
     * stored value.
     *
     * @throws SeriesRuleViolation for a game without casual 1v1
     */
    public function setLooking(User $user, ?string $game): ?string
    {
        if ($game !== null) {
            $this->matches->assertGame($game);
        }

        $wanted = $game === null ? null : $game.'/'.CasualMatches::mode();
        $previous = $user->looking_to_play;

        if ($previous === $wanted) {
            return $previous;
        }

        $user->forceFill(['looking_to_play' => $wanted])->save();
        Broadcasts::send(new LookingToPlayChanged($user->id, $wanted));

        // The chess lobby's live switch (`chess/blitz`, which takes rapid invites too: ChessInvites::looksFor()).
        if ($previous !== null && ChessInvites::looksFor($previous, ChessModes::DEFAULT)) {
            $this->chessInvites->declineAll($user);
        } elseif ($previous !== null) {
            $this->invites->declineAll($user);
        }

        return $wanted;
    }
}
