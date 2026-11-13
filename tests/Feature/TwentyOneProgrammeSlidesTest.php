<?php

/*
|--------------------------------------------------------------------------
| The radio programme on the stream (plan "TWENTY ONE Radio", P19)
|--------------------------------------------------------------------------
|
| User 2026-10-13: "neue Slides für den Livestream, welche das Programm der Radio-Show zeigen … Das, was wirklich
| vorbereitet wurde und fertig als Audio auch laufen wird". Only clips READY in the spool (and a stored podcast
| episode, the night loop's stored episodes, the fixed news slot) reach the slides; a planned or unvoiced show never
| does. The rotation takes one programme slide a round and the spotlight of a show that starts within two minutes
| next, once; never while a tournament runs. Every slide renders at 1280 x 720 with every text inside the frame.
|
*/

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\Blocks\AutoPilot;
use App\Support\Caster\Blocks\StockFeeder;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\PodcastLibrary;
use App\Support\Caster\Programme\Shows;
use App\Support\Caster\ScenePhase;
use App\Support\TwentyOne\Stream\BoardScene;
use App\Support\TwentyOne\Stream\ProgrammeSlides;
use App\Support\TwentyOne\Stream\RotationKit;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\SceneRenderer;
use App\Support\TwentyOne\Stream\SceneSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.programme.enabled' => true,
        'tv.commentary.news.enabled' => true,
        'tv.commentary.programme.sport_weather.enabled' => true,
        'tv.commentary.podcast_replay.enabled' => false,
        'tv.commentary.podcast_replay.night.enabled' => false,
        'tv.commentary.spool.quiet_hours' => ['from' => '01:00', 'to' => '09:00', 'timezone' => 'Europe/Berlin'],
    ]);
    // A Tuesday afternoon in Berlin (CEST, UTC+2): 14:18.
    $this->travelTo(CarbonImmutable::parse('2026-10-13 14:18:00', 'Europe/Berlin'));
    // The workstation voices (the fixed news slot needs it); seen far enough ahead for every travel below.
    config(['tv.commentary.fetch.online_seconds' => 86400 * 7]);
    Cache::put(ClipSpool::FETCHER_SEEN, now()->getTimestamp(), 86400 * 7);
});

/**
 * A voiced script of `$kind` with `$topic`, airing at `$airsAt`, and its clip (`$status`, `$seconds` long).
 *
 * @param  array<string, mixed>  $script  more script columns
 * @param  array<string, mixed>|null  $block
 */
function programmeClip(string $kind, ?string $topic, ?CarbonImmutable $airsAt, float $seconds = 120, string $status = CasterClip::READY, array $script = [], ?array $block = null, ?string $clipKind = null): CasterClip
{
    $row = CasterScript::query()->create([
        'kind' => $kind, 'phase' => ScenePhase::BREAK, 'priority' => 0, 'text' => "Eins.\nZwei.", 'facts_hash' => 'h'.random_int(1, PHP_INT_MAX),
        'status' => CasterScript::VOICED, 'source' => CasterScript::SOURCE_AI, 'topic' => $topic, 'estimated_seconds' => $seconds,
        'airs_at' => $airsAt?->utc(), 'expires_at' => now()->addHours(3), ...$script,
    ]);

    return CasterClip::query()->create([
        'caster_script_id' => $row->id, 'phase' => ScenePhase::BREAK, 'kind' => $clipKind ?? $kind, 'path' => 'caster/spool/'.$row->id.'.opus',
        'duration_seconds' => $seconds, 'bytes' => 1000, 'sentence_ends' => [$seconds], 'status' => $status, 'expires_at' => now()->addHours(3), 'block' => $block,
    ]);
}

function programmeBerlin(string $time): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-13 '.$time, 'Europe/Berlin');
}

function programmeNow(): array
{
    return (new ProgrammeSlides(app(PodcastLibrary::class)))->programme(CarbonImmutable::now());
}

/** The PNG of a programme slide (null without rsvg-convert) and its SVG. */
function programmeSlide(string $scene, ?int $viewers = 7): array
{
    $data = [...app(SceneSource::class)->rotation($scene, null, [], 0, (int) now()->getTimestampMs(), []), 'viewers' => $viewers];
    $renderer = SceneRenderer::fromConfig();
    $svg = $renderer->svg($data, RotationPlanner::VIEWS[$scene]);
    $png = is_executable((string) (exec('command -v '.escapeshellarg((string) config('twentyone.stream.scene.rsvg_convert'))) ?: ''))
        ? $renderer->png($data, RotationPlanner::VIEWS[$scene]) : null;

    return [$svg, $png];
}

/**
 * Every text of an SVG with its left and right edge (as RotationKit measures the brand fonts).
 *
 * @return list<array{text: string, left: float, right: float, y: float}>
 */
function programmeTexts(string $svg): array
{
    preg_match_all('#<text[^>]*\sx="([\d.]+)"[^>]*\sy="([\d.]+)"[^>]*font-family="(Unbounded|JetBrains Mono)"[^>]*font-weight="\d+"[^>]*font-size="([\d.]+)"[^>]*>([^<]+)</text>#', $svg, $texts, PREG_SET_ORDER);
    $out = [];

    foreach ($texts as [$tag, $x, $y, $font, $size, $text]) {
        $text = html_entity_decode($text);
        $width = RotationKit::width($text, $font === 'Unbounded' ? RotationKit::DISPLAY : RotationKit::MONO, (float) $size) * ($font === 'Unbounded' ? 1.04 : 1.0);
        $left = match (true) {
            str_contains($tag, 'text-anchor="middle"') => (float) $x - $width / 2,
            str_contains($tag, 'text-anchor="end"') => (float) $x - $width,
            default => (float) $x,
        };
        $out[] = ['text' => $text, 'left' => $left, 'right' => $left + $width, 'y' => (float) $y];
    }

    return $out;
}

/** A busy afternoon: the tribunal on air, ready shows, a bulletin, stock blocks. */
function programmeAfternoon(): void
{
    programmeClip(CasterScript::RUBRIC, Shows::TRIBUNAL, programmeBerlin('14:15'), 540, CasterClip::PLAYING)->forceFill(['updated_at' => now()->subMinutes(3)])->saveQuietly();
    programmeClip(CasterScript::BULLETIN, null, programmeBerlin('14:30'), 150);
    programmeClip(CasterScript::RUBRIC, Shows::FRIEDHOF, programmeBerlin('14:45'), 420);
    programmeClip(CasterScript::RUBRIC, 'zentralbank', programmeBerlin('15:15'), 300);
    programmeClip(CasterScript::SCENE, 'stock:talk:abc', null, 150, block: ['dressed' => true, 'stock' => true], clipKind: CasterClip::BLOCK);
    programmeClip(CasterScript::SCENE, 'stock:talk:def', null, 210, block: ['dressed' => true, 'stock' => true], clipKind: CasterClip::BLOCK);
    programmeClip(CasterScript::HISTORY, null, null, 260);
}

test('only what is ready in the spool reaches the programme: on air, the next shows at their slot, the fixed news, the stock between', function () {
    programmeAfternoon();
    // Planned or unvoiced: a queued rubric script without a clip, an expired clip, a teaser (it announces, it is no show).
    CasterScript::query()->create(['kind' => CasterScript::RUBRIC, 'phase' => ScenePhase::BREAK, 'priority' => 0, 'text' => 'x', 'facts_hash' => 'q', 'status' => CasterScript::QUEUED,
        'source' => CasterScript::SOURCE_AI, 'topic' => Shows::MEETUPS, 'estimated_seconds' => 60, 'airs_at' => programmeBerlin('15:45')->utc(), 'expires_at' => now()->addHours(2)]);
    programmeClip(CasterScript::RUBRIC, Shows::HOTLINE, programmeBerlin('16:15'))->forceFill(['expires_at' => now()->subMinute()])->save();
    programmeClip(CasterScript::TEASER, Shows::PARTIE, programmeBerlin('14:44'), 20);

    $p = programmeNow();

    expect($p['onAir']['title'])->toBe('Chat-Tribunal')
        ->and($p['onAir']['left'])->toBe(360)
        ->and(array_map(fn (array $item): string => ProgrammeSlides::time($item['at']).' '.$item['title'].($item['ready'] ? '' : ' (fest)'), $p['upcoming']))
        ->toBe(['14:30 Kurznachrichten', '14:45 Shitcoin-Friedhof', '15:15 Zentralbank-Kläranlage'])
        ->and(array_map(fn (array $s): string => $s['label'].' '.ProgrammeSlides::length($s['seconds']), $p['stock']))->toBe(['Talk 6 min', 'Geschichtsstunde 4 min'])
        ->and($p['newsLine'])->toBe('Zur vollen Stunde Nachrichten, danach Sport und Wetter. Zur halben Stunde die Kurzfassung.')
        ->and(implode(' ', array_column($p['upcoming'], 'title')))->not->toContain('Meetup', 'Hotline', 'Partie');
});

test('the next bulletin slot always stands, ready or not; a rubric in parts is one show with all its seconds', function () {
    $first = programmeClip(CasterScript::RUBRIC, 'presseschau', programmeBerlin('14:45'), 100, script: ['part_group' => 'g1', 'part_index' => 1]);
    programmeClip(CasterScript::RUBRIC, 'presseschau', programmeBerlin('14:45'), 80, script: ['part_group' => 'g1', 'part_index' => 2]);

    $p = programmeNow();

    expect(array_map(fn (array $item): string => ProgrammeSlides::time($item['at']).' '.$item['title'].' '.$item['seconds'].($item['ready'] ? '' : ' fest'), $p['upcoming']))
        ->toBe(['14:30 Kurznachrichten 0 fest', '14:45 Presseschau 180'])
        ->and($first->exists)->toBeTrue();

    config(['tv.commentary.news.enabled' => false]);

    expect(array_column(programmeNow()['upcoming'], 'title'))->toBe(['Presseschau']);
});

test('a later part left alone in the spool is no show to come: the part before it aired or is gone, so it never plays', function () {
    programmeClip(CasterScript::RUBRIC, Shows::STAMMTISCH, programmeBerlin('13:15'), 329, script: ['part_group' => 'old', 'part_index' => 41]);
    $second = programmeClip(CasterScript::RUBRIC, 'presseschau', programmeBerlin('14:45'), 80, script: ['part_group' => 'g1', 'part_index' => 2]);

    expect(array_column(programmeNow()['upcoming'], 'key'))->not->toContain('rubric:old')->not->toContain('rubric:g1');

    // The part before it is still to be voiced: the show comes.
    CasterScript::query()->create([
        'kind' => CasterScript::RUBRIC, 'phase' => ScenePhase::BREAK, 'priority' => 0, 'text' => 'Eins.', 'facts_hash' => 'first-part', 'status' => CasterScript::QUEUED,
        'source' => CasterScript::SOURCE_AI, 'topic' => 'presseschau', 'estimated_seconds' => 100, 'airs_at' => $second->script->airs_at, 'expires_at' => now()->addHours(3),
        'part_group' => 'g1', 'part_index' => 1,
    ]);

    expect(array_column(programmeNow()['upcoming'], 'key'))->toContain('rubric:g1')->not->toContain('rubric:old');
});

test('without a voice the fixed news slot and the news sentence leave; the autopilot hour shows as the station ID, never in the spotlight', function (string $gone) {
    config(['tv.commentary.news.enabled' => true]);
    match ($gone) {
        'workstation' => Cache::forget(ClipSpool::FETCHER_SEEN),
        'voicestudio' => Cache::put(AutoPilot::VOICE_DOWN, now()->getTimestamp(), 3600),
    };
    programmeClip(CasterScript::RUBRIC, StockFeeder::HOUR_TOPIC, programmeBerlin('14:19'), 20);

    $slides = new ProgrammeSlides(app(PodcastLibrary::class));
    $p = $slides->programme(CarbonImmutable::now());

    expect(array_map(fn (array $item): string => ProgrammeSlides::time($item['at']).' '.$item['title'], $p['upcoming']))->toBe(['14:19 Stationsansage'])
        ->and($p['newsLine'])->toBeNull()
        ->and($slides->entries(CarbonImmutable::now())['soon'])->toBeNull()
        ->and(ProgrammeSlides::voiceOnline(CarbonImmutable::now()))->toBeFalse();

    Cache::put(ClipSpool::FETCHER_SEEN, now()->getTimestamp(), 3600);
    Cache::forget(AutoPilot::VOICE_DOWN);

    expect(ProgrammeSlides::voiceOnline(CarbonImmutable::now()))->toBeTrue();
})->with(['workstation', 'voicestudio']);

test('a podcast airing shows its episode and part on air; a stored daytime episode shows at its slot, an unstored one never', function () {
    config(['tv.commentary.podcast_replay.enabled' => true, 'tv.commentary.podcast_replay.sources.einundzwanzig.enabled' => true,
        'tv.commentary.podcast_replay.sources.einundzwanzig.feed' => 'https://example.org/feed.xml', 'tv.commentary.podcast_replay.sources.einundzwanzig.starts_at' => '16:03']);

    expect(array_filter(programmeNow()['upcoming'], fn (array $item): bool => $item['family'] === ProgrammeSlides::PODCAST))->toBe([]);

    Storage::disk('local')->put('caster/podcasts/einundzwanzig/episode.json', json_encode(['guid' => 'g', 'title' => 'Folge 1890: Der Bitcoin-Standard', 'published_at' => 1, 'seconds' => 3600.0,
        'chunks' => [['path' => 'caster/podcasts/einundzwanzig/c1.opus', 'seconds' => 3600.0, 'bytes' => 1, 'pauses' => []]]]));
    Storage::disk('local')->put('caster/podcasts/einundzwanzig/c1.opus', 'x');

    // Outside the replay window (00:00 to 09:00 Berlin, user 2026-10-15) the slot never airs, so the programme leaves it out.
    config(['tv.commentary.podcast_replay.window' => ['from' => '00:00', 'to' => '09:00']]);
    expect(collect(programmeNow()['upcoming'])->firstWhere('family', ProgrammeSlides::PODCAST))->toBeNull();

    config(['tv.commentary.podcast_replay.window' => ['from' => '00:00', 'to' => '00:00']]);
    $replay = collect(programmeNow()['upcoming'])->firstWhere('family', ProgrammeSlides::PODCAST);
    expect(ProgrammeSlides::time($replay['at']).' '.$replay['title'])->toBe('16:03 Folge 1890: Der Bitcoin-Standard');

    programmeClip(CasterScript::PODCAST, 'einundzwanzig', programmeBerlin('14:03'), 600, CasterClip::PLAYING, ['text' => 'Der EINUNDZWANZIG Podcast: Folge 1889: Lightning für alle'], ['dressed' => true, 'podcast' => ['source' => 'einundzwanzig', 'index' => 3, 'count' => 7]]);

    $air = programmeNow()['onAir'];
    expect($air['title'])->toBe('Folge 1889: Lightning für alle')
        ->and($air['part'])->toBe('Teil 3 von 7')
        ->and($air['family'])->toBe(ProgrammeSlides::PODCAST);
});

test('entries name the slides with something to show, the duel only with the talk show ready, and a show within two minutes', function () {
    expect((new ProgrammeSlides(app(PodcastLibrary::class)))->entries(now()->toImmutable()))->toBe(['scenes' => ['r1', 'r2'], 'soon' => null, 'soonScene' => null]);

    $friedhof = programmeClip(CasterScript::RUBRIC, Shows::FRIEDHOF, programmeBerlin('14:19:30'), 300);
    $entries = (new ProgrammeSlides(app(PodcastLibrary::class)))->entries(now()->toImmutable());
    expect($entries)->toBe(['scenes' => ['r1', 'r2'], 'soon' => 'rubric:'.$friedhof->caster_script_id, 'soonScene' => 'r2']);

    $talk = programmeClip(CasterScript::RUBRIC, ProgrammeSlides::TALKSHOW, programmeBerlin('14:19'), 900);
    $entries = (new ProgrammeSlides(app(PodcastLibrary::class)))->entries(now()->toImmutable());
    expect($entries)->toBe(['scenes' => ['r1', 'r2', 'r3'], 'soon' => 'rubric:'.$talk->caster_script_id, 'soonScene' => 'r3']);

    config(['tv.commentary.enabled' => false]);
    expect((new ProgrammeSlides(app(PodcastLibrary::class)))->entries(now()->toImmutable())['scenes'])->toBe([]);
});

/**
 * The rotation over `$seconds` as "start kind scene" lines.
 *
 * @param  list<array{id: int, phase: string, fomo: bool}>  $live
 * @return list<string>
 */
function programmeRotation(RotationPlanner $planner, float $seconds, array $programme, array $live = [], float $from = 0.0): array
{
    $log = [];
    $last = null;

    for ($t = $from; $t < $from + $seconds; $t += 0.5) {
        $slot = $planner->at($t, [], [], BoardScene::OFF, $live, 'off', null, [], [], $programme);
        $key = $slot['kind'].' '.$slot['scene'].' '.$planner->slotNumber();

        if ($key !== $last) {
            $log[] = sprintf('%g %s %s', $t, $slot['kind'], $slot['scene'] ?? '-');
            $last = $key;
        }
    }

    return $log;
}

function programmePlanner(int $every = 1): RotationPlanner
{
    return new RotationPlanner(45, 60, 20, 12, 3, 3, 30, 15, false, false, 90, 120, 60, 180, 20, $every, 20, 15);
}

test('with spreading off: one programme slide a round, the slides in turn, about one slot in seven; none without one', function () {
    config(['twentyone.stream.rotation.programme_every_slots' => 0]);
    $programme = ['scenes' => ['r1', 'r2', 'r3'], 'soon' => null];
    $log = programmeRotation(programmePlanner(), 1200, $programme);
    $slides = array_values(array_filter($log, fn (string $line): bool => str_contains($line, 'programme')));
    $shown = array_values(array_filter($log, fn (string $line): bool => ! str_contains($line, 'loop')));

    expect(array_slice($log, 0, 9))->toBe(['0 loop -', '30 teaser d1', '42 teaser d2', '54 teaser e1', '66 programme r1', '86 teaser a3', '98 teaser a4', '110 teaser a5', '122 teaser e4'])
        ->and(array_map(fn (string $line): string => substr($line, -2), array_slice($slides, 0, 4)))->toBe(['r1', 'r2', 'r3', 'r1'])
        ->and(count($slides) / count($shown))->toBeGreaterThan(0.12)->toBeLessThan(0.16)
        ->and(implode("\n", programmeRotation(programmePlanner(), 600, ['scenes' => [], 'soon' => null])))->not->toContain('programme')
        ->and(count(array_filter(programmeRotation(programmePlanner(2), 1200, $programme), fn (string $line): bool => str_contains($line, 'programme'))))->toBe(intdiv(count($slides) + 1, 2));
});

test('the programme slides are spread through every round: one after every second slide (user: "viel viel öfter")', function () {
    $programme = ['scenes' => ['r1', 'r2', 'r3'], 'soon' => null];
    $log = programmeRotation(programmePlanner(), 1200, $programme);
    $slides = array_values(array_filter($log, fn (string $line): bool => str_contains($line, 'programme')));
    $shown = array_values(array_filter($log, fn (string $line): bool => ! str_contains($line, 'loop')));

    expect(count($slides) / count($shown))->toBeGreaterThan(0.28)
        ->and(array_map(fn (string $line): string => substr($line, -2), array_slice($slides, 0, 4)))->toBe(['r1', 'r2', 'r3', 'r1'])
        ->and(implode("\n", programmeRotation(programmePlanner(), 600, ['scenes' => [], 'soon' => null])))->not->toContain('programme');
});

test('a show within two minutes gets its spotlight as the next slot, once, never cutting a slot short and never while a tournament runs', function () {
    $planner = programmePlanner();
    programmeRotation($planner, 40, ['scenes' => ['r1', 'r2'], 'soon' => null]);
    $log = programmeRotation($planner, 60, ['scenes' => ['r1', 'r2'], 'soon' => 'rubric:9', 'soonScene' => 'r2'], from: 40);

    // The teaser on show (from 30 s) runs to its end at 42 s, then the spotlight, then the round goes on; once only.
    expect(array_slice($log, 0, 3))->toBe(['40 teaser d1', '42 programme r2', '57 teaser d2'])
        ->and(count(array_filter($log, fn (string $line): bool => str_contains($line, 'r2'))))->toBe(1);

    $running = programmePlanner();
    $live = [['id' => 5, 'phase' => 'running', 'fomo' => false]];
    expect(implode("\n", programmeRotation($running, 400, ['scenes' => ['r1', 'r2'], 'soon' => 'rubric:9', 'soonScene' => 'r2'], $live)))->not->toContain('programme');
});

test('a programme slide ends at once when its slide has nothing to show any more', function () {
    $planner = programmePlanner();
    programmeRotation($planner, 67, ['scenes' => ['r1'], 'soon' => null]);

    expect($planner->at(67, [], [], BoardScene::OFF, [], 'off', null, [], [], ['scenes' => ['r1']])['scene'])->toBe('r1')
        ->and($planner->at(68, [], [], BoardScene::OFF, [], 'off', null, [], [], ['scenes' => []])['scene'])->not->toBe('r1');
});

test('the rows say "Gleich" for the next and "Heute noch" for the rest, at night "Danach"; countdowns and lengths read as German', function () {
    programmeAfternoon();
    $p = programmeNow();

    expect(array_map(fn (array $row): string => trim($row['label'].' '.$row['when'].' '.$row['right']), ProgrammeSlides::rows($p, 4)))
        ->toBe(['Gleich 14:30 in 12 min', 'Heute noch 14:45 in 27 min', '15:15 in 57 min'])
        ->and(ProgrammeSlides::countdown(1000, 990))->toBe('jetzt')
        // Past its slot and still waiting (user 2026-10-25: "22:15" at 23:28): "gleich", and no clock time.
        ->and(ProgrammeSlides::countdown(1000, 1000 + 4380))->toBe('gleich')
        ->and(ProgrammeSlides::at(1000, 1000 + 4380))->toBe('')
        ->and(ProgrammeSlides::at(1000, 990))->toBe(ProgrammeSlides::time(1000))
        ->and(ProgrammeSlides::countdown(4000, 0))->toBe('in 1 Std 7 min')
        ->and(ProgrammeSlides::countdown(7200, 0))->toBe('in 2 Std')
        ->and(ProgrammeSlides::remaining(30))->toBe('gleich vorbei')
        ->and(ProgrammeSlides::length(3720))->toBe('1 Std 2 min')
        ->and(ProgrammeSlides::rows([...$p, 'night' => true], 2)[1]['label'])->toBe('Danach');
});

test('every programme slide renders at 1280 x 720 with every text inside the frame and clear of the LIVE badge, long titles included', function (string $case) {
    match ($case) {
        'afternoon' => programmeAfternoon(),
        'long' => [
            programmeClip(CasterScript::PODCAST, 'einundzwanzig', programmeBerlin('14:03'), 2400, CasterClip::PLAYING, ['text' => 'Der EINUNDZWANZIG Podcast: Folge 1889: Warum ein Fiatgeldsystem mit eingebautem Verfallsdatum niemals die Kaufkraft deiner Ersparnisse schützt, egal was die Zentralbank verspricht'], ['dressed' => true, 'podcast' => ['source' => 'einundzwanzig', 'index' => 12, 'count' => 14]]),
            programmeClip(CasterScript::RUBRIC, 'phrasendrescher', programmeBerlin('14:45'), 420),
            programmeClip(CasterScript::RUBRIC, 'corporate_stacker', programmeBerlin('15:15'), 300),
            programmeClip(CasterScript::RUBRIC, 'altcoin_hype', programmeBerlin('15:45'), 300),
            programmeClip(CasterScript::RUBRIC, ProgrammeSlides::TALKSHOW, programmeBerlin('16:15'), 1500),
        ],
        'talkshow' => programmeClip(CasterScript::RUBRIC, ProgrammeSlides::TALKSHOW, programmeBerlin('14:31'), 1500),
        'empty' => null,
        'offline' => Cache::forget(ClipSpool::FETCHER_SEEN),
    };

    foreach (ProgrammeSlides::SCENES as $scene) {
        [$svg, $png] = programmeSlide($scene, 1234);
        $texts = programmeTexts($svg);

        expect($svg)->toContain('width="1280" height="720"', '1,234', 'watching')
            ->and(count($texts))->toBeGreaterThan(3);

        foreach ($texts as $t) {
            expect($t['left'])->toBeGreaterThanOrEqual(32, $scene.' '.$t['text'])
                // The slide keeps the 32 px safe margin; the now-playing column (x 1040 on) keeps 16 px to the frame.
                ->and($t['right'])->toBeLessThanOrEqual($t['left'] >= 1040 ? 1264 : 1248, $scene.' '.$t['text'])
                ->and($t['y'])->toBeLessThanOrEqual(700, $scene.' '.$t['text']);

            if ($t['y'] < 112) {
                expect($t['right'])->toBeLessThan(1040, $scene.' '.$t['text']);
            }

            // The slide's own texts end by x 1016, before the stream's now-playing column (x 1040 on).
            if ($t['left'] < 1040) {
                expect($t['right'])->toBeLessThanOrEqual(1016, $scene.' '.$t['text']);
            }
        }

        if ($png !== null) {
            $size = getimagesizefromstring($png);
            expect([$size[0], $size[1]])->toBe([1280, 720]);

            if (is_string($dir = getenv('PROGRAMME_SHOTS')) && $dir !== '') {
                file_put_contents($dir.'/'.$scene.'-'.$case.'.png', $png);
            }
        }
    }
})->with(['afternoon', 'long', 'talkshow', 'empty', 'offline']);

test('at night the programme slide shows the loop: the episode on air and the stored ones still to come tonight', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 02:20:00', 'Europe/Berlin'));
    config(['tv.commentary.podcast_replay.enabled' => true, 'tv.commentary.podcast_replay.sources.einundzwanzig.enabled' => true,
        'tv.commentary.podcast_replay.sources.einundzwanzig.feed' => 'https://example.org/feed.xml',
        'tv.commentary.podcast_replay.night' => ['enabled' => true, 'sources' => ['einundzwanzig'], 'episodes' => 4, 'from' => '01:00', 'to' => '09:00', 'until' => '']]);
    $disk = Storage::disk('local');
    foreach ([['g1', 'Folge 1890: Der Bitcoin-Standard', 4], ['g2', 'Folge 1889: Lightning für alle', 3], ['g3', 'Folge 1888: Nostr erklärt', 2]] as [$guid, $title, $at]) {
        $disk->put('caster/podcasts/einundzwanzig/'.$guid.'/meta.json', json_encode(['guid' => $guid, 'title' => $title, 'published_at' => $at, 'seconds' => 3300.0,
            'chunks' => [['path' => 'caster/podcasts/einundzwanzig/'.$guid.'/c.opus', 'seconds' => 3300.0, 'bytes' => 1, 'pauses' => []]]]));
        $disk->put('caster/podcasts/einundzwanzig/'.$guid.'/c.opus', 'x');
    }
    programmeClip(CasterScript::PODCAST, 'n-einundzwanzig', CarbonImmutable::now()->subMinutes(20), 1800, CasterClip::PLAYING,
        ['text' => 'Der EINUNDZWANZIG Podcast: Folge 1890: Der Bitcoin-Standard', 'facts_hash' => sha1('night:g1')], ['dressed' => true, 'podcast' => ['source' => 'einundzwanzig', 'index' => 2, 'count' => 3]]);

    $p = programmeNow();

    expect($p['night'])->toBeTrue()
        ->and($p['onAir']['title'])->toBe('Folge 1890: Der Bitcoin-Standard')
        ->and(array_column($p['loop'], 'title'))->toBe(['Folge 1889: Lightning für alle', 'Folge 1888: Nostr erklärt'])
        ->and($p['newsLine'])->toBe('Zur vollen Stunde Nachrichten, danach das Wetter.');

    // The next episode follows the one on air (about 02:50), before the news at 03:00 cuts in.
    expect(array_map(fn (array $row): string => trim(preg_replace('/\s+/', ' ', $row['label'].' '.$row['when'].' '.$row['item']['title'])), ProgrammeSlides::rows($p, 4)))
        ->toBe(['Gleich Folge 1889: Lightning für alle', 'Danach 03:00 Nachrichten', 'Folge 1888: Nostr erklärt']);

    [$svg, $png] = programmeSlide(ProgrammeSlides::RUNDOWN);
    expect($svg)->toContain('Die Nachtschleife', 'Lightning für alle', 'Danach', '03:00', 'Teil 2 von 3. Der EINUNDZWANZIG Podcast.');

    if ($png !== null && is_string($dir = getenv('PROGRAMME_SHOTS')) && $dir !== '') {
        file_put_contents($dir.'/r1-night.png', $png);
        file_put_contents($dir.'/r2-night.png', programmeSlide(ProgrammeSlides::SPOTLIGHT)[1]);
    }
});

test('a slide that comes back unchanged in the rotation is not rendered again: PNGs are kept by the hash of their SVG', function () {
    programmeAfternoon();
    Process::fake(['*' => Process::result("\x89PNG fake")]);
    $renderer = SceneRenderer::fromConfig();
    $r1 = [...app(SceneSource::class)->rotation(ProgrammeSlides::RUNDOWN, null, [], 0, (int) now()->getTimestampMs(), []), 'viewers' => 3];
    $r2 = [...app(SceneSource::class)->rotation(ProgrammeSlides::SPOTLIGHT, null, [], 0, (int) now()->getTimestampMs(), []), 'viewers' => 3];

    foreach ([[$r1, 'r1'], [$r2, 'r2'], [$r1, 'r1'], [$r2, 'r2']] as [$data, $scene]) {
        expect($renderer->png($data, RotationPlanner::VIEWS[$scene]))->toStartWith("\x89PNG fake");
    }

    Process::assertRanTimes(fn ($process): bool => true, 2);

    // A changed slide (a minute later the countdown moves) renders again.
    $renderer->png([...$r1, 'viewers' => 4], RotationPlanner::VIEWS['r1']);
    Process::assertRanTimes(fn ($process): bool => true, 3);
});
