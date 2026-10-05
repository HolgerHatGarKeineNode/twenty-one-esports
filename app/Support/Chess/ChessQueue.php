<?php

namespace App\Support\Chess;

use App\Models\ChessGame;
use App\Models\ChessQueueEntry;
use App\Models\SeriesQueueEntry;
use App\Models\User;
use App\Support\Notifications\ChessNotifications;
use App\Support\Series\CasualInvites;
use App\Support\Series\CasualMatches;
use App\Support\Settings\LeagueSettings;
use App\Support\Tournaments\CupMatchNow;
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
 *
 * Rapid and blitz (plan "Schach Rapid und Clan", P2; user, 2026-10-05): two
 * entries pair only in a mode both take. "Either" takes every live mode
 * (ChessModes::live()); when both take more than one, the game is played in
 * the first choice of the one who waited longer. A player who waited
 * `switch_hint_seconds` alone is told when others search another mode
 * (switchHint), so a small player base does not wait in two queues.
 */
final class ChessQueue
{
    public function __construct(
        private ChessGameService $games,
        private ChessNotifications $notifications,
        private ChessInvites $invites,
        private PresenceLookup $presence,
        private CasualInvites $casualInvites,
    ) {}

    /**
     * Join (or stay in) the queue and try to pair at once. `either`: take
     * every live mode, `mode` first.
     *
     * @throws ChessRuleViolation when the player is already in a live game
     */
    public function join(User $user, string $mode = 'blitz', bool $rated = false, bool $either = false): ?ChessGame
    {
        if (! ChessModes::isLive($mode)) {
            throw new ChessRuleViolation('mode_not_live');
        }

        if ($this->games->activeGameOf($user) !== null) {
            throw new ChessRuleViolation('already_playing');
        }

        if (CasualMatches::runningMatchOf($user) !== null) {
            throw ChessGameService::casualPlaying();
        }

        // The casual lock (user, 2026-10-03): an open cup match in a running round comes first.
        if (($cup = CupMatchNow::refusal($user)) !== null) {
            throw new ChessRuleViolation($cup['reason'], $cup['message']);
        }

        $modes = $either ? array_values(array_unique([$mode, ...ChessModes::live()])) : [$mode];

        // Rated (P7d): only while the season is live, trust ranks exist and the player is Trusted.
        // "Either" searches rated in the modes whose ladder is open for this player (rapid opens later, rev. 9.22).
        if ($rated) {
            $refusals = array_map(fn (string $wanted): ?string => $this->ratedChess()->refusal($user, $wanted), array_combine($modes, $modes));
            $open = array_keys(array_filter($refusals, fn (?string $refusal): bool => $refusal === null));

            if ($open === []) {
                throw new ChessRuleViolation('rated_not_open', (string) $refusals[$mode]);
            }

            $modes = $open;
        }

        // One intent at a time (P23): searching blitz ends a casual 1v1 search and withdraws the casual invite sent.
        SeriesQueueEntry::query()->where('user_id', $user->id)->delete();
        $this->casualInvites->withdrawOutgoing($user);

        $invited = $this->fromOpenInvite($user, $modes);

        if ($invited !== null) {
            return $invited;
        }

        ChessQueueEntry::query()->firstOrCreate(['user_id' => $user->id], [
            'mode' => $modes[0],
            'modes' => count($modes) > 1 ? $modes : null,
            'rated' => $rated,
            'rating' => (int) config('esports.chess.queue.start_rating'),
            'joined_at' => now(),
        ]);

        return $this->pair($user);
    }

    /**
     * Search another mode instead, from the searching card's hint: the entry
     * keeps its place (joined_at, so its widened range) and tries to pair at
     * once. `either`: take every live mode, `mode` first. A rated entry
     * switches only to a mode whose ladder is open for this player.
     *
     * @throws ChessRuleViolation
     */
    public function switchTo(User $user, string $mode, bool $either = false): ?ChessGame
    {
        $entry = $this->entryOf($user);

        if ($entry === null) {
            return $this->games->activeGameOf($user);
        }

        if (! ChessModes::isLive($mode)) {
            throw new ChessRuleViolation('mode_not_live');
        }

        $modes = $either ? array_values(array_unique([$mode, ...ChessModes::live()])) : [$mode];

        if ($entry->rated) {
            $modes = array_values(array_filter($modes, fn (string $wanted): bool => $this->ratedChess()->refusal($user, $wanted) === null));

            if ($modes === []) {
                throw new ChessRuleViolation('rated_not_open', (string) $this->ratedChess()->refusal($user, $mode));
            }
        }

        $entry->forceFill(['mode' => $modes[0], 'modes' => count($modes) > 1 ? $modes : null])->save();

        return $this->pair($user);
    }

    /**
     * How many players search each live mode right now, the default first.
     * An "either" entry counts in every mode it takes.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = array_fill_keys(ChessModes::live(), 0);

        foreach (ChessQueueEntry::query()->get(['id', 'mode', 'modes']) as $entry) {
            foreach ($entry->takes() as $mode) {
                if (isset($counts[$mode])) {
                    $counts[$mode]++;
                }
            }
        }

        return $counts;
    }

    /**
     * The searching card's hint (user, 2026-10-05): once this entry waited
     * `switch_hint_seconds`, the live mode it does not take that the most
     * others search (in its kind, casual or rated), with their count; null
     * before then, or while nobody searches another mode.
     *
     * @return array{mode: string, count: int}|null
     */
    public function switchHint(ChessQueueEntry $entry, ?CarbonInterface $now = null): ?array
    {
        if ($this->switchHintAt($entry)->greaterThan($now ?? now())) {
            return null;
        }

        $others = array_fill_keys(array_values(array_diff(ChessModes::live(), $entry->takes())), 0);

        foreach (ChessQueueEntry::query()->where('user_id', '!=', $entry->user_id)->where('rated', $entry->rated)->get(['id', 'mode', 'modes']) as $other) {
            foreach ($other->takes() as $mode) {
                if (isset($others[$mode])) {
                    $others[$mode]++;
                }
            }
        }

        arsort($others);
        $mode = array_key_first($others);

        return $mode !== null && $others[$mode] > 0 ? ['mode' => (string) $mode, 'count' => $others[$mode]] : null;
    }

    /** When the searching card may show its switch hint (esports.chess.queue.switch_hint_seconds after joining). */
    public function switchHintAt(ChessQueueEntry $entry): CarbonInterface
    {
        return $entry->joined_at->copy()->addSeconds(max(0, (int) config('esports.chess.queue.switch_hint_seconds')));
    }

    /**
     * The newest open invite in one of these modes whose inviter is still online,
     * accepted as a found match. Online is asked of the websocket server;
     * when it cannot tell (null), the invite counts: it is at most
     * invite_seconds old, and the Accept button would start the same game
     * without asking. An inviter who plays by now withdraws the invite
     * (ChessInvites::accept), and the next one is tried.
     */
    /**
     * @param  list<string>  $modes
     */
    private function fromOpenInvite(User $user, array $modes): ?ChessGame
    {
        foreach ($this->invites->incoming($user)->whereIn('mode', $modes) as $invite) {
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

            // A cup match opened while this player searched (the casual lock): the search ends.
            if (CupMatchNow::lockOf($user) !== null) {
                $entry->delete();

                return null;
            }

            $now = now();
            // Every entry of this kind: the modes two entries share are compared below (a mode list, not a column).
            $candidates = ChessQueueEntry::query()
                ->where('user_id', '!=', $user->id)
                ->where('rated', $entry->rated)
                ->orderBy('joined_at')
                ->lockForUpdate()
                ->with('user')
                ->get();

            foreach ($candidates as $candidate) {
                $mode = self::commonMode($entry, $candidate);

                if ($mode === null) {
                    continue;
                }

                // In a casual 1v1 by now (P23): that player stops searching live chess.
                if (CasualMatches::runningMatchOf($candidate->user) !== null || CupMatchNow::lockOf($candidate->user) !== null) {
                    $candidate->delete();

                    continue;
                }

                $distance = abs($entry->rating - $candidate->rating);

                if ($distance > min($this->range($entry, $now), $this->range($candidate, $now))) {
                    continue;
                }

                if ($this->pairingLimitReached($user, $candidate->user, $entry->rated)) {
                    continue;
                }

                [$white, $black] = random_int(0, 1) === 0 ? [$user, $candidate->user] : [$candidate->user, $user];

                // Rated (P7d): the pairing is the accept; its trust gate is pinned with the game.
                $gate = $entry->rated ? $this->ratedChess()->pin($white, $black) : null;

                if ($entry->rated && $gate === null) {
                    continue;
                }

                $game = $this->games->start($white, $black, $mode, ratedGate: $gate);

                // The waiting player may be on another page, or in another tab.
                $this->notifications->matchFound($game);

                return $game;
            }

            return null;
        });
    }

    /**
     * The mode two entries are paired in: the one mode both take, or, when
     * they share more, the first choice of the entry that waited longer
     * (the earlier row on a tie); null when they share none.
     */
    public static function commonMode(ChessQueueEntry $a, ChessQueueEntry $b): ?string
    {
        $shared = array_intersect($a->takes(), $b->takes());

        if ($shared === []) {
            return null;
        }

        $first = $b->joined_at->lessThan($a->joined_at) || ($b->joined_at->equalTo($a->joined_at) && $b->id < $a->id) ? $b : $a;

        foreach ($first->takes() as $mode) {
            if (in_array($mode, $shared, true)) {
                return $mode;
            }
        }

        return null;
    }

    /**
     * Anti-farming limit of games per pairing and UTC day
     * (esports.chess.pairing_limit_per_day); off (null) for casual games.
     */
    private function pairingLimitReached(User $a, User $b, bool $rated): bool
    {
        $limit = LeagueSettings::get('esports.chess.pairing_limit_per_day.'.($rated ? 'rated' : 'casual'));

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

    /* ---------- Rated (P7d) ------------------------------------------------------------------------------------ */

    private function ratedChess(): RatedChess
    {
        return app(RatedChess::class);
    }
}
