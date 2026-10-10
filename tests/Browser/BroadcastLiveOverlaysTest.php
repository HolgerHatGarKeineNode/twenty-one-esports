<?php

use App\Enums\OverlayVariant;
use App\Enums\TournamentFormat;
use App\Models\ChessGame;
use App\Models\OverlayPreset;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserWait;
use Tests\Support\BrowserWebGL;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The league live and tournament overlays in the browser (plan "OBS-Broadcast-Overlays", P3, P4)
|--------------------------------------------------------------------------
|
| Each overlay opens as OBS opens it (no login, 1920x1080, software WebGL: tests/Support/BrowserWebGL.php) on real
| factory data and runs on its own:
|
| - It renders transparent, the free centre stays transparent while its elements are on air (their plates are the
|   sampler's positive control), and console and answers stay clean (a thrown error and a broken image are the
|   collector's positive control).
| - A live event becomes a corner moment that is visible within 3 s of its arrival, measured on the stage clock from
|   the call to the very handler the socket calls (league live: the `league.feed` listener with a rank-up; tournament:
|   `.tournament.changed` after an upset entered through TournamentRunner). The socket itself is not part of this.
| - Tempo, read from the running page (window.broadcast.timeline()), not from timing.js: every text holds
|   max(4 s, 1.5 s + 0.3 s per word), observed on the page's frames too; build-in 600-1200 ms, build-out 400-800 ms;
|   a pride moment 8-12 s; nothing held moves; the ticker crawls at most 80 px/s; one slot changes at most every 8 s;
|   two corner moments never overlap.
| - League live: the ticker takes a fresh list at its loop boundary (the feed's news joins the crawl).
|
*/

beforeEach(fn () => Http::fake(fn () => Http::response([])));

afterAll(fn () => BrowserWebGL::off());

function liveOverlay(string $token): Page
{
    BrowserWebGL::on();
    $page = visit('/broadcast/'.$token)->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize(1920, 1080);
    // The low tier (DPR 1, no bloom) keeps SwiftShader's CPU work to 1920x1080; the tiers are measured on a GPU.
    $page->goto(ComputeUrl::from('/broadcast/'.$token.'?tier=low'));
    BrowserWait::until($page, '() => ["running", "no-webgl"].includes(document.body.dataset.broadcast)', 20_000);
    expect($page->evaluate('() => window.broadcast.webgl'))->toBeTrue()
        ->and($page->evaluate('() => [getComputedStyle(document.documentElement).backgroundColor, getComputedStyle(document.body).backgroundColor]'))->toBe(['rgba(0, 0, 0, 0)', 'rgba(0, 0, 0, 0)']);

    return $page;
}

function liveOverlayAt(Page $page, int $ms): void
{
    BrowserWait::until($page, "() => window.broadcast.timeline().now >= {$ms}", $ms + 20_000);
}

/** The free centre's middle and its four corners, 10 px inside: all transparent. */
function liveOverlayCentre(Page $page): array
{
    return $page->evaluate('() => [[960, 540], [410, 235], [1510, 235], [410, 845], [1510, 845]].map(([x, y]) => window.broadcast.sampleAlpha(x, y))');
}

/**
 * Wait until a corner moment of `$moment` shows its plate at (x, y), and return the stage-clock time from `$sent`.
 */
function liveOverlayMomentAfter(Page $page, string $moment, int $sent, int $x, int $y): int
{
    BrowserWait::until($page, "() => window.broadcast.timeline().segments.some((s) => s.moment === '{$moment}') && window.broadcast.sampleAlpha({$x}, {$y}) > 0.5", 6_000);

    return (int) $page->evaluate('() => window.broadcast.timeline().now') - $sent;
}

/** The plan's tempo rules, held against the page's own timeline. */
function liveOverlayTempo(Page $page): array
{
    $snap = $page->evaluate('() => window.broadcast.timeline()');
    $rules = $snap['rules'];
    $segments = collect($snap['segments']);
    $frame = max(50, (float) $page->evaluate('() => window.broadcast.stats().p99'));
    $read = $segments->filter(fn (array $s): bool => $s['texts'] !== [] && $s['kind'] !== 'ticker');

    foreach ($read as $s) {
        $rule = max($rules['holdMinMs'], $rules['holdBaseMs'] + $rules['holdPerWordMs'] * $s['words']);
        expect($s['holdMs'])->toBeGreaterThanOrEqual($rule, "{$s['id']} plans its hold under the rule")
            ->and($s['introMs'])->toBeGreaterThanOrEqual($rules['introMinMs'])->toBeLessThanOrEqual($rules['introMaxMs'])
            ->and($s['outroMs'])->toBeGreaterThanOrEqual($rules['outroMinMs'])->toBeLessThanOrEqual($rules['outroMaxMs']);

        if ($s['kind'] === 'pride') {
            expect($s['end'] - $s['start'])->toBeGreaterThanOrEqual($rules['prideMinMs'])->toBeLessThanOrEqual($rules['prideMaxMs']);
        }

        if ($s['observed']['outroStart'] !== null) {
            expect($s['observed']['outroStart'] - $s['observed']['holdStart'])->toBeGreaterThanOrEqual($s['holdRuleMs'] - $frame, "{$s['id']} held too briefly");
        }

        if ($s['observed']['holdStart'] !== null) {
            expect($s['holdMotionPx'])->toBe(0, "{$s['id']} moved a held text");
        }
    }

    foreach ($read->groupBy('slot') as $slot => $inSlot) {
        $starts = $inSlot->pluck('start')->sort()->values();
        for ($i = 1; $i < $starts->count(); $i++) {
            expect($starts[$i] - $starts[$i - 1])->toBeGreaterThanOrEqual($rules['rotationMinMs'], "slot {$slot}");
        }
    }

    // Corner moments share the top band: one at a time, whatever corner.
    $pride = $segments->where('kind', 'pride')->sortBy('start')->values();
    for ($i = 1; $i < $pride->count(); $i++) {
        expect($pride[$i]['start'])->toBeGreaterThanOrEqual($pride[$i - 1]['end'], "{$pride[$i]['id']} overlaps {$pride[$i - 1]['id']}");
    }

    $ticker = $segments->firstWhere('kind', 'ticker');
    expect($ticker['observedPxPerS'])->toBeGreaterThan(0)->toBeLessThanOrEqual($rules['tickerMaxPxPerS']);

    return $snap;
}

function liveOverlayClean(Page $page, string $control): void
{
    $errors = [...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)];
    expect($errors)->toBe([]);

    $page->evaluate("() => { setTimeout(() => { throw new Error('{$control}'); }); const img = new Image(); img.src = '/{$control}-missing.png'; document.body.append(img); }");
    BrowserWait::until($page, "() => (window.__errors || []).some((e) => e.includes('{$control}'))", 5_000);
    BrowserWait::until($page, "() => performance.getEntries().some((e) => e.name.includes('{$control}-missing') && e.responseStatus === 404)", 5_000);
    expect($page->evaluate(BrowserConsole::BAD_RESPONSES))->toHaveCount(1);
}

test('league live: transparent with the centre free, a feed rank-up in a corner within 3 s, the tempo held and the ticker rebuilt at its loop', function () {
    $winner = User::factory()->create(['name' => 'hodlqueen']);
    $loser = User::factory()->create(['name' => 'markusturm']);
    ChessGame::factory()->finished('1-0')->create(['white_id' => $winner->id, 'black_id' => $loser->id]);
    Tournament::factory()->signup()->create(['published_at' => now(), 'signup_closes_at' => now()->addDay(), 'name' => 'Autumn Blitz Cup']);
    OverlayPreset::factory()->withToken($token = str_repeat('m', 48))->create(['name' => 'Laptop stream', 'locale' => 'en', 'modules' => ['cam-frame' => true] + OverlayPreset::MODULES]);

    $page = liveOverlay($token);

    // The stinger opens (0.3-1.9 s), the ticker and camera frame set in, the snapshot's latest win takes the right
    // corner (from about 3.4 s to 13.4 s); the lower third waits for it.
    liveOverlayAt($page, 7_000);
    $segments = collect($page->evaluate('() => window.broadcast.timeline().segments'));
    $alpha = $page->evaluate('() => ({ plate: window.broadcast.sampleAlpha(1200, 110), rail: window.broadcast.sampleAlpha(1400, 1002), cam: window.broadcast.sampleAlpha(1540, 860), camWindow: window.broadcast.sampleAlpha(1684, 867) })');

    expect($segments->pluck('kind')->unique()->sort()->values()->all())->toBe(['camFrame', 'pride', 'stinger', 'ticker'])
        ->and($segments->firstWhere('kind', 'pride')['texts'])->toBe(['hodlqueen', 'Beats markusturm', 'Blitz chess'])
        ->and(liveOverlayCentre($page))->toBe([0, 0, 0, 0, 0])
        // Positive controls of the sampler: the pride plate, the ticker rail, the camera bezel.
        ->and($alpha['plate'])->toBeGreaterThan(0.8)
        ->and($alpha['rail'])->toBeGreaterThan(0.5)
        ->and($alpha['cam'])->toBeGreaterThan(0.5)
        // The camera window stays open for the camera source.
        ->and($alpha['camWindow'])->toBe(0);

    // A breath after the moment, the join card with its QR code (the lower third's glass and the QR tile).
    BrowserWait::until($page, '() => window.broadcast.timeline().segments.some((s) => s.kind === "lowerThird" && s.observed.holdStart !== null)', 25_000);
    $card = collect($page->evaluate('() => window.broadcast.timeline().segments'))->firstWhere('kind', 'lowerThird');
    $pride = collect($page->evaluate('() => window.broadcast.timeline().segments'))->firstWhere('kind', 'pride');
    expect($card['texts'][0])->toBe('Play with us in the league')
        ->and($card['start'])->toBeGreaterThanOrEqual($pride['end'] + 3_000)
        ->and($page->evaluate('() => [window.broadcast.sampleAlpha(600, 930), window.broadcast.sampleAlpha(176, 890)]'))->each->toBeGreaterThan(0.8)
        ->and(liveOverlayCentre($page))->toBe([0, 0, 0, 0, 0]);

    // A rank-up arrives while the card is on air: a moment never waits for a card.
    $sentAt = (int) $page->evaluate('() => window.broadcast.timeline().now');
    expect($sentAt)->toBeLessThan($card['end']);
    $sent = (int) $page->evaluate('() => { window.broadcastOverlay.receive({ items: [{ kind: "rank-up", game: "chess", gameName: "Chess Blitz", winners: ["satsjaeger"], tier: "Gold II" }] }); return window.broadcast.timeline().now; }');
    // The second moment takes the left corner: its plate runs from x 368 to 952.
    expect(liveOverlayMomentAfter($page, 'rank-up', $sent, 600, 110))->toBeLessThanOrEqual(3_000)
        ->and(liveOverlayCentre($page))->toBe([0, 0, 0, 0, 0]);

    // The ticker's next loop takes the list with the feed's news first.
    BrowserWait::until($page, '() => window.broadcast.timeline().segments.find((s) => s.kind === "ticker").rebuilt >= 1', 60_000);
    liveOverlayTempo($page);
    liveOverlayClean($page, 'league-live-positive-control');
});

test('tournament: banner, board and ticker on air with the centre free, an upset in the corner within 3 s, the tempo held', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 8);
    $tournament->forceFill(['name' => 'Blitz Open', 'published_at' => now()->subHour()])->save();
    foreach ($tournament->participants()->orderBy('seed')->get() as $index => $participant) {
        $participant->forceFill(['name' => 'seed'.($index + 1)])->save();
    }
    OverlayPreset::factory()->withToken($token = str_repeat('n', 48))->create(['name' => 'Cup stream', 'locale' => 'en', 'variant' => OverlayVariant::Tournament, 'tournament_id' => $tournament->id]);

    $page = liveOverlay($token);

    // Stinger 0.3-1.9 s, ticker from 1.9 s, banner from 2.0 s, the board's first page from 2.4 s (held from 3.3 s).
    liveOverlayAt($page, 4_500);
    $segments = collect($page->evaluate('() => window.broadcast.timeline().segments'));
    $alpha = $page->evaluate('() => ({ banner: window.broadcast.sampleAlpha(500, 110), board: window.broadcast.sampleAlpha(230, 320), rail: window.broadcast.sampleAlpha(1400, 1002) })');

    expect($segments->pluck('kind')->unique()->sort()->values()->all())->toBe(['banner', 'board', 'stinger', 'ticker'])
        ->and($segments->firstWhere('kind', 'banner')['texts'])->toBe(['Blitz Open', 'Round 1, Single Elimination'])
        ->and($segments->firstWhere('kind', 'board')['texts'])->toContain('Round 1', 'seed1', 'seed8', 'Live')
        ->and(liveOverlayCentre($page))->toBe([0, 0, 0, 0, 0])
        ->and($alpha['banner'])->toBeGreaterThan(0.8)
        ->and($alpha['board'])->toBeGreaterThan(0.8)
        ->and($alpha['rail'])->toBeGreaterThan(0.5);

    // The 7th seed beats the 2nd (round 1 pairs 1-8, 4-5, 2-7, 3-6), entered as a director enters it.
    $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('key', 'm1-3')->firstOrFail();
    app(TournamentRunner::class)->enterResult($match, $tournament->creator, ['result' => '0-1']);
    Cache::flush();
    $sent = (int) $page->evaluate('() => { window.broadcastOverlay.tournamentChanged(); return window.broadcast.timeline().now; }');

    expect(liveOverlayMomentAfter($page, 'upset', $sent, 1200, 110))->toBeLessThanOrEqual(3_000);
    $upset = collect($page->evaluate('() => window.broadcast.timeline().segments'))->firstWhere('moment', 'upset');
    expect($upset['texts'])->toBe(['seed7', 'Beats seed2', 'Seed 7 beats seed 2, Round 1'])
        ->and($upset['slot'])->toBe('cornerRight')
        ->and(liveOverlayCentre($page))->toBe([0, 0, 0, 0, 0]);

    // Through the moment's end: the board's page, read for its 12 s, turns to one that carries the result.
    liveOverlayAt($page, max($upset['end'], 17_500) + 300);
    $snap = liveOverlayTempo($page);
    $boards = collect($snap['segments'])->where('kind', 'board')->values();
    expect($boards->count())->toBeGreaterThanOrEqual(2)
        ->and($boards[0]['end'] - $boards[0]['start'])->toBeLessThan(20_000)
        ->and($boards[1]['texts'])->toContain('seed7', '1', '0');
    liveOverlayClean($page, 'tournament-positive-control');
});
