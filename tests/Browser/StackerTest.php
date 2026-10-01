<?php

use App\Models\StackerRun;
use App\Models\User;
use App\Support\Stacker\StackerSettings;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BlockfillOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Blockfill in the browser (plan "Blockfill", P3)
|--------------------------------------------------------------------------
|
| Two flows on the game page, fed through the test-only hook
| `window.__stacker` (the page installs it in the testing environment only):
|
| 1. A guest practises: the built bundle replays every reference run of
|    tests/Fixtures/stacker to the same ticks and hash as Node (Chromium =
|    Node), and the recorded 40-line run, fed as a practice run, ends on the
|    result screen at 0:15.967. Measured at 375 and 1440 px.
| 2. A player plays it as a ranked run: the issued seed is pinned to the
|    reference seed (`esports.blockfill.testing_seed`, testing only), the
|    countdown ends with the start call, the recorded log replaces the
|    keyboard, the submission waits until the server's clock has moved past
|    the played time, and the real Node verifier replays it: verified.
|
| Console, uncaught errors and every answer >= 400 are collected
| (BrowserConsole) and stay empty, with a positive control.
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

    BlockfillOn::play();
});

function stackerPage(?User $user, int $width, int $height): Page
{
    $page = visit($user === null ? BrowserLogin::LANDING : BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('stacker.play', [], false)));
    BrowserWait::until($page, '() => window.__stacker !== undefined', 10_000);

    return $page;
}

/**
 * @return array<string, mixed>
 */
function stackerFixture(string $name): array
{
    return json_decode((string) file_get_contents(base_path("tests/Fixtures/stacker/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
}

test('a guest practises: Chromium replays every reference run like Node, the 40-line run ends on the result screen, no overflow, clean console', function (int $width, int $height) {
    $page = stackerPage(null, $width, $height);

    // Chromium = Node: the built bundle replays each reference run to its recorded ticks and hash.
    foreach (['forty-lines', 'top-out', 'hard-drops'] as $name) {
        $fixture = stackerFixture($name);
        $result = $page->evaluate('([seed, settings, inputs]) => window.__stacker.run(seed, settings, inputs)', [$fixture['seed'], $fixture['settings'], $fixture['inputs']]);
        expect($result)->toBe($fixture['expected'], $name);
    }

    shellShot($page, "stacker-{$width}-idle");

    $forty = stackerFixture('forty-lines');
    $fed = $page->evaluate('([inputs, seed, settings]) => window.__stacker.feed(inputs, { seed, settings })', [$forty['inputs'], $forty['seed'], $forty['settings']]);
    expect($fed)->toBe($forty['expected']);
    BrowserWait::until($page, '() => window.__stacker.state().result?.status === "practice"', 5_000);

    [$scrollWidth, $clientWidth] = $page->evaluate(BrowserConsole::WIDTHS);
    $well = $page->evaluate('() => { const r = document.querySelector("[data-test=well]").getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), width: Math.round(r.width), height: Math.round(r.height) }; }');

    expect($page->evaluate('() => document.querySelector("[data-test=result-time]").innerText'))->toBe('0:15.967')
        ->and($page->evaluate('() => document.querySelector("[data-test=result-status]").innerText'))->toBe('Practice run, not sent')
        ->and($page->evaluate('() => document.querySelector("[data-test=lines]").innerText'))->toBe('40')
        ->and($page->evaluate('() => document.querySelector("[data-test=time]").innerText'))->toBe('0:15.96')
        // The well: ten cells wide, 22 rows high, inside the viewport; the page does not scroll sideways.
        ->and($well['width'] * 22)->toBe($well['height'] * 10)
        ->and($well['width'])->toBeGreaterThanOrEqual($width < 640 ? 150 : 240)
        ->and($well['left'])->toBeGreaterThanOrEqual(0)
        ->and($well['right'])->toBeLessThanOrEqual($width)
        ->and($scrollWidth)->toBeLessThanOrEqual($clientWidth)
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    shellShot($page, "stacker-{$width}-result");
    fwrite(STDERR, "stacker {$width}x{$height}: well ".json_encode($well)." scroll {$scrollWidth}/{$clientWidth}".PHP_EOL);

    // Positive control: the collector sees a throw and a failed answer on this very page.
    $page->evaluate('() => { setTimeout(() => { throw new Error("stacker positive control"); }); fetch("/stacker/nothing-here"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("stacker positive control")) && window.__errors.some((e) => e.startsWith("404 ") || e.startsWith("405 "))', 5_000);
})->with([
    'phone 375' => [375, 812],
    'desktop 1440' => [1440, 900],
]);

test('a player\'s ranked run is started, submitted after the played time and verified by the Node verifier', function () {
    $this->freezeTime();
    $forty = stackerFixture('forty-lines');
    config(['esports.blockfill.testing_seed' => $forty['seed']]);
    $user = User::factory()->create(['stacker_settings' => $forty['settings'] + ['keys' => StackerSettings::DEFAULT_KEYS]]);

    $page = stackerPage($user, 1440, 900);
    $page->locator('[data-test=start-ranked]')->click();
    BrowserWait::until($page, '() => window.__stacker.state().mode === "countdown" && window.__stacker.state().kind === "ranked" && document.querySelector("[data-test=countdown]") !== null', 5_000);
    expect($page->evaluate('(inputs) => window.__stacker.feed(inputs, { hold: true })', $forty['inputs']))->toBe('queued');

    // The countdown ends with the start call; the recorded run plays at once and waits before it is sent.
    BrowserWait::until($page, '() => window.__stacker.state().result?.status === "held"', 8_000);
    $run = StackerRun::query()->sole();
    expect($run->started_at)->not->toBeNull()->and($run->seed)->toBe($forty['seed']);

    $this->travel(17)->seconds();
    $page->evaluate('() => window.__stacker.release()');
    BrowserWait::until($page, '() => window.__stacker.state().result?.status === "verified"', 15_000);

    // After the verdict the finished game stays on screen until the player starts another one.
    $page->evaluate('() => new Promise((resolve) => setTimeout(resolve, 1500))');
    $after = $page->evaluate('() => window.__stacker.state()');
    fwrite(STDERR, 'stacker ranked trace: '.json_encode($after['trace']).PHP_EOL);
    expect([$after['ticks'], $after['hash'], $after['lines'], $after['mode']])->toBe([958, '6102773e', 40, 'result'])
        ->and($page->evaluate('() => document.querySelector("[data-test=time]").innerText'))->toBe('0:15.96')
        ->and($page->evaluate('() => getComputedStyle(document.querySelector("[data-test=overlay]")).display'))->toBe('none');

    expect($run->refresh())
        ->status->value->toBe('verified')
        ->ticks->toBe(958)
        ->state_hash->toBe('6102773e')
        ->and($page->evaluate('() => document.querySelector("[data-test=result-status]").innerText'))->toBe('Verified: the league replayed your run to the same time')
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    shellShot($page, 'stacker-1440-ranked-verified');
});
