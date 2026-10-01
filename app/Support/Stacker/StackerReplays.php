<?php

namespace App\Support\Stacker;

use App\Enums\StackerRunStatus;
use App\Games\Blockfill;
use App\Models\ScoreRun;
use App\Models\StackerRun;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Scores\ScoreRuns;
use App\Support\Scores\ScoreStanding;
use App\Support\Scores\ScoreWindow;
use App\Support\Scores\Sources\ReplayScoreSource;

/**
 * Who may watch which Blockfill replay (plan "Blockfill", P5): the viewer
 * page `stacker.replay` asks canView(), the boards and /matches link only
 * what forStandings() and forRuns() allow, by the same rules.
 *
 * A run has a replay to watch while it keeps one (StackerRuns::keepWeekTop(),
 * keepHeld()) and is verified or held for review. Then:
 * - its player watches it, held or not;
 * - an admin watches it when it carries cheat hints (held, or decided);
 * - everybody watches it once its week has ended (the leaderboard's window,
 *   Monday 00:00 Berlin) when it is the time of one of that week's first
 *   PUBLIC_TOP places on the board.
 * Nobody else, guests included. The page shows the player's avatar and
 * name, nothing else of theirs.
 */
final class StackerReplays
{
    /** The places of an ended week whose replays everybody may watch. */
    public const PUBLIC_TOP = 10;

    /** Replays per player on the replays page (`stacker.replays`). */
    public const LIST_LIMIT = 12;

    /** Another player's newest runs read to find their public replays among them. */
    public const SCAN_LIMIT = 60;

    /** Finished weeks the replays page offers. */
    public const WEEKS = 6;

    /** Held runs the replays page shows an admin; the review list has them all. */
    public const HELD_LIMIT = 5;

    public function __construct(private ScoreRuns $scores, private BlockfillWeeks $weeks) {}

    /**
     * Whether the run holds a replay that can be shown at all.
     */
    public static function watchable(StackerRun $run): bool
    {
        return $run->replay !== null && in_array($run->status, [StackerRunStatus::Verified, StackerRunStatus::Review], true);
    }

    /**
     * Whether the run carries cheat hints (P5, NodeVerifier::HINTS).
     */
    public static function flagged(StackerRun $run): bool
    {
        return ((array) ($run->flags['hints']['flags'] ?? [])) !== [];
    }

    /**
     * The run's cheat hints for an admin, each with the number behind it.
     *
     * @return list<string>
     */
    public static function hintLines(StackerRun $run): array
    {
        $hints = (array) ($run->flags['hints'] ?? []);
        $finesse = (array) ($hints['finesse'] ?? []);

        return array_values(array_map(fn (string $flag): string => match ($flag) {
            'pps' => __('More than 5 pieces per second (:value)', ['value' => number_format((float) ($hints['pps'] ?? 0), 2)]),
            'same-tick' => __('More than 3 key presses in one tick (:value)', ['value' => (int) ($hints['maxPressesPerTick'] ?? 0)]),
            'timing' => __('An even key rhythm (variation :value)', ['value' => number_format((float) ($hints['timingCv'] ?? 0), 2)]),
            'finesse' => __('Every piece placed with the fewest presses (:perfect of :of)', ['perfect' => (int) ($finesse['perfect'] ?? 0), 'of' => (int) ($finesse['of'] ?? 0)]),
            default => $flag,
        }, (array) ($hints['flags'] ?? [])));
    }

    /**
     * What the replay viewer (components/stacker/replay-viewer) starts
     * from: the run's replay, its time and end state, and the viewer's words.
     *
     * @return array<string, mixed>
     */
    public static function viewerConfig(StackerRun $run): array
    {
        return [
            'replay' => (string) $run->replay,
            'ticks' => (int) $run->ticks,
            'hash' => (string) $run->state_hash,
            'testing' => app()->environment('testing'),
            't' => [
                'noBlock' => __('No block mined so far'),
                'block' => __('Block :n of 40'),
                'minedAt' => __('mined at :time'),
                'broken' => __('This replay cannot be played.'),
                'play' => __('Play'),
                'pause' => __('Pause'),
                'slider' => __(':time, block :n of 40'),
                'tick' => __('Tick :n'),
            ],
        ];
    }

    public function canView(?User $viewer, StackerRun $run): bool
    {
        if (! self::watchable($run)) {
            return false;
        }

        if ($viewer !== null && ($viewer->id === $run->user_id || ($viewer->isAdmin() && self::flagged($run)))) {
            return true;
        }

        return $run->status === StackerRunStatus::Verified && in_array($run->id, $this->publicIn($this->weekOf($run)), true);
    }

    /**
     * The runs of an ended week everybody may watch: the board's first
     * PUBLIC_TOP places. Empty while the week runs.
     *
     * @return list<int>
     */
    public function publicIn(?Tournament $week): array
    {
        if ($week === null || ! ScoreWindow::of($week)->hasEnded()) {
            return [];
        }

        $top = array_values(array_filter(
            $this->scores->standings($week),
            fn (ScoreStanding $row): bool => $row->place !== null && $row->place <= self::PUBLIC_TOP && $row->runId !== null,
        ));

        return array_values(self::runIds(array_map(fn (ScoreStanding $row): int => (int) $row->runId, $top)));
    }

    /**
     * The week a run counts in, if it was opened.
     */
    public function weekOf(StackerRun $run): ?Tournament
    {
        return $run->submitted_at === null ? null : $this->weeks->find(BlockfillWeeks::startOf($run->submitted_at));
    }

    /**
     * Replay links of a leaderboard's rows the viewer may watch, by
     * participant id. Empty for any board that is not Blockfill's. Three
     * queries at most, whatever the number of rows.
     *
     * @param  list<ScoreStanding>  $standings
     * @return array<int, string>
     */
    public function forStandings(array $standings, ?User $viewer): array
    {
        $rows = array_values(array_filter($standings, fn (ScoreStanding $row): bool => $row->source === ReplayScoreSource::KEY && $row->runId !== null));

        if ($rows === [] || ! $this->routed()) {
            return [];
        }

        $week = Tournament::query()->find($rows[0]->participant->tournament_id);

        if ($week === null || $week->game !== Blockfill::SLUG) {
            return [];
        }

        $stacker = self::runIds(array_map(fn (ScoreStanding $row): int => (int) $row->runId, $rows));
        $runs = StackerRun::query()->whereKey(array_values($stacker))->whereNotNull('replay')
            ->get(['id', 'user_id', 'status', 'flags'])->keyBy('id');
        $ended = ScoreWindow::of($week)->hasEnded();
        $links = [];

        foreach ($rows as $row) {
            $run = $runs->get($stacker[(int) $row->runId] ?? 0);

            if (! $run instanceof StackerRun || $run->status !== StackerRunStatus::Verified) {
                continue;
            }

            $public = $ended && $row->place !== null && $row->place <= self::PUBLIC_TOP;
            $own = $viewer !== null && $viewer->id === $run->user_id;
            $admin = $viewer !== null && $viewer->isAdmin() && self::flagged($run);

            if ($public || $own || $admin) {
                $links[$row->participant->id] = route('stacker.replay', $run->id);
            }
        }

        return $links;
    }

    /**
     * Replay links of stacker runs the viewer may watch, by run id (the
     * attempts on /matches). A public replay needs its week's board, read
     * once per ended week.
     *
     * @param  iterable<StackerRun>  $runs
     * @return array<int, string>
     */
    public function forRuns(iterable $runs, ?User $viewer): array
    {
        if (! $this->routed()) {
            return [];
        }

        $links = [];
        $public = [];

        foreach ($runs as $run) {
            if (! self::watchable($run)) {
                continue;
            }

            $allowed = $viewer !== null && ($viewer->id === $run->user_id || ($viewer->isAdmin() && self::flagged($run)));

            if (! $allowed && $run->status === StackerRunStatus::Verified && $run->week !== null) {
                $public[$run->week] ??= $this->publicIn($this->weekOf($run));
                $allowed = in_array($run->id, $public[$run->week], true);
            }

            if ($allowed) {
                $links[$run->id] = route('stacker.replay', $run->id);
            }
        }

        return $links;
    }

    /**
     * The finished weeks whose first ten everybody may watch, newest first:
     * the weeks before the running one (each ended Monday 00:00 Berlin).
     *
     * @return list<Tournament>
     */
    public function endedWeeks(int $limit = self::WEEKS): array
    {
        $running = BlockfillWeeks::startOf(now());

        return array_values(Tournament::query()->where('game', Blockfill::SLUG)->where('starts_at', '<', $running)
            ->orderByDesc('starts_at')->limit($limit)->get()
            ->filter(fn (Tournament $week): bool => ScoreWindow::of($week)->hasEnded())->all());
    }

    /**
     * An ended week's public replays: its first PUBLIC_TOP places that still
     * hold a verified replay, by place. Empty while the week runs. Four
     * queries for the board, one for the runs with their players.
     *
     * @return list<array{place: int, name: string, run: StackerRun, href: string}>
     */
    public function top(?Tournament $week): array
    {
        if ($week === null || ! ScoreWindow::of($week)->hasEnded() || ! $this->routed()) {
            return [];
        }

        $rows = array_values(array_filter($this->scores->standings($week),
            fn (ScoreStanding $row): bool => $row->place !== null && $row->place <= self::PUBLIC_TOP && $row->runId !== null));
        $stacker = self::runIds(array_map(fn (ScoreStanding $row): int => (int) $row->runId, $rows));
        $runs = StackerRun::query()->whereKey(array_values($stacker))->where('status', StackerRunStatus::Verified)->whereNotNull('replay')
            ->with('user')->get()->keyBy('id');
        $top = [];

        foreach ($rows as $row) {
            $run = $runs->get($stacker[(int) $row->runId] ?? 0);

            if ($run instanceof StackerRun) {
                $top[] = ['place' => (int) $row->place, 'name' => (string) $row->participant->name, 'run' => $run, 'href' => route('stacker.replay', $run->id)];
            }
        }

        return $top;
    }

    /**
     * The replays of `$player` that `$viewer` may watch, newest first, each
     * with its week and, for the run that holds the player's place on that
     * week's board, the place. The player sees all of their own (held ones
     * included); anybody else only the public ones (forRuns()).
     *
     * @return list<array{run: StackerRun, week: ?Tournament, place: ?int, href: string}>
     */
    public function ofPlayer(User $player, ?User $viewer, int $limit = self::LIST_LIMIT): array
    {
        if (! $this->routed()) {
            return [];
        }

        $own = $viewer !== null && $viewer->id === $player->id;
        $runs = StackerRun::query()->where('user_id', $player->id)->whereNotNull('replay')
            ->whereIn('status', $own ? [StackerRunStatus::Verified, StackerRunStatus::Review] : [StackerRunStatus::Verified])
            ->latest('submitted_at')->latest('id')->limit($own ? $limit : self::SCAN_LIMIT)->get();
        $links = $this->forRuns($runs, $viewer);
        $shown = array_slice(array_values($runs->filter(fn (StackerRun $run): bool => isset($links[$run->id]))->all()), 0, $limit);
        $weeks = [];
        $places = [];

        foreach ($shown as $run) {
            $key = (string) $run->week;

            if (! array_key_exists($key, $weeks)) {
                $weeks[$key] = $this->weekOf($run);
                $places[$key] = $weeks[$key] === null ? [] : $this->placesOf($weeks[$key], $player);
            }
        }

        return array_map(fn (StackerRun $run): array => [
            'run' => $run->setRelation('user', $player),
            'week' => $weeks[(string) $run->week],
            'place' => $places[(string) $run->week][$run->id] ?? null,
            'href' => $links[$run->id],
        ], $shown);
    }

    /**
     * The runs held for an admin's check that `$viewer` may watch: an
     * admin's only (they carry cheat hints), fastest first, with the number
     * held in all.
     *
     * @return array{runs: list<StackerRun>, total: int}
     */
    public function held(?User $viewer, int $limit = self::HELD_LIMIT): array
    {
        if ($viewer === null || ! $viewer->isAdmin() || ! $this->routed()) {
            return ['runs' => [], 'total' => 0];
        }

        $held = fn () => StackerRun::query()->where('status', StackerRunStatus::Review)->whereNotNull('replay');
        $runs = $held()->with('user')->orderBy('ticks')->orderBy('id')->limit($limit)->get();

        return [
            'runs' => array_values($runs->filter(fn (StackerRun $run): bool => self::flagged($run))->all()),
            'total' => $held()->count(),
        ];
    }

    /**
     * The places on a week's board held by `$player`'s runs, by stacker run id.
     *
     * @return array<int, int>
     */
    private function placesOf(Tournament $week, User $player): array
    {
        $rows = array_values(array_filter($this->scores->standings($week),
            fn (ScoreStanding $row): bool => $row->participant->user_id === $player->id && $row->place !== null && $row->runId !== null));
        $stacker = self::runIds(array_map(fn (ScoreStanding $row): int => (int) $row->runId, $rows));
        $places = [];

        foreach ($rows as $row) {
            if (isset($stacker[(int) $row->runId])) {
                $places[$stacker[(int) $row->runId]] = (int) $row->place;
            }
        }

        return $places;
    }

    private function routed(): bool
    {
        return app('router')->has('stacker.replay');
    }

    /**
     * The stacker run behind each score run of the replay source, by score run id.
     *
     * @param  list<int>  $scoreRunIds
     * @return array<int, int>
     */
    private static function runIds(array $scoreRunIds): array
    {
        if ($scoreRunIds === []) {
            return [];
        }

        return ScoreRun::query()->whereKey($scoreRunIds)->where('source', ReplayScoreSource::KEY)->whereNotNull('external_id')
            ->pluck('external_id', 'id')->map(fn (mixed $id): int => (int) $id)->all();
    }
}
