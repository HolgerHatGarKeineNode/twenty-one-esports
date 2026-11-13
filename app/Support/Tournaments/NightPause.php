<?php

namespace App\Support\Tournaments;

use App\Models\Tournament;
use App\Support\LeagueTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The night pause of a tournament (user decision 2026-10-10: "Turniere, die
 * wegen ihrem Turnierverlauf für Matches am Tag 24 Uhr erreichen, sollten am
 * Folgetag mit Pause weiterspielbar sein"): from `until` to `resume` in the
 * league's zone (Europe/Berlin, daylight saving included) no new match
 * starts and no league clock runs. A match under way at `until` may be played
 * to its end and reported; its deadlines stop at `until` and run on from
 * `resume` with the time they had left (a clock freeze, {@see addSeconds()}).
 *
 * League default `esports.tournaments.night_pause` (on, 00:00 to 18:00). A
 * tournament's own columns win: `night_pause` (null = the league's switch,
 * true / false), `night_pause_until` and `night_pause_resume` ("HH:MM"; null
 * = the league's times). `until` after `resume` (23:00 to 12:00) crosses
 * midnight; equal times mean no pause.
 *
 * Only where league clocks run against the players on one day
 * ({@see applies()}): an online players-mode tournament of a minute game.
 * A casual cup too (user, 2026-10-10: "Auch Casual Cups"), on its region's
 * own clock (CasualCups::timezoneOf(), the window's `zone`): no round opens
 * and nothing is decided in its pause, a round window that would close in
 * the night keeps its remaining time from `resume` ({@see outOfPause()}),
 * and its series' casual clocks stop like any other. Not daily
 * correspondence (a move takes days), a score leaderboard or a league week
 * (a window of days), a tournament on site or in director mode (the
 * directors run the clock by hand).
 *
 * A series pins the window at its pairing (TournamentDeadlines::forSeries(),
 * `deadlines.night_pause`), as it pins its other deadlines; the start of new
 * matches always follows the tournament's current setting.
 */
final class NightPause
{
    /** "HH:MM", 00:00 to 23:59. */
    public const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

    /**
     * Whether the night pause can apply to this tournament at all.
     */
    public static function applies(Tournament $tournament): bool
    {
        $profile = $tournament->profile();

        return ! $tournament->on_site && ! $tournament->isDirectorMode() && ! $tournament->isLeagueWeek()
            && ! $profile->isDaily() && ! $profile->isScore();
    }

    /**
     * The league default.
     *
     * @return array{enabled: bool, until: string, resume: string}
     */
    public static function defaults(): array
    {
        $until = (string) config('esports.tournaments.night_pause.until', '00:00');
        $resume = (string) config('esports.tournaments.night_pause.resume', '18:00');

        return [
            'enabled' => (bool) config('esports.tournaments.night_pause.enabled', true),
            'until' => preg_match(self::TIME_PATTERN, $until) === 1 ? $until : '00:00',
            'resume' => preg_match(self::TIME_PATTERN, $resume) === 1 ? $resume : '18:00',
        ];
    }

    /**
     * The tournament's switch: its own, else the league's.
     */
    public static function enabled(Tournament $tournament): bool
    {
        return $tournament->night_pause ?? self::defaults()['enabled'];
    }

    /**
     * The window a tournament plays under now; null when it has none (off,
     * or a tournament it does not apply to).
     *
     * @return array{until: string, resume: string, zone?: string}|null
     */
    public static function windowOf(Tournament $tournament): ?array
    {
        if (! self::applies($tournament) || ! self::enabled($tournament)) {
            return null;
        }

        $defaults = self::defaults();

        return self::window($tournament->night_pause_until ?? $defaults['until'], $tournament->night_pause_resume ?? $defaults['resume'],
            $tournament->isCasualCup() ? CasualCups::timezoneOf($tournament) : null);
    }

    /**
     * What a new series pins (`deadlines.night_pause`): the window, or false
     * for none.
     *
     * @return array{until: string, resume: string, zone?: string}|false
     */
    public static function pin(Tournament $tournament): array|false
    {
        return self::windowOf($tournament) ?? false;
    }

    /**
     * A window from two typed times; null for an invalid or empty one.
     *
     * @return array{until: string, resume: string, zone?: string}|null
     */
    public static function window(string $until, string $resume, ?string $zone = null): ?array
    {
        if (preg_match(self::TIME_PATTERN, $until) !== 1 || preg_match(self::TIME_PATTERN, $resume) !== 1 || $until === $resume) {
            return null;
        }

        // The league's zone stays implicit, so a pinned window reads as before; another zone (a US cup) is named.
        return $zone === null || $zone === LeagueTime::zone() ? ['until' => $until, 'resume' => $resume] : ['until' => $until, 'resume' => $resume, 'zone' => $zone];
    }

    /**
     * A moment a deadline lands on, moved out of the pause: inside one it
     * keeps the time it had left at the pause start and gets it from the
     * end. A casual cup's round window (days long) uses this instead of a
     * full freeze, so only the last night counts.
     *
     * @param  array{until: string, resume: string, zone?: string}|null  $window
     */
    public static function outOfPause(?array $window, CarbonImmutable $at): CarbonImmutable
    {
        $current = self::current($window, $at);

        return $current === null ? $at : $current[1]->addSeconds($at->getTimestamp() - $current[0]->getTimestamp());
    }

    /**
     * Drawn at its sign-up close and waiting for its start: no match starts
     * before `starts_at` (TournamentMatchMaker::startReady()), so a pause
     * that is over by then holds nothing up. A casual cup schedules each
     * series itself and is never held for its start.
     */
    public static function heldForStart(Tournament $tournament, ?CarbonInterface $at = null): bool
    {
        return ! $tournament->isCasualCup() && $tournament->draw_committed_at !== null
            && $tournament->starts_at->getTimestamp() > ($at ?? now())->getTimestamp();
    }

    /**
     * Whether a pause that ends at `$resumes` is what the players of this
     * tournament wait for: not when the tournament has not started yet and
     * starts at or after the pause's end (DerCaddy, 2026-11-13: a blitz
     * tournament drawn at 16:30 for 18:00 said "Nachtpause bis 18:00", as
     * if play had stopped; the default pause runs 00:00 to 18:00).
     */
    public static function holdsUp(Tournament $tournament, CarbonInterface $resumes, ?CarbonInterface $at = null): bool
    {
        return ! self::heldForStart($tournament, $at) || $resumes->getTimestamp() > $tournament->starts_at->getTimestamp();
    }

    /**
     * The pause of this tournament under way at `$at` (now by default):
     * its start and its end; null outside one.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public static function activeFor(Tournament $tournament, ?CarbonInterface $at = null): ?array
    {
        return self::current(self::windowOf($tournament), $at ?? now());
    }

    /**
     * The next pause of this tournament that starts after `$at`; null without a window.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public static function nextFor(Tournament $tournament, ?CarbonInterface $at = null): ?array
    {
        return self::next(self::windowOf($tournament), $at ?? now());
    }

    /**
     * The pause of a window under way at `$at`, start inclusive, end exclusive.
     *
     * @param  array{until: string, resume: string, zone?: string}|null  $window
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public static function current(?array $window, CarbonInterface $at): ?array
    {
        if ($window === null) {
            return null;
        }

        $day = CarbonImmutable::instance($at)->setTimezone($window['zone'] ?? LeagueTime::zone())->startOfDay();

        foreach ([$day->subDay(), $day] as $start) {
            [$from, $to] = self::interval($window, $start);

            if ($from->getTimestamp() <= $at->getTimestamp() && $at->getTimestamp() < $to->getTimestamp()) {
                return [$from, $to];
            }
        }

        return null;
    }

    /**
     * The first pause of a window that starts strictly after `$at`.
     *
     * @param  array{until: string, resume: string, zone?: string}|null  $window
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public static function next(?array $window, CarbonInterface $at): ?array
    {
        if ($window === null) {
            return null;
        }

        $day = CarbonImmutable::instance($at)->setTimezone($window['zone'] ?? LeagueTime::zone())->startOfDay();

        for ($offset = -1; $offset <= 2; $offset++) {
            [$from, $to] = self::interval($window, $day->addDays($offset));

            if ($from->getTimestamp() > $at->getTimestamp()) {
                return [$from, $to];
            }
        }

        return null;
    }

    /**
     * `$from` plus `$seconds` of running clock: the time inside a pause does
     * not count. A start inside a pause counts from its end; a deadline that
     * reaches a pause start with nothing left stays there. Without a window
     * it is plain addition. Keeps the class and zone of `$from`.
     *
     * @param  array{until: string, resume: string, zone?: string}|null  $window
     */
    public static function addSeconds(?array $window, CarbonInterface $from, int $seconds): CarbonInterface
    {
        $seconds = max(0, $seconds);

        if ($window === null) {
            return $from->copy()->addSeconds($seconds);
        }

        $at = CarbonImmutable::createFromTimestamp($from->getTimestamp(), 'UTC');

        // A pause is at least a minute and the gap between two at least a minute: this ends long before the bound.
        for ($guard = 0; $guard < 10_000; $guard++) {
            $current = self::current($window, $at);

            if ($current !== null) {
                $at = $current[1];
            }

            $next = self::next($window, $at);
            $room = $next === null ? 0 : $next[0]->getTimestamp() - $at->getTimestamp();

            if ($next === null || $seconds <= $room) {
                return $from->copy()->setTimestamp($at->getTimestamp() + $seconds);
            }

            $seconds -= $room;
            $at = $next[1];
        }

        return $from->copy()->setTimestamp($at->getTimestamp() + $seconds);
    }

    /**
     * The pause that starts on a local day.
     *
     * @param  array{until: string, resume: string, zone?: string}  $window
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function interval(array $window, CarbonImmutable $day): array
    {
        $zone = $window['zone'] ?? LeagueTime::zone();
        $date = $day->format('Y-m-d');
        $from = CarbonImmutable::parse($date.' '.$window['until'], $zone);
        $to = CarbonImmutable::parse(($window['until'] < $window['resume'] ? $date : $day->addDay()->format('Y-m-d')).' '.$window['resume'], $zone);

        return [$from->utc(), $to->utc()];
    }

    /**
     * A pause start as players say it: "24:00" for midnight.
     */
    public static function untilLabel(string $until): string
    {
        return $until === '00:00' ? '24:00' : $until;
    }
}
