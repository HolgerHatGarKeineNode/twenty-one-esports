<?php

use App\Models\Admin;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\BrowserWebGL;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The broadcast engine on its styleguide (plan "OBS-Broadcast-Overlays", P1)
|--------------------------------------------------------------------------
|
| Runs with software WebGL (SwiftShader, tests/Support/BrowserWebGL.php) on the stage alone (`?stage=1`, what an
| OBS browser source shows) at 1920x1080, as an admin in German.
|
| 1. The engine renders with WebGL, the canvas is transparent in the free centre and where nothing is drawn, and
|    opaque on a held lower third (the sampler's positive control); console and answers stay clean, with a thrown
|    error and a broken image as the collector's positive control.
| 2. Tempo (the user's rule: AI-built motion runs too fast to read): the real timeline of the running page
|    (window.broadcast.timeline()), not resources/js/broadcast/timing.js, is held to the plan: every text holds
|    max(4 s, 1.5 s + 0.3 s per word) after its build-in, observed on the page's frames too; build-in 600-1200 ms,
|    build-out 400-800 ms; a pride moment 8-12 s in total; nothing on a held text moves; the ticker crawls at most
|    80 px/s; one slot changes at most every 8 s.
|
| SwiftShader draws on the CPU, so frame times are recorded (BROADCAST_SHOTS) and judged on a GPU, never here.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    config(['session.driver' => 'database']);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
});

afterAll(fn () => BrowserWebGL::off());

function broadcastStage(int $width = 1920, int $height = 1080): Page
{
    BrowserWebGL::on();
    $user = User::factory()->create(['locale' => 'de']);
    Admin::query()->create(['pubkey' => $user->pubkey]);
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    // The low tier (DPR 1, no bloom) keeps SwiftShader's CPU work to 1920x1080; the tiers are measured on a GPU.
    $page->goto(ComputeUrl::from('/broadcast/styleguide?stage=1&tier=low'));
    BrowserWait::until($page, '() => ["running", "no-webgl"].includes(document.body.dataset.broadcast)', 20_000);

    return $page;
}

/** Wait until the stage clock has passed `ms`. */
function broadcastAt(Page $page, int $ms): void
{
    BrowserWait::until($page, "() => window.broadcast.timeline().now >= {$ms}", $ms + 15_000);
}

function broadcastShot(Page $page, string $name): void
{
    $dir = getenv('BROADCAST_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

test('the stage renders with WebGL, transparent in the free centre and opaque on a held lower third, console clean', function () {
    $page = broadcastStage();

    expect($page->evaluate('() => window.broadcast.webgl'))->toBeTrue()
        // Drawing buffer = CSS size x devicePixelRatio, capped by the tier (low: 1).
        ->and($page->evaluate('() => window.broadcast.stats().tier'))->toBe('low')
        ->and($page->evaluate('() => window.broadcast.stats().width'))->toBe(1920);

    // The first lower third holds from 2.4 s (start 1.2 s after the program's 0.2 s, build-in 1 s) to 6.6 s.
    broadcastAt($page, 4_000);
    $alpha = $page->evaluate('() => ({ centre: window.broadcast.sampleAlpha(960, 540), centreEdge: window.broadcast.sampleAlpha(410, 240), corner: window.broadcast.sampleAlpha(1900, 400), plate: window.broadcast.sampleAlpha(420, 930) })');
    broadcastShot($page, 'broadcast-lower-third-1920');

    expect($alpha['centre'])->toBe(0)
        ->and($alpha['centreEdge'])->toBe(0)
        ->and($alpha['corner'])->toBe(0)
        // Positive control of the sampler: the glass plate of the lower third covers about 90 %.
        ->and($alpha['plate'])->toBeGreaterThan(0.8);

    $errors = [...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)];
    expect($errors)->toBe([]);

    file_put_contents((string) (getenv('BROADCAST_SHOTS') ?: sys_get_temp_dir()).'/broadcast-frames-swiftshader.json', json_encode($page->evaluate('() => window.broadcast.stats()')));

    $page->evaluate('() => { setTimeout(() => { throw new Error("broadcast positive control"); }); const img = new Image(); img.src = "/broadcast-positive-control-missing.png"; document.body.append(img); }');
    BrowserWait::until($page, '() => (window.__errors || []).some((e) => e.includes("broadcast positive control"))', 5_000);
    BrowserWait::until($page, '() => performance.getEntries().some((e) => e.name.includes("broadcast-positive-control-missing") && e.responseStatus === 404)', 5_000);
    expect($page->evaluate(BrowserConsole::BAD_RESPONSES))->toHaveCount(1);
});

test('tempo: every text of the running program holds long enough, builds in and out within the bounds and never moves while read', function () {
    $page = broadcastStage();

    // The first pride moment (right) starts at 3.2 s and ends 10 s later; wait for its end and the first lower third's.
    broadcastAt($page, 13_600);
    broadcastShot($page, 'broadcast-timing-end-1920');
    $snap = $page->evaluate('() => window.broadcast.timeline()');
    $rules = $snap['rules'];
    $segments = collect($snap['segments']);
    // The page's own frame interval bounds how late a phase can be stamped.
    $frame = max(50, (float) $page->evaluate('() => window.broadcast.stats().p99'));

    $finished = $segments->filter(fn (array $s): bool => in_array($s['kind'], ['lowerThird', 'pride'], true) && $s['observed']['end'] !== null);
    expect($finished->pluck('kind')->unique()->sort()->values()->all())->toBe(['lowerThird', 'pride']);

    foreach ($segments->whereIn('kind', ['lowerThird', 'pride']) as $s) {
        $rule = max($rules['holdMinMs'], $rules['holdBaseMs'] + $rules['holdPerWordMs'] * $s['words']);
        expect($s['holdMs'])->toBeGreaterThanOrEqual($rule, "{$s['id']} plans its hold under the rule")
            ->and($s['introMs'])->toBeGreaterThanOrEqual($rules['introMinMs'])->toBeLessThanOrEqual($rules['introMaxMs'])
            ->and($s['outroMs'])->toBeGreaterThanOrEqual($rules['outroMinMs'])->toBeLessThanOrEqual($rules['outroMaxMs']);

        if ($s['kind'] === 'pride') {
            expect($s['end'] - $s['start'])->toBeGreaterThanOrEqual($rules['prideMinMs'])->toBeLessThanOrEqual($rules['prideMaxMs']);
        }
    }

    foreach ($finished as $s) {
        $o = $s['observed'];
        // Observed on the page's frames: the hold really lasted, and nothing on a held text moved.
        expect($o['outroStart'] - $o['holdStart'])->toBeGreaterThanOrEqual($s['holdRuleMs'] - $frame, "{$s['id']} held {$o['holdStart']}..{$o['outroStart']}")
            ->and($s['holdMotionPx'])->toBe(0);
    }

    $ticker = $segments->firstWhere('kind', 'ticker');
    expect($ticker['observedPxPerS'])->toBeGreaterThan(0)->toBeLessThanOrEqual($rules['tickerMaxPxPerS']);

    // One slot changes at most every 8 s (start to start).
    foreach ($segments->whereIn('kind', ['lowerThird', 'pride'])->groupBy('slot') as $slot => $inSlot) {
        $starts = $inSlot->pluck('start')->sort()->values();
        for ($i = 1; $i < $starts->count(); $i++) {
            expect($starts[$i] - $starts[$i - 1])->toBeGreaterThanOrEqual($rules['rotationMinMs'], "slot {$slot}");
        }
    }
});
