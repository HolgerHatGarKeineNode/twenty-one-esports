<?php

namespace App\Support\TwentyOne\Stream;

use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Games\ScoreMetric;
use App\Games\TrackmaniaNationsForever;
use App\Models\Tournament;
use App\Support\Scores\ScoreRuns;
use App\Support\Scores\ScoreStanding;
use App\Support\Scores\ScoreWindow;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Tmnf\TmnfWeeks;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * TMNF's slide set on the stream, next to the g1 teaser (TmnfSlide), each over
 * an official screenshot of the game with the scrim baked in
 * (resources/stream/stills/tmnf-*.jpg, sharp, not a blurred cover):
 *
 * - g2 the race to the author time: the week's top 5 as lanes, each player's
 *   marker as close to the author time's line as their best time is.
 * - g3 the call to join: our server TWENTY ONE, the four steps of How to
 *   join (the favourite link while the server's login is known) and the QR
 *   code of the game page's How to join.
 * - g4 the time to beat: the week's #1 big, how far off (or under) the
 *   author time, and "New #1 on the board" while the time is younger than
 *   NEW_MINUTES.
 *
 * Every slide carries the game's mark (its cover top left) and its name in the
 * copy. In the teaser pool while TMNF is registered (RotationPlanner::teasers()),
 * never otherwise: data() is null then and the views invite to every game.
 *
 * Which week: this week once somebody is on its board; before that last week
 * while it has a board; else this week without anybody. The read is cached
 * for CACHE_SECONDS; players show by league name and avatar only, never a TMNF
 * login. Stream copy is English, whatever the app locale.
 */
final class TmnfSlides
{
    public const RACE = 'g2';

    public const JOIN = 'g3';

    public const LEADER = 'g4';

    /** @var list<string> */
    public const SCENES = [self::RACE, self::JOIN, self::LEADER];

    /** The places the race shows. */
    public const LANES = 5;

    /** A #1 younger than this is "New #1 on the board". */
    public const NEW_MINUTES = 60;

    public const CACHE_KEY = 'twentyone:stream:tmnf-slides';

    private const CACHE_SECONDS = 15;

    /** The still behind each slide (resources/stream/stills). */
    private const STILLS = [self::RACE => 'tmnf-board', self::JOIN => 'tmnf-join', self::LEADER => 'tmnf-leader'];

    /** @var array<string, string|null> still or cover => its data URI, read once per process */
    private static array $files = [];

    public function __construct(
        private TmnfWeeks $weeks,
        private ScoreRuns $runs,
        private StreamImages $images,
    ) {}

    /**
     * The scene data of one slide: the set's data, the stats bar, the slide's
     * still as its backdrop (the brand's while TMNF is off), the game's cover
     * as its mark and the QR code of How to join.
     *
     * @param  array<string, mixed>  $stats
     * @return array<string, mixed>
     */
    public function scene(string $scene, int $nowMs, array $stats): array
    {
        $data = $this->framed($this->cachedRead(), $nowMs);

        return [
            'tmnf' => $data,
            'stats' => $stats,
            'siteQrSvg' => app(SceneSource::class)->qr('tmnf'),
            'backdrop' => ($data === null ? null : self::still($scene)) ?? $this->images->backdrop(StreamImages::BRAND),
            'cover' => $data === null ? null : $this->cover(),
        ];
    }

    /**
     * The set's data, read now (no cache), at `$now`.
     *
     * @return array<string, mixed>|null
     */
    public function data(?CarbonInterface $now = null): ?array
    {
        $now = CarbonImmutable::instance($now ?? now());

        return $this->framed($this->read($now), (int) $now->getTimestampMs());
    }

    /** A slide's still as a JPEG data URI, null for an unknown scene or a missing file. */
    public static function still(string $scene): ?string
    {
        $name = self::STILLS[$scene] ?? null;

        return $name === null ? null : self::file(resource_path('stream/stills/'.$name.'.jpg'));
    }

    /**
     * Where a lane's marker stands, from the left end of the lane (`$from`, the slowest shown) to the author
     * time's line (`$line`); a time under the author time stands past the line, at most `$past` px.
     */
    public static function laneX(int $ms, int $authorMs, int $span, float $from, float $line, float $past): float
    {
        $gap = $ms - $authorMs;

        if ($gap <= 0) {
            return round($line + min($past, $past * -$gap / max(1, $span)), 1);
        }

        return round($line - ($line - $from) * min(1, $gap / max(1, $span)), 1);
    }

    /** The difference to the author time in words: "0.560 off the author time", "0.120 under it", "on the author time". */
    public static function versusAuthor(int $ms, int $authorMs): string
    {
        $gap = $ms - $authorMs;

        return match (true) {
            $gap === 0 => 'right on the author time',
            $gap > 0 => BlockfillSlides::seconds($gap).' off the author time',
            default => BlockfillSlides::seconds(-$gap).' under the author time',
        };
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
            return $this->readWeek($now);
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    /**
     * read() in English.
     *
     * @return array<string, mixed>
     */
    private function readWeek(CarbonImmutable $now): array
    {
        $current = $this->weeks->current($now);
        $rows = $current === null ? [] : $this->rows($current);
        $week = $current;
        $state = 'running';

        if ($rows === []) {
            $previous = $this->weeks->previous($now);
            $last = $previous === null ? [] : $this->rows($previous);

            if ($previous !== null && $last !== []) {
                [$week, $rows, $state] = [$previous, $last, $previous->status === TournamentStatus::Finished ? 'finished' : 'checking'];
            } else {
                $state = 'empty';
            }
        }

        $start = BlockfillWeeks::startOf($now);
        // No week runs (not approved yet, LeagueWeekDrafts): no track is guessed for it.
        $track = TmnfWeeks::track($week?->score_course);
        $end = $week === null ? BlockfillWeeks::endOf($start) : ScoreWindow::of($week)->end;
        $login = config('esports.tmnf.server.login');

        return [
            'state' => $state,
            // No week runs (not approved yet, LeagueWeekDrafts): no week number is shown for it.
            'week' => $week?->title() ?? 'TMNF',
            'track' => $track['name'] ?? 'the track of the week',
            'author' => $track['author'] ?? '',
            'authorMs' => (int) ($track['author_ms'] ?? 0),
            'environment' => $track['environment'] ?? '',
            'players' => $week === null ? 0 : count(array_filter($this->runs->standings($week), fn (ScoreStanding $row): bool => $row->place !== null)),
            'rows' => $rows,
            'endsMs' => (int) $end->getTimestampMs(),
            'closes' => $end->setTimezone(BlockfillWeeks::TIMEZONE)->format('l H:i').' Berlin',
            'server' => (string) config('esports.tmnf.server.name'),
            'favourite' => is_string($login) && preg_match('/^[A-Za-z0-9_.-]{1,40}$/', trim($login)) === 1 ? 'tmtp://#addfavourite='.trim($login) : null,
            'url' => $this->url(),
        ];
    }

    /**
     * The placed rows of a week, best first, at most LANES.
     *
     * @return list<array{place: int, name: string, ms: int, at: int|null, ref: array{id: int, pubkey: string, source: string|null}|null}>
     */
    private function rows(Tournament $week): array
    {
        $placed = array_values(array_filter($this->runs->standings($week), fn (ScoreStanding $row): bool => $row->place !== null && $row->value !== null));

        return array_map(fn (ScoreStanding $row): array => [
            'place' => (int) $row->place,
            'name' => (string) $row->participant->name,
            'ms' => (int) $row->value,
            'at' => $row->achievedAt?->getTimestamp(),
            'ref' => StreamImages::avatarRef($row->participant->user),
        ], array_slice($placed, 0, self::LANES));
    }

    /**
     * The read made a frame at `$nowMs`: times formatted, avatars added, the lanes placed.
     *
     * @param  array<string, mixed>|null  $read
     * @return array<string, mixed>|null
     */
    private function framed(?array $read, int $nowMs): ?array
    {
        if ($read === null) {
            return null;
        }

        $metric = ScoreMetric::time();
        $author = (int) $read['authorMs'];
        $rows = array_values(array_filter((array) $read['rows'], 'is_array'));
        // The lane runs from the slowest time shown (at least a second off) to the author time's line.
        $span = max(1000, ...array_map(fn (array $row): int => (int) $row['ms'] - $author, $rows ?: [['ms' => $author]]));
        $lanes = [];

        foreach ($rows as $row) {
            $lanes[] = [
                'place' => (int) $row['place'],
                'name' => (string) $row['name'],
                'time' => $metric->format((int) $row['ms']),
                'versus' => $author > 0 ? self::gapToAuthor((int) $row['ms'], $author) : '',
                'under' => $author > 0 && (int) $row['ms'] < $author,
                'x' => $author > 0 ? self::laneX((int) $row['ms'], $author, $span, 400, 1088, 96) : 400.0,
                'avatar' => $this->avatar($row['ref'] ?? null),
            ];
        }

        $leader = $rows[0] ?? null;
        $age = is_int($leader['at'] ?? null) ? intdiv($nowMs, 1000) - $leader['at'] : null;

        return [
            'state' => (string) $read['state'],
            'week' => (string) $read['week'],
            'track' => (string) $read['track'],
            'author' => (string) $read['author'],
            'authorTime' => $author > 0 ? $metric->format($author) : null,
            'environment' => (string) $read['environment'],
            'players' => (int) $read['players'],
            'lanes' => $lanes,
            'leader' => $leader === null ? null : [
                'name' => (string) $leader['name'],
                'time' => $metric->format((int) $leader['ms']),
                'avatar' => $lanes[0]['avatar'] ?? null,
                'versus' => $author > 0 ? self::versusAuthor((int) $leader['ms'], $author) : null,
                'new' => $read['state'] === 'running' && $age !== null && $age < self::NEW_MINUTES * 60,
                'when' => $age === null ? null : 'Set '.BlockfillSlides::ago($age),
            ],
            'countdown' => $read['state'] === 'running' || $read['state'] === 'empty' ? TournamentSlides::countdown((int) $read['endsMs'], $nowMs) : null,
            'closes' => (string) $read['closes'],
            'server' => (string) $read['server'],
            'favourite' => is_string($read['favourite']) ? $read['favourite'] : null,
            'url' => (string) $read['url'],
        ];
    }

    /** The gap to the author time as the lanes print it: "+0.560", "-0.120", "±0.000". */
    private static function gapToAuthor(int $ms, int $authorMs): string
    {
        $gap = $ms - $authorMs;

        return ($gap > 0 ? '+' : ($gap < 0 ? '-' : '±')).substr(BlockfillSlides::gap(abs($gap)), 1);
    }

    /** A cached avatar ref (plain scalars from the cache store) as a data URI. */
    private function avatar(mixed $ref): ?string
    {
        return is_array($ref) && is_int($ref['id'] ?? null) && is_string($ref['pubkey'] ?? null)
            ? $this->images->avatar(['id' => $ref['id'], 'pubkey' => $ref['pubkey'], 'source' => is_string($ref['source'] ?? null) ? $ref['source'] : null])
            : null;
    }

    /** TMNF's cover as the slides' game mark: the stream's prebuilt tile, else the smallest JPEG of the cover. */
    private function cover(): ?string
    {
        $tile = $this->images->coverTile(TrackmaniaNationsForever::SLUG);

        if ($tile !== null) {
            return $tile;
        }

        $cover = app(GameRegistry::class)->cover(TrackmaniaNationsForever::SLUG);

        return $cover === null ? null : self::file(public_path($cover->path($cover->smallest(), 'jpg')));
    }

    /** A JPEG as a data URI, read once per process; null when it is missing or empty. */
    private static function file(string $path): ?string
    {
        if (! array_key_exists($path, self::$files)) {
            self::$files[$path] = TournamentSlides::coverUri(is_file($path) ? $path : null);
        }

        return self::$files[$path];
    }

    /** The game page's How to join without its scheme, as the other slides print the site. */
    private function url(): string
    {
        return rtrim((string) preg_replace('#^https?://#', '', (string) config('twentyone.stream.scene.url')), '/').'/scores/tmnf';
    }
}
