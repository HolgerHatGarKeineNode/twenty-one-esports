<?php

namespace App\Support\Stacker;

use App\Enums\StackerRunStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Games\Blockfill;
use App\Games\GameRegistry;
use App\Models\ScoreRun;
use App\Models\StackerRun;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Scores\LeaderboardEntries;
use App\Support\Scores\LeagueWeekDrafts;
use App\Support\Scores\ScoreCourse;
use App\Support\Scores\ScoreLeaderboards;
use App\Support\Scores\ScoreRuns;
use App\Support\Scores\ScoreWindow;
use App\Support\Scores\Sources\ReplayScoreSource;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentPublisher;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * The casual weekly hunt of Blockfill (plan "Blockfill", P4): one
 * leaderboard tournament per week, Monday 00:00 to Monday 00:00
 * Europe/Berlin (a week of 167 or 169 hours when the clocks change), the
 * same week a run counts in (StackerRuns::weekOf()).
 *
 * - open(): the week's leaderboard, made once (its slug
 *   `blockfill-<monday>` is unique), running and published from its start,
 *   but only once an admin approved the week (LeagueWeekDrafts, user
 *   2026-10-02): at its planned start, or at the approval when that came
 *   later, ending on the following Monday 00:00 either way. Not approved, no
 *   week runs. The scheduler opens it every hour (`blockfill:weeks`); the
 *   first verified run of a week opens it too, if it came first.
 * - The week's rules (BlockfillRules: lines, level-ups, gravity curve) as
 *   their engine id: the engine its ranked runs are issued and verified on;
 *   only a run of that engine counts for it.
 * - record(): a verified run joins its player to the week's leaderboard (no
 *   sign-up, no cup, no draw) and is stored as a score run of the replay
 *   source. A run outside a running week's window, or of a finished week,
 *   changes nothing.
 * - announce(): the week's NIP-52 calendar event (31923), once (P6).
 * - sweep(): what record() missed (it failed, or the verifier answered
 *   while the switch was off) joins on the next hourly run, and the score
 *   runs are read again (ScoreLeaderboards::snapshot()).
 *
 * The leaderboard is the score kind's own: ScoreRuns ranks it (lowest time
 * first, a tie to the earlier submission), ScoreLeaderboards ends it after
 * the review time (`scores:tick`), ScorePoints gives the points per place.
 * No Elo, no series, no casual queue: a score game is left out of all of
 * those. Everything here does nothing while Blockfill is not registered
 * (`esports.blockfill.enabled` off).
 */
final class BlockfillWeeks
{
    public const TIMEZONE = 'Europe/Berlin';

    public function __construct(private GameRegistry $games, private ScoreRuns $runs, private ScoreLeaderboards $leaderboards, private TournamentPublisher $publisher) {}

    public function game(): ?Blockfill
    {
        $game = $this->games->find(Blockfill::SLUG);

        return $game instanceof Blockfill ? $game : null;
    }

    /**
     * The start of the week `$at` lies in: Monday 00:00 Europe/Berlin, in UTC.
     */
    public static function startOf(CarbonInterface $at): CarbonImmutable
    {
        return CarbonImmutable::parse(StackerRuns::weekOf($at), self::TIMEZONE)->startOfDay()->utc();
    }

    /**
     * The end of the week starting at `$start` (the next Monday 00:00 Europe/Berlin), in UTC.
     */
    public static function endOf(CarbonInterface $start): CarbonImmutable
    {
        return CarbonImmutable::instance($start)->setTimezone(self::TIMEZONE)->addWeek()->startOfDay()->utc();
    }

    public static function slugOf(CarbonInterface $start): string
    {
        return 'blockfill-'.$start->copy()->setTimezone(self::TIMEZONE)->toDateString();
    }

    /**
     * A leaderboard's name for pages, in the page's language (Tournament::title()).
     */
    public static function title(Tournament $tournament): string
    {
        return $tournament->title();
    }

    /**
     * The leaderboard of the week starting at `$start`, if it was opened.
     */
    public function find(CarbonInterface $start): ?Tournament
    {
        return Tournament::query()->where('slug', self::slugOf($start))->where('game', Blockfill::SLUG)->first();
    }

    /**
     * The leaderboard of the week `$now` lies in, if it was opened.
     */
    public function current(?CarbonInterface $now = null): ?Tournament
    {
        return $this->find(self::startOf($now ?? now()));
    }

    /**
     * The leaderboard of the week before the one `$now` lies in, if it was opened.
     */
    public function previous(?CarbonInterface $now = null): ?Tournament
    {
        return $this->find(self::startOf(self::startOf($now ?? now())->subSecond()));
    }

    /**
     * The rules (engine id, BlockfillRules) ranked runs are issued on at `$at`
     * (now): the running week's, or the default while no week runs.
     */
    public function difficultyAt(?CarbonInterface $at = null): string
    {
        $week = $this->current($at);

        return $week === null ? BlockfillRules::default() : $this->difficultyOf($week);
    }

    /**
     * The rules (engine id, BlockfillRules) of a week: its plan's, or the default for a week from before the approvals.
     */
    public function difficultyOf(Tournament $week): string
    {
        return (string) LeagueWeekDrafts::normalize(Blockfill::SLUG, app(LeagueWeekDrafts::class)->ofTournament($week)?->settings)['difficulty'];
    }

    /**
     * Opens the leaderboard of the week `$now` lies in, once, if an admin
     * approved it (LeagueWeekDrafts::startable()). Null while Blockfill is not
     * registered or the week is not approved.
     */
    public function open(?CarbonInterface $now = null): ?Tournament
    {
        $game = $this->game();

        if ($game === null) {
            return null;
        }

        $now ??= now();
        $start = self::startOf($now);
        $existing = $this->find($start);

        if ($existing !== null) {
            return $existing;
        }

        $drafts = app(LeagueWeekDrafts::class);
        $plan = $drafts->startable(Blockfill::SLUG, $start, $now);

        if ($plan === null) {
            return null;
        }

        // At its planned start, or at the approval when that came later; the end is the Monday either way.
        $opens = LeagueWeekDrafts::startTime($plan);
        $end = self::endOf($start);
        $local = $start->setTimezone(self::TIMEZONE);
        $profile = GameProfile::for(Blockfill::SLUG, Blockfill::MODE);

        try {
            // forceCreate: `opened_by_league` is never mass assignable (audit F1 of plan "AoE2 und Trackmania", P7).
            $week = Tournament::query()->forceCreate([
                // Stored in English; pages show it in their language (title()).
                'name' => 'Blockfill Week '.$local->isoWeek().', '.$local->isoWeekYear(),
                'game' => Blockfill::SLUG,
                'mode' => Blockfill::MODE,
                'format' => TournamentFormat::Leaderboard,
                'options' => FormatOptions::defaults($profile)->toArray(),
                'capacity' => 2,
                'starts_at' => $opens,
                'time_window' => 7,
                // The window is the rest of this week: 167, 168 or 169 hours from Monday, less for a late start (ScoreWindow reads the game length in days).
                'times' => ['game' => $opens->diffInMinutes($end) / 1440],
                'on_site' => false,
                'results_mode' => TournamentResultsMode::Players,
                'status' => TournamentStatus::Running,
                'created_by_id' => null,
                // The league's own window: the only kind that can mine a solo block (plan "Blockfill", P7).
                'opened_by_league' => true,
                'slug' => self::slugOf($start),
                'published_at' => $opens,
                'score_course' => Blockfill::MODE,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another run opened it first.
            $week = $this->find($start);
        }

        if ($week !== null) {
            $drafts->started($plan, $week);
        }

        return $week;
    }

    /**
     * A verified run joins its player to its week's leaderboard and is stored
     * there. Null when it counts nowhere: Blockfill off, no verified time, its
     * week finished or never opened (an older week is not opened late).
     */
    public function record(StackerRun $run, ?CarbonInterface $now = null): ?ScoreRun
    {
        $game = $this->game();

        if ($game === null || $run->status !== StackerRunStatus::Verified || $run->ticks === null || $run->submitted_at === null) {
            return null;
        }

        $start = self::startOf($run->submitted_at);
        $week = $start->equalTo(self::startOf($now ?? now())) ? $this->open($now) : $this->find($start);

        // Only a run on the week's rules counts for it (one issued before a new week started does not).
        if ($week === null || $week->status !== TournamentStatus::Running || $run->engine !== $this->difficultyOf($week)) {
            return null;
        }

        $window = ScoreWindow::of($week);
        $record = ReplayScoreSource::record($run);

        if (! $window->contains($record->achievedAt)) {
            return null;
        }

        $user = User::query()->find($run->user_id);

        if ($user === null) {
            return null;
        }

        $this->join($week, $user);

        return $this->runs->store($record, $user->id, new ScoreCourse($game, $game->modes()[Blockfill::MODE], Blockfill::MODE), $window);
    }

    /**
     * Makes next week's draft when it is due, opens this week's leaderboard
     * if it was approved; every running week's verified players
     * who are not in it yet join, and its score runs are read again.
     *
     * @return array{opened: bool, joined: int, read: int}
     */
    public function sweep(?CarbonInterface $now = null): array
    {
        $done = ['opened' => false, 'joined' => 0, 'read' => 0];

        if ($this->game() === null) {
            return $done;
        }

        try {
            // The admins' part first (LeagueWeekDrafts): next week's draft, their bell entries.
            app(LeagueWeekDrafts::class)->prepare(Blockfill::SLUG, $now);
        } catch (Throwable $e) {
            report($e);
        }

        $done['opened'] = $this->open($now) !== null;

        foreach (Tournament::query()->where(['game' => Blockfill::SLUG, 'status' => TournamentStatus::Running])->orderBy('starts_at')->get() as $week) {
            try {
                $window = ScoreWindow::of($week);
                $entered = $week->participants()->pluck('user_id')->filter()->all();
                $missing = StackerRun::query()->where('status', StackerRunStatus::Verified)->where('engine', $this->difficultyOf($week))
                    ->where('submitted_at', '>=', $window->start->format('Y-m-d H:i:s.v'))
                    ->where('submitted_at', '<', $window->end->format('Y-m-d H:i:s.v'))
                    ->whereNotIn('user_id', $entered)->distinct()->pluck('user_id');

                foreach (User::query()->whereIn('id', $missing)->orderBy('id')->get() as $user) {
                    $done['joined'] += $this->join($week, $user) ? 1 : 0;
                }

                $done['read'] += $this->leaderboards->snapshot($week)['stored'];
            } catch (Throwable $e) {
                // Each week on its own: one that fails is reported, the others go on.
                report($e);
            }
        }

        return $done;
    }

    /**
     * Signs the 31923 of every running week that has none yet (plan
     * "Blockfill", P6; NIP "Tournaments", `d` = the week's slug), once per
     * week (TournamentPublisher::announce()). Nothing while Blockfill is not
     * registered or without the league key; the next hourly run tries again.
     * Returns how many were signed.
     */
    public function announce(): int
    {
        if ($this->game() === null) {
            return 0;
        }

        $signed = 0;

        foreach (Tournament::query()->where(['game' => Blockfill::SLUG, 'status' => TournamentStatus::Running])->whereNull('event_id')->orderBy('starts_at')->get() as $week) {
            if (! $week->isBlockfillWeek()) {
                continue;
            }

            try {
                $signed += $this->publisher->announce($week) === null ? 0 : 1;
            } catch (Throwable $e) {
                // One week that fails is reported; the next run tries it again.
                report($e);
            }
        }

        return $signed;
    }

    /**
     * Enters the player into the week, once (LeaderboardEntries): a
     * participant and a slot on the leaderboard's one board, seeded in the
     * order they came. True when the player was new to it.
     */
    public function join(Tournament $week, User $user): bool
    {
        return app(LeaderboardEntries::class)->join($week, $user);
    }
}
