<?php

namespace App\Support\Chess;

use App\Models\ChessGame;
use App\Models\ChessQueueEntry;
use App\Models\User;
use App\Support\Notifications\ChessNotifications;
use App\Support\SeasonChain\Seasons;
use Carbon\CarbonInterface;

/**
 * The blitz queue: players who are online and searching right now
 * (plan "Blitz-Schach ist reines Live-Matchmaking"). Two entries pair when
 * their ratings are within BOTH players' current range; the range widens the
 * longer a player waits (config esports.chess.queue.range).
 *
 * Pairing is attempted when a player joins and whenever a waiting player's
 * page asks again (the searching state polls), so a widening range pairs
 * without anyone new joining.
 *
 * An open invite comes first (P5e): a player who searches while holding
 * one is paired with its inviter at once, if the inviter is free and still
 * online (ChessInvites).
 */
final class ChessQueue
{
    public function __construct(
        private ChessGameService $games,
        private ChessNotifications $notifications,
        private ChessInvites $invites,
        private PresenceLookup $presence,
    ) {}

    /**
     * Join (or stay in) the queue and try to pair at once.
     *
     * @throws ChessRuleViolation when the player is already in a live game
     */
    public function join(User $user, string $mode = 'blitz', bool $rated = false): ?ChessGame
    {
        if ($this->games->activeGameOf($user) !== null) {
            throw new ChessRuleViolation('already_playing');
        }

        if ($rated) {
            // Rated play rests before Block 0 and between seasons (P7c), and rated
            // chess games are not built yet: the queue pairs casual games only.
            throw new ChessRuleViolation('rated_not_open', Seasons::isLive()
                ? __('Rated chess is not open yet. Blitz games are casual for now.')
                : Seasons::restMessage($user));
        }

        $invited = $this->fromOpenInvite($user, $mode);

        if ($invited !== null) {
            return $invited;
        }

        ChessQueueEntry::query()->firstOrCreate(['user_id' => $user->id], [
            'mode' => $mode,
            'rated' => $rated,
            'rating' => (int) config('esports.chess.queue.start_rating'),
            'joined_at' => now(),
        ]);

        return $this->pair($user);
    }

    /**
     * The newest open invite in this mode whose inviter is still online,
     * accepted as a found match. Online is asked of the websocket server;
     * when it cannot tell (null), the invite counts: it is at most
     * invite_seconds old, and the Accept button would start the same game
     * without asking. An inviter who plays by now withdraws the invite
     * (ChessInvites::accept), and the next one is tried.
     */
    private function fromOpenInvite(User $user, string $mode): ?ChessGame
    {
        foreach ($this->invites->incoming($user)->where('mode', $mode) as $invite) {
            if ($this->presence->online($invite->inviter) === false) {
                continue;
            }

            try {
                return $this->invites->acceptAsMatch($invite, $user);
            } catch (ChessRuleViolation) {
                continue;
            }
        }

        return null;
    }

    public function leave(User $user): void
    {
        ChessQueueEntry::query()->where('user_id', $user->id)->delete();
    }

    public function entryOf(User $user): ?ChessQueueEntry
    {
        return ChessQueueEntry::query()->where('user_id', $user->id)->first();
    }

    /**
     * Rating distance this entry accepts right now: `initial`, plus `step`
     * for every full `every_seconds` waited, never above `max`.
     */
    public function range(ChessQueueEntry $entry, ?CarbonInterface $now = null): int
    {
        $config = config('esports.chess.queue.range');
        $waited = max(0, (int) $entry->joined_at->diffInSeconds($now ?? now()));
        $steps = intdiv($waited, max(1, (int) $config['every_seconds']));

        return min((int) $config['max'], (int) $config['initial'] + $steps * (int) $config['step']);
    }

    /**
     * When this entry's range next opens, or null once it is at `max`. The
     * searching lobby asks for a pairing then: nobody new has to join for a
     * wider range to fit, so no push would announce it.
     */
    public function nextWidening(ChessQueueEntry $entry, ?CarbonInterface $now = null): ?CarbonInterface
    {
        $now ??= now();

        if ($this->range($entry, $now) >= (int) config('esports.chess.queue.range.max')) {
            return null;
        }

        $every = max(1, (int) config('esports.chess.queue.range.every_seconds'));
        $waited = max(0, (int) $entry->joined_at->diffInSeconds($now));

        return $entry->joined_at->copy()->addSeconds((intdiv($waited, $every) + 1) * $every);
    }

    /**
     * Pair this player with the longest-waiting fitting opponent, if any.
     * Returns the new game, or the live game a pairing already gave them.
     */
    public function pair(User $user): ?ChessGame
    {
        return ChessTransaction::run(function () use ($user): ?ChessGame {
            $entry = ChessQueueEntry::query()->where('user_id', $user->id)->lockForUpdate()->first();

            if ($entry === null) {
                return $this->games->activeGameOf($user);
            }

            $now = now();
            $candidates = ChessQueueEntry::query()
                ->where('user_id', '!=', $user->id)
                ->where('mode', $entry->mode)
                ->where('rated', $entry->rated)
                ->orderBy('joined_at')
                ->lockForUpdate()
                ->with('user')
                ->get();

            foreach ($candidates as $candidate) {
                $distance = abs($entry->rating - $candidate->rating);

                if ($distance > min($this->range($entry, $now), $this->range($candidate, $now))) {
                    continue;
                }

                if ($this->pairingLimitReached($user, $candidate->user, $entry->rated)) {
                    continue;
                }

                [$white, $black] = random_int(0, 1) === 0 ? [$user, $candidate->user] : [$candidate->user, $user];

                $game = $this->games->start($white, $black, $entry->mode);

                // The waiting player may be on another page, or in another tab.
                $this->notifications->matchFound($game);

                return $game;
            }

            return null;
        });
    }

    /**
     * Anti-farming limit of games per pairing and UTC day
     * (esports.chess.pairing_limit_per_day); off (null) for casual games.
     */
    private function pairingLimitReached(User $a, User $b, bool $rated): bool
    {
        $limit = config('esports.chess.pairing_limit_per_day.'.($rated ? 'rated' : 'casual'));

        if ($limit === null) {
            return false;
        }

        $played = ChessGame::query()
            ->where('rated', $rated)
            ->where('created_at', '>=', now()->utc()->startOfDay())
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('white_id', $a->id)->where('black_id', $b->id))
                ->orWhere(fn ($q) => $q->where('white_id', $b->id)->where('black_id', $a->id)))
            ->count();

        return $played >= (int) $limit;
    }
}
