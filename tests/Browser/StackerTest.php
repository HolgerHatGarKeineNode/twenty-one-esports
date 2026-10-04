<?php

use App\Games\Blockfill;
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
|    result screen at 0:15.966. Measured at 375 and 1440 px.
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
    // The reference bot plays faster than a human: without this its runs would be held for review (P5 hints).
    config(['esports.blockfill.hints' => ['pps' => 7]]);
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

    expect($page->evaluate('() => document.querySelector("[data-test=result-time]").innerText'))->toBe('0:15.966')
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

    // The result is in view when the run ends, also below the well on a phone.
    BrowserWait::until($page, '() => { const top = document.querySelector("[data-test=result]").getBoundingClientRect().top; return top >= 0 && top < innerHeight / 2; }', 3_000);

    // The chain counter stays on one line, at 40/40 and at 24/40.
    $counter = '() => { const c = document.querySelector("[data-test=chain-count]"); return [c.getBoundingClientRect().height, parseFloat(getComputedStyle(c).lineHeight), c.innerText]; }';
    [$counterHeight, $lineHeight, $counterText] = $page->evaluate($counter);
    $page->evaluate('() => { Alpine.$data(document.querySelector("[data-test=stacker]")).lines = 24; }');
    $page->evaluate('() => new Promise((resolve) => requestAnimationFrame(() => resolve(true)))');
    [$counterHeight24, , $counterText24] = $page->evaluate($counter);
    expect([$counterText, $counterText24])->toBe(['40 / 40', '24 / 40'])
        ->and($counterHeight)->toBeLessThan($lineHeight * 1.5)
        ->and($counterHeight24)->toBeLessThan($lineHeight * 1.5);

    // R starts the next run: during its countdown the well is the new, empty game and Next its own queue.
    // The countdown is held open while the checks look, not left to race the 3-second wall clock (a slow round trip
    // on a loaded host saw the run already playing); the hook then ends it, and the run starts.
    $page->evaluate('() => window.__stacker.countdown(60_000)');
    $page->locator('body')->press('KeyR');
    BrowserWait::until($page, '() => window.__stacker.state().mode === "countdown"', 3_000);
    $countdown = $page->evaluate('() => window.__stacker.state()');
    $nextImages = '() => [...document.querySelectorAll("[data-next]")].map((c) => c.toDataURL())';
    $nextInCountdown = $page->evaluate($nextImages);
    shellShot($page, "stacker-{$width}-countdown");
    $page->evaluate('() => window.__stacker.countdown(0)');
    BrowserWait::until($page, '() => window.__stacker.state().mode === "playing"', 5_000);
    expect($countdown['boardEmpty'])->toBeTrue()
        ->and($countdown['drawn'])->toBe(['seed' => $countdown['seed'], 'tick' => 0])
        ->and($page->evaluate($nextImages))->toBe($nextInCountdown)
        ->and($page->evaluate('() => window.__errors'))->toBe([]);

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
    // P5: the reference run is a program at 6.45 pieces per second; under a bound of 7 it carries no cheat hint and counts at once
    config(['esports.blockfill.hints' => ['pps' => 7]]);
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

test('a player\'s practice stays in the browser and leads back to a ranked run; a slower ranked run is verified and says it is not faster than the week\'s best', function (string $locale, int $width, int $height) {
    $this->freezeTime();
    $forty = stackerFixture('forty-lines');
    config(['esports.blockfill.testing_seed' => $forty['seed']]);
    $user = User::factory()->create(['stacker_settings' => $forty['settings'] + ['keys' => StackerSettings::DEFAULT_KEYS]]);
    // This week's best: 0:15.000, faster than the reference run's 0:15.966.
    StackerRun::factory()->for($user)->verified(900)->create();

    $page = stackerPage($user, $width, $height);
    $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    $page->goto(ComputeUrl::from(route('stacker.play', [], false)));
    BrowserWait::until($page, '() => window.__stacker !== undefined', 10_000);
    $runRequests = '() => performance.getEntriesByType("resource").filter((e) => e.name.includes("/stacker/runs")).length';

    // Practice: the quiet button, played in the browser alone.
    $page->locator('[data-test=start-practice]')->click();
    BrowserWait::until($page, '() => window.__stacker.state().kind === "practice" && window.__stacker.state().mode === "countdown"', 3_000);
    $page->evaluate('([inputs, seed, settings]) => window.__stacker.feed(inputs, { seed, settings })', [$forty['inputs'], $forty['seed'], $forty['settings']]);
    BrowserWait::until($page, '() => window.__stacker.state().result?.status === "practice"', 5_000);
    expect($page->evaluate($runRequests))->toBe(0)
        ->and(StackerRun::query()->count())->toBe(1)
        ->and($page->evaluate('() => getComputedStyle(document.querySelector("[data-test=result-ranked]")).display'))->not->toBe('none');

    // From the practice result straight back to a ranked run.
    $page->locator('[data-test=result-ranked]')->click();
    BrowserWait::until($page, '() => window.__stacker.state().mode === "countdown" && window.__stacker.state().kind === "ranked" && document.querySelector("[data-test=countdown]") !== null', 5_000);
    expect($page->evaluate('(inputs) => window.__stacker.feed(inputs, { hold: true })', $forty['inputs']))->toBe('queued');
    BrowserWait::until($page, '() => window.__stacker.state().result?.status === "held"', 8_000);
    $this->travel(17)->seconds();
    $page->evaluate('() => window.__stacker.release()');
    BrowserWait::until($page, '() => window.__stacker.state().result?.status === "verified"', 15_000);

    $run = StackerRun::query()->where('ticks', 958)->sole();
    $status = $page->evaluate('() => document.querySelector("[data-test=result-status]").innerText');
    fwrite(STDERR, "stacker slower ranked {$locale} {$width}: {$status}".PHP_EOL);
    expect($run->status->value)->toBe('verified')
        ->and($status)->toBe(__('Verified, not faster than your best :best', ['best' => '0:15.000'], $locale))
        // the best is named once, in the status line
        ->and($page->evaluate('() => document.querySelector("[data-test=result-best]").innerText.trim()'))->toBe('')
        ->and($page->evaluate('() => document.querySelector("[data-test=result-status]").classList.contains("text-win")'))->toBeTrue()
        ->and($page->evaluate(BrowserConsole::WIDTHS)[0])->toBeLessThanOrEqual($page->evaluate(BrowserConsole::WIDTHS)[1])
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    shellShot($page, "stacker-{$width}-{$locale}-slower-verified");
})->with([
    'en 1440' => ['en', 1440, 900],
    'de 375' => ['de', 375, 812],
    'en 375' => ['en', 375, 812],
    'de 1440' => ['de', 1440, 900],
]);

test('a guest practises with the real Practice button and real keys: the well moves and the clock runs, clean console, in English and German', function (string $locale) {
    $page = visit(BrowserLogin::LANDING)->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize(1440, 900);
    $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    $page->goto(ComputeUrl::from(route('stacker.play', [], false)));
    BrowserWait::until($page, '() => window.__stacker !== undefined', 10_000);

    $wellImage = '() => document.querySelector("[data-test=well]").toDataURL()';
    $idle = $page->evaluate($wellImage);
    $page->locator('[data-test=start-practice]')->click();
    BrowserWait::until($page, '() => window.__stacker.state().mode === "playing" && window.__stacker.state().ticks > 2', 6_000);
    // The image and the frame it shows, read in one go. The first piece may look like the idle well's (two random seeds
    // can deal the same piece), so "the well was drawn after the start" is read from the frame's tick, not from a picture.
    $startedFrame = $page->evaluate('() => ({ image: document.querySelector("[data-test=well]").toDataURL(), drawn: window.__stacker.state().drawn, ticks: window.__stacker.state().ticks })');
    $started = $startedFrame['image'];
    $startedAt = $startedFrame['ticks'];

    // Only a key moves a piece sideways or locks three pieces this early: gravity takes a second a row.
    $x = $page->evaluate('() => window.__stacker.state().piece.x');
    $page->locator('body')->press('ArrowLeft');
    BrowserWait::until($page, "() => window.__stacker.state().piece.x === {$x} - 1", 2_000);

    // Real key presses through the browser (no hook): moves, a turn and three hard drops.
    foreach (['ArrowLeft', 'Space', 'ArrowRight', 'KeyX', 'Space', 'ArrowRight', 'ArrowRight', 'ArrowRight', 'Space'] as $key) {
        $page->locator('body')->press($key);
        $page->evaluate('() => new Promise((resolve) => setTimeout(resolve, 80))');
    }
    BrowserWait::until($page, '() => window.__stacker.state().pieces >= 3', 3_000);
    BrowserWait::until($page, '() => window.__stacker.state().ticks > 60', 5_000);
    $state = $page->evaluate('() => window.__stacker.state()');
    $final = $page->evaluate($wellImage);
    fwrite(STDERR, "stacker real keys {$locale}: started at tick {$startedAt} (".md5($started)."), now tick {$state['ticks']} (".md5($final).'), hash '.$state['hash'].PHP_EOL);

    expect([$state['kind'], $state['mode'], $state['pieces']])->toBe(['practice', 'playing', 3])
        ->and((float) $page->evaluate('() => document.querySelector("[data-test=hud] [x-ref=pps]")?.innerText ?? "0"'))->toBeGreaterThan(0.0)
        ->and($startedFrame['drawn']['tick'])->toBeGreaterThan(2)
        ->and($final)->not->toBe($idle)
        // the well was drawn after the keys were played
        ->and($state['drawn']['tick'])->toBeGreaterThan(60)
        ->and($page->evaluate('() => document.querySelector("[data-test=time]").innerText'))->not->toBe('0:00.00')
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    shellShot($page, "stacker-1440-{$locale}-playing");
    fwrite(STDERR, "stacker real keys {$locale}: ticks {$state['ticks']}, time ".$page->evaluate('() => document.querySelector("[data-test=time]").innerText').PHP_EOL);
})->with(['en', 'de']);

test('leaving the tab during a ranked run stops it; the next ranked run abandons it on the server', function () {
    $this->freezeTime();
    $page = stackerPage(User::factory()->create(), 1440, 900);

    $page->locator('[data-test=start-ranked]')->click();
    BrowserWait::until($page, '() => window.__stacker.state().mode === "playing" && window.__stacker.state().kind === "ranked"', 8_000);
    $run = StackerRun::query()->sole();
    expect($run->started_at)->not->toBeNull();

    $page->evaluate('() => { Object.defineProperty(document, "hidden", { configurable: true, get: () => true }); document.dispatchEvent(new Event("visibilitychange")); }');
    BrowserWait::until($page, '() => window.__stacker.state().result?.status === "aborted"', 3_000);
    expect($page->evaluate('() => document.querySelector("[data-test=result-status]").innerText'))->toBe('Run stopped: you left the tab')
        ->and($run->refresh()->status->value)->toBe('issued')
        ->and($run->submitted_at)->toBeNull();

    // Back on the tab, the next ranked run replaces the stopped one: abandoned, never scored.
    $page->evaluate('() => { Object.defineProperty(document, "hidden", { configurable: true, get: () => false }); document.dispatchEvent(new Event("visibilitychange")); }');
    $this->travel(3)->seconds();
    $page->locator('[data-test=play-again]')->click();
    BrowserWait::until($page, '() => performance.getEntriesByType("resource").filter((e) => e.name.endsWith("/stacker/runs")).length === 2', 5_000);

    fwrite(STDERR, 'stacker visibility network failures: '.json_encode($page->evaluate('() => window.__stackerNetwork ?? []')).' trace '.json_encode(array_column($page->evaluate('() => window.__stacker.state().trace'), 'event')).PHP_EOL);
    expect($run->refresh())->status->value->toBe('abandoned')->reason->toBe('replaced')
        ->and(StackerRun::query()->count())->toBe(2)
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
});

test('on a phone every touch button is in reach above the tab bar, and ranked runs ask for a keyboard', function (int $width, int $height) {
    $page = visit(BrowserLogin::LANDING)->on()->mobile()->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('stacker.play', [], false)));
    BrowserWait::until($page, '() => window.__stacker !== undefined', 10_000);

    expect($page->evaluate('() => matchMedia("(pointer: coarse)").matches'))->toBeTrue()
        ->and($page->evaluate('() => getComputedStyle(document.querySelector("[data-test=start-ranked]")).display'))->toBe('none')
        ->and($page->evaluate('() => getComputedStyle(document.querySelector("[data-test=ranked-needs-keyboard]")).display'))->toBe('block')
        // one note only: no "log in for ranked runs" next to "ranked runs need a keyboard"
        ->and($page->evaluate('() => getComputedStyle(document.querySelector("[data-test=guest-login-note]")).display'))->toBe('none');

    $page->locator('[data-test=start-practice]')->click();
    BrowserWait::until($page, '() => window.__stacker.state().mode === "playing"', 6_000);

    // the centre of every button hits that very button (nothing on top, nothing off screen); the panel is fixed, so
    // wherever the start has scrolled the page to (it brings the well below the sticky header and above the panel)
    $hits = $page->evaluate('() => [...document.querySelectorAll("[data-test^=touch-]")].map((b) => { const r = b.getBoundingClientRect(); return [b.dataset.test, document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2)?.closest("[data-test^=touch-]")?.dataset.test ?? null, Math.round(r.bottom)]; })');
    expect(count($hits))->toBe(8)
        // and the well is whole in view, between the header and the panel
        ->and($page->evaluate(STACKER_WELL_IN_VIEW))->toBeTrue();
    foreach ($hits as [$button, $hit, $bottom]) {
        expect($hit)->toBe($button, "{$button} at {$width}")->and($bottom)->toBeLessThanOrEqual($height);
    }

    // a tap works the game: hard drop locks a piece
    $page->locator('[data-test=touch-hard]')->tap();
    BrowserWait::until($page, '() => window.__stacker.state().pieces >= 1', 2_000);
    expect($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    shellShot($page, "stacker-{$width}-touch-playing");
})->with([
    'phone 375' => [375, 812],
    'phone 390' => [390, 844],
]);

test('a ranked run the league turns away as busy says it was not saved, never that it arrived', function () {
    $this->freezeTime();
    $forty = stackerFixture('forty-lines');
    config(['esports.blockfill.testing_seed' => $forty['seed']]);
    // P5: the reference run is a program at 6.45 pieces per second; under a bound of 7 it carries no cheat hint and counts at once
    config(['esports.blockfill.hints' => ['pps' => 7]]);
    $user = User::factory()->create(['stacker_settings' => $forty['settings'] + ['keys' => StackerSettings::DEFAULT_KEYS]]);

    $page = stackerPage($user, 1440, 900);
    $page->locator('[data-test=start-ranked]')->click();
    BrowserWait::until($page, '() => window.__stacker.state().mode === "countdown" && window.__stacker.state().kind === "ranked" && document.querySelector("[data-test=countdown]") !== null', 5_000);
    $page->evaluate('(inputs) => window.__stacker.feed(inputs, { hold: true })', $forty['inputs']);
    BrowserWait::until($page, '() => window.__stacker.state().result?.status === "held"', 8_000);

    // every slot taken: the submission is answered 503
    config(['esports.blockfill.replay_inflight_max' => 0]);
    $this->travel(17)->seconds();
    $page->evaluate('() => window.__stacker.release()');
    BrowserWait::until($page, '() => window.__stacker.state().result?.status === "busy"', 10_000);

    $text = $page->evaluate('() => document.querySelector("[data-test=result-status]").innerText');
    $errors = $page->evaluate('() => window.__errors');
    expect($text)->toBe('Not saved — the league is busy. Play the run again in a moment.')
        ->and($text)->not->toContain('Received')
        ->and(StackerRun::query()->sole())->status->value->toBe('issued')->replay->toBeNull()
        // the only failed answer is the 503 itself
        ->and(count($errors))->toBe(1)
        ->and($errors[0])->toStartWith('503 ')->toContain('/stacker/runs/')
        // no "your first time" under a run that was not saved, and the status in the warning tone
        ->and($page->evaluate('() => document.querySelector("[data-test=result-best]").innerText.trim()'))->toBe('')
        ->and($page->evaluate('() => document.querySelector("[data-test=result-status]").classList.contains("text-btc")'))->toBeTrue();
});

/** Whether the well lies inside the viewport: below the sticky header, and above the touch panel when that is shown. */
const STACKER_WELL_IN_VIEW = '() => { const w = document.querySelector("[data-test=well]").getBoundingClientRect(); const panel = document.querySelector("[data-test=touch]"); const floor = panel && getComputedStyle(panel).display !== "none" ? panel.getBoundingClientRect().top : innerHeight; const ceiling = document.querySelector("body > header").getBoundingClientRect().bottom; return w.top >= ceiling && w.bottom <= floor; }';

test('the next run starts with the well in view, after the result had scrolled the page down', function (string $device, int $width, int $height, ?int $countdownMs) {
    $page = $device === 'touch' ? visit(BrowserLogin::LANDING)->on()->mobile()->page() : visit(BrowserLogin::LANDING)->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('stacker.play', [], false)));
    BrowserWait::until($page, '() => window.__stacker !== undefined', 10_000);

    $forty = stackerFixture('forty-lines');
    $page->evaluate('([inputs, seed, settings]) => window.__stacker.feed(inputs, { seed, settings })', [$forty['inputs'], $forty['seed'], $forty['settings']]);
    // shown (a hidden element reports top 0: that would pass before the result is even on screen) and scrolled up
    BrowserWait::until($page, '() => { const r = document.querySelector("[data-test=result]"); const top = r.getBoundingClientRect().top; return getComputedStyle(r).display !== "none" && top >= 0 && top < innerHeight / 2; }', 3_000);
    $scrolled = $page->evaluate('() => ({ inView: ('.STACKER_WELL_IN_VIEW.')(), scrollY: Math.round(scrollY), result: document.querySelector("[data-test=result]").getBoundingClientRect().toJSON(), well: document.querySelector("[data-test=well]").getBoundingClientRect().toJSON(), mode: window.__stacker.state().mode })');
    expect($scrolled['inView'])->toBeFalse(json_encode($scrolled));

    // The countdown's length is pinned, not left to the wall clock: 0 is the order in which it ran out before the
    // checks below looked (a slow round trip on a loaded host), and the checks must hold in that order too.
    if ($countdownMs !== null) {
        $page->evaluate('(ms) => window.__stacker.countdown(ms)', $countdownMs);
    }

    // the touch player taps Play again, the keyboard player presses R
    if ($device === 'touch') {
        $page->locator('[data-test=play-again]')->tap();
    } else {
        $page->locator('body')->press('KeyR');
    }
    // the next run has begun: its countdown, or already the run once the countdown is over
    BrowserWait::until($page, '() => { const s = window.__stacker.state(); return s.result === null && ["countdown", "playing"].includes(s.mode); }', 3_000);
    BrowserWait::until($page, STACKER_WELL_IN_VIEW, 3_000);
    shellShot($page, "stacker-{$width}-restart");

    expect($page->evaluate('() => window.__errors'))->toBe([]);
})->with([
    'touch 375' => ['touch', 375, 812, null],
    'keyboard 1023' => ['keyboard', 1023, 800, null],
    'touch 375, countdown over before the checks' => ['touch', 375, 812, 0],
]);

test('on a small phone (360x640) the whole well stays above the touch panel', function () {
    $page = visit(BrowserLogin::LANDING)->on()->mobile()->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize(360, 640);
    $page->goto(ComputeUrl::from(route('stacker.play', [], false)));
    BrowserWait::until($page, '() => window.__stacker !== undefined', 10_000);

    $page->locator('[data-test=start-practice]')->tap();
    BrowserWait::until($page, '() => window.__stacker.state().mode === "playing"', 6_000);
    BrowserWait::until($page, STACKER_WELL_IN_VIEW, 3_000);
    $rects = $page->evaluate('() => ({ well: document.querySelector("[data-test=well]").getBoundingClientRect().toJSON(), panel: document.querySelector("[data-test=touch]").getBoundingClientRect().toJSON() })');
    fwrite(STDERR, 'stacker 360x640: well '.round($rects['well']['top']).'..'.round($rects['well']['bottom']).', panel from '.round($rects['panel']['top']).PHP_EOL);
    shellShot($page, 'stacker-360-playing');

    expect($rects['well']['bottom'])->toBeLessThanOrEqual($rects['panel']['top'])
        ->and($rects['well']['top'])->toBeGreaterThanOrEqual(0)
        ->and($page->evaluate('() => window.__errors'))->toBe([]);
});

test('a week on its own rules: the page shows them as chips, plays them (60 blocks, the level climbing every 5) and ends at the 60th block, clean console', function (string $locale, int $width, int $height) {
    leagueWeeksApproved(Blockfill::SLUG, ['difficulty' => 't60e5g1s1c9'], before: 0, after: 0);
    $this->artisan('blockfill:weeks')->assertSuccessful();
    $page = stackerPage(null, $width, $height);
    if ($locale === 'de') {
        $page->goto(ComputeUrl::from(route('locale.switch', 'de', false)));
        $page->goto(ComputeUrl::from(route('stacker.play', [], false)));
        BrowserWait::until($page, '() => window.__stacker !== undefined', 10_000);
    }

    $chips = $page->evaluate('() => [...document.querySelectorAll("[data-test=stacker-rule]")].map((el) => el.innerText)');
    $chipsFit = $page->evaluate('() => [...document.querySelectorAll("[data-test=stacker-rule]")].map((el) => { const r = el.getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth && el.scrollWidth <= el.clientWidth + 1; })');
    $idle = $page->evaluate('() => ({ goal: document.querySelector("[data-test=goal]").innerText, level: document.querySelector("[data-test=level]").checkVisibility(), cubes: document.querySelectorAll("[data-test=chain] [role=progressbar] i").length })');
    shellShot($page, "stacker-rules-{$locale}-{$width}-idle");

    // The person-paced 60-block run of the fixtures, fed as practice: it plays on the week's rules.
    $sixty = stackerFixture('sixty-lines-rules');
    $fed = $page->evaluate('([inputs, seed, settings]) => window.__stacker.feed(inputs, { seed, settings })', [$sixty['inputs'], $sixty['seed'], $sixty['settings']]);
    BrowserWait::until($page, '() => window.__stacker.state().result?.status === "practice"', 5_000);
    [$scrollWidth, $clientWidth] = $page->evaluate(BrowserConsole::WIDTHS);
    $done = $page->evaluate('() => ({ lines: document.querySelector("[data-test=lines]").innerText, level: Number(document.querySelector("[data-test=level]").innerText), head: document.querySelector("[data-test=result] span").innerText })');
    shellShot($page, "stacker-rules-{$locale}-{$width}-result");

    expect($chips)->toBe($locale === 'de' ? ['60 Blöcke', 'Level-up alle 5 Blöcke', 'Wird schneller: 1 Reihe/s → 11 Reihen/s'] : ['60 blocks', 'Level up every 5 blocks', 'Speeds up 1 row/s → 11 rows/s'])
        ->and($chipsFit)->toBe([true, true, true])
        ->and($idle)->toBe(['goal' => '60', 'level' => true, 'cubes' => 60])
        ->and($fed)->toBe($sixty['expected'])
        ->and($done['lines'])->toBe('61')
        ->and($done['level'])->toBe(12)
        ->and($done['head'])->toBe($locale === 'de' ? '60 Blöcke geschürft in' : '60 blocks mined in')
        ->and($scrollWidth)->toBeLessThanOrEqual($clientWidth)
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    // Positive control: the collector sees a throw on this very page.
    $page->evaluate('() => { setTimeout(() => { throw new Error("stacker rules positive control"); }); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("stacker rules positive control"))', 5_000);
})->with([
    'en 1440' => ['en', 1440, 900],
    'en 375' => ['en', 375, 812],
    'de 375' => ['de', 375, 812],
]);
