<?php

namespace App\Support\Tmnf;

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Games\TrackmaniaNationsForever;
use App\Models\LeagueWeek;
use App\Models\ScoreRun;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Scores\LeaderboardEntries;
use App\Support\Scores\LeagueWeekDrafts;
use App\Support\Scores\ScoreWindow;
use App\Support\Scores\ServerIngest;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentPublisher;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * The weekly time attack of TrackMania Nations Forever (plan "Trackmania und
 * Restposten", P2), Blockfill's weekly hunt on our own server: one
 * leaderboard per week and track, Monday 00:00 to Monday 00:00
 * Europe/Berlin (BlockfillWeeks' rhythm, a week of 167 or 169 hours when the
 * clocks change).
 *
 * - The track of a week: the one an admin approved for it (LeagueWeekDrafts,
 *   user 2026-10-02), a draft starting from the previous week's; the first
 *   draft of all takes `esports.tmnf.tracks` in turn from CYCLE_START.
 * - open(): the week's leaderboard, once (slug `tmnf-<monday>`, unique),
 *   running and published from its start, its course the track's UID, but
 *   only once an admin approved the week and the listener saw the server on
 *   its track (TmnfTrackSwitch): at its planned start, or later at the
 *   approval or the switch, ending on the following Monday 00:00 either way.
 *   Not approved, no week runs. The scheduler opens it every hour
 *   (`tmnf:weeks`), the listener as soon as the server is on the track, and
 *   the first finish of a week too.
 * - record(): a finish of a linked login on the week's track inside its
 *   window joins the player (no sign-up). The run itself is the server's
 *   (TmnfIngest): verified as read, or held for an admin (TmnfOutliers).
 * - sweep(): who record() missed joins on the next hourly run.
 * - announce(): the week's NIP-52 calendar event (31923), once.
 *
 * Only a linked login's finish counts (a run with a player); the board is the
 * score kind's own (ScoreRuns, ScoreLeaderboards, ScorePoints). Nothing
 * while TMNF is not registered (`esports.tmnf.enabled` off).
 */
final class TmnfWeeks
{
    /** The Monday the track cycle starts from (Europe/Berlin). */
    public const CYCLE_START = '2026-01-05';

    /** The time limit of a round an admin may set for a week, in minutes (null: the server's own, league.txt). */
    public const MIN_ROUND_MINUTES = 3;

    public const MAX_ROUND_MINUTES = 120;

    /** The server's own time limit of a round (timeattack_limit in docker/tmnf/league.txt), used while a week sets none. */
    public const SERVER_ROUND_MINUTES = 15;

    public function __construct(private GameRegistry $games, private TournamentPublisher $publisher) {}

    public function game(): ?TrackmaniaNationsForever
    {
        $game = $this->games->find(TrackmaniaNationsForever::SLUG);

        return $game instanceof TrackmaniaNationsForever ? $game : null;
    }

    public static function slugOf(CarbonInterface $start): string
    {
        return 'tmnf-'.$start->copy()->setTimezone(BlockfillWeeks::TIMEZONE)->toDateString();
    }

    /**
     * A track of `esports.tmnf.tracks` by its UID, or null for one the league does not run.
     *
     * @return array{uid: string, name: string, author: string, environment: string, author_ms: int, file: string}|null
     */
    public static function track(?string $uid): ?array
    {
        $track = $uid === null ? null : (config('esports.tmnf.tracks') ?? [])[$uid] ?? null;

        if (! is_array($track)) {
            return null;
        }

        return [
            'uid' => (string) $uid,
            'name' => (string) ($track['name'] ?? $uid),
            'author' => (string) ($track['author'] ?? ''),
            'environment' => (string) ($track['environment'] ?? ''),
            'author_ms' => (int) ($track['author_ms'] ?? 0),
            // Where the server finds it, relative to GameData/Tracks (InsertChallenge, ChooseNextChallenge).
            'file' => (string) ($track['file'] ?? ''),
        ];
    }

    /**
     * The track of the week starting at `$start`: the configured tracks in turn; null without any.
     */
    public static function trackFor(CarbonInterface $start): ?string
    {
        $uids = array_keys((array) config('esports.tmnf.tracks', []));

        if ($uids === []) {
            return null;
        }

        $weeks = (int) floor(CarbonImmutable::parse(self::CYCLE_START, BlockfillWeeks::TIMEZONE)->diffInDays(CarbonImmutable::instance($start)->setTimezone(BlockfillWeeks::TIMEZONE), false) / 7);

        return (string) $uids[(($weeks % count($uids)) + count($uids)) % count($uids)];
    }

    public function find(CarbonInterface $start): ?Tournament
    {
        return Tournament::query()->where('slug', self::slugOf($start))->where('game', TrackmaniaNationsForever::SLUG)->first();
    }

    public function current(?CarbonInterface $now = null): ?Tournament
    {
        return $this->find(BlockfillWeeks::startOf($now ?? now()));
    }

    public function previous(?CarbonInterface $now = null): ?Tournament
    {
        return $this->find(BlockfillWeeks::startOf(BlockfillWeeks::startOf($now ?? now())->subSecond()));
    }

    /**
     * Opens the leaderboard of the week `$now` lies in, once, if an admin
     * approved it and the listener saw the server on its track. Null while
     * TMNF is not registered, or the week is not approved or not on its track yet.
     */
    public function open(?CarbonInterface $now = null): ?Tournament
    {
        if ($this->game() === null) {
            return null;
        }

        $now ??= now();
        $start = BlockfillWeeks::startOf($now);
        $existing = $this->find($start);

        if ($existing !== null) {
            return $existing;
        }

        $drafts = app(LeagueWeekDrafts::class);
        $plan = $drafts->startable(TrackmaniaNationsForever::SLUG, $start, $now);
        $track = $plan === null ? null : self::track((string) ($plan->settings['track'] ?? ''));

        // Never a week on a track the server is not on (TmnfTrackSwitch marks it once it saw it there).
        if ($plan === null || $track === null || $plan->track_ready_at === null) {
            return null;
        }

        $opens = LeagueWeekDrafts::startTime($plan);
        $end = BlockfillWeeks::endOf($start);
        $local = $start->setTimezone(BlockfillWeeks::TIMEZONE);
        $profile = GameProfile::for(TrackmaniaNationsForever::SLUG, TrackmaniaNationsForever::MODE);

        try {
            // forceCreate: `opened_by_league` is never mass assignable. False: a TMNF week mines no season block.
            $week = Tournament::query()->forceCreate([
                // Stored in English; pages show it in their language (title()).
                'name' => 'TMNF Week '.$local->isoWeek().', '.$local->isoWeekYear(),
                'game' => TrackmaniaNationsForever::SLUG,
                'mode' => TrackmaniaNationsForever::MODE,
                'format' => TournamentFormat::Leaderboard,
                'options' => FormatOptions::defaults($profile)->toArray(),
                'capacity' => 2,
                'starts_at' => $opens,
                'time_window' => 7,
                'times' => ['game' => $opens->diffInMinutes($end) / 1440],
                'on_site' => false,
                'results_mode' => TournamentResultsMode::Players,
                'status' => TournamentStatus::Running,
                'created_by_id' => null,
                'opened_by_league' => false,
                'slug' => self::slugOf($start),
                'published_at' => $opens,
                'score_course' => $track['uid'],
            ]);
        } catch (UniqueConstraintViolationException) {
            $week = $this->find($start);
        }

        if ($week !== null) {
            $drafts->started($plan, $week);
        }

        return $week;
    }

    /**
     * The track and the round's time limit the server should run now: the
     * approved plan of this week once it may start, else the running week's
     * track (a week from before the approvals has no time limit of its own).
     * Null when no week runs or is due: the server keeps what it has.
     *
     * @return array{track: array{uid: string, name: string, author: string, environment: string, author_ms: int, file: string}, limit_ms: int|null, plan: LeagueWeek|null}|null
     */
    public function target(?CarbonInterface $now = null): ?array
    {
        if ($this->game() === null) {
            return null;
        }

        $now ??= now();
        $start = BlockfillWeeks::startOf($now);
        $drafts = app(LeagueWeekDrafts::class);
        $week = $this->find($start);
        $plan = $week !== null ? $drafts->ofTournament($week) : $drafts->startable(TrackmaniaNationsForever::SLUG, $start, $now);

        if ($week === null && $plan === null) {
            return null;
        }

        $track = self::track($plan !== null ? (string) ($plan->settings['track'] ?? '') : $week->score_course);
        $minutes = $plan?->settings['time_limit_minutes'] ?? null;

        return $track === null ? null : ['track' => $track, 'limit_ms' => is_int($minutes) ? $minutes * 60_000 : null, 'plan' => $plan];
    }

    /**
     * The running week a finish counts for, or null: the run is a server
     * finish of a linked player (held or counted), on the week's track,
     * inside its window. The week of `$now` is opened on the way.
     */
    public function weekOf(ScoreRun $run, ?CarbonInterface $now = null): ?Tournament
    {
        if ($this->game() === null || $run->game !== TrackmaniaNationsForever::SLUG || $run->source !== ServerIngest::SOURCE || $run->user_id === null || $run->rejected_at !== null) {
            return null;
        }

        $start = BlockfillWeeks::startOf($run->achieved_at);
        $week = $start->equalTo(BlockfillWeeks::startOf($now ?? now())) ? $this->open($now) : $this->find($start);

        if ($week === null || $week->status !== TournamentStatus::Running || $week->score_course !== $run->course || ! ScoreWindow::of($week)->contains($run->achieved_at)) {
            return null;
        }

        return $week;
    }

    /**
     * A finish joins its player to its week (no sign-up). Returns the week, or null when it counts in none.
     */
    public function record(ScoreRun $run, ?CarbonInterface $now = null): ?Tournament
    {
        $week = $this->weekOf($run, $now);
        $user = $week === null ? null : User::query()->find($run->user_id);

        if ($week === null || $user === null) {
            return null;
        }

        app(LeaderboardEntries::class)->join($week, $user);

        return $week;
    }

    /**
     * Opens this week's leaderboard, and every running week's players with a
     * finish on its track inside its window who are not in it yet join.
     *
     * @return array{opened: bool, joined: int}
     */
    public function sweep(?CarbonInterface $now = null): array
    {
        $done = ['opened' => false, 'joined' => 0];

        if ($this->game() === null) {
            return $done;
        }

        try {
            // The admins' part first (LeagueWeekDrafts): next week's draft, their bell entries.
            app(LeagueWeekDrafts::class)->prepare(TrackmaniaNationsForever::SLUG, $now);
        } catch (Throwable $e) {
            report($e);
        }

        $done['opened'] = $this->open($now) !== null;

        if (! $done['opened']) {
            try {
                // Approved and due, but the server is not on its track (no listener): the admins hear it once.
                app(TmnfTrackSwitch::class)->overdue(CarbonImmutable::instance($now ?? now()));
            } catch (Throwable $e) {
                report($e);
            }
        }

        foreach (Tournament::query()->where(['game' => TrackmaniaNationsForever::SLUG, 'status' => TournamentStatus::Running])->orderBy('starts_at')->get() as $week) {
            try {
                if (! $week->isTmnfWeek()) {
                    continue;
                }

                $window = ScoreWindow::of($week);
                $entered = $week->participants()->pluck('user_id')->filter()->all();
                $missing = ScoreRun::query()->where(['game' => TrackmaniaNationsForever::SLUG, 'source' => ServerIngest::SOURCE, 'course' => (string) $week->score_course])
                    ->whereNotNull('user_id')->whereNull('rejected_at')->whereNotIn('user_id', $entered)
                    ->where('achieved_at', '>=', $window->start)->where('achieved_at', '<', $window->end)
                    ->distinct()->pluck('user_id');

                foreach (User::query()->whereIn('id', $missing)->orderBy('id')->get() as $user) {
                    $done['joined'] += app(LeaderboardEntries::class)->join($week, $user) ? 1 : 0;
                }
            } catch (Throwable $e) {
                // Each week on its own: one that fails is reported, the others go on.
                report($e);
            }
        }

        return $done;
    }

    /**
     * Signs the 31923 of every running week that has none yet, once per week
     * (TournamentPublisher::announce()). Nothing while TMNF is off or without
     * the league key. Returns how many were signed.
     */
    public function announce(): int
    {
        if ($this->game() === null) {
            return 0;
        }

        $signed = 0;

        foreach (Tournament::query()->where(['game' => TrackmaniaNationsForever::SLUG, 'status' => TournamentStatus::Running])->whereNull('event_id')->orderBy('starts_at')->get() as $week) {
            if (! $week->isTmnfWeek()) {
                continue;
            }

            try {
                $signed += $this->publisher->announce($week) === null ? 0 : 1;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $signed;
    }
}
