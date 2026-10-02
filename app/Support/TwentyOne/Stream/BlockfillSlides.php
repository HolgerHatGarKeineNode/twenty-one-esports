<?php

namespace App\Support\TwentyOne\Stream;

use App\Enums\StackerRunStatus;
use App\Games\Blockfill;
use App\Games\GameRegistry;
use App\Games\ScoreMetric;
use App\Models\StackerRun;
use App\Models\Tournament;
use App\Support\Matches\ScoreAttempts;
use App\Support\Scores\ScoreRuns;
use App\Support\Scores\ScoreStanding;
use App\Support\Scores\ScoreWindow;
use App\Support\Stacker\BlockfillRules;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\StackerRuns;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Blockfill's slide set on the stream, next to the f1 teaser (BlockfillSlide):
 *
 * - f2 the week's board: the top BOARD_PLACES with the time and the gap to
 *   first, everyone counted, and the countdown to the end of the week
 *   (Monday 00:00 Europe/Berlin), ticking with the frame's clock.
 * - f3 the fresh blocks: the week's latest FRESH_RUNS runs, verified ones
 *   with their time and what they did (`top`: took first place and holds it;
 *   `was`: took first place, beaten since; `best`: the player's own best of
 *   the week improved), runs still waiting
 *   for the replay marked unconfirmed and never with their claimed time.
 * - f4 the moment: a run that took first place in the last
 *   `twentyone.stream.rotation.blockfill_moment_minutes` minutes, with the
 *   time it beat. The planner shows it once, first in line (RotationPlanner).
 * - f5 the call to play: the game page, its QR code and the time to beat.
 *
 * Every slide carries the game's mark: its cover (`cover`, the image of the
 * game tiles and cards) top left and "Blockfill" in its copy, so a viewer who
 * joins mid-rotation sees which game this is.
 *
 * state() tells the planner which of them apply: OFF while Blockfill is not
 * registered, IDLE without a running week (the f1 teaser covers that), EMPTY
 * while the running week has nobody on its board (the call to play only),
 * RUNNING with the moment's key, if any.
 *
 * The daemon polls every second: the read is cached for CACHE_SECONDS in the
 * cache store, the avatars come from StreamImages' bounded map, the
 * countdown is computed from the frame's clock. Players show only with their
 * avatar and name. Stream copy is English, whatever the app locale.
 */
final class BlockfillSlides
{
    public const BOARD = 'f2';

    public const FRESH = 'f3';

    public const MOMENT = 'f4';

    public const PLAY = 'f5';

    /** @var list<string> */
    public const SCENES = [self::BOARD, self::FRESH, self::MOMENT, self::PLAY];

    public const OFF = 'off';

    public const IDLE = 'idle';

    public const EMPTY = 'empty';

    public const RUNNING = 'running';

    /** Places the board lists (two columns of five). */
    public const BOARD_PLACES = 10;

    /** Runs the fresh blocks show. */
    public const FRESH_RUNS = 4;

    public const CACHE_KEY = 'twentyone:stream:blockfill-slides';

    private const CACHE_SECONDS = 15;

    /** @var array<string, string|null> the cover's data URI by game slug, null for a missing file */
    private static array $covers = [];

    public function __construct(
        private BlockfillWeeks $weeks,
        private ScoreRuns $runs,
        private StreamImages $images,
    ) {}

    /**
     * What the planner needs: the week's state and the key of a new #1 of the
     * last minutes (only while RUNNING). Cached like the slides.
     *
     * @return array{week: string, moment: string|null}
     */
    public function state(?CarbonInterface $now = null): array
    {
        $read = $now === null ? $this->cachedRead() : $this->read(CarbonImmutable::instance($now));

        return $read === null ? ['week' => self::OFF, 'moment' => null]
            : ['week' => $read['week'], 'moment' => $read['week'] === self::RUNNING ? ($read['moment']['key'] ?? null) : null];
    }

    /**
     * The scene data of one slide of the set: the set's data, the stats bar,
     * and Blockfill's blurred cover (the brand's while it is not built).
     *
     * @param  array<string, mixed>  $stats
     * @return array<string, mixed>
     */
    public function scene(int $nowMs, array $stats): array
    {
        $read = $this->cachedRead();
        $data = $read === null ? null : $this->framed($read, $nowMs);

        return [
            'blockfill' => $data,
            'stats' => $stats,
            'siteQrSvg' => app(SceneSource::class)->qr('blockfill'),
            'backdrop' => ($data === null ? null : $this->images->backdrop(Blockfill::SLUG)) ?? $this->images->backdrop(StreamImages::BRAND),
            'cover' => $data === null ? null : $this->cover(),
        ];
    }

    /**
     * Blockfill's cover, the image of its game tiles and cards, as the slides'
     * game mark: the stream's prebuilt tile (288x162), else the smallest JPEG
     * of the cover, read once per process.
     */
    private function cover(): ?string
    {
        $tile = $this->images->coverTile(Blockfill::SLUG);

        if ($tile !== null) {
            return $tile;
        }

        if (! array_key_exists(Blockfill::SLUG, self::$covers)) {
            $cover = app(GameRegistry::class)->cover(Blockfill::SLUG);
            self::$covers[Blockfill::SLUG] = $cover === null ? null : TournamentSlides::coverUri(public_path($cover->path($cover->smallest(), 'jpg')));
        }

        return self::$covers[Blockfill::SLUG];
    }

    /**
     * The set's data, read now (no cache), with avatars and the countdown at `$now`.
     *
     * @return array<string, mixed>|null
     */
    public function data(?CarbonInterface $now = null): ?array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $read = $this->read($now);

        return $read === null ? null : $this->framed($read, (int) $now->getTimestampMs());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cachedRead(): ?array
    {
        try {
            $read = Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => ['data' => $this->read(CarbonImmutable::now())]);
        } catch (Throwable $e) {
            report($e);
            $read = ['data' => $this->read(CarbonImmutable::now())];
        }

        return is_array($read['data'] ?? null) ? $read['data'] : null;
    }

    /**
     * The clock-free read: plain scalars and avatar refs, so the cache can hold it.
     *
     * @return array<string, mixed>|null
     */
    private function read(CarbonImmutable $now): ?array
    {
        if ($this->weeks->game() === null) {
            return null;
        }

        $previousLocale = app()->getLocale();
        app()->setLocale('en');

        try {
            $week = $this->weeks->current($now);
            $window = $week === null ? null : ScoreWindow::of($week);
            $start = BlockfillWeeks::startOf($now);
            $end = $window->end ?? BlockfillWeeks::endOf($start);
            $local = $start->setTimezone(BlockfillWeeks::TIMEZONE);
            // the rules runs are played on now: their blocks, and the only runs a badge compares
            $engine = $week === null ? $this->weeks->difficultyAt($now) : $this->weeks->difficultyOf($week);
            $base = [
                'title' => $week?->title() ?? 'Blockfill Week '.$local->isoWeek().', '.$local->isoWeekYear(),
                'goal' => BlockfillRules::goal($engine),
                'endsMs' => (int) $end->getTimestampMs(),
                'closes' => $end->setTimezone(BlockfillWeeks::TIMEZONE)->format('l H:i').' Berlin',
                'url' => $this->url(),
            ];

            if ($week === null || ! $window->contains($now)) {
                return [...$base, 'week' => self::IDLE, 'players' => 0, 'board' => [], 'fresh' => [], 'moment' => null];
            }

            $board = $this->board($week);
            $fresh = $this->fresh($now, $start, $end, $engine);

            return [
                ...$base,
                'week' => $board['rows'] === [] ? self::EMPTY : self::RUNNING,
                'players' => $board['players'],
                'board' => $board['rows'],
                'fresh' => $fresh,
                'moment' => $board['rows'] === [] ? null : $this->moment($now, $engine),
            ];
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    /**
     * The placed rows of the week, best first, at most BOARD_PLACES, with the gap to first; and how many are placed.
     *
     * @return array{rows: list<array{place: int, name: string, ms: int, ref: array{id: int, pubkey: string, source: string|null}|null}>, players: int}
     */
    private function board(Tournament $week): array
    {
        $placed = array_values(array_filter($this->runs->standings($week), fn (ScoreStanding $row): bool => $row->place !== null && $row->value !== null));

        return [
            'rows' => array_map(fn (ScoreStanding $row): array => [
                'place' => (int) $row->place,
                'name' => (string) $row->participant->name,
                'ms' => (int) $row->value,
                'ref' => StreamImages::avatarRef($row->participant->user),
            ], array_slice($placed, 0, self::BOARD_PLACES)),
            'players' => count($placed),
        ];
    }

    /**
     * The week's latest runs, newest first: verified and waiting ones (ScoreAttempts' states), never a time unchecked.
     *
     * @return list<array{name: string, ms: int|null, badge: string|null, at: int, ref: array{id: int, pubkey: string, source: string|null}|null}>
     */
    private function fresh(CarbonImmutable $now, CarbonImmutable $start, CarbonImmutable $end, string $engine): array
    {
        $runs = ScoreAttempts::stacker('all')->with('user')
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->latest()->latest('id')->limit(self::FRESH_RUNS)->get();

        return array_values($runs->map(function (StackerRun $run) use ($now, $engine): array {
            $waiting = ScoreAttempts::isWaiting($run);

            return [
                'name' => $run->user->displayName(),
                'ms' => $waiting ? null : Blockfill::milliseconds((int) $run->ticks),
                'badge' => $waiting ? null : $this->badge($run, $engine),
                'at' => (int) (ScoreAttempts::at($run) ?? $now)->getTimestamp(),
                'ref' => StreamImages::avatarRef($run->user),
            ];
        })->all());
    }

    /**
     * `top` when the run took first place when it came in and still holds it,
     * `was` when it took first place and a faster run has beaten it since,
     * `best` when it beat the player's own earlier run of the week, else null.
     * Only one run of the week is `top` at a time: the stream never shows two
     * "New #1" at once. Every ranked run is verified, so a slower one gets no
     * badge at all. Only runs on the week's rules (`$engine`) compare, and only
     * they get a badge: a 40-block time is no first place of a 60-block week.
     */
    private function badge(StackerRun $run, string $engine): ?string
    {
        if ($run->engine !== $engine) {
            return null;
        }

        if ($this->before($run)->where('ticks', '<=', (int) $run->ticks)->doesntExist()) {
            return StackerRun::query()->where('week', $this->weekOfRun($run))->where('engine', $run->engine)->where('status', StackerRunStatus::Verified)
                ->where('ticks', '<', (int) $run->ticks)->exists() ? 'was' : 'top';
        }

        $own = fn (): Builder => $this->before($run)->where('user_id', $run->user_id);

        return $own()->exists() && $own()->where('ticks', '<=', (int) $run->ticks)->doesntExist() ? 'best' : null;
    }

    /**
     * The verified runs of the run's week and rules that came in before it: verified in
     * an earlier second, or in the same second with a lower id. Only `<` on
     * the timestamp: SQLite keeps it as text with milliseconds, so `=` against
     * a bound second never matches.
     *
     * @return Builder<StackerRun>
     */
    private function before(StackerRun $run): Builder
    {
        $at = $run->verified_at === null ? null : CarbonImmutable::instance($run->verified_at)->startOfSecond();

        return StackerRun::query()->where('week', $this->weekOfRun($run))->where('engine', $run->engine)
            ->where('status', StackerRunStatus::Verified)->whereKeyNot($run->id)
            ->when($at !== null, fn (Builder $query) => $query->where('verified_at', '<', $at->addSecond())
                ->where(fn (Builder $query) => $query->where('verified_at', '<', $at)->orWhere('id', '<', $run->id)));
    }

    /** The week a run counts for. */
    private function weekOfRun(StackerRun $run): string
    {
        return $run->week ?? StackerRuns::weekOf($run->created_at ?? now());
    }

    /**
     * The latest run of this week that took first place in the last minutes,
     * with the best time of the week before it.
     *
     * @return array{key: string, name: string, ms: int, at: int, ref: array{id: int, pubkey: string, source: string|null}|null, before: array{name: string, ms: int, own: bool}|null}|null
     */
    private function moment(CarbonImmutable $now, string $engine): ?array
    {
        $minutes = max(1, (int) config('twentyone.stream.rotation.blockfill_moment_minutes', 10));
        $recent = StackerRun::query()->with('user')
            ->where('week', StackerRuns::weekOf($now))->where('status', StackerRunStatus::Verified)
            ->where('verified_at', '>=', $now->subMinutes($minutes))
            ->orderByDesc('verified_at')->orderByDesc('id')->limit(self::FRESH_RUNS)->get();

        foreach ($recent as $run) {
            if ($this->badge($run, $engine) !== 'top') {
                continue;
            }

            $previous = $this->before($run)->with('user')->orderBy('ticks')->orderBy('verified_at')->first();

            return [
                'key' => 'run-'.$run->id,
                'name' => $run->user->displayName(),
                'ms' => Blockfill::milliseconds((int) $run->ticks),
                'at' => (int) $run->verified_at?->getTimestamp(),
                'ref' => StreamImages::avatarRef($run->user),
                'before' => $previous === null ? null : [
                    'name' => $previous->user->displayName(),
                    'ms' => Blockfill::milliseconds((int) $previous->ticks),
                    'own' => $previous->user_id === $run->user_id,
                ],
            ];
        }

        return null;
    }

    /**
     * The read made a frame at `$nowMs`: times formatted, avatars added, the countdown ticking.
     *
     * @param  array<string, mixed>  $read
     * @return array<string, mixed>
     */
    private function framed(array $read, int $nowMs): array
    {
        $metric = ScoreMetric::time();
        // The refs come back from the cache store: only a complete one reaches StreamImages.
        $avatar = fn (mixed $ref): ?string => is_array($ref) && is_int($ref['id'] ?? null) && is_string($ref['pubkey'] ?? null)
            ? $this->images->avatar(['id' => $ref['id'], 'pubkey' => $ref['pubkey'], 'source' => is_string($ref['source'] ?? null) ? $ref['source'] : null])
            : null;
        $first = $read['board'][0]['ms'] ?? null;
        $ago = fn (int $at): string => self::ago(intdiv($nowMs, 1000) - $at);
        $moment = $read['moment'];

        return [
            'week' => $read['week'],
            'title' => $read['title'],
            'goal' => (int) ($read['goal'] ?? 40),
            'countdown' => TournamentSlides::countdown($read['endsMs'], $nowMs),
            'closes' => $read['closes'],
            'players' => $read['players'],
            'board' => array_map(fn (array $row): array => [
                'place' => $row['place'],
                'name' => $row['name'],
                'time' => $metric->format($row['ms']),
                'gap' => $row['place'] === 1 || $first === null ? null : self::gap($row['ms'] - $first),
                'avatar' => $avatar($row['ref']),
            ], $read['board']),
            'fresh' => array_map(fn (array $run): array => [
                'name' => $run['name'],
                'time' => $run['ms'] === null ? null : $metric->format($run['ms']),
                'waiting' => $run['ms'] === null,
                'badge' => $run['badge'],
                'when' => $ago($run['at']),
                'avatar' => $avatar($run['ref']),
            ], $read['fresh']),
            'moment' => $moment === null ? null : [
                'key' => $moment['key'],
                'name' => $moment['name'],
                'time' => $metric->format($moment['ms']),
                'avatar' => $avatar($moment['ref']),
                'when' => $ago($moment['at']),
                'before' => $moment['before'] === null ? null : ['name' => $moment['before']['name'], 'time' => $metric->format($moment['before']['ms'])],
                'own' => (bool) ($moment['before']['own'] ?? false),
                'by' => $moment['before'] === null ? null : self::seconds($moment['before']['ms'] - $moment['ms']),
            ],
            'leader' => ($read['board'][0] ?? null) === null ? null : ['name' => $read['board'][0]['name'], 'time' => $metric->format($read['board'][0]['ms'])],
            'url' => $read['url'],
        ];
    }

    /** How long ago, in English whatever the app locale: "just now", "1 minute ago", "3 hours ago", "2 days ago". */
    public static function ago(int $seconds): string
    {
        [$n, $unit] = match (true) {
            $seconds < 60 => [0, ''],
            $seconds < 3600 => [intdiv($seconds, 60), 'minute'],
            $seconds < 86400 => [intdiv($seconds, 3600), 'hour'],
            default => [intdiv($seconds, 86400), 'day'],
        };

        return $n === 0 ? 'just now' : $n.' '.$unit.($n === 1 ? '' : 's').' ago';
    }

    /** The gap to first: "+0.200", "+12.480", from a minute "+1:02.500". */
    public static function gap(int $ms): string
    {
        return '+'.($ms < 60_000 ? sprintf('%d.%03d', intdiv($ms, 1000), $ms % 1000) : ScoreMetric::time()->format($ms));
    }

    /** A margin in words: "1.884 s", from a minute "1:02.500". */
    public static function seconds(int $ms): string
    {
        return $ms < 60_000 ? sprintf('%d.%03d s', intdiv($ms, 1000), $ms % 1000) : ScoreMetric::time()->format($ms);
    }

    /** The game page without its scheme, as the other slides print the site. */
    private function url(): string
    {
        return rtrim((string) preg_replace('#^https?://#', '', (string) config('twentyone.stream.scene.url')), '/').'/blockfill';
    }
}
