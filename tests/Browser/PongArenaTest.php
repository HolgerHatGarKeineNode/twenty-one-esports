<?php

use App\Models\Admin;
use App\Models\User;
use App\Support\Pong\PongCast;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\BrowserWebGL;
use Tests\Support\PongOn;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Proof of Pong's three.js arena (plan "Proof of Pong", P3)
|--------------------------------------------------------------------------
|
| This file runs with software WebGL (SwiftShader, tests/Support/BrowserWebGL.php), so the page draws the three.js
| arena and not its 2D fallback (tests/Browser/PongBotTest.php keeps measuring that one). Measured as an admin in
| German, as players see it: the arena is active, the field and the HUD lie in the window without scrolling or
| overflow at five sizes (upright and lying phones, desktops), nothing moves when only the height changes, the
| console and the answers stay clean (positive control). The frame times are recorded and written next to the
| pictures (PONG_SHOTS); SwiftShader renders on the CPU, so the 60 fps goal is judged on reported numbers, never
| asserted here. Every figure of the cast has its portrait and pose, and a goal shows the scorer's pose for at
| most 2.5 s without covering the middle of the field.
|
| The five meme events of P7 run in a real game (`eventEvery` 1: rally 1 of the seed is the event): the tax block
| and the border wall drawn where the physics has them, the ball hidden under Few understand's fog, Proof of Work's
| paddles growing with their hits, the Arbeitsamt's stamp with the waiting number and Markus Turm's line.
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

    PongOn::play();
});

afterAll(fn () => BrowserWebGL::off());

function pongAdmin(): User
{
    $user = User::factory()->create(['locale' => 'de']);
    Admin::query()->create(['pubkey' => $user->pubkey]);

    return $user;
}

/**
 * @param  array<string, mixed>  $settings
 */
function arenaPage(string $path, int $width, int $height, array $settings = [], string $ready = 'document.body.dataset.ready === "1"'): Page
{
    BrowserWebGL::on();
    $page = visit(BrowserLogin::url(pongAdmin()))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript('try { localStorage.setItem("pong-settings", '.json_encode((string) json_encode($settings)).'); localStorage.setItem("pong-sound", JSON.stringify({ vol: 0 })); } catch (e) {}');
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($path));
    BrowserWait::until($page, '() => '.$ready, 15_000);

    return $page;
}

function arenaShot(Page $page, string $name): void
{
    $dir = getenv('PONG_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/** The field, the HUD, the start card and the page's scroll and overflow, in CSS pixels. */
const ARENA_FIT = <<<'JS'
() => {
    const box = (el) => { const r = el.getBoundingClientRect(); return { top: Math.round(r.top), left: Math.round(r.left), right: Math.round(r.right), bottom: Math.round(r.bottom), w: Math.round(r.width), h: Math.round(r.height) }; };
    const hud = document.querySelector('.hud');
    const card = [...document.querySelectorAll('.overlay')].find((o) => !o.hidden)?.querySelector('.card');
    const hudParts = [...hud.querySelectorAll('.leave, .face, .score, .mid')].filter((el) => el.checkVisibility()).map(box);
    return {
        field: box(document.querySelector('[data-test=pong-field]')),
        hud: box(hud),
        hudParts,
        hudOverflow: hud.scrollWidth - hud.clientWidth,
        card: card ? box(card) : null,
        cardScroll: card ? card.scrollHeight - card.clientHeight : 0,
        canvas: box(document.getElementById('arena3d') || document.getElementById('arena')),
        width: innerWidth, height: innerHeight,
        scrollY: document.documentElement.scrollHeight - innerHeight,
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    };
}
JS;

test('the three.js arena runs at every size: field and HUD in the window, steady on a height change, console clean', function (int $width, int $height) {
    $page = arenaPage('/proof-of-pong/bot?bot=lagarde&seed=7', $width, $height, ['autoplay' => 2]);

    $state = $page->evaluate('() => window.pongGame.state()');
    $fit = $page->evaluate(ARENA_FIT);

    expect($state['renderer'])->toBe('webgl')
        ->and($state['portrait'])->toBe($width < $height)
        ->and($page->evaluate('() => document.body.classList.contains("gl") && !!document.getElementById("arena3d")'))->toBeTrue()
        // The field below the HUD and inside the window, its long side along the window's.
        ->and($fit['field']['top'])->toBeGreaterThanOrEqual($fit['hud']['bottom'])
        ->and($fit['field']['left'])->toBeGreaterThanOrEqual(0)
        ->and($fit['field']['right'])->toBeLessThanOrEqual($width)
        ->and($fit['field']['bottom'])->toBeLessThanOrEqual($height)
        ->and($width < $height ? $fit['field']['h'] > $fit['field']['w'] : $fit['field']['w'] > $fit['field']['h'])->toBeTrue()
        // The HUD's parts inside the window and inside the HUD, nothing pushed out.
        ->and($fit['hudOverflow'])->toBeLessThanOrEqual(0)
        ->and(collect($fit['hudParts'])->every(fn (array $r): bool => $r['left'] >= 0 && $r['right'] <= $width && $r['bottom'] <= $fit['hud']['bottom']))->toBeTrue()
        // The start card with the figure picker lies inside the field.
        ->and($fit['card'])->not->toBeNull()
        ->and($fit['card']['top'])->toBeGreaterThanOrEqual($fit['field']['top'])
        ->and($fit['card']['bottom'])->toBeLessThanOrEqual($fit['field']['bottom'])
        // ... without scrolling inside: the start button in view (a lying phone scrolls the portrait grid itself).
        ->and($fit['cardScroll'])->toBeLessThanOrEqual(0)
        ->and($fit['scrollY'])->toBeLessThanOrEqual(0)
        ->and($fit['overflow'])->toBe(0)
        // The canvas covers the window.
        ->and([$fit['canvas']['w'], $fit['canvas']['h']])->toBe([$width, $height]);

    arenaShot($page, "arena-{$width}x{$height}-start");

    $page->locator('[data-test=pong-start-btn]')->click();
    BrowserWait::until($page, '() => window.pongGame.state().phase === "play" && window.pongGame.state().ticks > 240', 20_000);
    $play = $page->evaluate(ARENA_FIT);
    arenaShot($page, "arena-{$width}x{$height}-play");

    // Only the height changes (a phone's address bar or keyboard): the field and the canvas stay as they were.
    $page->setViewportSize($width, $height - 90);
    usleep(300_000);
    $shorter = $page->evaluate(ARENA_FIT);
    $page->setViewportSize($width, $height);

    fwrite(STDERR, "pong arena {$width}x{$height}: ".json_encode(['field' => $fit['field'], 'hud' => $fit['hud']['h'], 'card' => $fit['card'], 'cardScroll' => $fit['cardScroll'], 'scrollY' => $fit['scrollY'], 'overflow' => $fit['overflow'], 'shorter' => $shorter['field'] === $fit['field'], 'quality' => $state['quality']]).PHP_EOL);

    expect($play['field'])->toBe($fit['field'])
        ->and($shorter['field'])->toBe($fit['field'])
        ->and($shorter['canvas'])->toBe($fit['canvas'])
        ->and($play['overflow'])->toBe(0);

    $errors = [...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)];
    expect($errors)->toBe([]);

    // Positive control: a thrown error and a broken image are seen.
    $page->evaluate('() => { setTimeout(() => { throw new Error("arena positive control"); }); const img = new Image(); img.src = "/arena-positive-control-missing.png"; document.body.append(img); }');
    BrowserWait::until($page, '() => (window.__errors || []).some((e) => e.includes("arena positive control"))', 5_000);
    BrowserWait::until($page, '() => performance.getEntries().some((e) => e.name.includes("arena-positive-control-missing") && e.responseStatus === 404)', 5_000);
    expect($page->evaluate(BrowserConsole::BAD_RESPONSES))->toHaveCount(1);
})->with([
    'phone upright 390x844' => [390, 844],
    'small phone upright 360x740' => [360, 740],
    'phone lying 844x390' => [844, 390],
    'desktop 1440x900' => [1440, 900],
    'desktop 1920x1080' => [1920, 1080],
]);

test('frame times of the arena at 1440x900 are recorded for each quality tier', function (string $tier) {
    $page = arenaPage('/proof-of-pong/bot?bot=schiff&seed=11', 1440, 900, ['autoplay' => 3, 'quality' => $tier]);
    $page->locator('[data-test=pong-start-btn]')->click();
    BrowserWait::until($page, '() => window.pongGame.state().phase === "play"', 10_000);
    $page->evaluate('() => { window.pongFrames.length = 0; }');
    // A number of frames, not a stretch of time: SwiftShader under a full parallel run drew 10 in the 5 s this used to
    // wait (measured 2026-10-09), which said more about the machine's load than about the arena.
    BrowserWait::until($page, '() => window.pongFrames.length >= 30', 30_000);

    $frames = $page->evaluate('() => window.pongFrames.slice()');
    $state = $page->evaluate('() => window.pongGame.state()');
    sort($frames);
    $at = fn (float $q): float => round($frames[(int) floor((count($frames) - 1) * $q)], 1);
    $report = ['tier' => $tier, 'frames' => count($frames), 'p50' => $at(0.5), 'p95' => $at(0.95), 'max' => round(end($frames), 1), 'draws' => $state['draws'], 'gpu' => $state['gpu']];
    fwrite(STDERR, 'pong frame times 1440x900: '.json_encode($report).PHP_EOL);

    $dir = getenv('PONG_SHOTS');
    if (is_string($dir) && $dir !== '') {
        File::ensureDirectoryExists($dir);
        File::put($dir."/frame-times-1440x900-{$tier}.json", (string) json_encode($report, JSON_PRETTY_PRINT));
    }

    expect($state['renderer'])->toBe('webgl')
        ->and($state['quality'])->toBe($tier)
        ->and($report['frames'])->toBeGreaterThan(10)
        ->and($report['p50'])->toBeGreaterThan(0.0);
})->with(['high', 'medium', 'low']);

test('every figure has its portrait and pose, the picker changes the paddle, and a goal celebrates for at most 2.5 s', function () {
    $page = arenaPage('/proof-of-pong/bot?bot=nocoiner&seed=3', 1440, 900, ['autoplay' => 1]);

    // All 25 figures and 5 bots: portrait and victory pose load.
    $ids = [...PongCast::playerIds(), ...PongCast::botIds()];
    $broken = $page->evaluate('async (ids) => { const load = (src) => new Promise((ok) => { const i = new Image(); i.onload = () => ok(i.naturalWidth > 0 ? null : src); i.onerror = () => ok(src); i.src = src; }); return (await Promise.all(ids.flatMap((id) => [load(`/pong/art/por-${id}.webp`), load(`/pong/art/win-${id}.webp`)]))).filter(Boolean); }', $ids);
    expect(count($ids))->toBe(30)->and($broken)->toBe([]);

    // The picker on the start card plays the player's paddle.
    $page->locator('[data-test=pong-figure-saylor]')->click();
    expect($page->evaluate('() => window.pongGame.state().figures'))->toBe(['saylor', 'nocoiner'])
        ->and($page->evaluate('() => document.querySelector("[data-test=pong-face-me]").getAttribute("src")'))->toBe('/pong/art/por-saylor.webp')
        ->and($page->evaluate('() => document.querySelector("[data-test=pong-picked-name]").textContent'))->toBe('Michael Saylor');

    $page->locator('[data-test=pong-start-btn]')->click();
    BrowserWait::until($page, '() => window.pongGame.state().phase === "play"', 10_000);

    // A goal of the player: the pose slides in on the player's side, never past the middle, and goes in time.
    $page->evaluate('() => window.pongShow.goal(0, 1)');
    usleep(400_000);
    $cheer = $page->evaluate('() => { const c = document.getElementById("cheer"); const r = c.getBoundingClientRect(); const f = document.querySelector("[data-test=pong-field]").getBoundingClientRect(); return { shown: c.checkVisibility(), src: c.querySelector("img").getAttribute("src"), right: r.right - f.left, half: f.width / 2 }; }');
    expect($cheer['shown'])->toBeTrue()
        ->and($cheer['src'])->toBe('/pong/art/win-saylor.webp')
        ->and($cheer['right'])->toBeLessThanOrEqual($cheer['half']);
    usleep(2_200_000);
    expect($page->evaluate('() => document.getElementById("cheer").checkVisibility()'))->toBeFalse();

    // A click takes it away at once.
    $page->evaluate('() => window.pongShow.goal(1, 1)');
    usleep(200_000);
    $page->evaluate('() => document.getElementById("cheer").dispatchEvent(new PointerEvent("pointerdown", { bubbles: true }))');
    expect($page->evaluate('() => document.getElementById("cheer").checkVisibility()'))->toBeFalse()
        ->and([...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)])->toBe([]);
});

test('the four meme events take the field over, and reduced motion stills the show', function () {
    $page = arenaPage('/proof-of-pong/bot?bot=shitcoiner&seed=5', 390, 844, ['autoplay' => 2, 'motion' => false]);
    $page->locator('[data-test=pong-start-btn]')->click();
    BrowserWait::until($page, '() => window.pongGame.state().phase === "play"', 10_000);

    foreach (['halving' => 'Halving', 'brrr' => 'Brrr', 'pizza' => 'Pizza Day', 'difficulty' => 'Difficulty Adjustment'] as $event => $name) {
        $page->evaluate("() => window.pongShow.announce('{$event}', 2000, 1)");
        usleep(150_000);
        $banner = $page->evaluate('() => { const b = document.querySelector("[data-test=pong-banner]"); const i = b.querySelector("img"); return { shown: b.checkVisibility(), event: b.dataset.event, name: b.querySelector("b").textContent, icon: i.getAttribute("src"), loaded: i.complete && i.naturalWidth > 0, animation: getComputedStyle(i).animationName }; }');
        expect($banner)->toMatchArray(['shown' => true, 'event' => $event, 'icon' => "/pong/art/ev-{$event}.webp", 'animation' => 'none'])
            ->and($banner['name'])->toBe(__($name, [], 'de'));
        BrowserWait::until($page, '() => document.querySelector("[data-test=pong-banner] img").naturalWidth > 0', 5_000);
    }

    expect($page->evaluate('() => document.body.classList.contains("still")'))->toBeTrue()
        ->and([...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)])->toBe([]);
});

test('pictures of the arena for the report: lobby, match, meme event, goal, end', function (int $width, int $height) {
    $dir = getenv('PONG_SHOTS');
    if (! is_string($dir) || $dir === '') {
        $this->markTestSkipped('PONG_SHOTS names no directory.');
    }

    $page = arenaPage('/proof-of-pong', $width, $height, [], 'document.readyState === "complete" && !!document.querySelector("[data-test=pong-play-form]")');
    usleep(500_000);
    arenaShot($page, "shot-{$width}-lobby");

    $page->close();
    $page = arenaPage('/proof-of-pong/bot?bot=lagarde&figure=turm&seed=9', $width, $height, ['autoplay' => 3, 'quality' => 'high']);
    arenaShot($page, "shot-{$width}-select");
    $page->locator('[data-test=pong-start-btn]')->click();
    BrowserWait::until($page, '() => window.pongGame.state().phase === "play" && window.pongGame.state().ticks > 300', 20_000);
    arenaShot($page, "shot-{$width}-match");
    $page->evaluate('() => window.pongShow.announce("brrr", 4000, 1)');
    usleep(600_000);
    arenaShot($page, "shot-{$width}-event-brrr");
    usleep(3_000_000);
    $page->evaluate('() => window.pongShow.announce("halving", 4000, 2)');
    BrowserWait::until($page, '() => document.querySelector("#banner img").naturalWidth > 0', 5_000);
    usleep(300_000);
    arenaShot($page, "shot-{$width}-event-halving");
    usleep(2_500_000);
    $page->evaluate('() => window.pongShow.goal(0, 2)');
    // Pest's screenshot fast-forwards every animation to its end, where the celebration has faded out: it is shown
    // without its animation for the picture.
    usleep(500_000);
    $page->evaluate('() => { document.getElementById("cheer").style.animation = "none"; }');
    arenaShot($page, "shot-{$width}-goal");

    // The events in a real game: rally 21 of seed 29 is a Pizza Day (two pizza balls), of seed 4 a Difficulty
    // Adjustment (the paddles shrink in ratchet steps).
    $page->close();
    $page = arenaPage('/proof-of-pong/bot?bot=shitcoiner&figure=lutze&seed=29', $width, $height, ['autoplay' => 2, 'speed' => 6, 'quality' => 'high']);
    $page->locator('[data-test=pong-start-btn]')->click();
    BrowserWait::until($page, '() => window.pongGame.state().rally === 21 && window.pongGame.state().phase === "play" && window.pongGame.state().ticks > 0', 120_000);
    usleep(400_000);
    arenaShot($page, "shot-{$width}-event-pizza");

    $page->close();
    $page = arenaPage('/proof-of-pong/bot?bot=schiff&figure=oma&seed=4', $width, $height, ['autoplay' => 2, 'speed' => 6, 'quality' => 'high']);
    $page->locator('[data-test=pong-start-btn]')->click();
    BrowserWait::until($page, '() => window.pongGame.state().rally === 21 && window.pongGame.state().phase === "announce"', 120_000);
    usleep(250_000);
    arenaShot($page, "shot-{$width}-event-difficulty");

    $page->close();
    $page = arenaPage('/proof-of-pong/bot?bot=lagarde&figure=saylor&seed=9', $width, $height, ['autoplay' => 4, 'speed' => 100]);
    $page->locator('[data-test=pong-start-btn]')->click();
    BrowserWait::until($page, '() => window.pongGame.state().phase === "over"', 90_000);
    usleep(800_000);
    arenaShot($page, "shot-{$width}-end");

    expect([...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)])->toBe([]);
})->with([
    'phone 390x844' => [390, 844],
    'desktop 1440x900' => [1440, 900],
]);

/** Rally 1 of each seed is that P7 event when every rally is one (PongRules::eventOf(), `eventEvery` 1). */
const PONG_P7_SEEDS = ['tax' => 7, 'controls' => 6, 'few' => 1, 'pow' => 15, 'arbeitsamt' => 5];

/** The German name of each P7 event on its banner. */
const PONG_P7_NAMES = ['tax' => 'Taxation is Theft', 'controls' => 'Capital Controls', 'few' => 'Few understand', 'pow' => 'Proof of Work', 'arbeitsamt' => 'Job Centre – Please wait'];

/**
 * A game against a bot whose rally 1 is P7 event `$event`, played to the moment that shows it: its banner during the
 * announcement, then in play the obstacle drawn, the ball hidden under the fog, a paddle grown by its hits, or the
 * queue's stamp. Returns the page there.
 */
function pongP7Moment(string $event, int $width, int $height): Page
{
    $figure = $event === 'arbeitsamt' ? 'turm' : 'saylor';
    $page = arenaPage('/proof-of-pong/bot?bot=schiff&figure='.$figure.'&seed='.PONG_P7_SEEDS[$event], $width, $height, ['autoplay' => 3, 'eventEvery' => 1, 'speed' => 2]);
    $page->locator('[data-test=pong-start-btn]')->click();
    BrowserWait::until($page, '() => window.pongGame.state().phase === "announce"', 10_000);
    $banner = $page->evaluate('() => { const b = document.querySelector("[data-test=pong-banner]"); return { shown: b.checkVisibility(), event: b.dataset.event, name: b.querySelector("b").textContent, icon: b.querySelector("img").getAttribute("src") }; }');
    expect($banner)->toBe(['shown' => true, 'event' => $event, 'name' => __(PONG_P7_NAMES[$event], [], 'de'), 'icon' => "/pong/art/ev-{$event}.webp"]);
    BrowserWait::until($page, '() => document.querySelector("[data-test=pong-banner] img").naturalWidth > 0', 5_000);

    $moment = match ($event) {
        'tax', 'controls' => '() => { const s = window.pongGame.state(); return s.phase === "play" && s.ticks > 60 && s.drawn.obstacles > 0; }',
        'few' => '() => { const s = window.pongGame.state(); return s.phase === "play" && s.drawn.fog && s.drawn.balls[0] === false; }',
        'pow' => '() => { const s = window.pongGame.state(); return s.phase === "play" && Math.max(...s.halves) >= 12000; }',
        'arbeitsamt' => '() => window.pongGame.state().waiting && document.querySelector("[data-test=pong-queue]").checkVisibility()',
    };
    BrowserWait::until($page, $moment, 30_000);

    return $page;
}

test('the five P7 meme events take the field over in a real game', function () {
    foreach (array_keys(PONG_P7_SEEDS) as $event) {
        $page = pongP7Moment($event, 1440, 900);
        $state = $page->evaluate('() => window.pongGame.state()');

        expect($state['renderer'])->toBe('webgl')->and($state['event'])->toBe($event);

        match ($event) {
            'tax' => expect($state['drawn']['obstacles'])->toBe(1),
            'controls' => expect($state['drawn']['obstacles'])->toBeGreaterThanOrEqual(1),
            'few' => BrowserWait::until($page, '() => { const s = window.pongGame.state(); return s.phase !== "play" || s.drawn.balls[0] === true; }', 10_000),
            'pow' => expect(max($state['halves']))->toBe(9000 + 1500 * max($state['sideHits'])),
            'arbeitsamt' => expect($page->evaluate('() => [...document.querySelectorAll("[data-test=pong-queue] b, [data-test=pong-queue] span, [data-test=pong-queue] small")].map((e) => e.textContent)'))
                ->toBe([__('Stamped', [], 'de'), __('Your waiting number: :n', ['n' => 21], 'de'), __('Markus Turm knows his way around here.', [], 'de')]),
        };

        expect([...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)])->toBe([], $event);
        $page->close();
    }
});

test('measurements of the five P7 events at five sizes for the report: in the window, console clean', function (int $width, int $height) {
    $dir = getenv('PONG_SHOTS');
    if (! is_string($dir) || $dir === '') {
        $this->markTestSkipped('PONG_SHOTS names no directory.');
    }

    foreach (array_keys(PONG_P7_SEEDS) as $event) {
        $page = pongP7Moment($event, $width, $height);
        $page->evaluate('() => { const q = document.getElementById("queue"); if (q) q.style.animation = "none"; }');
        $fit = $page->evaluate(<<<'JS'
            () => {
                const box = (el) => { const r = el.getBoundingClientRect(); return { top: Math.round(r.top), left: Math.round(r.left), right: Math.round(r.right), bottom: Math.round(r.bottom) }; };
                const queue = document.querySelector('[data-test=pong-queue]');
                return {
                    field: box(document.querySelector('[data-test=pong-field]')),
                    queue: queue.checkVisibility() ? box(queue) : null,
                    queueOverflow: queue.checkVisibility() ? queue.scrollWidth - queue.clientWidth : 0,
                    scrollY: document.documentElement.scrollHeight - innerHeight,
                    overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
                    renderer: window.pongGame.state().renderer,
                    drawn: window.pongGame.state().drawn,
                };
            }
            JS);
        arenaShot($page, "p7-{$width}x{$height}-{$event}");
        fwrite(STDERR, "pong p7 {$width}x{$height} {$event}: ".json_encode($fit).PHP_EOL);

        expect($fit['renderer'])->toBe('webgl')
            ->and($fit['scrollY'])->toBeLessThanOrEqual(0)
            ->and($fit['overflow'])->toBe(0);
        if ($fit['queue'] !== null) {
            expect($fit['queue']['left'])->toBeGreaterThanOrEqual($fit['field']['left'])
                ->and($fit['queue']['right'])->toBeLessThanOrEqual($fit['field']['right'])
                ->and($fit['queue']['top'])->toBeGreaterThanOrEqual($fit['field']['top'])
                ->and($fit['queue']['bottom'])->toBeLessThanOrEqual($fit['field']['bottom'])
                ->and($fit['queueOverflow'])->toBeLessThanOrEqual(0);
        }
        expect([...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)])->toBe([], $event);

        if ($event === 'arbeitsamt') {
            // Positive control: a thrown error and a broken image are seen.
            $page->evaluate('() => { setTimeout(() => { throw new Error("p7 positive control"); }); const img = new Image(); img.src = "/p7-positive-control-missing.png"; document.body.append(img); }');
            BrowserWait::until($page, '() => (window.__errors || []).some((e) => e.includes("p7 positive control"))', 5_000);
            BrowserWait::until($page, '() => performance.getEntries().some((e) => e.name.includes("p7-positive-control-missing") && e.responseStatus === 404)', 5_000);
        }
        $page->close();
    }
})->with([
    'phone upright 390x844' => [390, 844],
    'small phone upright 360x740' => [360, 740],
    'phone lying 844x390' => [844, 390],
    'desktop 1440x900' => [1440, 900],
    'desktop 1920x1080' => [1920, 1080],
]);
