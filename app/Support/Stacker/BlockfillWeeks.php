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
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\TournamentRound;
use App\Models\TournamentStage;
use App\Models\User;
use App\Support\Scores\ScoreCourse;
use App\Support\Scores\ScoreLeaderboards;
use App\Support\Scores\ScoreRuns;
use App\Support\Scores\ScoreWindow;
use App\Support\Scores\Sources\ReplayScoreSource;
use App\Support\Tournaments\Engine\Slot;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The casual weekly hunt of Blockfill (plan "Blockfill", P4): one
 * leaderboard tournament per week, Monday 00:00 to Monday 00:00
 * Europe/Berlin (a week of 167 or 169 hours when the clocks change), the
 * same week a run counts in (StackerRuns::weekOf()).
 *
 * - open(): the week's leaderboard, made once (its slug
 *   `blockfill-<monday>` is unique), running and published from its start.
 *   The scheduler opens it every hour (`blockfill:weeks`); the first
 *   verified run of a week opens it too, if it came first.
 * - record(): a verified run joins its player to the week's leaderboard (no
 *   sign-up, no cup, no draw) and is stored as a score run of the replay
 *   source. A run outside a running week's window, or of a finished week,
 *   changes nothing.
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

    public function __construct(private GameRegistry $games, private ScoreRuns $runs, private ScoreLeaderboards $leaderboards) {}

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
     * A leaderboard's name for pages, in the page's language: a Blockfill
     * week as "Blockfill Week 41, 2026" / "Blockfill Woche 41, 2026" (its
     * stored name is the English one), any other tournament its own name.
     */
    public static function title(Tournament $tournament): string
    {
        if ($tournament->game !== Blockfill::SLUG || ! str_starts_with((string) $tournament->slug, 'blockfill-')) {
            return $tournament->name;
        }

        $local = $tournament->starts_at->toImmutable()->setTimezone(self::TIMEZONE);

        return __('Blockfill Week :week, :year', ['week' => $local->isoWeek(), 'year' => $local->isoWeekYear()]);
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
     * Opens the leaderboard of the week `$now` lies in, once. Null while
     * Blockfill is not registered.
     */
    public function open(?CarbonInterface $now = null): ?Tournament
    {
        $game = $this->game();

        if ($game === null) {
            return null;
        }

        $start = self::startOf($now ?? now());
        $existing = $this->find($start);

        if ($existing !== null) {
            return $existing;
        }

        $end = self::endOf($start);
        $local = $start->setTimezone(self::TIMEZONE);
        $profile = GameProfile::for(Blockfill::SLUG, Blockfill::MODE);

        try {
            return Tournament::query()->create([
                // Stored in English; pages show it in their language (title()).
                'name' => 'Blockfill Week '.$local->isoWeek().', '.$local->isoWeekYear(),
                'game' => Blockfill::SLUG,
                'mode' => Blockfill::MODE,
                'format' => TournamentFormat::Leaderboard,
                'options' => FormatOptions::defaults($profile)->toArray(),
                'capacity' => 2,
                'starts_at' => $start,
                'time_window' => 7,
                // The window is exactly this week: 167, 168 or 169 hours (ScoreWindow reads the game length in days).
                'times' => ['game' => $start->diffInMinutes($end) / 1440],
                'on_site' => false,
                'results_mode' => TournamentResultsMode::Players,
                'status' => TournamentStatus::Running,
                'created_by_id' => null,
                'slug' => self::slugOf($start),
                'published_at' => $start,
                'score_course' => Blockfill::MODE,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another run opened it first.
            return $this->find($start);
        }
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

        if ($week === null || $week->status !== TournamentStatus::Running) {
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
     * Opens this week's leaderboard; every running week's verified players
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

        $done['opened'] = $this->open($now) !== null;

        foreach (Tournament::query()->where(['game' => Blockfill::SLUG, 'status' => TournamentStatus::Running])->orderBy('starts_at')->get() as $week) {
            try {
                $window = ScoreWindow::of($week);
                $entered = $week->participants()->pluck('user_id')->filter()->all();
                $missing = StackerRun::query()->where('status', StackerRunStatus::Verified)
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
     * Enters the player into the week, once: a participant and a slot on the
     * leaderboard's one board, seeded in the order they came. True when the
     * player was new to it.
     */
    public function join(Tournament $week, User $user): bool
    {
        return DB::transaction(function () use ($week, $user): bool {
            $locked = Tournament::query()->lockForUpdate()->findOrFail($week->id);

            if (TournamentParticipant::query()->where(['tournament_id' => $locked->id, 'user_id' => $user->id])->exists()) {
                return false;
            }

            $seed = (int) TournamentParticipant::query()->where('tournament_id', $locked->id)->max('seed') + 1;
            $participant = TournamentParticipant::query()->create([
                'tournament_id' => $locked->id,
                'user_id' => $user->id,
                'name' => mb_substr($user->displayName(), 0, 80),
                'seed' => $seed,
                'members' => [$user->id],
            ]);

            $board = $this->board($locked);
            $board->slots()->create([
                'slot' => $board->slots()->count(),
                'source' => Slot::entrant($participant->id)->toArray(),
                'tournament_participant_id' => $participant->id,
            ]);

            // The size it is planned and shown with follows the entries (two at least, as a leaderboard needs).
            $locked->forceFill(['capacity' => max(2, $seed)])->save();

            return true;
        });
    }

    /**
     * The leaderboard's one board match (the engine's Leaderboard bracket:
     * stage 1, round 1, key `board`), made with the first entry.
     */
    private function board(Tournament $week): TournamentMatch
    {
        $board = TournamentMatch::query()->where(['tournament_id' => $week->id, 'bracket' => 'board'])->first();

        if ($board !== null) {
            return $board;
        }

        $stage = TournamentStage::query()->create(['tournament_id' => $week->id, 'number' => 1, 'format' => TournamentFormat::Leaderboard]);
        $round = TournamentRound::query()->create(['tournament_stage_id' => $stage->id, 'number' => 1]);

        return $week->matches()->create([
            'tournament_round_id' => $round->id,
            'key' => 'board',
            'group' => null,
            'bracket' => 'board',
            'position' => 1,
            'if_needed' => false,
            'status' => 'ready',
        ]);
    }
}
