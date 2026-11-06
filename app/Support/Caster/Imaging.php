<?php

namespace App\Support\Caster;

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\Blocks\BlockComposer;
use App\Support\Caster\Blocks\JingleLibrary;
use App\Support\Caster\Programme\BroadcastClock;
use App\Support\TwentyOne\Stream\ChildEnvironment;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Radio imaging on the server (plan "TWENTY ONE Radio", P11; user
 * 2026-10-12: "Mehr typische Radio-Zwischenfüller, wie man sie von einem
 * Radiosender kennt. Es ist noch nicht lebhaft genug."): the Anchor's
 * jingles the workstation uploaded (ImagingStore, manifest.json in
 * `tv.commentary.imaging.dir`) go on air between clips, independent of the
 * programme blocks. SceneDirector asks choose() for every clip it starts and
 * wraps the clip's PCM with what it gets (wrap()); the jingle becomes part
 * of the clip, so the hold, the sentence ends and the news window include it.
 *
 * The rules, in this order:
 *  - never on a chat reply or live moment, never on a later part of a story,
 *    never on a block (it carries its own jingles);
 *  - a podcast replay (P12) opens with the podcast opener of its source
 *    (podcast-open-<source>, else podcast-open) on its first chunk and closes
 *    with the back-announce (podcast-close-<source>, else podcast-close) on
 *    its last; nothing in between;
 *  - quiet hours: a station ID only, when one is due;
 *  - a bulletin opens with the news open of its slot (headlines at :30) and
 *    closes with the news close (back-announce);
 *  - a rubric opens with its format opener (opener-<topic>, else rubric-<topic>),
 *    a history segment with the Geschichtsstunde, a teaser with coming-up,
 *    where the library has a jingle of that category;
 *  - a teaser opens with the promo of the format it teases (promo-<topic>),
 *    else coming-up or a generic promo;
 *  - else a station ID when the last one is `station_every_minutes` ago
 *    (a news open, a time-of-day ID, a format opener or promo counts as one:
 *    they all name the station), a long one when the
 *    last long one is `station_long_every_minutes` ago;
 *  - else a sweeper or liner on `sweeper_share` of the transitions between two
 *    talk clips;
 *  - a power intro over the first seconds of a music gap after a talk clip,
 *    on `power_intro_share` of the gaps, never just before a bulletin or
 *    rubric slot (powerIntro(); the stream music ducks under it);
 *  - never two jingles back to back: a clip starting less than
 *    `back_to_back_seconds` after a news close or power intro opens without;
 *  - the same line never within `repeat_minutes` (JingleLibrary's rotation,
 *    remembered in rotation.json next to the manifest, so a restart keeps it).
 *
 * Fails open towards the programme: no manifest, a category without jingles
 * or an undecodable file leave the clip as it was. The manifest is read again
 * whenever its file changes, so an upload takes effect without a restart.
 */
final class Imaging
{
    /** The categories that name the station: each of them resets the station-ID clock. */
    public const STATION_CATEGORIES = ['station', 'station-long', 'station-short', 'news', 'headlines', 'time-morning', 'time-day', 'time-evening', 'time-night'];

    /** The clip kinds that are talk (a sweeper may stand between two of them). */
    private const TALK_KINDS = [CasterScript::SCENE, CasterScript::FILLER, CasterScript::NEWS, CasterScript::FOLLOWED, CasterScript::REPLAY];

    private ?JingleLibrary $library = null;

    private ?int $manifestMtime = null;

    /** When a station ID played last (unix seconds), null: none since the start. */
    private ?int $stationAt = null;

    /** Whether the clip that started last was talk. */
    private bool $previousTalk = false;

    /** When a long station ID played last (unix seconds), null: none since the start. */
    private ?int $longStationAt = null;

    /** When the jingle the last clip closed with (or the power intro after it) ends, unix seconds; null: none. */
    private ?int $tailEndsAt = null;

    /** When a jingle rode a track change of the music last (unix seconds), null: none yet. */
    private ?int $trackChangeAt = null;

    /** @var Closure(): float */
    private Closure $random;

    /**
     * @param  array{dir?: string, station_every_minutes?: float|int, station_long_every_minutes?: float|int, sweeper_share?: float|int, power_intro_share?: float|int, back_to_back_seconds?: float|int, repeat_minutes?: float|int, pause_seconds?: float|int, music_change_share?: float|int, music_change_night_share?: float|int, music_change_every_minutes?: float|int}  $settings
     * @param  (Closure(): float)|null  $random  a number in [0, 1), mt_rand by default
     */
    public function __construct(
        private readonly array $settings,
        public readonly int $rate = 24000,
        ?Closure $random = null,
        private readonly ?BroadcastClock $clock = null,
    ) {
        $this->random = $random ?? fn (): float => mt_rand(0, 999) / 1000;
    }

    /** The imaging of tv.commentary.imaging, or null while it is switched off. */
    public static function fromConfig(): ?self
    {
        /** @var array{enabled?: bool, dir?: string, station_every_minutes?: float|int, station_long_every_minutes?: float|int, sweeper_share?: float|int, power_intro_share?: float|int, back_to_back_seconds?: float|int, repeat_minutes?: float|int, pause_seconds?: float|int} $settings */
        $settings = (array) config('tv.commentary.imaging', []);

        return ($settings['enabled'] ?? false) === true ? new self($settings, (int) config('tv.commentary.mix.sample_rate', 24000)) : null;
    }

    /** The uploaded library, read again when its manifest changed. */
    public function library(): JingleLibrary
    {
        $dir = (string) ($this->settings['dir'] ?? storage_path('app/stream/imaging'));
        $manifest = rtrim($dir, '/').'/manifest.json';
        clearstatcache(true, $manifest);
        $mtime = is_file($manifest) ? (int) filemtime($manifest) : 0;

        if ($this->library === null || $mtime !== $this->manifestMtime) {
            $this->library = new JingleLibrary($dir, (float) ($this->settings['repeat_minutes'] ?? 45), strict: true);
            $this->manifestMtime = $mtime;
        }

        return $this->library;
    }

    /**
     * The jingles a clip opens and closes with at `$now`, picked (and
     * remembered as played) by the rules of the class.
     *
     * @return array{before: array{id: string, line: string, category: string, file: string, seconds: float}|null, after: array{id: string, line: string, category: string, file: string, seconds: float}|null}
     */
    public function choose(CasterClip $clip, bool $continuation, CarbonImmutable $now): array
    {
        $none = ['before' => null, 'after' => null];
        $kind = $clip->kind;

        if ($continuation) {
            return $none;
        }

        // Never back to back: a jingle that ended (or ends, a news close) less than `back_to_back_seconds` before this clip.
        $backToBack = $this->tailEndsAt !== null && $now->getTimestamp() - $this->tailEndsAt < (float) ($this->settings['back_to_back_seconds'] ?? 12);
        $previousTalk = $this->previousTalk;
        $this->tailEndsAt = null;
        $this->previousTalk = in_array($kind, self::TALK_KINDS, true) && ! $clip->isBlock();

        if ($kind === CasterScript::PODCAST) {
            return $this->podcast($clip, $now);
        }

        // The announcement before a podcast and the back-announce after it: the podcast's own jingles stand between.
        if (in_array($kind, [CasterScript::PODCAST_INTRO, CasterScript::PODCAST_OUTRO], true)) {
            return $none;
        }

        if (in_array($kind, ClipSpool::LIVE_KINDS, true) || $clip->isBlock() || $this->library()->items() === []) {
            return $none;
        }

        $stationDue = $this->stationAt === null || $now->getTimestamp() - $this->stationAt >= 60 * (float) ($this->settings['station_every_minutes'] ?? 15);
        $longDue = $this->longStationAt === null || $now->getTimestamp() - $this->longStationAt >= 60 * (float) ($this->settings['station_long_every_minutes'] ?? 30);
        $before = [];
        $after = null;

        if (QuietHours::active($now)) {
            $before = $stationDue ? [['station-short', 'station']] : [];
        } else {
            $topic = in_array($kind, [CasterScript::RUBRIC, CasterScript::TEASER], true) ? (string) $clip->script()->value('topic') : '';
            $specific = match ($kind) {
                CasterScript::BULLETIN => $this->newsOpen($clip),
                // The long produced opener (anchor-imaging.json) first, the short block jingle of the rubric else.
                CasterScript::RUBRIC => $topic === '' ? null : ['opener-'.$topic, 'rubric-'.$topic],
                CasterScript::HISTORY => ['opener-geschichte', 'rubric-geschichte'],
                CasterScript::TEASER => [...($topic === '' ? [] : ['promo-'.$topic]), 'coming-up', 'promo'],
                default => null,
            };

            if ($specific !== null) {
                $before[] = $specific;
            }

            if ($stationDue) {
                $before[] = match (true) {
                    $longDue => ['station-long', 'station'],
                    ($this->random)() < 0.35 => ['time-'.BlockComposer::daypart($now), 'station-short', 'station'],
                    default => ['station-short', 'station'],
                };
            }

            if ($previousTalk && $this->previousTalk && ($this->random)() < (float) ($this->settings['sweeper_share'] ?? 0.35)) {
                $before[] = ($this->random)() < 0.5 ? ['sweeper', 'liner'] : ['liner', 'sweeper'];
            }

            if ($kind === CasterScript::BULLETIN) {
                $after = ['news-close', 'back-announce'];
            }
        }

        $opened = null;

        foreach ($backToBack ? [] : $before as $categories) {
            $opened = $this->library()->pick($categories, $now);

            if ($opened !== null) {
                break;
            }
        }

        $closed = $after === null ? null : $this->library()->pick($after, $now);

        if ($opened !== null && self::namesStation($opened['category'])) {
            $this->stationAt = $now->getTimestamp();
            $this->longStationAt = $opened['category'] === 'station-long' ? $now->getTimestamp() : $this->longStationAt;
        }

        if ($closed !== null) {
            $this->tailEndsAt = $now->getTimestamp() + (int) ceil((float) $clip->duration_seconds + ($opened['seconds'] ?? 0.0) + $closed['seconds']);
        }

        return ['before' => $opened, 'after' => $closed];
    }

    /**
     * Whether a jingle of `$category` counts as a station ID: the station categories, and the format openers and
     * promos of anchor-imaging.json, which all end with the station's name.
     */
    public static function namesStation(string $category): bool
    {
        return in_array($category, self::STATION_CATEGORIES, true) || str_starts_with($category, 'opener-') || str_starts_with($category, 'promo-');
    }

    /**
     * The power intro over the first seconds of a music gap (P11): after a talk clip by day, on `power_intro_share`
     * of the gaps, never when a bulletin or rubric slot starts within two minutes (its opener comes first); only a
     * talk clip leads to one, and a talk clip never closes with a jingle, so it never follows another. Picked and
     * remembered as played, null when none is due.
     *
     * @return array{id: string, line: string, category: string, file: string, seconds: float}|null
     */
    public function powerIntro(CasterClip $ended, CarbonImmutable $now): ?array
    {
        if (! in_array($ended->kind, self::TALK_KINDS, true) || $ended->isBlock() || QuietHours::active($now)
            || ($this->random)() >= (float) ($this->settings['power_intro_share'] ?? 0.3)) {
            return null;
        }

        $clock = $this->clock ?? BroadcastClock::fromConfig();

        foreach ($clock->enabled() ? $clock->upcoming($now, 2) : [] as $slot) {
            if (BroadcastClock::isBulletin($slot['type']) || $slot['type'] === BroadcastClock::RUBRIC) {
                return null;
            }
        }

        $intro = $this->library()->pick(['power-intro'], $now);

        if ($intro !== null) {
            $this->tailEndsAt = $now->getTimestamp() + (int) ceil($intro['seconds']);
            $this->previousTalk = false;
        }

        return $intro;
    }

    /**
     * A short jingle over a track change of the music (user 2026-10-17: "ganz kurze Jingles … bei einem
     * Musik-Titel-Wechsel … aber nicht immer, nur bei ruhigen Zeitphasen"): the Anchor alone on the timeline while
     * no clip is on air, the stream music ducks under it. Not every change — `music_change_share` of them, at most
     * one within `music_change_every_minutes` — and none in the two minutes before a bulletin or rubric slot. In
     * the quiet hours only the short station ID (the quiet hours' own imaging rule).
     *
     * @return array{id: string, line: string, category: string, file: string, seconds: float}|null
     */
    public function trackChange(CarbonImmutable $now): ?array
    {
        $night = QuietHours::active($now);
        $share = (float) ($this->settings[$night ? 'music_change_night_share' : 'music_change_share'] ?? 0.0);

        if ($share <= 0.0 || ($this->random)() >= $share) {
            return null;
        }

        if ($this->trackChangeAt !== null && $now->getTimestamp() - $this->trackChangeAt < 60 * (int) ($this->settings['music_change_every_minutes'] ?? 20)) {
            return null;
        }

        $clock = $this->clock ?? BroadcastClock::fromConfig();

        foreach ($clock->enabled() ? $clock->upcoming($now, 2) : [] as $slot) {
            if (BroadcastClock::isBulletin($slot['type']) || $slot['type'] === BroadcastClock::RUBRIC) {
                return null;
            }
        }

        $jingle = $this->library()->pick($night ? ['station-short'] : ['sweeper', 'liner', 'station-short'], $now);

        if ($jingle !== null) {
            $this->trackChangeAt = $now->getTimestamp();
            $this->tailEndsAt = $now->getTimestamp() + (int) ceil($jingle['seconds']);
            $this->previousTalk = false;
        }

        return $jingle;
    }

    /**
     * The clip's PCM with the chosen jingles around it, `pause_seconds` apart;
     * the sentence ends move behind the opener, and the end of each jingle is
     * a sentence end too (a takeover cuts after a jingle, never inside it).
     * A jingle that cannot be decoded is left out.
     *
     * @param  array{before: array{file: string}|null, after: array{file: string}|null}  $choice
     * @param  list<float>  $sentenceEnds
     * @return array{pcm: string, ends: list<float>, before: bool, after: bool}
     */
    public function wrap(array $choice, string $pcm, array $sentenceEnds): array
    {
        $pause = Pcm::silence(max(0.0, (float) ($this->settings['pause_seconds'] ?? 0.3)), $this->rate);
        $before = $choice['before'] === null ? null : $this->pcm($choice['before']['file']);
        $after = $choice['after'] === null ? null : $this->pcm($choice['after']['file']);
        $ends = $sentenceEnds;

        if ($before !== null) {
            $offset = Pcm::seconds($before.$pause, $this->rate);
            $ends = [round(Pcm::seconds($before, $this->rate), 3), ...array_map(fn (float $end): float => round($end + $offset, 3), $ends)];
            $pcm = $before.$pause.$pcm;
        }

        if ($after !== null) {
            $speech = round(Pcm::seconds($pcm, $this->rate), 3);
            $pcm .= $pause.$after;
            $ends = [...array_values(array_filter($ends, fn (float $end): bool => $end < $speech - 0.05)), $speech, round(Pcm::seconds($pcm, $this->rate), 3)];
        }

        return ['pcm' => $pcm, 'ends' => $ends, 'before' => $before !== null, 'after' => $after !== null];
    }

    /**
     * The opener of a podcast replay's first chunk and the back-announce after its last.
     *
     * @return array{before: array{id: string, line: string, category: string, file: string, seconds: float}|null, after: array{id: string, line: string, category: string, file: string, seconds: float}|null}
     */
    private function podcast(CasterClip $clip, CarbonImmutable $now): array
    {
        $podcast = $clip->podcastChunk() ?? ['source' => '', 'index' => 0, 'count' => 0];
        $source = $podcast['source'];
        $first = $podcast['index'] === 1;
        $last = $podcast['index'] > 0 && $podcast['index'] === $podcast['count'];
        $library = $this->library()->items() === [] ? null : $this->library();

        // The opener belongs to the episode: it plays even right after another jingle.
        $opened = $first ? $library?->pick(['podcast-open-'.$source, 'podcast-open'], $now) : null;
        $closed = $last ? $library?->pick(['podcast-close-'.$source, 'podcast-close'], $now) : null;

        if ($closed !== null) {
            $this->tailEndsAt = $now->getTimestamp() + (int) ceil((float) $clip->duration_seconds + ($opened['seconds'] ?? 0.0) + $closed['seconds']);
        }

        return ['before' => $opened, 'after' => $closed];
    }

    /**
     * The news open of a bulletin's slot: the headlines at :30, the news at :00.
     *
     * @return list<string>
     */
    private function newsOpen(CasterClip $clip): array
    {
        $airsAt = $clip->script()->value('airs_at');
        $clock = $this->clock ?? BroadcastClock::fromConfig();
        $type = $airsAt === null ? BroadcastClock::NEWS : $clock->slotAt(CarbonImmutable::parse((string) $airsAt))['type'];

        return $type === BroadcastClock::HEADLINES ? ['headlines', 'news-open', 'news'] : ['news-open', 'news'];
    }

    /** A jingle as PCM (s16le mono at the voice's rate), decoded by ffmpeg; null when it fails. */
    public function pcm(string $file): ?string
    {
        try {
            $result = Process::timeout(10)->env(ChildEnvironment::withoutSecrets())->run([
                (string) config('twentyone.stream.ffmpeg', 'ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error',
                '-i', $file, '-ac', '1', '-ar', (string) $this->rate, '-f', 's16le', 'pipe:1',
            ]);
        } catch (Throwable) {
            $result = null;
        }

        $pcm = $result !== null && $result->successful() ? $result->output() : '';
        $pcm = substr($pcm, 0, strlen($pcm) - strlen($pcm) % Pcm::BYTES_PER_SAMPLE);

        if ($pcm === '') {
            Log::warning('Caster imaging jingle failed', ['file' => basename($file)]);

            return null;
        }

        return $pcm;
    }
}
