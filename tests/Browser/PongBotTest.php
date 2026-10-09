<?php

use App\Models\User;
use App\Support\Pong\PongCast;
use App\Support\Pong\PongGame;
use App\Support\Pong\PongRules;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\PongOn;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| A game of Proof of Pong against a bot in the browser (plan "Proof of Pong", P1)
|--------------------------------------------------------------------------
|
| A whole game to 21 on the full-screen page, on a phone held upright (390x844) and a desktop (1440x900). The
| player's paddle is played by a bot (`pong-settings` autoplay) at 100 times the speed, so the game is the one the
| server's PongGame::bots() plays for the same seed and levels: the page must end with exactly that score. The field
| lies fully in the window without scrolling or overflow, keeps its size when only the height changes (a phone's
| address bar), and the console and the answers stay clean, proved by a positive control. Headless Chromium has no
| WebGL, so this measures the 2D fallback; the three.js arena is measured in tests/Browser/PongArenaTest.php.
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

/**
 * @param  array<string, mixed>  $settings  the page's saved settings (pong-settings)
 */
function pongPage(User $user, string $path, int $width, int $height, array $settings): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript('try { localStorage.setItem("pong-settings", '.json_encode((string) json_encode($settings)).'); } catch (e) {}');
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($path));
    BrowserWait::until($page, '() => document.body.dataset.ready === "1"', 10_000);

    return $page;
}

/** A picture for the report, only when PONG_SHOTS names a directory. */
function pongShot(Page $page, string $name): void
{
    $dir = getenv('PONG_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/** Where the field lies, how far the page could scroll, and whether it overflows. */
const PONG_FIT = <<<'JS'
() => {
    const r = document.querySelector('[data-test=pong-field]').getBoundingClientRect();
    return {
        field: { top: Math.round(r.top), left: Math.round(r.left), right: Math.round(r.right), bottom: Math.round(r.bottom), w: Math.round(r.width), h: Math.round(r.height) },
        width: innerWidth, height: innerHeight,
        scrollY: document.documentElement.scrollHeight - innerHeight,
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    };
}
JS;

test('a bot game plays to its end with the server\'s score, the field fits the window, and the console stays clean', function (int $width, int $height, int $seed, int $auto, int $level) {
    $expected = PongGame::bots($seed, [$auto, $level]);
    expect($expected['winner'])->not->toBeNull()
        ->and(array_values(array_filter(array_map(fn (int $rally): ?string => (new PongRules)->eventOf($seed, $rally), range(1, 21)))))->toEqualCanonicalizing(PongRules::EVENTS);

    $page = pongPage(User::factory()->create(), route('pong.bot', ['level' => $level, 'seed' => $seed], false), $width, $height, ['speed' => 100, 'autoplay' => $auto]);

    // Before the start: the field fits, upright on the phone, lying on the desktop.
    $before = $page->evaluate(PONG_FIT);
    expect($page->evaluate('() => window.pongGame.state()'))->toMatchArray(['phase' => 'ready', 'portrait' => $width < $height, 'renderer' => '2d'])
        ->and($before['field']['top'])->toBeGreaterThanOrEqual(56)
        ->and($before['field']['left'])->toBeGreaterThanOrEqual(0)
        ->and($before['field']['right'])->toBeLessThanOrEqual($width)
        ->and($before['field']['bottom'])->toBeLessThanOrEqual($height)
        ->and($before['scrollY'])->toBeLessThanOrEqual(0)
        ->and($before['overflow'])->toBe(0)
        // The field's long side follows the window's: 16:9 lying, 9:16 upright.
        ->and($width < $height ? $before['field']['h'] > $before['field']['w'] : $before['field']['w'] > $before['field']['h'])->toBeTrue()
        ->and($page->evaluate('() => document.querySelector("[data-test=pong-bot-name]").textContent'))->toBe(PongCast::bot(null, $level)['name']);

    pongShot($page, "pong-{$width}-start");
    $page->locator('[data-test=pong-start-btn]')->click();
    BrowserWait::until($page, '() => window.pongGame.state().phase === "play" && window.pongGame.state().ticks > 400', 20_000);
    pongShot($page, "pong-{$width}-play");
    // The first block's nine meme events are announced on the way.
    BrowserWait::until($page, '() => window.pongGame.state().rally >= 21 || window.pongGame.state().phase === "over"', 60_000);
    BrowserWait::until($page, '() => window.pongGame.state().phase === "over"', 60_000);

    $state = $page->evaluate('() => window.pongGame.state()');
    $end = $page->evaluate(PONG_FIT);
    pongShot($page, "pong-{$width}-end");

    expect($state['score'])->toBe($expected['score'])
        ->and($state['rally'])->toBe($expected['rallies'])
        ->and($state['winner'])->toBe($expected['winner'])
        ->and($page->evaluate('() => document.querySelector("[data-test=pong-end-score]").textContent'))->toBe($expected['score'][0].' : '.$expected['score'][1])
        ->and($page->evaluate('() => [document.querySelector("[data-test=pong-score-me]").textContent, document.querySelector("[data-test=pong-score-bot]").textContent]'))->toBe(array_map('strval', $expected['score']))
        ->and($page->evaluate('() => document.querySelector("[data-test=pong-end]").checkVisibility()'))->toBeTrue()
        ->and($page->evaluate('() => document.body.dataset.result'))->toBe($expected['winner'] === 0 ? 'win' : 'loss')
        ->and($end['field'])->toBe($before['field'])
        ->and($end['scrollY'])->toBeLessThanOrEqual(0)
        ->and($end['overflow'])->toBe(0);

    // Only the height changes (a phone's address bar or keyboard): the field keeps its size and place.
    $page->setViewportSize($width, $height - 90);
    usleep(300_000);
    expect($page->evaluate(PONG_FIT)['field'])->toBe($before['field']);
    $page->setViewportSize($width, $height);

    // The console and the answers stayed clean ...
    $errors = [...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)];
    expect($errors)->toBe([]);

    // ... and the collector sees a thrown error and a broken image (positive control).
    $page->evaluate('() => { setTimeout(() => { throw new Error("pong positive control"); }); const img = new Image(); img.src = "/pong-positive-control-missing.png"; document.body.append(img); }');
    BrowserWait::until($page, '() => (window.__errors || []).some((e) => e.includes("pong positive control"))', 5_000);
    BrowserWait::until($page, '() => performance.getEntries().some((e) => e.name.includes("pong-positive-control-missing") && e.responseStatus === 404)', 5_000);
    expect($page->evaluate(BrowserConsole::BAD_RESPONSES))->toHaveCount(1);
})->with([
    'phone upright 390x844' => [390, 844, 2, 3, 2],
    'desktop 1440x900' => [1440, 900, 7, 2, 3],
]);

test('the lobby shows the five bots and, opened, the 25 figures without overflow or cut labels, and its form leads into the game', function (int $width, int $height) {
    $page = visit(BrowserLogin::url(User::factory()->create()))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('pong.index', absolute: false)));
    BrowserWait::until($page, '() => document.readyState === "complete" && !!document.querySelector("[data-test=pong-play-form]")', 10_000);
    pongShot($page, "pong-{$width}-lobby");

    // The bots are a row of portraits (P9), each named by its picture; the line under the row names the picked one.
    $bots = array_column(PongCast::bots(), 'id');
    $labels = $page->evaluate('(ids) => ids.map((id) => { const el = document.querySelector(`[data-test=pong-bot-${id}]`); const r = el.getBoundingClientRect(); return { name: el.querySelector("img").alt, size: Math.round(Math.min(r.width, r.height)) }; })', $bots);
    $detail = '() => [...document.querySelectorAll("[data-test=pong-bot-detail]")].filter((el) => el.checkVisibility()).map((el) => ({ text: el.innerText.trim(), cut: [...el.querySelectorAll("span, b")].some((s) => s.scrollWidth > s.clientWidth + 1) }))';
    // The roster opens over the page on a click on the figure's bar.
    $page->locator('[data-test=pong-figure-open]')->click();
    $faces = $page->evaluate('() => [...document.querySelectorAll("[data-test=pong-picker] .pp-face")].map((el) => { const r = el.getBoundingClientRect(); return Math.round(Math.min(r.width, r.height)); })');

    expect(array_column($labels, 'name'))->toBe(array_column(PongCast::bots(), 'name'))
        ->and(min(array_column($labels, 'size')))->toBeGreaterThanOrEqual(44)
        ->and($shown = $page->evaluate($detail))->toHaveCount(1)
        ->and($shown[0]['cut'])->toBeFalse()
        ->and($shown[0]['text'])->toStartWith(PongCast::bots()[0]['name'])
        ->and(count($faces))->toBe(25)
        // Every portrait is a target of at least 44 px.
        ->and(min($faces))->toBeGreaterThanOrEqual(44);

    [$scroll, $client] = $page->evaluate(BrowserConsole::WIDTHS);
    expect($scroll)->toBeLessThanOrEqual($client);

    // Saylor and the Goldbug picked: the form's address is that game with that figure, and the line names the Goldbug.
    $page->locator('[data-test=pong-figure-saylor]')->click();
    $page->locator('[data-test=pong-bot-schiff]')->click();
    // Alpine shows the picked bot's line on its next flush.
    BrowserWait::until($page, '() => [...document.querySelectorAll("[data-test=pong-bot-detail]")].some((el) => el.checkVisibility() && el.innerText.startsWith('.json_encode(PongCast::bot('schiff')['name']).'))', 5_000);
    expect($page->evaluate('() => { const f = document.querySelector("[data-test=pong-play-form]"); return f.action + "?" + new URLSearchParams(new FormData(f)).toString(); }'))
        ->toEndWith('/proof-of-pong/bot?figure=saylor&bot=schiff')
        ->and($page->evaluate('() => document.querySelector("[data-test=pong-picked-name]").textContent'))->toBe('Michael Saylor')
        ->and($page->evaluate($detail)[0]['text'])->toStartWith(PongCast::bot('schiff')['name'])
        ->and([...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)])->toBe([]);
})->with([
    'phone 390x844' => [390, 844],
    'desktop 1440x900' => [1440, 900],
]);
