<?php

namespace App\Support\Tournaments;

use App\Enums\SeriesStatus;
use App\Enums\TournamentStatus;
use App\Models\SeriesMatch;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Chess\ChessTeamMatches;
use Illuminate\Database\Eloquent\Builder;

/**
 * The tournament match a player has to play now (user, 2026-10-03: "die
 * Auffindbarkeit der Turniermatches unbedingt perfektionieren! ÜBERALL!!!!",
 * after a live cup where 4 of 9 chess games were forfeited with 0 or 1
 * moves and an opponent blocked a cup match with an unrelated casual game).
 * One answer for every place that points at it: the first entry of the
 * match dock, the header badge, the top of home, the banner of the chess
 * lobby, the Blitz page and /matches, and the casual lock.
 *
 * {@see for()} — the signal, most pressing match first:
 * - `live`: the player's tournament chess or board game is on the board
 *   (a correspondence game is not: it has its own dock tab and days to move);
 * - `lobby`: their tournament series is on (check-in, lobby, play, report);
 * - `waiting`: the match is ready and nothing is on yet (no game, a series
 *   later, a replay to come); it leads to the tournament page.
 *
 * {@see lockOf()} — the casual lock: an open match (ready, undecided, not
 * held) in a running round (its window set, `window_ends_at`) of a running
 * tournament. While it lasts the league refuses the player every casual
 * game: the queues, invites and challenges, with {@see refusal()}.
 */
final class CupMatchNow
{
    /** How pressing a state is: the lowest wins. */
    private const RANK = ['live' => 0, 'lobby' => 1, 'waiting' => 2];

    /** The reason code of a refused casual action. */
    public const LOCKED = 'cup_match_first';

    /** The reason code when the other player's cup match comes first. */
    public const OTHER_LOCKED = 'opponent_in_cup';

    /**
     * The player's most pressing open tournament match, once per request.
     *
     * @return array{state: 'live'|'lobby'|'waiting', key: string, href: string, label: string, short: string, tournament: string, round: string, opponent: string, cup: bool, locked: bool, match: int}|null
     */
    public function for(User $user): ?array
    {
        $key = 'cup-match-now.'.$user->id;

        if (request()->attributes->has($key)) {
            /** @var array{state: 'live'|'lobby'|'waiting', key: string, href: string, label: string, short: string, tournament: string, round: string, opponent: string, cup: bool, locked: bool, match: int}|null */
            return request()->attributes->get($key);
        }

        $now = $this->find($user);
        request()->attributes->set($key, $now);

        return $now;
    }

    /**
     * Why this player may not start or accept anything casual now: the own
     * cup match (`cup_match_first`) or the locked chess team match he is
     * named for (`team_match_first`, ChessTeamMatches::reservationOf(), plan
     * "Schach Rapid und Clan"); null when free. Every queue, pairing and
     * accept that asks about one player asks this, so a team match
     * reservation reaches every path the cup lock reaches.
     */
    public static function lockReason(User $user): ?string
    {
        if (self::lockOf($user) !== null) {
            return self::LOCKED;
        }

        return ChessTeamMatches::reservationOf($user) !== null ? ChessTeamMatches::RESERVED : null;
    }

    /** The same, said about the other player (`opponent_in_cup`, `opponent_in_team_match`); null when free. */
    public static function otherLockReason(User $other): ?string
    {
        return match (self::lockReason($other)) {
            self::LOCKED => self::OTHER_LOCKED,
            ChessTeamMatches::RESERVED => ChessTeamMatches::OTHER_RESERVED,
            default => null,
        };
    }

    /**
     * The open match that locks the player out of casual play, or null.
     */
    public static function lockOf(User $user): ?TournamentMatch
    {
        return self::open($user)
            // Only a live round locks: one that ends within `casual_lock_hours`. A cup round over days (36–48 h
            // windows) must not keep a player out of casual games for two days (2026-10-03).
            ->whereHas('round', fn (Builder $query) => $query->whereNotNull('window_ends_at')
                ->where('window_ends_at', '<=', now()->addHours(max(1, (int) config('esports.tournaments.casual_lock_hours', 3)))))
            ->with('tournament')
            ->orderBy('id')
            ->first();
    }

    /**
     * The refusal of a casual action: the player's own cup match comes
     * first (`cup_match_first`), or the other player's does
     * (`opponent_in_cup`). A locked chess team match reserves its players
     * the same way (ChessTeamMatches::refusal(), `team_match_first`, plan
     * "Schach Rapid und Clan", P4). Null when both are free to play.
     *
     * @return array{reason: string, message: string}|null
     */
    public static function refusal(User $actor, ?User $other = null): ?array
    {
        if (self::lockOf($actor) !== null) {
            return ['reason' => self::LOCKED, 'message' => (string) __('Your cup match comes first.')];
        }

        if ($other !== null && self::lockOf($other) !== null) {
            return ['reason' => self::OTHER_LOCKED, 'message' => (string) __(':name is playing a cup match right now.', ['name' => $other->displayName()])];
        }

        return ChessTeamMatches::refusal($actor, $other);
    }

    /**
     * The open matches of a player: ready, undecided, not held, no bye and
     * no lobby, in a running tournament, on an entry that is still in.
     *
     * @return Builder<TournamentMatch>
     */
    private static function open(User $user): Builder
    {
        $entries = TournamentParticipant::query()->whereNull('disqualified_at')
            ->where(fn (Builder $query) => $query->where('user_id', $user->id)->orWhereJsonContains('members', $user->id))
            ->select('id');

        return TournamentMatch::query()
            ->where('status', 'ready')->whereNull('result')->whereNull('held')->whereNull('lobby')->where('bracket', '!=', 'bye')
            ->whereHas('tournament', fn (Builder $query) => $query->where('status', TournamentStatus::Running))
            ->whereHas('slots', fn (Builder $query) => $query->whereIn('tournament_participant_id', $entries));
    }

    /**
     * @return array{state: 'live'|'lobby'|'waiting', key: string, href: string, label: string, short: string, tournament: string, round: string, opponent: string, cup: bool, locked: bool, match: int}|null
     */
    private function find(User $user): ?array
    {
        $matches = self::open($user)->with(['tournament', 'round', 'slots.participant', 'chessGame', 'boardGame', 'seriesMatch'])
            ->orderBy('id')->limit(10)->get();
        $best = null;

        foreach ($matches as $match) {
            $profile = $match->tournament->profile();

            // Directors enter the result of a game played elsewhere; a score board has no opponent.
            if ($match->tournament->isDirectorMode() || $profile->isScore() || $profile->isUnknown()) {
                continue;
            }

            $item = $this->item($match, $user);

            if ($item !== null && ($best === null || self::RANK[$item['state']] < self::RANK[$best['state']])) {
                $best = $item;
            }
        }

        return $best;
    }

    /**
     * @return array{state: 'live'|'lobby'|'waiting', key: string, href: string, label: string, short: string, tournament: string, round: string, opponent: string, cup: bool, locked: bool, match: int}|null
     */
    private function item(TournamentMatch $match, User $user): ?array
    {
        $mine = $match->slots->first(fn ($slot): bool => in_array($user->id, $slot->participant?->memberIds() ?? [], true));

        if ($mine === null) {
            return null;
        }

        // A solo entry under its player's current name (a renamed player is never shown under the old one); a team under its own.
        $theirs = $match->slots->first(fn ($slot): bool => $slot->id !== $mine->id)?->participant;
        $members = $theirs?->memberIds() ?? [];
        $opponent = match (true) {
            $theirs === null => '',
            count($members) === 1 => User::query()->find($members[0])?->displayName() ?? $theirs->name,
            default => $theirs->name,
        };
        $chess = $match->chessGame;
        $board = $match->boardGame;
        $series = $match->seriesMatch;

        [$state, $href] = match (true) {
            $chess !== null && ! $match->isReplaced($chess->id) && $chess->isActive() => $chess->isCorrespondence() ? [null, null] : ['live', route('games.show', $chess)],
            $board !== null && ! $match->isReplaced($board->id) && $board->isActive() => $board->isCorrespondence() ? [null, null] : ['live', route('board.show', $board)],
            $series !== null && ! $match->isReplaced($series->id) && $series->status === SeriesStatus::Accepted => [self::seriesState($series), route('matches.room', $series)],
            $series !== null && ! $match->isReplaced($series->id) && $series->status->isRunning() => [null, null],
            default => ['waiting', route('tournaments.show', $match->tournament)],
        };

        if ($state === null || $href === null) {
            return null;
        }

        $cup = $match->tournament->isCasualCup();

        return [
            'state' => $state,
            'key' => 'cup-'.$match->id.'-'.$state,
            'href' => $href,
            'label' => (string) match ($state) {
                'live' => $cup ? __('Your cup game is live — play now') : __('Your tournament game is live — play now'),
                'lobby' => __('Join your lobby'),
                'waiting' => $opponent === '' ? __('Your next match') : __('Waiting for :name', ['name' => $opponent]),
            },
            'short' => (string) match ($state) {
                'live' => __('Play now'),
                'lobby' => __('Your lobby'),
                'waiting' => __('Your match'),
            },
            'tournament' => $match->tournament->title(),
            'round' => (string) __('Round :number', ['number' => $match->round->number]),
            'opponent' => $opponent,
            'cup' => $cup,
            // In a running round the casual lock holds (lockOf()).
            'locked' => $match->round->window_ends_at !== null,
            'match' => $match->id,
        ];
    }

    /**
     * An accepted series: on (`lobby`) from its check-in or start on, `waiting` before.
     *
     * @return 'lobby'|'waiting'
     */
    private static function seriesState(SeriesMatch $series): string
    {
        $opens = $series->isCasualPairing() ? ($series->checkInOpensAt() ?? $series->start_at) : $series->start_at;

        return $opens !== null && $opens->isFuture() ? 'waiting' : 'lobby';
    }
}
