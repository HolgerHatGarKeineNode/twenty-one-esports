<?php

namespace App\Support\Tournaments;

use App\Models\Tournament;

/**
 * The deadlines of a players-mode tournament (P18, slice 2): each
 * tournament may set its own, null falls back to the league default in
 * `config/esports.php`.
 *
 * - checkin_minutes: chess, the first-move window of a tournament game (a
 *   side that misses it loses by forfeit, both missing restarts the game
 *   and then the double no-show rule decides, slice 1);
 * - noshow_minutes: a series, how long after its start a captain may report
 *   the other side as missing;
 * - report_hours: a series nobody reported this long after its start joins
 *   the admin queue;
 * - response_minutes: a series report, or a reported no-show, the other
 *   side did not answer for this long is decided by the league, unrated
 *   (auto-confirm, or a forfeit for the side that showed up).
 *
 * The values a match plays under are pinned when it is paired (a series'
 * `deadlines`, a chess game's `first_move_seconds`): an edit after the start
 * reaches only matches paired later.
 */
final class TournamentDeadlines
{
    /** The per-tournament columns with their bounds, inclusive. */
    public const BOUNDS = [
        'checkin_minutes' => [1, 2880],
        'noshow_minutes' => [5, 120],
        'report_hours' => [1, 72],
        'response_minutes' => [5, 1440],
    ];

    /**
     * The league defaults for a mode (the check-in window depends on it).
     *
     * @return array{checkin_minutes: int, noshow_minutes: int, report_hours: int, response_minutes: int}
     */
    public static function defaults(string $mode): array
    {
        return [
            'checkin_minutes' => max(1, intdiv(self::defaultCheckinSeconds($mode), 60)),
            'noshow_minutes' => (int) config('esports.series.noshow_minutes', 15),
            'report_hours' => (int) config('esports.tournaments.report_hours', 2),
            'response_minutes' => (int) config('esports.tournaments.response_minutes', 30),
        ];
    }

    /**
     * The first-move window of a new game of this tournament, in seconds.
     */
    public static function checkinSeconds(Tournament $tournament): int
    {
        return $tournament->checkin_minutes !== null
            ? $tournament->checkin_minutes * 60
            : self::defaultCheckinSeconds($tournament->mode);
    }

    /**
     * What a new series of this tournament is paired with.
     *
     * @return array{noshow_minutes: int, report_hours: int, response_minutes: int}
     */
    public static function forSeries(Tournament $tournament): array
    {
        $defaults = self::defaults($tournament->mode);

        return [
            'noshow_minutes' => $tournament->noshow_minutes ?? $defaults['noshow_minutes'],
            'report_hours' => $tournament->report_hours ?? $defaults['report_hours'],
            'response_minutes' => $tournament->response_minutes ?? $defaults['response_minutes'],
        ];
    }

    private static function defaultCheckinSeconds(string $mode): int
    {
        return (int) (config("esports.tournaments.first_move_seconds.{$mode}") ?? config('esports.chess.first_move_seconds'));
    }
}
