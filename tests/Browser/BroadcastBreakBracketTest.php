<?php

use App\Enums\OverlayVariant;
use App\Enums\TournamentFormat;
use App\Models\Admin;
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
| The break scene, the bracket scene and the page's 3D view (plan "OBS-Broadcast-Overlays", P5, P6)
|--------------------------------------------------------------------------
|
| The two full-screen variants open as OBS opens them (no login, 1920x1080, software WebGL) on factory data:
|
| - Both are opaque over the whole frame (the free centre of the overlays does not apply), console and answers stay
|   clean (a thrown error and a broken image are the collector's positive control).
| - Tempo from the running page (window.broadcast.timeline()), not from timing.js: every text holds max(4 s, 1.5 s +
|   0.3 s per word) planned and observed, build-in 600-1200 ms, build-out 400-800 ms, nothing held moves; the break
|   scene's sections change at most every 8 s behind the wipe; a bracket page (a camera stop) holds at least 8 s and
|   its names do not move on the screen while it holds.
| - The bracket plays a live result on the page the camera stands on: within 3 s of `.tournament.changed` the light
|   runs from the decided match and the winner's name stands in the next match.
|
| The tournament page's switch: 3D loads three.js only when picked, is remembered (no layout shift on the next load,
| measured as layout-shift entries), keeps the page free of sideways overflow at 1440 px; without WebGL (the suite's
| plain browser) a remembered 3D falls back to the 2D bracket and the 3D button is disabled.
|
*/

beforeEach(fn () => Http::fake(fn () => Http::response([])));

afterAll(fn () => BrowserWebGL::off());

function scenePage(string $token, string $query = '?tier=low'): Page
{
    BrowserWebGL::on();
    $page = visit('/broadcast/'.$token)->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize(1920, 1080);
    $page->goto(ComputeUrl::from('/broadcast/'.$token.$query));
    BrowserWait::until($page, '() => ["running", "no-webgl"].includes(document.body.dataset.broadcast)', 20_000);
    expect($page->evaluate('() => window.broadcast.webgl'))->toBeTrue();

    return $page;
}

function sceneAt(Page $page, int $ms): void
{
    BrowserWait::until($page, "() => window.broadcast.timeline().now >= {$ms}", $ms + 20_000);
}

/** The frame's middle, its four corners and the old free centre's corners: all opaque in a full-screen scene. */
function sceneOpaque(Page $page): array
{
    return $page->evaluate('() => [[960, 540], [10, 10], [1910, 10], [10, 1070], [1910, 1070], [410, 235], [1510, 845]].map(([x, y]) => window.broadcast.sampleAlpha(x, y))');
}

/** The plan's tempo rules for every text segment of the page's own timeline. */
function sceneTempo(Page $page): array
{
    $snap = $page->evaluate('() => window.broadcast.timeline()');
    $rules = $snap['rules'];
    $frame = max(50, (float) $page->evaluate('() => window.broadcast.stats().p99'));

    foreach (collect($snap['segments'])->filter(fn (array $s): bool => $s['texts'] !== [] && $s['kind'] !== 'ticker') as $s) {
        $rule = max($rules['holdMinMs'], $rules['holdBaseMs'] + $rules['holdPerWordMs'] * $s['words']);
        expect($s['holdMs'])->toBeGreaterThanOrEqual($rule, "{$s['id']} plans its hold under the rule")
            ->and($s['introMs'])->toBeGreaterThanOrEqual($rules['introMinMs'])->toBeLessThanOrEqual($rules['introMaxMs'])
            ->and($s['outroMs'])->toBeGreaterThanOrEqual($rules['outroMinMs'])->toBeLessThanOrEqual($rules['outroMaxMs']);

        if ($s['observed']['outroStart'] !== null) {
            expect($s['observed']['outroStart'] - $s['observed']['holdStart'])->toBeGreaterThanOrEqual($s['holdRuleMs'] - $frame, "{$s['id']} held too briefly");
        }

        if ($s['observed']['holdStart'] !== null) {
            expect($s['holdMotionPx'])->toBe(0, "{$s['id']} moved a held text");
        }
    }

    return $snap;
}

function sceneClean(Page $page, string $control): void
{
    $errors = [...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)];
    expect($errors)->toBe([]);

    $page->evaluate("() => { setTimeout(() => { throw new Error('{$control}'); }); const img = new Image(); img.src = '/{$control}-missing.png'; document.body.append(img); }");
    BrowserWait::until($page, "() => (window.__errors || []).some((e) => e.includes('{$control}'))", 5_000);
    BrowserWait::until($page, "() => performance.getEntries().some((e) => e.name.includes('{$control}-missing') && e.responseStatus === 404)", 5_000);
    expect($page->evaluate(BrowserConsole::BAD_RESPONSES))->toHaveCount(1);
}

function sceneBracket(): Tournament
{
    $tournament = runningChess(TournamentFormat::SingleElimination, 8);
    $tournament->forceFill(['name' => 'Blitz Open', 'published_at' => now()->subHour()])->save();

    foreach ($tournament->participants()->orderBy('seed')->get() as $index => $participant) {
        $participant->forceFill(['name' => 'seed'.($index + 1)])->save();
    }

    return $tournament->refresh();
}

test('break: opaque full screen, the clock to the start, sections in turn behind the wipe, the tempo held', function () {
    $soon = Tournament::factory()->signup()->create(['published_at' => now(), 'signup_closes_at' => now()->addMinutes(20), 'starts_at' => now()->addMinutes(50), 'name' => 'Evening Cup', 'pot_source' => Tournament::POT_LEAGUE, 'prize_target_sats' => 21_000]);
    Tournament::factory()->signup()->create(['published_at' => now(), 'signup_closes_at' => now()->addDays(2), 'starts_at' => now()->addDays(3), 'name' => 'Autumn Cup']);
    OverlayPreset::factory()->withToken($token = str_repeat('p', 48))->create(['name' => 'Pause', 'locale' => 'en', 'variant' => OverlayVariant::Break, 'tournament_id' => $soon->id, 'modules' => ['music' => false] + OverlayPreset::MODULES]);

    $page = scenePage($token);

    // Stinger 0.3-1.9 s; the hero from 0.8 s (held from 2.0 s), the column's first section from 2.3 s.
    sceneAt($page, 5_000);
    $segments = collect($page->evaluate('() => window.broadcast.timeline().segments'));
    $hero = $segments->firstWhere('kind', 'hero');

    expect(sceneOpaque($page))->each->toBe(1)
        ->and(array_slice($hero['texts'], 0, 4))->toBe(['Starting soon', 'Evening Cup, Chess', 'Starts in', '00:00:00'])
        ->and($hero['texts'][4])->toStartWith('Starts ')
        ->and($segments->pluck('kind')->unique()->sort()->values()->all())->toContain('hero', 'panel', 'stinger', 'join')
        ->and($page->evaluate('() => window.broadcastOverlay.state().sections'))->toBe(['coming', 'pot']);

    // The first section holds 16 s, the wipe covers the change, the next section builds in behind it.
    BrowserWait::until($page, '() => window.broadcast.timeline().segments.filter((s) => s.kind === "panel").length >= 2 && window.broadcast.timeline().segments.filter((s) => s.kind === "panel")[1].observed.holdStart !== null', 40_000);
    $snap = sceneTempo($page);
    $panels = collect($snap['segments'])->where('kind', 'panel')->values();
    $wipe = collect($snap['segments'])->firstWhere('kind', 'wipe');

    expect($panels[1]['start'] - $panels[0]['start'])->toBeGreaterThanOrEqual($snap['rules']['rotationMinMs'])
        ->and($panels[0]['holdMs'])->toBeGreaterThanOrEqual(16_000)
        // The wipe covers the column once the old rows are gone and before the new ones build in.
        ->and($wipe['start'] + $wipe['introMs'])->toBeGreaterThanOrEqual($panels[0]['end'] - 100)
        ->and($wipe['start'] + $wipe['introMs'])->toBeLessThanOrEqual($panels[1]['start'])
        ->and(sceneOpaque($page))->each->toBe(1);
    sceneClean($page, 'break-positive-control');
});

test('bracket: opaque full screen, pages that hold still for 8 s, a live result played on its page within 3 s', function () {
    $tournament = sceneBracket();
    OverlayPreset::factory()->withToken($token = str_repeat('q', 48))->create(['name' => 'Bracket', 'locale' => 'en', 'variant' => OverlayVariant::Bracket, 'tournament_id' => $tournament->id]);

    $page = scenePage($token);

    // The overview from 1.0 s, then the first page (round 1 with the semifinals it feeds) holds from ~12 s.
    BrowserWait::until($page, '() => window.bracketScene.current()?.kind === "page" && window.broadcast.timeline().segments.some((s) => s.kind === "bracketPage" && s.pageKind === "page" && s.observed.holdStart !== null)', 40_000);
    $pages = $page->evaluate('() => window.bracketScene.pages()');
    expect(collect($pages)->pluck('kind')->all())->toBe(['overview', 'page', 'page', 'final'])
        ->and($pages[1]['plates'])->toBe(['m1-1', 'm1-2', 'm1-3', 'm1-4', 'm2-1', 'm2-2'])
        ->and($pages[1]['texts'])->toContain('Round 1', 'seed1', 'seed8')
        ->and(sceneOpaque($page))->each->toBe(1);

    // The 7th seed beats the 2nd in m1-3, entered as a director enters it; the light runs, the name lands.
    $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('key', 'm1-3')->firstOrFail();
    app(TournamentRunner::class)->enterResult($match, $tournament->creator, ['result' => '0-1']);
    Cache::flush();
    $sent = (int) $page->evaluate('() => { window.broadcastOverlay.tournamentChanged(); return window.broadcast.timeline().now; }');
    BrowserWait::until($page, '() => window.bracketScene.lit().includes("m1-3")', 6_000);
    $lit = (int) $page->evaluate('() => window.broadcast.timeline().now') - $sent;
    expect($lit)->toBeLessThanOrEqual(3_000)
        ->and(collect($page->evaluate('() => window.bracketScene.pages()'))->firstWhere('id', 'p1')['texts'])->toContain('seed7');

    // Through the next page turn: every page held 8 s at least and its names stood still on the screen.
    BrowserWait::until($page, '() => window.broadcast.timeline().segments.filter((s) => s.kind === "bracketPage" && s.observed.end !== null).length >= 2', 40_000);
    $snap = sceneTempo($page);
    foreach (collect($snap['segments'])->where('kind', 'bracketPage')->filter(fn (array $s): bool => $s['observed']['end'] !== null) as $s) {
        expect($s['holdMs'])->toBeGreaterThanOrEqual(8_000)
            ->and($s['observed']['outroStart'] - $s['observed']['holdStart'])->toBeGreaterThanOrEqual(8_000)
            ->and($s['holdMotionPx'])->toBe(0);
    }
    sceneClean($page, 'bracket-positive-control');
});

test('the tournament page: 3D loads on demand, is remembered without a layout shift and fits 1440 px; 2D comes back', function () {
    $tournament = sceneBracket();
    $admin = User::factory()->create(['locale' => 'de']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $this->actingAs($admin);

    BrowserWebGL::on();
    $page = visit(route('tournaments.show', $tournament).'?lang=de')->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript("document.addEventListener('readystatechange', () => { if (document.readyState === 'interactive') window.__viewAtParse = document.querySelector('[data-test=bracket]')?.dataset.bracketView ?? null; }); window.__shifts = []; new PerformanceObserver((l) => l.getEntries().forEach((e) => { if (!e.hadRecentInput) window.__shifts.push(e); })).observe({ type: 'layout-shift', buffered: true });");
    $page->setViewportSize(1440, 900);
    $page->goto(ComputeUrl::from(route('tournaments.show', $tournament, false).'?lang=de'));
    BrowserWait::until($page, '() => !!document.querySelector("[data-test=bracket-view-toggle]")', 10_000);

    expect($page->evaluate('() => [!!window.THREE, document.querySelector("[data-test=bracket]").dataset.bracketView, document.querySelector("[data-test=bracket-2d]").offsetParent !== null]'))->toBe([false, '2d', true]);

    $page->evaluate('() => document.querySelector("[data-test=bracket-view-3d]").click()');
    BrowserWait::until($page, '() => window.bracket3d?.ready === true && window.bracket3d.state().count > 0', 30_000);
    $state = $page->evaluate('() => window.bracket3d.state()');
    $box = $page->evaluate('() => { const r = document.querySelector("[data-test=bracket-3d]").getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)]; }');
    expect($state['pages'][0])->toBe('Runde 1')
        ->and($page->evaluate('() => [localStorage.getItem("bracket-view"), document.querySelector("[data-test=bracket-2d]").offsetParent]'))->toBe(['3d', null])
        ->and($page->evaluate(BrowserConsole::WIDTHS)[0])->toBeLessThanOrEqual($page->evaluate(BrowserConsole::WIDTHS)[1])
        // 16:9 of the column, capped at 70 % of the viewport (630 px at 900), plus the 56 px button bar.
        ->and($box[0])->toBe($page->evaluate('() => { const s = document.querySelector("[data-test=bracket]"); const c = getComputedStyle(s); return Math.round(s.clientWidth - parseFloat(c.paddingLeft) - parseFloat(c.paddingRight)); }'))
        ->and($box[1])->toBeLessThanOrEqual(min(630, (int) round($box[0] * 9 / 16)) + 60);

    // The next part flies there; the title follows.
    $page->evaluate('() => document.querySelector("[data-test=bracket-3d-next]").click()');
    BrowserWait::until($page, '() => document.querySelector("[data-test=bracket-3d-title]").textContent === "Halbfinale"', 5_000);

    // Next visit: the remembered 3D is there before the first paint: nothing in or below the bracket shifts, the
    // section stays where it was laid out. (The desk's chat rail beside it shifts on its own, 0.015, measured
    // 2026-10-10: not this switch, so only shifts of nodes from the bracket on count.)
    $page->goto(ComputeUrl::from(route('tournaments.show', $tournament, false).'?lang=de'));
    $top = $page->evaluate('() => Math.round(document.querySelector("[data-test=bracket]").getBoundingClientRect().top + scrollY)');
    BrowserWait::until($page, '() => window.bracket3d?.ready === true', 30_000);
    $shift = $page->evaluate('() => { const s = document.querySelector("[data-test=bracket]"); return window.__shifts.filter((e) => (e.sources || []).some((x) => x.node && (s.contains(x.node) || (s.compareDocumentPosition(x.node) & Node.DOCUMENT_POSITION_FOLLOWING)))).reduce((n, e) => n + e.value, 0); }');
    expect($shift)->toBeLessThan(0.001)
        // Set while the HTML was parsed, before any script of the bundle ran (Alpine would be too late on a slow load).
        ->and($page->evaluate('() => window.__viewAtParse'))->toBe('3d')
        ->and($page->evaluate('() => Math.round(document.querySelector("[data-test=bracket]").getBoundingClientRect().top + scrollY)'))->toBe($top);

    $page->evaluate('() => document.querySelector("[data-test=bracket-view-2d]").click()');
    expect($page->evaluate('() => [localStorage.getItem("bracket-view"), document.querySelector("[data-test=bracket-2d]").offsetParent !== null, document.querySelector("[data-test=bracket-3d]").offsetParent]'))->toBe(['2d', true, null]);
    sceneClean($page, 'page-3d-positive-control');
});

test('without WebGL a remembered 3D falls back to the 2D bracket and the 3D button is disabled', function () {
    $tournament = sceneBracket();
    BrowserWebGL::off();

    $page = visit(route('tournaments.show', $tournament).'?lang=en')->page();
    $page->context()->addInitScript("try { localStorage.setItem('bracket-view', '3d'); } catch (e) {}");
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize(390, 844);
    $page->goto(ComputeUrl::from(route('tournaments.show', $tournament, false).'?lang=en'));
    BrowserWait::until($page, '() => !!window.Alpine && document.querySelector("[data-test=bracket-view-3d]").disabled === true', 10_000);

    expect($page->evaluate('() => [document.querySelector("[data-test=bracket]").dataset.bracketView, document.querySelector("[data-test=bracket-2d]").offsetParent !== null, document.querySelector("[data-test=bracket-3d]").offsetParent, !!window.THREE]'))->toBe(['2d', true, null, false])
        ->and($page->evaluate(BrowserConsole::WIDTHS)[0])->toBeLessThanOrEqual($page->evaluate(BrowserConsole::WIDTHS)[1]);
    sceneClean($page, 'page-2d-positive-control');
});
