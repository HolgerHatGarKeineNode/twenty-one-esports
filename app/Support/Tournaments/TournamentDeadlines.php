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
 *
 * A one-day tournament (online, a minute game; not daily chess) runs its
 * series on the round clock instead of the long defaults: every deadline
 * follows from the match's own start ({@see RoundClock}, config
 * `esports.tournaments.round_clock`). Its own values still win: a report
 * deadline in hours replaces the derived one.
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
     * Online and a minute game: played on one day, on the round clock.
     */
    public static function isSingleDay(Tournament $tournament): bool
    {
        return ! $tournament->on_site && ! $tournament->profile()->isDaily();
    }

    /**
     * The round clock's defaults: no-show wait, grace and response, in minutes.
     *
     * @return array{noshow_minutes: int, grace_minutes: int, response_minutes: int}
     */
    public static function roundClockDefaults(): array
    {
        return [
            'noshow_minutes' => (int) config('esports.tournaments.round_clock.noshow_minutes', 15),
            'grace_minutes' => (int) config('esports.tournaments.round_clock.grace_minutes', 5),
            'response_minutes' => (int) config('esports.tournaments.round_clock.response_minutes', 10),
        ];
    }

    /**
     * The round clock of a mode with the organizer's own deadlines; null or
     * a missing key = the round clock's default.
     *
     * @param  array{checkin_minutes?: int|null, noshow_minutes?: int|null, report_hours?: int|null, response_minutes?: int|null}  $own
     */
    public static function clock(string $mode, array $own = []): RoundClock
    {
        $defaults = self::roundClockDefaults();

        return new RoundClock(
            $own['noshow_minutes'] ?? $defaults['noshow_minutes'],
            $defaults['grace_minutes'],
            $own['response_minutes'] ?? $defaults['response_minutes'],
            $own['report_hours'] ?? null,
            $own['checkin_minutes'] ?? self::defaults($mode)['checkin_minutes'],
        );
    }

    /**
     * The round clock of a tournament, with its own deadlines.
     */
    public static function clockOf(Tournament $tournament): RoundClock
    {
        return self::clock($tournament->mode, $tournament->only(['checkin_minutes', 'noshow_minutes', 'report_hours', 'response_minutes']));
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
     * What a new series of this tournament is paired with. On the round
     * clock (a one-day tournament) the report deadline is `report_minutes`
     * after the series' start, derived from its best-of, unless the
     * tournament set its own `report_hours`.
     *
     * @return array{noshow_minutes: int, report_hours?: int, report_minutes?: int, response_minutes: int}
     */
    public static function forSeries(Tournament $tournament, int $bestOf): array
    {
        if (self::isSingleDay($tournament)) {
            $clock = self::clockOf($tournament);

            return [
                'noshow_minutes' => $clock->noshowMinutes,
                ...($clock->reportHours !== null ? ['report_hours' => $clock->reportHours] : ['report_minutes' => $clock->reportDueMinutes($tournament->profile(), $bestOf)]),
                'response_minutes' => $clock->responseMinutes,
            ];
        }

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
