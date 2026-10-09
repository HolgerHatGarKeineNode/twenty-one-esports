<?php

namespace App\Support\Tournaments;

use App\Enums\BoardGameStatus;
use App\Enums\ChessGameStatus;
use App\Enums\SeriesStatus;
use App\Enums\TournamentStatus;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentMatchSlot;
use App\Models\User;
use App\Support\LeagueTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Who blocks what in a running tournament (P18, slice 5): for every open
 * match its waiting state, the players it waits on, since when, and the
 * league's automatic decision with its time. Read-only; it only reads the
 * deadlines the league acts on:
 *
 * - a series with league deadlines: SeriesMatch::nextDeadline(), applied
 *   by TournamentScheduler (held while the tournament is paused);
 * - a casual cup's series (the casual flow): SeriesMatch::casualNextDeadline(),
 *   applied by CasualScheduler (not held by a pause);
 * - a chess game: its own `deadline_ms` (the first-move window,
 *   ChessGameService; a game keeps its clock during a pause);
 * - a cup match without a game: the auto slot (CasualCups::autoSlot()).
 *
 * The panel of the control section, the player's countdown and the
 * reminders (TournamentReminders) all read this one place.
 */
final class TournamentWaits
{
    /**
     * Every open match of a running tournament, most urgent first
     * (MatchWait::urgency()). Empty for any other status.
     *
     * @return list<MatchWait>
     */
    public static function of(Tournament $tournament): array
    {
        // A score leaderboard (plan "AoE2 und Trackmania", P4) has no match anybody waits for.
        if ($tournament->status !== TournamentStatus::Running || $tournament->profile()->isScore() || $tournament->profile()->isUnknown()) {
            return [];
        }

        $matches = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')
            ->where(fn ($query) => $query->whereNotNull('held')->orWhere(fn ($query) => $query->where('status', 'ready')->whereNull('result')))
            ->with(['round', 'slots.participant', 'seriesMatch.latestReport', 'chessGame', 'boardGame'])->orderBy('id')->get();

        $waits = [];

        foreach ($matches as $match) {
            $match->setRelation('tournament', $tournament);
            $wait = self::forMatch($tournament, $match);

            if ($wait !== null) {
                $waits[] = $wait;
            }
        }

        usort($waits, fn (MatchWait $a, MatchWait $b): int => $a->urgency() <=> $b->urgency());

        return $waits;
    }

    /**
     * The open matches of a running tournament one player plays in.
     *
     * @return list<MatchWait>
     */
    public static function ofPlayer(Tournament $tournament, User $user): array
    {
        return array_values(array_filter(self::of($tournament), fn (MatchWait $wait): bool => in_array($user->id, $wait->players, true)));
    }

    /**
     * The wait of the tournament match a series or chess game plays; null
     * outside a running tournament, or when it no longer counts there.
     */
    public static function ofPlay(SeriesMatch|ChessGame $played): ?MatchWait
    {
        $match = $played->tournament_match_id === null ? null
            : TournamentMatch::query()->with(['tournament', 'round', 'slots.participant', 'seriesMatch.latestReport', 'chessGame'])->find($played->tournament_match_id);

        if ($match === null || $match->tournament->status !== TournamentStatus::Running || $match->isReplaced($played->id)) {
            return null;
        }

        return self::forMatch($match->tournament, $match);
    }

    /**
     * One match's wait; null for a match without two sides, and for a score leaderboard.
     */
    public static function forMatch(Tournament $tournament, TournamentMatch $match): ?MatchWait
    {
        $slots = $match->slots;

        // A Hyperbitcoinization match (P5) waits on nobody's report: the league's server ends it. Held after a
        // correction (P5c), it waits for the desk like any other game; a free-for-all table has no two sides to
        // set a result for, so its round is restarted.
        if ($tournament->profile()->isHyper()) {
            if ($match->held === null || count($slots) < 2 || $slots->contains(fn (TournamentMatchSlot $slot): bool => $slot->participant === null)) {
                return null;
            }

            return (new WaitBuilder($tournament, $match))->make('held', since: self::stamp($match->held['at'] ?? null),
                consequence: count($slots) === 2 ? 'Set its result or restart its round' : 'Restart its round', needsAdmin: true);
        }

        // A score leaderboard's board is no duel, even with two entries (plan "AoE2 und Trackmania", P4); a lobby (P10) neither.
        if ($tournament->profile()->isScore() || $tournament->profile()->isUnknown() || $match->lobby !== null || count($slots) !== 2 || $slots->contains(fn (TournamentMatchSlot $slot): bool => $slot->participant === null)) {
            return null;
        }

        $base = new WaitBuilder($tournament, $match);

        if ($match->held !== null) {
            return $base->make('held', since: self::stamp($match->held['at'] ?? null), consequence: 'Set its result or restart its round', needsAdmin: true);
        }

        if ($tournament->isDirectorMode()) {
            return $base->make('director', consequence: 'The tournament directors enter the result', needsAdmin: true);
        }

        $game = $match->chessGame;
        $game = $game !== null && ! $match->isReplaced($game->id) && $game->status === ChessGameStatus::Active ? $game : null;

        if ($game !== null) {
            return self::chess($base, $game);
        }

        $board = $match->boardGame;

        if ($board !== null && ! $match->isReplaced($board->id) && $board->status === BoardGameStatus::Active) {
            return self::board($base, $board);
        }

        $series = $match->seriesMatch;
        $series = $series !== null && ! $match->isReplaced($series->id) && $series->status->isRunning() ? $series : null;

        if ($series !== null) {
            return $series->isCasualPairing() ? self::casualSeries($base, $series) : self::leagueSeries($base, $tournament, $series);
        }

        return self::notStarted($base, $tournament, $match);
    }

    private static function chess(WaitBuilder $base, ChessGame $game): MatchWait
    {
        $base->subject('chess:'.$game->id)->url(route('games.show', $game));
        $at = $game->deadline_ms === null ? null : CarbonImmutable::createFromTimestampMs($game->deadline_ms);
        $since = CarbonImmutable::createFromTimestampMs($game->turn_started_ms);
        $player = fn (string $color): int => (int) ($color === 'w' ? $game->white_id : $game->black_id);

        if (! $game->clocksRunning()) {
            // As ChessGameService::missedFirstMove(): before any move, a Black who never opened the board missed as well.
            $missed = [$game->turn()];

            if ($game->ply === 0 && $game->black_seen_at === null) {
                $missed[] = 'b';
            }

            $base->waitOnUsers(array_map($player, $missed));

            return count($missed) === 1
                ? $base->make('first_move', since: $since, decidesAt: $at, consequence: ':name loses by forfeit', action: 'Make your first move, or you lose by forfeit.', params: ['name' => $base->nameOf($player($missed[0]))])
                : $base->make('first_move', since: $since, decidesAt: $at, consequence: 'The game is aborted: both missed it', action: 'Open the board and make your first move, or the game is aborted.');
        }

        if ($game->isCorrespondence()) {
            $base->waitOnUsers([$player($game->turn())]);

            // A daily game reminds its players itself (Notifier, `reminder`): no automatic reminder here, only the one by hand.
            return $base->make('move', since: $since, decidesAt: $at, consequence: ':name loses on time', action: 'Make your move, or you lose on time.',
                params: ['name' => $base->nameOf($player($game->turn()))], remindable: false);
        }

        return $base->make('playing');
    }

    /**
     * A board game of a tournament match (plan "Mühle und Dame", P5): before
     * both first moves the side to move has the first-move window (White
     * missing it aborts the game, Black loses by forfeit); then it plays.
     */
    private static function board(WaitBuilder $base, BoardGame $game): MatchWait
    {
        $base->subject('board:'.$game->id)->url(route('board.show', $game));

        if ($game->clocksRunning()) {
            return $base->make('playing');
        }

        $at = $game->deadline_ms === null ? null : CarbonImmutable::createFromTimestampMs($game->deadline_ms);
        $since = CarbonImmutable::createFromTimestampMs($game->turn_started_ms);
        $mover = (int) ($game->turn === 'w' ? $game->white_id : $game->black_id);
        $base->waitOnUsers([$mover]);

        return $game->ply === 0
            ? $base->make('first_move', since: $since, decidesAt: $at, consequence: 'The game is aborted', action: 'Make your first move, or the game is aborted.')
            : $base->make('first_move', since: $since, decidesAt: $at, consequence: ':name loses by forfeit', action: 'Make your first move, or you lose by forfeit.', params: ['name' => $base->nameOf($mover)]);
    }

    /**
     * A series with the league's tournament deadlines (TournamentScheduler).
     */
    private static function leagueSeries(WaitBuilder $base, Tournament $tournament, SeriesMatch $series): MatchWait
    {
        $base->subject('series:'.$series->id)->url(route('matches.room', $series))->paused($tournament->isPaused());

        if ($series->status === SeriesStatus::Disputed) {
            return $base->make('disputed', since: self::disputedSince($series), consequence: 'An admin decides the dispute', needsAdmin: true);
        }

        if ($series->status === SeriesStatus::Accepted && $series->noshow_reported_at === null && $series->overdue_at !== null) {
            return $base->make('overdue', since: CarbonImmutable::instance($series->overdue_at), consequence: 'An admin decides: nobody reported by the deadline', needsAdmin: true);
        }

        $next = $series->nextDeadline();

        if ($next === null) {
            return $base->make('playing');
        }

        $at = CarbonImmutable::instance($next['at']);

        return match ($next['kind']) {
            'noshow' => $base->waitOnSide($next['side'])->make('noshow', since: self::at($series->noshow_reported_at), decidesAt: $at,
                consequence: ':name loses by forfeit', action: 'Enter the game or answer in the match room, or you lose by forfeit.', params: ['name' => $base->sideName($next['side'])]),
            'response' => $base->waitOnSide($next['side'])->make('response', since: self::at($series->latestReport?->created_at), decidesAt: $at,
                consequence: 'The league confirms the reported result, unrated', action: 'Confirm or dispute the reported result, or the league confirms it.'),
            'checkin_noshow' => $base->waitOnSide($next['side'])->make('checkin', since: self::at($series->start_at), decidesAt: $at,
                consequence: ':name counts as a no-show', action: 'Check in to the lobby in the match room, or you count as a no-show.', params: ['name' => $base->sideName($next['side'])]),
            'checkin_double' => $base->waitOnSide('challenger')->waitOnSide('challenged')->make('checkin', since: self::at($series->start_at), decidesAt: $at,
                consequence: 'Double no-show: nobody checked in', action: 'Check in to the lobby in the match room, or the match is decided as a double no-show.'),
            default => $base->waitOnSide('challenger')->waitOnSide('challenged')->make('report', since: self::at($series->start_at), decidesAt: $at,
                consequence: 'The series goes to the admins', action: 'Report the result, or the series goes to the admins.'),
        };
    }

    /**
     * A casual cup's series on the casual flow (CasualScheduler).
     */
    private static function casualSeries(WaitBuilder $base, SeriesMatch $series): MatchWait
    {
        $base->subject('series:'.$series->id)->url(route('matches.room', $series));

        if ($series->status === SeriesStatus::Disputed) {
            return $base->make('disputed', since: self::disputedSince($series), consequence: 'An admin decides the dispute', needsAdmin: true);
        }

        $next = $series->casualNextDeadline();

        if ($next === null) {
            return $base->make('playing');
        }

        $at = CarbonImmutable::instance($next['at']);
        $other = fn (?string $side): string => SeriesMatch::otherSide($side ?? 'challenger');

        if ($next['kind'] === 'checkin' || $next['kind'] === 'ready') {
            $missing = array_values(array_filter(SeriesMatch::SIDES, fn (string $side): bool => $series->readyAt($side) === null));

            foreach ($missing as $side) {
                $base->waitOnSide($side);
            }

            return count($missing) === 1
                ? $base->make('checkin', since: self::at($series->checkInOpensAt()), decidesAt: $at, consequence: ':name loses by forfeit', action: 'Check in, or you lose by forfeit.', params: ['name' => $base->sideName($missing[0])])
                : $base->make('checkin', since: self::at($series->checkInOpensAt()), decidesAt: $at, consequence: 'The match is void: nobody checked in', action: 'Check in, or you lose by forfeit.');
        }

        return match ($next['kind']) {
            'contest' => $base->waitOnSide($next['side'])->make('noshow', since: self::at($series->noshow_reported_at), decidesAt: $at,
                consequence: ':name loses by forfeit', action: 'Contest the no-show claim in the match room, or you lose by forfeit.', params: ['name' => $base->sideName((string) $next['side'])]),
            'lobby' => $base->waitOnSide($next['side'])->make('lobby', since: self::at($series->host_swapped_at ?? $series->start_at), decidesAt: $at,
                consequence: ':name may claim a no-show', action: 'Share the lobby in the match room, or your opponent may claim a no-show.', params: ['name' => $base->sideName($other($next['side']))]),
            'join' => $base->waitOnSide($next['side'])->make('join', since: self::at($series->lobby_shared_at), decidesAt: $at,
                consequence: ':name may claim a no-show', action: 'Join the shared lobby, or your opponent may claim a no-show.', params: ['name' => $base->sideName($other($next['side']))]),
            'confirm' => $base->waitOnSide($next['side'])->make('response', since: self::at($series->latestReport?->created_at), decidesAt: $at,
                consequence: 'The league confirms the reported result', action: 'Confirm or dispute the reported result, or the league confirms it.'),
            default => $base->waitOnSide('challenger')->waitOnSide('challenged')->make('report', since: self::at($series->start_at), decidesAt: $at,
                consequence: 'The match is void', action: 'Report the result, or the match is void.'),
        };
    }

    /**
     * No game or series yet. A casual cup match waits for its players (a
     * series: an agreed time; chess: a game they start) until the league
     * starts it at the auto slot; any other match starts with the next tick.
     */
    private static function notStarted(WaitBuilder $base, Tournament $tournament, TournamentMatch $match): MatchWait
    {
        $base->subject('match:'.$match->id);
        $endsAt = $match->round->window_ends_at;

        if (! $tournament->isCasualCup() || $endsAt === null || CasualCups::isEvening($tournament)) {
            return $base->make('not_started', consequence: $tournament->isPaused() ? 'Starts when the tournament resumes' : 'The league starts it shortly');
        }

        $slot = CasualCups::autoSlot($endsAt, CasualCups::timezoneOf($tournament));
        $params = ['time' => LeagueTime::stamp($slot)];

        if ($tournament->profile()->isChess() || $tournament->profile()->isBoard()) {
            return $base->waitOnSlot(0)->waitOnSlot(1)->make('not_started', decidesAt: $slot,
                consequence: 'The league starts the game at :time', action: 'Start your cup game with your opponent, or the league starts it at :time.', params: $params, timeAt: $slot);
        }

        $agreed = CupSchedules::agreedAt($match);

        if ($agreed !== null) {
            return $base->make('scheduled', decidesAt: $agreed, consequence: 'The check-in opens before the agreed time');
        }

        $schedule = $match->schedule;
        $pending = $schedule !== null && $schedule['respond_by'] > now()->getTimestamp();

        if ($pending) {
            $base->waitOnUsers(array_values(array_diff(self::slotUsers($match), [(int) $schedule['by']])));
        } else {
            $base->waitOnSlot(0)->waitOnSlot(1);
        }

        return $base->make('cup_time', decidesAt: $slot, consequence: 'The league starts it at :time', action: 'Agree on a time with your opponent, or the league starts it at :time.', params: $params, timeAt: $slot);
    }

    /**
     * @return list<int>
     */
    private static function slotUsers(TournamentMatch $match): array
    {
        return array_merge(...$match->slots->map(fn (TournamentMatchSlot $slot): array => $slot->participant?->memberIds() ?? [])->all());
    }

    private static function disputedSince(SeriesMatch $series): ?CarbonImmutable
    {
        return self::at($series->latestReport->responded_at ?? $series->latestReport?->updated_at);
    }

    private static function at(?CarbonInterface $at): ?CarbonImmutable
    {
        return $at === null ? null : CarbonImmutable::instance($at);
    }

    private static function stamp(mixed $iso): ?CarbonImmutable
    {
        return is_string($iso) && $iso !== '' ? CarbonImmutable::parse($iso) : null;
    }
}
