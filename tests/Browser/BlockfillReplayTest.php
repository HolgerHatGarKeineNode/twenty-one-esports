<?php

use App\Enums\StackerRunStatus;
use App\Models\Admin;
use App\Models\StackerRun;
use App\Models\User;
use App\Support\Stacker\StackerRuns;
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
| The Blockfill replay viewer (plan "Blockfill", P5)
|--------------------------------------------------------------------------
|
| The player opens the replay of their verified reference run (forty-lines,
| 958 ticks, hash 6102773e): it plays at 4x, a click on a cube of the chain
| jumps to the tick that block was mined, comma/full stop and the buttons
| step one tick, and the end is the verified hash. Seeking to a tick in
| Chromium gives the hash playing reached at that tick. Measured at 375 and
| 1440 px in English and at 375 px in German: nothing overflows, every
| control is at least 44 px high. The admin review list is measured too.
| Console, uncaught errors and every answer >= 400 stay empty, with a
| positive control.
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

/**
 * The player's verified reference run with its replay; `$hints` holds it for review instead.
 *
 * @param  list<string>  $hints
 */
function replayFixtureRun(User $user, array $hints = []): StackerRun
{
    $forty = BlockfillOn::fixture('forty-lines');

    return StackerRun::factory()->for($user)->create([
        'seed' => $forty['seed'],
        'status' => $hints === [] ? StackerRunStatus::Verified : StackerRunStatus::Review,
        'ticks' => 958,
        'state_hash' => '6102773e',
        'replay' => $forty['replay'],
        'settings' => $forty['settings'],
        'submitted_at' => now()->subHour(),
        'verified_at' => $hints === [] ? now() : null,
        'week' => StackerRuns::weekOf(now()),
        'flags' => ['hints' => ['flags' => $hints, 'pps' => 6.45, 'maxPressesPerTick' => 1, 'timingCv' => 0.78, 'finesse' => ['perfect' => 39, 'of' => 69]]],
    ]);
}

function replayPage(User $user, string $locale, int $width, int $height, string $path): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    $page->goto(ComputeUrl::from($path));

    return $page;
}

/** Widths of the document, the boxes of the well and the chain, and every control smaller than 44 px or outside the viewport. */
const REPLAY_MEASURE = <<<'JS'
    () => {
        const box = (selector) => { const r = document.querySelector(selector).getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), width: Math.round(r.width), height: Math.round(r.height) }; };
        const small = [...document.querySelectorAll('[data-test=replay] button, [data-test=replay] a, [data-test=replay-chain]')]
            .filter((el) => el.offsetParent !== null)
            .map((el) => ({ test: el.dataset.test || el.textContent.trim().slice(0, 20), r: el.getBoundingClientRect() }))
            .filter(({ r }) => r.height < 44 || r.left < 0 || r.right > document.documentElement.clientWidth)
            .map(({ test, r }) => `${test} ${Math.round(r.width)}x${Math.round(r.height)} @${Math.round(r.left)}`);
        return {
            scroll: document.documentElement.scrollWidth,
            client: document.documentElement.clientWidth,
            well: box('[data-test=replay-well]'),
            chain: box('[data-test=replay-chain]'),
            small,
            nameLines: Math.round(document.querySelector('[data-test=replay-player] b').getBoundingClientRect().height / parseFloat(getComputedStyle(document.querySelector('[data-test=replay-player] b')).lineHeight)),
        };
    }
    JS;

test('the viewer plays, seeks along the chain and steps tick by tick, ends on the verified hash; measured, clean console', function (string $locale, int $width, int $height) {
    $user = User::factory()->create(['name' => 'Satoshi Stacker']);
    $run = replayFixtureRun($user);

    $page = replayPage($user, $locale, $width, $height, route('stacker.replay', $run, false));
    BrowserWait::until($page, '() => window.__stackerReplay !== undefined', 10_000);

    $start = $page->evaluate('() => window.__stackerReplay.state()');
    expect([$start['tick'], $start['total'], $start['lines'], $start['playing'], $start['ended']])->toBe([0, 958, 0, false, true])
        ->and(count($start['blockTicks']))->toBe(40)
        ->and($page->evaluate('() => document.querySelectorAll("[data-test=replay-cube]").length'))->toBe(40);

    // play at 4x: about 240 ticks a second
    $page->locator('[data-test=replay-speed-4]')->click();
    $page->locator('[data-test=replay-play]')->click();
    BrowserWait::until($page, '() => window.__stackerReplay.state().tick >= 300', 5_000);
    $page->locator('[data-test=replay-play]')->click();
    $played = $page->evaluate('() => window.__stackerReplay.state()');
    expect($played['playing'])->toBeFalse()->and($played['speed'])->toEqual(4);

    // Chromium: seeking away and back to the tick playing reached gives the same state
    $back = $page->evaluate('(tick) => { window.__stackerReplay.seek(0); return window.__stackerReplay.seek(tick); }', $played['tick']);
    expect($back['hash'])->toBe($played['hash'])->and($back['tick'])->toBe($played['tick']);

    // the chain is the seek bar: a click on the 20th cube's column jumps to the tick its block was mined
    $block20 = $start['blockTicks'][19];
    $track = $page->evaluate('() => { const r = document.querySelector("[data-test=replay-chain]").getBoundingClientRect(); return [r.width, r.height]; }');
    $page->locator('[data-test=replay-chain]')->click(['position' => ['x' => $block20 / 958 * $track[0], 'y' => $track[1] - 10]]);
    $seek = $page->evaluate('() => window.__stackerReplay.state()');
    expect($seek['tick'])->toBe($block20)->and($seek['lines'])->toBeGreaterThanOrEqual(20);

    // one tick on with the full stop, one back with the button, and the arrow key to the next block
    $page->locator('[data-test=replay-chain]')->press('.');
    expect($page->evaluate('() => window.__stackerReplay.state().tick'))->toBe($block20 + 1);
    $page->locator('[data-test=replay-back]')->click();
    $page->locator('[data-test=replay-back]')->click();
    expect($page->evaluate('() => window.__stackerReplay.state().tick'))->toBe($block20 - 1);
    $page->locator('[data-test=replay-chain]')->press('ArrowRight');
    expect($page->evaluate('() => window.__stackerReplay.state().tick'))->toBe($block20);

    // the end is the verified hash
    $page->locator('[data-test=replay-chain]')->press('End');
    $end = $page->evaluate('() => window.__stackerReplay.state()');
    expect([$end['tick'], $end['hash'], $end['lines']])->toBe([958, '6102773e', 40])
        ->and($page->evaluate('() => document.querySelector("[data-test=replay-tick]").innerText'))->toBe('Tick 958');

    // back to the middle for the picture
    $page->evaluate('() => window.__stackerReplay.seek(560)');
    $measure = $page->evaluate(REPLAY_MEASURE);
    fwrite(STDERR, "replay {$locale} {$width}: ".json_encode($measure).PHP_EOL);

    expect($measure['scroll'])->toBeLessThanOrEqual($measure['client'])
        ->and($measure['well']['width'] * 22)->toBe($measure['well']['height'] * 10)
        ->and($measure['well']['left'])->toBeGreaterThanOrEqual(0)
        ->and($measure['well']['right'])->toBeLessThanOrEqual($width)
        ->and($measure['chain']['right'])->toBeLessThanOrEqual($width)
        ->and($measure['small'])->toBe([])
        ->and($measure['nameLines'])->toBe(1)
        ->and($page->evaluate('() => document.querySelector("[data-test=replay-title]").innerText'))->toBe($locale === 'de' ? 'Replay von 0:15.966' : 'Replay of 0:15.966')
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    $page->evaluate('() => window.scrollTo(0, 0)');
    shellShot($page, "blockfill-replay-{$locale}-{$width}");
    $page->evaluate('() => document.querySelector("[data-test=replay-chain]").scrollIntoView({ block: "center" })');
    shellShot($page, "blockfill-replay-{$locale}-{$width}-chain");

    // positive control: the collector sees a throw and a failed answer
    $page->evaluate('() => { setTimeout(() => { throw new Error("replay positive control"); }); fetch("/blockfill/replays/nothing-here"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("replay positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
})->with([
    'en 375' => ['en', 375, 812],
    'en 1440' => ['en', 1440, 900],
    'de 375' => ['de', 375, 812],
]);

test('the admin review list shows a held run with its hints and its replay link, measured', function (int $width, int $height) {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $held = replayFixtureRun(User::factory()->create(['name' => 'Fast Fingers']), ['pps', 'finesse']);

    $page = replayPage($admin, 'en', $width, $height, route('admin.blockfill', [], false));
    BrowserWait::until($page, '() => document.querySelector("[data-test=blockfill-run]") !== null', 10_000);

    [$scroll, $client] = $page->evaluate(BrowserConsole::WIDTHS);
    $row = $page->evaluate('() => { const r = document.querySelector("[data-test=blockfill-run]").getBoundingClientRect(); return [Math.round(r.left), Math.round(r.right)]; }');
    expect($scroll)->toBeLessThanOrEqual($client)
        ->and($row[0])->toBeGreaterThanOrEqual(0)
        ->and($row[1])->toBeLessThanOrEqual($width)
        ->and($page->evaluate('() => document.querySelector("[data-test=blockfill-hints]").innerText'))->toContain('More than 5 pieces per second (6.45)')
        ->and($page->evaluate('() => document.querySelector("[data-test=blockfill-watch]").getAttribute("href")'))->toBe(route('stacker.replay', $held))
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    shellShot($page, "blockfill-admin-review-{$width}");

    // the admin opens the held run's replay: it plays like any other
    $page->goto(ComputeUrl::from(route('stacker.replay', $held, false)));
    BrowserWait::until($page, '() => window.__stackerReplay !== undefined', 10_000);
    $page->evaluate('() => window.__stackerReplay.seek(400)');
    [$heldScroll, $heldClient] = $page->evaluate(BrowserConsole::WIDTHS);
    $chip = $page->evaluate('() => { const r = document.querySelector("[data-test=replay-held]").getBoundingClientRect(); return [Math.round(r.right), document.querySelector("[data-test=replay-held]").scrollWidth <= document.querySelector("[data-test=replay-held]").clientWidth]; }');
    expect($heldScroll)->toBeLessThanOrEqual($heldClient)
        ->and($chip[0])->toBeLessThanOrEqual($width)
        ->and($chip[1])->toBeTrue()
        ->and($page->evaluate('() => document.querySelector("[data-test=replay-pieces]").innerText'))->toBe('103');
    expect($page->evaluate('() => document.querySelector("[data-test=replay-hints]").innerText'))->toContain('Every piece placed with the fewest presses (39 of 69)')
        ->and($page->evaluate('() => window.__errors'))->toBe([]);
    shellShot($page, "blockfill-replay-admin-{$width}");
})->with([
    'phone 375' => [375, 812],
    'desktop 1440' => [1440, 900],
]);
