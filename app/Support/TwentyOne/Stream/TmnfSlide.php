<?php

namespace App\Support\TwentyOne\Stream;

use App\Enums\TournamentStatus;
use App\Games\ScoreMetric;
use App\Models\Tournament;
use App\Support\LeagueTime;
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
 * TrackMania Nations Forever on the stream (plan "Trackmania und Restposten",
 * P2): the still `g1` (resources/views/stream/rotation/g1-tmnf.blade.php)
 * with the week's track, its top 5 and how to join our server, in the teaser
 * pool while TMNF is registered (RotationPlanner), as Blockfill's f1.
 *
 * Which week: this week once somebody is on its board (`running`); before
 * that last week while it has a board (`finished`); else this week without
 * anybody (`empty`, the call to be the first). Null while TMNF is not registered.
 *
 * The daemon renders a frame every second: cached() keeps the read for
 * CACHE_SECONDS in the cache store (never in the process), the avatars are
 * added from StreamImages' own bounded map. Players show with their league
 * name and avatar only, never a TMNF login. Stream copy is English.
 */
final class TmnfSlide
{
    public const SCENE = 'g1';

    /** The places the slide lists. */
    public const TOP = 5;

    private const CACHE_KEY = 'twentyone:stream:tmnf-slide';

    private const CACHE_SECONDS = 15;

    public function __construct(
        private TmnfWeeks $weeks,
        private ScoreRuns $runs,
        private StreamImages $images,
    ) {}

    /**
     * data() read through the cache store for CACHE_SECONDS; a failing cache store reads directly.
     *
     * @return array{state: string, title: string, line: string, track: string, server: string, top: list<array{place: int, name: string, time: string, avatar: string|null}>, leader: array{name: string, time: string, avatar: string|null}|null, url: string}|null
     */
    public function cached(): ?array
    {
        try {
            $read = Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => ['data' => $this->read()]);
        } catch (Throwable $e) {
            report($e);
            $read = ['data' => $this->read()];
        }

        return $this->withAvatars(is_array($read['data'] ?? null) ? $read['data'] : null);
    }

    /**
     * The slide's data, read now.
     *
     * @return array{state: string, title: string, line: string, track: string, server: string, top: list<array{place: int, name: string, time: string, avatar: string|null}>, leader: array{name: string, time: string, avatar: string|null}|null, url: string}|null
     */
    public function data(?CarbonInterface $now = null): ?array
    {
        return $this->withAvatars($this->read($now));
    }

    /**
     * @return array{state: string, title: string, line: string, track: string, server: string, top: list<array{place: int, name: string, time: string, ref: array{id: int, pubkey: string, source: string|null}|null}>, url: string}|null
     */
    private function read(?CarbonInterface $now = null): ?array
    {
        if ($this->weeks->game() === null) {
            return null;
        }

        $now = CarbonImmutable::instance($now ?? now());
        $previousLocale = app()->getLocale();
        app()->setLocale('en');

        try {
            $current = $this->weeks->current($now);
            $top = $current === null ? [] : $this->top($current);

            if ($current !== null && $top !== []) {
                return $this->frame('running', $current, 'Ends '.LeagueTime::stamp(ScoreWindow::of($current)->end, null, 'en'), $top);
            }

            $previous = $this->weeks->previous($now);
            $last = $previous === null ? [] : $this->top($previous);

            if ($previous !== null && $last !== []) {
                return $this->frame('finished', $previous, $previous->status === TournamentStatus::Finished ? 'Final standings' : 'Week over, the times are being checked', $last);
            }

            $start = BlockfillWeeks::startOf($now);
            $local = $start->setTimezone(BlockfillWeeks::TIMEZONE);

            return [
                'state' => 'empty',
                'title' => $current?->title() ?? 'TMNF Week '.$local->isoWeek().', '.$local->isoWeekYear(),
                'line' => 'Nobody is on the board yet',
                'track' => $this->trackName($current->score_course ?? TmnfWeeks::trackFor($start)),
                'server' => (string) config('esports.tmnf.server.name'),
                'top' => [],
                'url' => $this->url(),
            ];
        } finally {
            app()->setLocale($previousLocale);
        }
    }

    /**
     * @param  list<array{place: int, name: string, time: string, ref: array{id: int, pubkey: string, source: string|null}|null}>  $top
     * @return array{state: string, title: string, line: string, track: string, server: string, top: list<array{place: int, name: string, time: string, ref: array{id: int, pubkey: string, source: string|null}|null}>, url: string}
     */
    private function frame(string $state, Tournament $week, string $line, array $top): array
    {
        return ['state' => $state, 'title' => $week->title(), 'line' => $line, 'track' => $this->trackName($week->score_course),
            'server' => (string) config('esports.tmnf.server.name'), 'top' => $top, 'url' => $this->url()];
    }

    private function trackName(?string $uid): string
    {
        return TmnfWeeks::track($uid)['name'] ?? 'the track of the week';
    }

    /**
     * The placed rows of a week, best first, at most TOP.
     *
     * @return list<array{place: int, name: string, time: string, ref: array{id: int, pubkey: string, source: string|null}|null}>
     */
    private function top(Tournament $week): array
    {
        $metric = ScoreMetric::time();
        $rows = array_filter($this->runs->standings($week), fn (ScoreStanding $row): bool => $row->place !== null && $row->value !== null);

        return array_map(fn (ScoreStanding $row): array => [
            'place' => (int) $row->place,
            'name' => (string) $row->participant->name,
            'time' => $metric->format((int) $row->value),
            'ref' => StreamImages::avatarRef($row->participant->user),
        ], array_slice(array_values($rows), 0, self::TOP));
    }

    /**
     * The rows with their pictures as data URIs, and the leader (the first row) apart.
     *
     * @param  array<string, mixed>|null  $data
     * @return array{state: string, title: string, line: string, track: string, server: string, top: list<array{place: int, name: string, time: string, avatar: string|null}>, leader: array{name: string, time: string, avatar: string|null}|null, url: string}|null
     */
    private function withAvatars(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $top = array_map(fn (array $row): array => [
            'place' => (int) $row['place'], 'name' => (string) $row['name'], 'time' => (string) $row['time'], 'avatar' => $this->images->avatar($row['ref'] ?? null),
        ], array_values((array) $data['top']));

        return [
            'state' => (string) $data['state'],
            'title' => (string) $data['title'],
            'line' => (string) $data['line'],
            'track' => (string) $data['track'],
            'server' => (string) $data['server'],
            'top' => $top,
            'leader' => $top === [] ? null : ['name' => $top[0]['name'], 'time' => $top[0]['time'], 'avatar' => $top[0]['avatar']],
            'url' => (string) $data['url'],
        ];
    }

    /** The game page without its scheme, as the other slides print the site. */
    private function url(): string
    {
        return rtrim((string) preg_replace('#^https?://#', '', (string) config('twentyone.stream.scene.url')), '/').'/scores/tmnf';
    }
}
