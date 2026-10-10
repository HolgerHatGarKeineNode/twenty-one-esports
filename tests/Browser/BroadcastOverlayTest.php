<?php

use App\Enums\OverlayVariant;
use App\Models\OverlayPreset;
use App\Models\Tournament;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserWait;
use Tests\Support\BrowserWebGL;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| An OBS overlay preset's browser source (plan "OBS-Broadcast-Overlays", P2)
|--------------------------------------------------------------------------
|
| /broadcast/{token} of a break preset (the shell) opened without a login at 1920x1080 with software WebGL (SwiftShader,
| tests/Support/BrowserWebGL.php), as OBS opens it: the document is transparent, the engine runs the shell (the
| preset's name as a lower third, the ticker with the snapshot's segments), the free centre stays transparent
| while both are on air (the lower third's plate and the ticker rail are the sampler's positive control), the
| snapshot poll answers 200, and console and answers stay clean, with a thrown error and a broken image as the
| collector's positive control.
|
*/

beforeEach(fn () => Http::fake(fn () => Http::response([])));

afterAll(fn () => BrowserWebGL::off());

test('the overlay renders transparent with the centre free, its shell on air and a clean console', function () {
    BrowserWebGL::on();
    Tournament::factory()->signup()->create(['published_at' => now(), 'signup_closes_at' => now()->addDay(), 'name' => 'Autumn Blitz Cup']);
    // The transparent league overlay as OBS opens it (its moments: tests/Browser/BroadcastLiveOverlaysTest.php; the
    // opaque break and bracket scenes: BroadcastBreakBracketTest).
    OverlayPreset::factory()->withToken($token = str_repeat('k', 48))->create(['name' => 'Laptop stream', 'locale' => 'en', 'variant' => OverlayVariant::LeagueLive]);

    $page = visit('/broadcast/'.$token)->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize(1920, 1080);
    $page->goto(ComputeUrl::from('/broadcast/'.$token.'?tier=low'));
    BrowserWait::until($page, '() => ["running", "no-webgl"].includes(document.body.dataset.broadcast)', 20_000);

    expect($page->evaluate('() => window.broadcast.webgl'))->toBeTrue()
        ->and($page->evaluate('() => [getComputedStyle(document.documentElement).backgroundColor, getComputedStyle(document.body).backgroundColor]'))->toBe(['rgba(0, 0, 0, 0)', 'rgba(0, 0, 0, 0)'])
        ->and($page->evaluate('() => window.broadcast.stats().width'))->toBe(1920);

    $snapshot = $page->evaluate('() => window.broadcastOverlay.snapshot()');
    expect($snapshot['preset']['name'])->toBe('Laptop stream')
        ->and(collect($snapshot['ticker'])->pluck('text')->first(fn (string $text): bool => str_starts_with($text, 'Autumn Blitz Cup, starts ')))->not->toBeNull();

    // The name plate holds from about 1.3 s (start 0.3 s, build-in 1 s); the ticker is in from about 1.5 s.
    BrowserWait::until($page, '() => window.broadcast.timeline().now >= 3000', 20_000);
    $segments = collect($page->evaluate('() => window.broadcast.timeline().segments'));
    $alpha = $page->evaluate('() => ({ centre: window.broadcast.sampleAlpha(960, 540), centreEdge: window.broadcast.sampleAlpha(410, 240), corner: window.broadcast.sampleAlpha(1900, 400), plate: window.broadcast.sampleAlpha(420, 930), rail: window.broadcast.sampleAlpha(1400, 1002) })');

    expect($segments->pluck('kind')->unique()->all())->toContain('ticker')
        ->and($alpha['centre'])->toBe(0)
        ->and($alpha['centreEdge'])->toBe(0)
        ->and($alpha['corner'])->toBe(0)
        // Positive control of the sampler: the ticker rail covers most of its pixels.
        ->and($alpha['rail'])->toBeGreaterThan(0.5);

    // The poll fallback answers: one fetch of the snapshot, the way the 20 s timer does it.
    expect($page->evaluate('async () => (await fetch(JSON.parse(document.getElementById("broadcast-config").textContent).snapshotUrl)).status'))->toBe(200);

    $errors = [...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)];
    expect($errors)->toBe([]);

    $page->evaluate('() => { setTimeout(() => { throw new Error("overlay positive control"); }); const img = new Image(); img.src = "/overlay-positive-control-missing.png"; document.body.append(img); }');
    BrowserWait::until($page, '() => (window.__errors || []).some((e) => e.includes("overlay positive control"))', 5_000);
    BrowserWait::until($page, '() => performance.getEntries().some((e) => e.name.includes("overlay-positive-control-missing") && e.responseStatus === 404)', 5_000);
    expect($page->evaluate(BrowserConsole::BAD_RESPONSES))->toHaveCount(1);
});
