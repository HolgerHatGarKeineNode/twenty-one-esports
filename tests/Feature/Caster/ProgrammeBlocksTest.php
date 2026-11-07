<?php

use App\Models\CasterClip;
use App\Models\CasterScript;
use App\Support\Caster\AirLog;
use App\Support\Caster\Blocks\BlockComposer;
use App\Support\Caster\Blocks\BlockRenderer;
use App\Support\Caster\Blocks\JingleLibrary;
use App\Support\Caster\CasterFetcher;
use App\Support\Caster\ClipSpool;
use App\Support\Caster\CommentaryTimeline;
use App\Support\Caster\Idents;
use App\Support\Caster\Pcm;
use App\Support\Caster\Programme\BroadcastClock;
use App\Support\Caster\Programme\SportWeather;
use App\Support\Caster\SceneDirector;
use App\Support\Caster\ScenePhase;
use App\Support\Caster\ScriptQueue;
use App\Support\TwentyOne\Stream\RotationPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| TWENTY ONE Radio, P6: programme blocks rendered on the workstation
|--------------------------------------------------------------------------
|
| User 2026-10-12: "Die Composition soll lokal erfolgen … Der Server bekommt
| am besten vorgerenderte Programme hochgeladen, trotzdem in Häppchen, damit
| eine Reaktion auf Chat-Messages möglich bleibt." The server stores a block
| as one dressed clip, plays it whole, joins the next block within a second,
| lets a reaction in only at a segment boundary (resuming the block from
| there, not a sample lost), never dresses a block again and still airs a
| bulletin at its slot. The workstation composes the running order, rotates
| the Anchor's jingles, voices speaker by speaker, places blips under the
| other speaker and makes one loudness pass.
|
*/

const PROGRAMME_BLOCK_TOKEN = 'block-token-0123456789abcdef0123456789abcdef';

beforeEach(function () {
    Storage::fake('local');
    config([
        'tv.commentary.enabled' => true,
        'tv.commentary.fetch.token' => PROGRAMME_BLOCK_TOKEN,
        'tv.commentary.voicestudio.server' => 'https://tv.test',
        'tv.commentary.voicestudio.url' => 'http://voicestudio.test',
        'tv.commentary.voicestudio.retry_seconds' => 0,
        'tv.commentary.voicestudio.pause.jitter' => 0.0,
        'tv.commentary.spool.filler_below_seconds' => 10,
    ]);
});

/** Fake Opus whose decode (programmeBlockDecodes()) gives `$seconds` of the sample value `$fill`. */
function programmeBlockOpus(float $seconds, int $fill): string
{
    return casterOpus().sprintf('LEN:%.3F;FILL:%d;', $seconds, $fill);
}

/** Every decode answers with the PCM its fake Opus names; any other ffmpeg call with two seconds of value 9 (a stinger). */
function programmeBlockDecodes(): void
{
    Process::fake(function ($process) {
        if (preg_match('/LEN:([\d.]+);FILL:(\d+);/', (string) $process->input, $match) === 1) {
            return Process::result(output: str_repeat(pack('v', (int) $match[2]), (int) round(24000 * (float) $match[1])));
        }

        return Process::result(output: str_repeat(pack('v', 9), 24000 * 2));
    });
}

/**
 * A stored block of `$seconds` (sentence ends every two seconds) of the sample value `$fill`.
 *
 * @param  list<float>  $boundaries
 */
function programmeBlockClip(float $seconds, int $fill, array $boundaries = [], float $protected = 0.0, string $leadKind = CasterScript::SCENE, ?CarbonImmutable $airsAt = null): CasterClip
{
    $lead = casterScript($leadKind);

    if ($airsAt !== null) {
        $lead->update(['airs_at' => $airsAt->utc(), 'expires_at' => $airsAt->utc()->addSeconds(120)]);
    }

    $scripts = [$lead->fresh(), casterScript()];

    return app(ClipSpool::class)->storeBlock($scripts, programmeBlockOpus($seconds, $fill), $seconds, range(2.0, $seconds, 2.0), $boundaries, $protected, CarbonImmutable::now());
}

/** A plain spooled clip (a chat reaction, a bulletin) of `$seconds` and sample value `$fill`. */
function programmeBlockPlainClip(string $kind, float $seconds, int $fill, ?CarbonImmutable $airsAt = null): CasterClip
{
    $script = casterScript($kind);

    if ($airsAt !== null) {
        $script->update(['airs_at' => $airsAt->utc(), 'expires_at' => $airsAt->utc()->addSeconds(120)]);
    }

    return app(ClipSpool::class)->store($script->fresh(), programmeBlockOpus($seconds, $fill), $seconds, ($seconds >= 4.0 ? range(2.0, $seconds, 2.0) : [$seconds]), CarbonImmutable::now());
}

function programmeBlockDirector(?Idents $idents = null, float $reactionWait = 90, float $newsMusicDb = 0.0): SceneDirector
{
    return new SceneDirector(app(ClipSpool::class), new CommentaryTimeline(24000, $newsMusicDb), [
        'min_scene_seconds' => 20, 'silent_min_scene_seconds' => 15, 'tail_seconds' => 1.5, 'lead_in_seconds' => 0.5, 'max_hold_seconds' => 100,
        'feed_lead_seconds' => 1.0, 'gap_seconds' => ['min' => 8, 'max' => 8], 'bulletin_late_seconds' => 120,
        'blocks' => ['join_seconds' => ['min' => 0.4, 'max' => 0.6], 'reaction_wait_seconds' => $reactionWait, 'hold_seconds' => 30, 'resume_within_seconds' => 600],
    ], $idents);
}

/**
 * Runs the director like the stream does (a poll every second, the voice feed pumped every 0.25 s and written one
 * second ahead) and returns what the feed wrote: the sample values in order, run-length encoded.
 *
 * @param  array<int, Closure(): void>  $events  seconds from the start => what happens then (a reaction arrives)
 * @return list<array{value: int, seconds: float, from: float}>
 */
function programmeBlockAir(SceneDirector $director, float $start, float $seconds, array $events = []): array
{
    $planner = new RotationPlanner(teaserSeconds: 12, loopSeconds: 5);
    $next = (int) round($start * 24000);
    $written = '';

    for ($step = 0; $step <= $seconds * 4; $step++) {
        $t = $start + $step * 0.25;

        foreach ($events as $at => $event) {
            if (abs($start + $at - $t) < 0.01) {
                $event();
            }
        }

        if ($step % 4 === 0) {
            $director->tick($planner, $planner->at($t, []), $t);
        }

        $target = (int) round(($t + 1.0) * 24000);
        $written .= $director->timeline->samples($next, $target);
        $next = $target;
    }

    $runs = [];
    $position = 0;
    foreach (unpack('v*', $written) ?: [] as $value) {
        $last = array_key_last($runs);

        if ($last !== null && $runs[$last]['value'] === $value) {
            $runs[$last]['seconds'] += 1 / 24000;
        } else {
            $runs[] = ['value' => $value, 'seconds' => 1 / 24000, 'from' => $position / 24000];
        }

        $position++;
    }

    return array_map(fn (array $run): array => ['value' => $run['value'], 'seconds' => round($run['seconds'], 3), 'from' => round($run['from'], 3)], $runs);
}

/** Seconds of sample value `$value` on air. */
function programmeBlockSecondsOf(array $runs, int $value): float
{
    return round(array_sum(array_map(fn (array $run): float => $run['value'] === $value ? $run['seconds'] : 0.0, $runs)), 2);
}

/* ---------------------------------------------------------------------- server: the block endpoint */

it('stores an uploaded block as one dressed clip for all its scripts, with its boundaries', function () {
    $scripts = [casterScript(), casterScript(), casterScript(CasterScript::HISTORY)];

    $response = $this->call('POST', '/api/caster/blocks', server: [
        'HTTP_AUTHORIZATION' => 'Bearer '.PROGRAMME_BLOCK_TOKEN, 'CONTENT_TYPE' => 'audio/ogg',
        'HTTP_X_CASTER_SCRIPTS' => implode(',', array_map(fn (CasterScript $script): int => $script->id, $scripts)),
        'HTTP_X_CASTER_DURATION' => '200.5', 'HTTP_X_CASTER_SENTENCE_ENDS' => '10.0,70.2,140.0,200.5',
        'HTTP_X_CASTER_BOUNDARIES' => '70.2,140.0', 'HTTP_X_CASTER_PROTECTED' => '0',
    ], content: casterOpus());

    $response->assertStatus(201)->assertJson(['kind' => CasterClip::BLOCK]);
    $clip = CasterClip::query()->sole();

    expect($clip->caster_script_id)->toBe($scripts[0]->id)
        ->and($clip->isDressed())->toBeTrue()
        ->and($clip->boundaries())->toBe([70.2, 140.0])
        ->and($clip->block['scripts'])->toBe(array_map(fn (CasterScript $script): int => $script->id, $scripts))
        ->and(CasterScript::query()->pluck('status')->unique()->all())->toBe([CasterScript::VOICED])
        ->and(app(ClipSpool::class)->readySeconds())->toBe(200.5);
});

it('keeps the kind of a slot-placed lead, so a news block airs at its slot', function () {
    $slot = CarbonImmutable::now()->addMinutes(5);
    $clip = programmeBlockClip(60, 1, [20.0], 20.0, CasterScript::BULLETIN, $slot);

    expect($clip->kind)->toBe(CasterScript::BULLETIN)
        ->and($clip->protectedSeconds())->toBe(20.0)
        ->and($clip->expires_at->getTimestamp())->toBe($slot->addSeconds(120)->getTimestamp());
});

it('refuses a block with a reaction in it, an unclaimed script, or a slot-placed script behind a talk lead', function () {
    $headers = fn (array $ids): array => ['HTTP_AUTHORIZATION' => 'Bearer '.PROGRAMME_BLOCK_TOKEN, 'CONTENT_TYPE' => 'audio/ogg',
        'HTTP_X_CASTER_SCRIPTS' => implode(',', $ids), 'HTTP_X_CASTER_DURATION' => '30', 'HTTP_X_CASTER_SENTENCE_ENDS' => '30'];
    $chat = casterScript(CasterScript::CHAT);
    $unclaimed = casterScript();
    $unclaimed->update(['status' => CasterScript::QUEUED]);
    $bulletin = casterScript(CasterScript::BULLETIN);
    $bulletin->update(['airs_at' => now()->addMinute()]);

    $this->call('POST', '/api/caster/blocks', server: $headers([casterScript()->id, $chat->id]), content: casterOpus())->assertStatus(422);
    $this->call('POST', '/api/caster/blocks', server: $headers([casterScript()->id, $unclaimed->id]), content: casterOpus())->assertStatus(409);
    $this->call('POST', '/api/caster/blocks', server: $headers([casterScript()->id, $bulletin->id]), content: casterOpus())->assertStatus(422);
    $this->call('POST', '/api/caster/blocks', server: ['HTTP_X_CASTER_SCRIPTS' => (string) casterScript()->id], content: casterOpus())->assertStatus(401);

    expect(CasterClip::query()->count())->toBe(0);
});

it('tells the workstation what it composes by: airtime, slot, topic and where a part stands in its story', function () {
    $queue = app(ScriptQueue::class);
    config(['tv.commentary.parts.sentences' => 2]);
    $queue->enqueue(CasterScript::HISTORY, ScenePhase::BREAK, "Eins ist hier.\nZwei ist hier.\nDrei ist hier.\nVier ist hier.", 'h', 50, CarbonImmutable::now());

    $claim = $this->postJson('/api/caster/claim', [], ['Authorization' => 'Bearer '.PROGRAMME_BLOCK_TOKEN])->assertOk()->json('script');

    expect($claim['estimated_seconds'])->toBeGreaterThan(0)
        ->and($claim['airs_at'])->toBeNull()
        ->and($claim['part'])->toMatchArray(['index' => 1, 'continues' => true]);
});

/* ---------------------------------------------------------------------- server: playing blocks */

it('joins the next block within a second of the one on air, without changing a sample of either', function () {
    programmeBlockDecodes();
    programmeBlockClip(20.0, 1);
    programmeBlockClip(15.0, 3);
    $start = CarbonImmutable::now()->getTimestamp() + 0.37;

    $runs = programmeBlockAir(programmeBlockDirector(), $start, 50);
    $gaps = AirLog::gaps(AirLog::entries(), blocksOnly: true);
    $between = collect($runs)->first(fn (array $run): bool => $run['value'] === 0 && $run['from'] > 1.0);

    expect(programmeBlockSecondsOf($runs, 1))->toBe(20.0)
        ->and(programmeBlockSecondsOf($runs, 3))->toBe(15.0)
        ->and($between['seconds'])->toBeLessThanOrEqual(1.0)
        ->and($gaps)->toHaveCount(1)
        ->and($gaps[0]['gap'])->toBeLessThanOrEqual(1.0)
        ->and(CasterClip::query()->count())->toBe(0);
});

it('never lets a reaction into a block, not even at a segment boundary: it follows the block (user 2026-10-15)', function () {
    programmeBlockDecodes();
    programmeBlockClip(40.0, 1, [12.0, 26.0]);
    $start = (float) CarbonImmutable::now()->getTimestamp();

    $runs = programmeBlockAir(programmeBlockDirector(), $start, 70, [4 => fn () => programmeBlockPlainClip(CasterScript::CHAT, 3.0, 5)]);
    $chat = collect($runs)->firstWhere('value', 5);
    $blockStart = collect($runs)->firstWhere('value', 1)['from'];

    // The block starts about 1 s in; its boundary at 12 s no longer lets the reaction in: the block runs whole
    // and the reaction follows right at its end, in one piece.
    expect(programmeBlockSecondsOf($runs, 1))->toBe(40.0)
        ->and(programmeBlockSecondsOf($runs, 5))->toBe(3.0)
        ->and($chat['from'] - $blockStart)->toBeGreaterThanOrEqual(40.0)
        ->and($chat['from'] - $blockStart)->toBeLessThan(45.0)
        ->and(collect($runs)->where('value', 1)->count())->toBe(1);
});

it('runs the block to its end when no boundary comes: no cut at a sentence end any more, however short the wait (user 2026-10-15)', function () {
    programmeBlockDecodes();
    programmeBlockClip(60.0, 1, [50.0]);
    $start = (float) CarbonImmutable::now()->getTimestamp();

    $runs = programmeBlockAir(programmeBlockDirector(reactionWait: 10), $start, 90, [4 => fn () => programmeBlockPlainClip(CasterScript::CHAT, 3.0, 5)]);
    $chat = collect($runs)->firstWhere('value', 5);
    $blockStart = collect($runs)->firstWhere('value', 1)['from'];

    // reaction_wait_seconds no longer forces a sentence-end cut: with ten seconds of wait the block still
    // plays its 60 s whole and the reaction follows.
    expect(programmeBlockSecondsOf($runs, 1))->toBe(60.0)
        ->and($chat['from'] - $blockStart)->toBeGreaterThanOrEqual(60.0)
        ->and($chat['from'] - $blockStart)->toBeLessThan(66.0)
        ->and(collect($runs)->where('value', 1)->count())->toBe(1);
});

it('protects the whole block now, not only its opening news: the reaction follows the block (user 2026-10-15)', function () {
    programmeBlockDecodes();
    programmeBlockClip(40.0, 1, [6.0, 18.0], protected: 18.0);
    $start = (float) CarbonImmutable::now()->getTimestamp();

    $runs = programmeBlockAir(programmeBlockDirector(), $start, 60, [2 => fn () => programmeBlockPlainClip(CasterScript::CHAT, 3.0, 5)]);
    $blockStart = collect($runs)->firstWhere('value', 1)['from'];

    expect(collect($runs)->firstWhere('value', 1)['seconds'])->toBe(40.0)
        ->and(collect($runs)->firstWhere('value', 5)['from'] - $blockStart)->toBeGreaterThanOrEqual(40.0)
        ->and(collect($runs)->where('value', 1)->count())->toBe(1)
        ->and(programmeBlockSecondsOf($runs, 1))->toBe(40.0);
});

it('still airs a bulletin at its slot while a block is on air, and the block resumes after the news', function () {
    $slot = CarbonImmutable::parse('2026-10-12 14:00', 'Europe/Berlin');
    $this->travelTo($slot->subSeconds(30));
    programmeBlockDecodes();
    programmeBlockClip(120.0, 1, [60.0]);
    programmeBlockPlainClip(CasterScript::BULLETIN, 10.0, 7, $slot);

    $runs = programmeBlockAir(programmeBlockDirector(), (float) $slot->subSeconds(30)->getTimestamp(), 160);
    $news = collect($runs)->firstWhere('value', 7);

    // On air 30 s before the slot: the bulletin comes at the next sentence end past the feed lead after 14:00, at most 6 s late (as in P1).
    expect($news['from'])->toBeGreaterThanOrEqual(30.0)
        ->and($news['from'])->toBeLessThan(36.0)
        ->and(programmeBlockSecondsOf($runs, 7))->toBe(10.0)
        ->and(programmeBlockSecondsOf($runs, 1))->toBe(120.0);
});

it('puts the music into news mode under a rendered news block, and not under a talk block', function () {
    $slot = CarbonImmutable::parse('2026-10-12 14:00', 'Europe/Berlin');
    $this->travelTo($slot);
    programmeBlockDecodes();
    $planner = new RotationPlanner(teaserSeconds: 12, loopSeconds: 5);
    $mid = fn (SceneDirector $director): int => unpack('v', $director->timeline->musicDuck((int) round(($director->timeline->startsAt() + 10) * 24000), (int) round(($director->timeline->startsAt() + 10) * 24000) + 1))[1];

    programmeBlockClip(30.0, 1, [10.0], 10.0, CasterScript::BULLETIN, $slot);
    $news = programmeBlockDirector(newsMusicDb: -60);
    $news->tick($planner, $planner->at((float) $slot->getTimestamp(), []), (float) $slot->getTimestamp());

    CasterClip::query()->delete();
    programmeBlockClip(30.0, 1, [10.0]);
    $talk = programmeBlockDirector(newsMusicDb: -60);
    $talk->tick($planner, $planner->at((float) $slot->getTimestamp() + 1, []), (float) $slot->getTimestamp() + 1);

    expect($news->clip()?->kind)->toBe(CasterScript::BULLETIN)
        ->and($mid($news))->toBeGreaterThan(32000)
        ->and($talk->clip()?->isBlock())->toBeTrue()
        ->and($mid($talk))->toBe(0);
});

it('lets a due programme teaser wait for the block\'s end too, then it airs (user 2026-10-15)', function () {
    programmeBlockDecodes();
    programmeBlockClip(40.0, 1, [12.0, 26.0]);
    $start = (float) CarbonImmutable::now()->getTimestamp();

    $runs = programmeBlockAir(programmeBlockDirector(), $start, 70, [4 => fn () => programmeBlockPlainClip(CasterScript::TEASER, 4.0, 6, CarbonImmutable::now()->subSecond())]);
    $teaser = collect($runs)->firstWhere('value', 6);
    $blockStart = collect($runs)->firstWhere('value', 1)['from'];

    expect($teaser['from'] - $blockStart)->toBeGreaterThanOrEqual(40.0)
        ->and($teaser['from'] - $blockStart)->toBeLessThan(45.0)
        ->and(programmeBlockSecondsOf($runs, 6))->toBe(4.0)
        ->and(programmeBlockSecondsOf($runs, 1))->toBe(40.0);
});

it('never dresses a rendered block again: a news block gets no stinger, a plain bulletin still does', function () {
    $slot = CarbonImmutable::parse('2026-10-12 14:00', 'Europe/Berlin');
    $this->travelTo($slot);
    programmeBlockDecodes();
    $idents = new Idents((array) config('tv.commentary.idents'), 24000);
    $planner = new RotationPlanner(teaserSeconds: 12, loopSeconds: 5);

    programmeBlockClip(30.0, 1, [10.0], 10.0, CasterScript::BULLETIN, $slot);
    $director = programmeBlockDirector($idents);
    $director->tick($planner, $planner->at((float) $slot->getTimestamp(), []), (float) $slot->getTimestamp());
    $blockIdent = $director->ident();
    $blockLength = $director->timeline->endsAt() - $director->timeline->startsAt();

    CasterClip::query()->delete();
    programmeBlockPlainClip(CasterScript::BULLETIN, 30.0, 1, $slot);
    $plain = programmeBlockDirector($idents);
    $plain->tick($planner, $planner->at((float) $slot->getTimestamp() + 1, []), (float) $slot->getTimestamp() + 1);

    expect($director->clip()?->isBlock())->toBeTrue()
        ->and($blockIdent)->toBeNull()
        ->and(round($blockLength, 2))->toBe(30.0)
        ->and($plain->ident())->toBe('news');
});

/* ---------------------------------------------------------------------- workstation: running order and jingles */

it('composes a news block: news jingle, bulletin, back-announce, then the talk', function () {
    $clock = new BroadcastClock((array) config('tv.commentary.programme.slots'));
    $slot = CarbonImmutable::parse('2026-10-12 14:00', 'Europe/Berlin');
    $steps = BlockComposer::plan([
        ['id' => 1, 'kind' => CasterScript::BULLETIN, 'airs_at' => $slot->toIso8601String()],
        ['id' => 2, 'kind' => 'rubric', 'topic' => 'nachrichten_talk', 'airs_at' => $slot->toIso8601String(), 'speakers' => ['markus', 'sirius']],
    ], $slot, 0.0, fn (): float => 0.0, $clock);

    expect($steps)->toBe([
        ['type' => 'jingle', 'categories' => ['news']],
        ['type' => 'script', 'index' => 0],
        ['type' => 'jingle', 'categories' => ['back-announce', 'sweeper']],
        ['type' => 'script', 'index' => 1],
    ]);

    $headlines = BlockComposer::plan([['id' => 1, 'kind' => CasterScript::BULLETIN, 'airs_at' => $slot->addMinutes(30)->toIso8601String()]], $slot, 0.0, fn (): float => 0.0, $clock);
    expect($headlines[0]['categories'])->toBe(['headlines', 'news']);
});

it('composes a news block with Sport und Wetter: news jingle, bulletin, Sport und Wetter, back-announce, then the talk', function () {
    $clock = new BroadcastClock((array) config('tv.commentary.programme.slots'));
    $slot = CarbonImmutable::parse('2026-10-12 14:00', 'Europe/Berlin');
    $steps = BlockComposer::plan([
        ['id' => 1, 'kind' => CasterScript::BULLETIN, 'airs_at' => $slot->toIso8601String()],
        ['id' => 2, 'kind' => 'rubric', 'topic' => SportWeather::TOPIC, 'airs_at' => $slot->toIso8601String(), 'part' => ['group' => 'sw', 'index' => 1, 'continues' => true]],
        ['id' => 3, 'kind' => 'rubric', 'topic' => SportWeather::TOPIC, 'airs_at' => $slot->toIso8601String(), 'part' => ['group' => 'sw', 'index' => 2, 'continues' => false]],
        ['id' => 4, 'kind' => 'rubric', 'topic' => 'nachrichten_talk', 'airs_at' => $slot->toIso8601String(), 'speakers' => ['markus', 'sirius']],
    ], $slot, 0.0, fn (): float => 0.0, $clock);

    expect($steps)->toBe([
        ['type' => 'jingle', 'categories' => ['news']],
        ['type' => 'script', 'index' => 0],
        ['type' => 'script', 'index' => 1],
        ['type' => 'script', 'index' => 2],
        ['type' => 'jingle', 'categories' => ['back-announce', 'sweeper']],
        ['type' => 'script', 'index' => 3],
    ]);

    // Leading a block of its own (not joined to the news), it opens with its rubric jingle or a sweeper.
    $alone = BlockComposer::plan([['id' => 2, 'kind' => 'rubric', 'topic' => SportWeather::TOPIC, 'airs_at' => $slot->toIso8601String()]], $slot, 0.0, fn (): float => 0.0, $clock);
    expect($alone[0]['categories'])->toBe(['rubric-sport_wetter', 'sweeper']);
});

it('joins Sport und Wetter and the news talk to the bulletin\'s block and leaves the next script in the queue', function () {
    config(['tv.commentary.blocks.enabled' => true, 'tv.commentary.blocks.seconds' => ['min' => 30, 'max' => 600], 'tv.commentary.blocks.jingles.dir' => programmeBlockJingleDir(['s1', 's2']),
        'tv.commentary.blocks.news_talk_topics' => [SportWeather::TOPIC, 'nachrichten_talk']]);
    $slot = CarbonImmutable::now()->addMinutes(5)->toIso8601String();
    $claims = [
        ['id' => 31, 'kind' => CasterScript::BULLETIN, 'airs_at' => $slot, 'sentences' => ['Das ist die Nachricht.'], 'estimated_seconds' => 60],
        ['id' => 32, 'kind' => 'rubric', 'topic' => SportWeather::TOPIC, 'airs_at' => $slot, 'sentences' => ['Das ist der Sport.'], 'estimated_seconds' => 40, 'part' => ['group' => 'sw', 'index' => 1, 'continues' => true]],
        ['id' => 33, 'kind' => 'rubric', 'topic' => SportWeather::TOPIC, 'airs_at' => $slot, 'sentences' => ['Das ist das Wetter.'], 'estimated_seconds' => 40, 'part' => ['group' => 'sw', 'index' => 2, 'continues' => false]],
        ['id' => 34, 'kind' => 'rubric', 'topic' => 'nachrichten_talk', 'airs_at' => $slot, 'sentences' => ['Das ist der Talk.'], 'estimated_seconds' => 200],
        ['id' => 35, 'kind' => 'rubric', 'topic' => SportWeather::TOPIC, 'airs_at' => $slot, 'sentences' => ['Das ist nochmal Sport.'], 'estimated_seconds' => 40],
    ];
    Http::fake(function (Request $request) use (&$claims) {
        return match (true) {
            str_ends_with($request->url(), '/api/caster/claim') => Http::response(['script' => array_shift($claims), 'full' => false, 'spool_seconds' => 0, 'pronunciation' => []]),
            str_ends_with($request->url(), '/generate') => Http::response(programmeBlockWav(1.0)),
            str_ends_with($request->url(), '/clip'), str_ends_with($request->url(), '/blocks') => Http::response(['clip' => 1], 201),
            default => Http::response([]),
        };
    });
    Process::fake(function ($process) {
        if (in_array('loudnorm=I=-16:TP=-1.5:LRA=11', $process->command, true)) {
            return Process::result(output: casterOpus());
        }

        return Process::result(output: str_repeat(pack('v', 2), 24000));
    });

    (new CasterFetcher(fn () => null))->runOnce();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://tv.test/api/caster/blocks' && $request->header('X-Caster-Scripts')[0] === '31,32,33,34');
    // At most one claim per follow-up topic: the next script stays in the queue for the next block.
    expect($claims)->toHaveCount(1);
});

it('composes a talk block: an opener, a rubric opener before a history segment, sweepers, never a jingle inside a story', function () {
    $at = CarbonImmutable::parse('2026-10-12 19:00', 'Europe/Berlin');
    $steps = BlockComposer::plan([
        ['id' => 1, 'kind' => 'scene', 'speakers' => ['markus', 'sirius']],
        ['id' => 2, 'kind' => 'history', 'part' => ['group' => 'g', 'index' => 1, 'continues' => true]],
        ['id' => 3, 'kind' => 'history', 'part' => ['group' => 'g', 'index' => 2, 'continues' => false]],
        ['id' => 4, 'kind' => 'scene'],
        ['id' => 5, 'kind' => 'teaser'],
    ], $at, 1.0, fn (): float => 0.5);

    expect($steps)->toBe([
        ['type' => 'jingle', 'categories' => ['time-evening', 'station']],
        ['type' => 'script', 'index' => 0],
        ['type' => 'jingle', 'categories' => ['rubric-geschichte', 'sweeper']],
        ['type' => 'script', 'index' => 1],
        ['type' => 'script', 'index' => 2],
        ['type' => 'jingle', 'categories' => ['sweeper', 'station']],
        ['type' => 'script', 'index' => 3],
        ['type' => 'jingle', 'categories' => ['coming-up', 'sweeper']],
        ['type' => 'script', 'index' => 4],
    ]);
});

/** A jingle directory with a manifest of `$lines` lines (two variants each) in `$category`, small real files. */
function programmeBlockJingleDir(array $lines, string $category = 'sweeper'): string
{
    $dir = sys_get_temp_dir().'/programme-block-jingles-'.bin2hex(random_bytes(4));
    mkdir($dir.'/anchor', 0755, true);
    mkdir($dir.'/blips/sirius', 0755, true);
    mkdir($dir.'/blips/markus', 0755, true);
    $items = [];

    foreach ($lines as $line) {
        foreach (['a', 'b'] as $variant) {
            file_put_contents($dir.'/anchor/'.$line.'-'.$variant.'.opus', programmeBlockOpus(2.0, 2));
            $items[] = ['id' => $line.'-'.$variant, 'line' => $line, 'category' => $category, 'file' => 'anchor/'.$line.'-'.$variant.'.opus', 'seconds' => 2.0];
        }
    }

    file_put_contents($dir.'/manifest.json', json_encode(['items' => $items]));
    file_put_contents($dir.'/blips/sirius/01.wav', Pcm::toWav(str_repeat(pack('v', 4), 12000), 24000));
    file_put_contents($dir.'/blips/markus/01.wav', Pcm::toWav(str_repeat(pack('v', 6), 12000), 24000));

    return $dir;
}

it('rotates the jingles: never the same line twice in a row, none again within repeat_minutes while another is left', function () {
    $library = new JingleLibrary(programmeBlockJingleDir(['s1', 's2', 's3']), 30);
    $now = CarbonImmutable::parse('2026-10-12 12:00');
    $lines = [];

    for ($minute = 0; $minute < 12; $minute++) {
        $lines[] = $library->pick(['sweeper'], $now->addMinutes($minute * 5))['line'];
    }

    // Three lines, a pick every five minutes: each line comes back only after the other two, never sooner.
    foreach ($lines as $index => $line) {
        if ($index > 0) {
            expect($line)->not->toBe($lines[$index - 1]);
        }
        if ($index > 1) {
            expect($line)->not->toBe($lines[$index - 2]);
        }
    }

    expect(array_count_values($lines))->toHaveCount(3)
        ->and($library->pick(['rubric-liga', 'sweeper'], $now->addHours(2))['category'])->toBe('sweeper')
        ->and($library->pick(['nothing'], $now))->toBeNull();
});

/** A VoiceStudio answer of `$seconds` of tone at 24 kHz. */
function programmeBlockWav(float $seconds): string
{
    $pcm = '';
    for ($i = 0; $i < (int) (24000 * $seconds); $i++) {
        $pcm .= pack('v', ((int) (8000 * sin($i / 8))) & 0xFFFF);
    }

    return Pcm::toWav($pcm, 24000);
}

it('voices a block speaker by speaker, Sirius first, and assembles jingles, speech, boundaries and blips in running order', function () {
    config(['tv.commentary.voicestudio.profiles' => ['markus' => 'mmmm', 'sirius' => 'ssss'], 'tv.commentary.blocks.blips.share' => 1.0, 'tv.commentary.blocks.blips.min_gap_seconds' => 0]);
    $profiles = [];
    Http::fake(function (Request $request) use (&$profiles) {
        if (str_ends_with($request->url(), '/generate')) {
            $profiles[] = collect($request->data())->firstWhere('name', 'profile_id')['contents'] ?? null;

            return Http::response(programmeBlockWav(4.0), 200, ['x-audio-id' => 'abcd'.count($profiles)]);
        }

        return Http::response([]);
    });
    programmeBlockDecodes();
    $library = new JingleLibrary(programmeBlockJingleDir(['s1', 's2', 's3', 's4']), 30);

    $block = (new BlockRenderer(new CasterFetcher(fn () => null), $library, null, fn (): float => 0.0))->render([
        ['id' => 1, 'kind' => 'scene', 'sentences' => ['Markus sagt einen langen Satz.', 'Sirius antwortet darauf.', 'Markus schließt ab.'], 'speakers' => ['markus', 'sirius', 'markus']],
        ['id' => 2, 'kind' => 'scene', 'sentences' => ['Markus redet allein weiter.']],
    ], [], CarbonImmutable::parse('2026-10-12 12:00'));

    expect($profiles)->toBe(['ssss', 'mmmm', 'mmmm', 'mmmm'])
        ->and($block['batches'])->toBe(['sirius', 'markus'])
        ->and(array_map(fn (string $step): string => preg_replace('/^jingle:s\d-[ab]$/', 'jingle', $step), $block['steps']))->toBe(['jingle', 'script:1', 'jingle', 'script:2'])
        ->and(substr($block['steps'][0], 7, 2))->not->toBe(substr($block['steps'][2], 7, 2))
        ->and($block['boundaries'])->toHaveCount(1)
        ->and($block['ends'])->toHaveCount(4)
        ->and($block['ends'])->toBe(collect($block['ends'])->sort()->values()->all())
        // A blip under every turn of the duet (share 1), in the other speaker's voice; none under the solo script.
        ->and(array_column($block['blips'], 'speaker'))->toBe(['sirius', 'markus', 'sirius'])
        ->and(max(array_column($block['blips'], 'at')))->toBeLessThan($block['boundaries'][0]);
});

it('mixes an overlay at its gain into the base and keeps the rest of either', function () {
    $base = str_repeat(pack('v', 1000), 10);
    $mixed = Pcm::overlay($base, str_repeat(pack('v', 4000), 6), 8, -12.0);
    $values = array_values(unpack('v*', $mixed));

    // 4000 at -12 dB is 1005 (1004.75 rounded); the overlay reaches four samples past the base.
    expect(count($values))->toBe(14)
        ->and($values[7])->toBe(1000)
        ->and($values[8])->toBe(2005)
        ->and($values[13])->toBe(1005);
});

it('places no blip with share 0, and none under a script one speaker reads alone', function () {
    config(['tv.commentary.blocks.blips.share' => 0.0]);
    Http::fake(['http://voicestudio.test/generate' => Http::response(programmeBlockWav(4.0)), '*' => Http::response([])]);
    programmeBlockDecodes();

    $block = (new BlockRenderer(new CasterFetcher(fn () => null), new JingleLibrary(programmeBlockJingleDir([]), 30), null, fn (): float => 0.0))->render([
        ['id' => 1, 'kind' => 'scene', 'sentences' => ['Eins.', 'Zwei.'], 'speakers' => ['markus', 'sirius']],
    ], [], CarbonImmutable::now());

    expect($block['blips'])->toBe([])
        ->and($block['steps'])->toBe(['script:1']);
});

/* ---------------------------------------------------------------------- workstation: the block loop */

it('claims scripts up to the block length, uploads a reaction at once on its own, and the block in one loudness pass', function () {
    // Several scripts per block: the mode without music first (MusicFirstTest covers one item per block).
    config(['tv.commentary.music_first.enabled' => false]);
    config(['tv.commentary.blocks.enabled' => true, 'tv.commentary.blocks.seconds' => ['min' => 30, 'max' => 60], 'tv.commentary.blocks.jingles.dir' => programmeBlockJingleDir(['s1', 's2'])]);
    $claims = [
        ['id' => 11, 'kind' => 'scene', 'sentences' => ['Eins ist ein Satz.'], 'estimated_seconds' => 12],
        ['id' => 12, 'kind' => 'chat', 'sentences' => ['Danke für die Frage.'], 'estimated_seconds' => 3],
        ['id' => 13, 'kind' => 'history', 'sentences' => ['Zwei ist ein Satz.'], 'estimated_seconds' => 12, 'part' => ['group' => 'g', 'index' => 1, 'continues' => true]],
        ['id' => 14, 'kind' => 'history', 'sentences' => ['Drei ist ein Satz.'], 'estimated_seconds' => 12, 'part' => ['group' => 'g', 'index' => 2, 'continues' => false]],
        ['id' => 15, 'kind' => 'scene', 'sentences' => ['Vier.'], 'estimated_seconds' => 12],
    ];
    Http::fake(function (Request $request) use (&$claims) {
        return match (true) {
            str_ends_with($request->url(), '/api/caster/claim') => Http::response(['script' => array_shift($claims), 'full' => false, 'spool_seconds' => 0, 'pronunciation' => []]),
            str_ends_with($request->url(), '/generate') => Http::response(programmeBlockWav(1.0)),
            str_ends_with($request->url(), '/clip'), str_ends_with($request->url(), '/blocks') => Http::response(['clip' => 1], 201),
            default => Http::response([]),
        };
    });
    Process::fake(function ($process) {
        if (in_array('loudnorm=I=-16:TP=-1.5:LRA=11', $process->command, true)) {
            return Process::result(output: casterOpus());
        }

        return Process::result(output: str_repeat(pack('v', 2), 24000));
    });

    expect((new CasterFetcher(fn () => null))->runOnce())->toBe(CasterFetcher::VOICED);

    // The reaction went up alone, right away; the block holds 11, 13 and 14 (the story ran past the minimum).
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://tv.test/api/caster/scripts/12/clip');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://tv.test/api/caster/blocks'
        && $request->header('X-Caster-Scripts')[0] === '11,13,14' && count(explode(',', $request->header('X-Caster-Boundaries')[0])) === 2);
    expect($claims)->toHaveCount(1);
    // One loudness pass for the reaction, one for the whole block.
    Process::assertRanTimes(fn ($process): bool => in_array('loudnorm=I=-16:TP=-1.5:LRA=11', $process->command, true), 2);
});

it('renders one programme item per block with music first: a scene alone, a story with all its parts, the next scene alone', function () {
    config(['tv.commentary.music_first.enabled' => true, 'tv.commentary.music_first.one_item_per_block' => true,
        'tv.commentary.blocks.enabled' => true, 'tv.commentary.blocks.seconds' => ['min' => 30, 'max' => 60], 'tv.commentary.blocks.jingles.dir' => programmeBlockJingleDir([])]);
    $claims = [
        ['id' => 41, 'kind' => 'scene', 'sentences' => ['Eins ist ein Satz.'], 'estimated_seconds' => 12],
        ['id' => 43, 'kind' => 'history', 'sentences' => ['Zwei ist ein Satz.'], 'estimated_seconds' => 12, 'part' => ['group' => 'g', 'index' => 1, 'continues' => true]],
        ['id' => 44, 'kind' => 'history', 'sentences' => ['Drei ist ein Satz.'], 'estimated_seconds' => 12, 'part' => ['group' => 'g', 'index' => 2, 'continues' => false]],
        ['id' => 45, 'kind' => 'scene', 'sentences' => ['Vier ist ein Satz.'], 'estimated_seconds' => 12],
    ];
    $blocks = [];
    Http::fake(function (Request $request) use (&$claims, &$blocks) {
        if (str_ends_with($request->url(), '/blocks')) {
            $blocks[] = $request->header('X-Caster-Scripts')[0];
        }

        return match (true) {
            str_ends_with($request->url(), '/api/caster/claim') => Http::response(['script' => array_shift($claims), 'full' => false, 'spool_seconds' => 0, 'pronunciation' => []]),
            str_ends_with($request->url(), '/generate') => Http::response(programmeBlockWav(1.0)),
            str_ends_with($request->url(), '/clip'), str_ends_with($request->url(), '/blocks') => Http::response(['clip' => 1], 201),
            default => Http::response([]),
        };
    });
    Process::fake(fn ($process) => Process::result(output: in_array('libopus', $process->command, true) ? casterOpus() : str_repeat(pack('v', 2), 24000)));

    $fetcher = new CasterFetcher(fn () => null);
    foreach (range(1, 3) as $run) {
        $fetcher->runOnce();
    }

    expect($blocks)->toBe(['41', '43,44', '45']);
});

it('hands out only what cannot wait when asked for urgent scripts in the middle of a render', function () {
    $queue = app(ScriptQueue::class);
    $scene = $queue->enqueue(CasterScript::SCENE, ScenePhase::BREAK, 'Eine ganz normale Szene für den Block.', 'a', 90, CarbonImmutable::now());
    $ask = fn () => $this->postJson('/api/caster/claim?only=urgent', [], ['Authorization' => 'Bearer '.PROGRAMME_BLOCK_TOKEN])->assertOk()->json('script');

    expect($ask())->toBeNull()
        ->and($scene->fresh()->status)->toBe(CasterScript::QUEUED);

    $chat = $queue->enqueue(CasterScript::CHAT, ScenePhase::BREAK, 'Danke dir für die Frage im Chat.', 'b', 10, CarbonImmutable::now());

    expect($ask()['id'])->toBe($chat->id)
        ->and($scene->fresh()->status)->toBe(CasterScript::QUEUED);
});

it('answers a reaction that arrives while a block is voiced at once, before the block is uploaded', function () {
    config(['tv.commentary.blocks.enabled' => true, 'tv.commentary.blocks.seconds' => ['min' => 10, 'max' => 60], 'tv.commentary.blocks.urgent_poll_seconds' => 0, 'tv.commentary.blocks.jingles.dir' => programmeBlockJingleDir([])]);
    $normal = [['id' => 31, 'kind' => 'scene', 'sentences' => ['Eins ist ein Satz.', 'Zwei ist ein Satz.'], 'estimated_seconds' => 20]];
    $urgent = [['id' => 32, 'kind' => 'chat', 'sentences' => ['Danke für die Frage.'], 'estimated_seconds' => 3]];
    $order = [];
    Http::fake(function (Request $request) use (&$normal, &$urgent, &$order) {
        $order[] = preg_replace('#^https://tv\.test#', '', $request->url());

        return match (true) {
            str_ends_with($request->url(), '/api/caster/claim?only=urgent') => Http::response(['script' => array_shift($urgent), 'pronunciation' => []]),
            str_ends_with($request->url(), '/api/caster/claim') => Http::response(['script' => array_shift($normal), 'spool_seconds' => 0, 'pronunciation' => []]),
            str_ends_with($request->url(), '/generate') => Http::response(programmeBlockWav(1.0)),
            str_ends_with($request->url(), '/clip'), str_ends_with($request->url(), '/blocks') => Http::response(['clip' => 1], 201),
            default => Http::response([]),
        };
    });
    Process::fake(fn ($process) => Process::result(output: in_array('libopus', $process->command, true) ? casterOpus() : str_repeat(pack('v', 2), 24000)));

    expect((new CasterFetcher(fn () => null))->runOnce())->toBe(CasterFetcher::VOICED);

    $uploads = array_values(array_filter($order, fn (string $url): bool => str_ends_with($url, '/clip') || str_ends_with($url, '/blocks')));
    expect($uploads)->toBe(['/api/caster/scripts/32/clip', '/api/caster/blocks']);
});

it('gives a short block back to the queue while the stream has audio enough, and sends it when the stream runs low', function () {
    // Several scripts per block: the mode without music first (MusicFirstTest covers one item per block).
    config(['tv.commentary.music_first.enabled' => false]);
    config(['tv.commentary.blocks.enabled' => true, 'tv.commentary.blocks.seconds' => ['min' => 120, 'max' => 240], 'tv.commentary.blocks.starve_seconds' => 150, 'tv.commentary.blocks.jingles.dir' => programmeBlockJingleDir([])]);
    Process::fake(fn ($process) => Process::result(output: in_array('libopus', $process->command, true) ? casterOpus() : str_repeat(pack('v', 2), 24000)));

    $fetcher = new CasterFetcher(fn () => null);
    $spool = 400;
    $sequence = 0;
    Http::fake(function (Request $request) use (&$sequence, &$spool) {
        return match (true) {
            str_ends_with($request->url(), '/api/caster/claim') => Http::response(['script' => $sequence++ === 0 ? ['id' => 21, 'kind' => 'scene', 'sentences' => ['Eins.'], 'estimated_seconds' => 20] : null, 'spool_seconds' => $spool, 'pronunciation' => []]),
            str_ends_with($request->url(), '/generate') => Http::response(programmeBlockWav(1.0)),
            str_ends_with($request->url(), '/blocks') => Http::response(['clip' => 1], 201),
            default => Http::response([]),
        };
    });

    expect($fetcher->runOnce())->toBe(CasterFetcher::IDLE);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://tv.test/api/caster/scripts/21/release');
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/blocks'));

    [$spool, $sequence] = [40, 0];

    expect($fetcher->runOnce())->toBe(CasterFetcher::VOICED);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://tv.test/api/caster/blocks' && $request->header('X-Caster-Scripts')[0] === '21');
});

it('names the kept copy of a block after the programme of its scripts and keeps no copy of a live tournament block', function () {
    $dir = sys_get_temp_dir().'/caster-keep-'.bin2hex(random_bytes(4));
    config(['tv.commentary.music_first.enabled' => false, 'tv.commentary.voicestudio.keep_local_dir' => $dir,
        'tv.commentary.blocks.enabled' => true, 'tv.commentary.blocks.seconds' => ['min' => 1, 'max' => 240], 'tv.commentary.blocks.jingles.dir' => programmeBlockJingleDir([])]);
    Process::fake(fn ($process) => Process::result(output: in_array('libopus', $process->command, true) ? casterOpus() : str_repeat(pack('v', 2), 24000)));
    $claims = [];
    Http::fake(function (Request $request) use (&$claims) {
        return match (true) {
            str_ends_with($request->url(), '/api/caster/claim') => Http::response(['script' => array_shift($claims), 'spool_seconds' => 0, 'pronunciation' => []]),
            str_ends_with($request->url(), '/generate') => Http::response(programmeBlockWav(1.0)),
            str_ends_with($request->url(), '/blocks') => Http::response(['clip' => 1], 201),
            default => Http::response([]),
        };
    });

    $claims = [['id' => 41, 'kind' => 'scene', 'topic' => 'stock:stammtisch:bitcoin', 'sentences' => ['Eins.'], 'estimated_seconds' => 20]];
    expect((new CasterFetcher(fn () => null))->runOnce())->toBe(CasterFetcher::VOICED)
        ->and(array_map('basename', glob($dir.'/*.ogg')))->toHaveCount(1)
        ->and(glob($dir.'/*-block-stammtisch-*.ogg'))->toHaveCount(1);

    $claims = [['id' => 42, 'kind' => 'scene', 'topic' => 'turnier_live', 'sentences' => ['Zwei.'], 'estimated_seconds' => 20]];
    expect((new CasterFetcher(fn () => null))->runOnce())->toBe(CasterFetcher::VOICED)
        ->and(glob($dir.'/*.ogg'))->toHaveCount(1);

    array_map('unlink', glob($dir.'/*'));
    rmdir($dir);
});
