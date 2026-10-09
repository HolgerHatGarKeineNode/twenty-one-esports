<?php

use App\Models\User;
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
| Proof of Pong's background music in the browser (plan "Proof of Pong", P8)
|--------------------------------------------------------------------------
|
| A game against a bot plays Blockfill's MIDI playlist (resources/js/midi/player.js) from the first gesture, shows the
| track's credit line in the strip under the field (inside the window, over neither the field nor the HUD, nothing
| overflows), ducks the music while the end's snippet speaks, and the settings' music switch and music volume work and
| are kept in `pong-sound`. Console and answers stay clean, proved by a positive control.
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

/** A picture for the report, only when PONG_SHOTS names a directory. */
function pongMusicShot(Page $page, string $name): void
{
    $dir = getenv('PONG_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

test('the bot game plays the MIDI playlist from the first gesture with its credit line, ducks under a snippet, and the music switch and volume work', function (int $width, int $height) {
    $page = visit(BrowserLogin::url(User::factory()->create()))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript('try { localStorage.setItem("pong-settings", '.json_encode((string) json_encode(['speed' => 100, 'autoplay' => 3])).'); } catch (e) {}');
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('pong.bot', ['level' => 2, 'seed' => 7], false)));
    BrowserWait::until($page, '() => document.body.dataset.ready === "1"', 10_000);

    // Nothing sounds before a gesture.
    expect($page->evaluate('() => window.__midi === undefined || window.__midi.scheduled === 0'))->toBeTrue()
        ->and($page->evaluate('() => document.querySelector("[data-test=pong-now-playing]").hidden'))->toBeTrue();

    // A key that does nothing in the game is the gesture.
    $page->locator('body')->press('Shift');
    BrowserWait::until($page, '() => window.__midi !== undefined && window.__midi.playing && window.__midi.scheduled > 20', 10_000);
    $track = $page->evaluate('() => window.__midi.track');
    expect($page->evaluate('() => window.__midi.tracks'))->toBe(10)
        ->and($track)->toBeIn(['Airport Attack', 'Chase!', 'Elegy for the Summer of 4096', 'Fun is Infinite at AGM', 'Neon Flames', 'Punch Your Way Through', 'The Journey Continues', 'Gods of Trance', 'Happy Sunset', 'Quantum 2']);

    // The credit line: in the window, over neither the field nor the HUD, no overflow.
    BrowserWait::until($page, '() => !document.querySelector("[data-test=pong-now-playing]").hidden', 3_000);
    $credit = $page->evaluate(<<<'JS'
        () => {
            const box = (el) => { const r = el.getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), top: Math.round(r.top), bottom: Math.round(r.bottom) }; };
            const hit = (a, b) => a.left < b.right && b.left < a.right && a.top < b.bottom && b.top < a.bottom;
            const line = document.querySelector('[data-test=pong-now-playing]');
            const mine = box(line);
            const field = box(document.querySelector('[data-test=pong-field]'));
            const hud = box(document.querySelector('header.hud'));
            return { ...mine, text: line.innerText.trim(), href: document.querySelector('#now-playing-title').getAttribute('href'), overField: hit(mine, field), overHud: hit(mine, hud), clipped: line.scrollWidth > line.clientWidth + 1 && getComputedStyle(line).textOverflow !== 'ellipsis' };
        }
        JS);
    [$scrollWidth, $clientWidth] = $page->evaluate(BrowserConsole::WIDTHS);
    fwrite(STDERR, "pong credit {$width}: ".json_encode($credit).PHP_EOL);
    expect($credit['text'])->toStartWith('Playing now: '.$track.' – ')
        ->and($credit['href'])->toStartWith('https://opengameart.org/content/')
        ->and($credit['left'])->toBeGreaterThanOrEqual(0)
        ->and($credit['right'])->toBeLessThanOrEqual($width)
        ->and($credit['bottom'])->toBeLessThanOrEqual($height)
        ->and($credit['overField'])->toBeFalse()
        ->and($credit['overHud'])->toBeFalse()
        ->and($credit['clipped'])->toBeFalse()
        ->and($scrollWidth)->toBeLessThanOrEqual($clientWidth);

    // The game runs to its end; the end's snippet ducks the music, which comes back up after it.
    $page->locator('[data-test=pong-start-btn]')->click();
    pongMusicShot($page, "pong-music-{$width}-play");
    BrowserWait::until($page, '() => window.pongGame.state().phase === "over"', 60_000);
    BrowserWait::until($page, '() => window.pongGame.state().music.ducked === true', 5_000);
    BrowserWait::until($page, '() => window.pongGame.state().music.gain < 0.2', 3_000);
    BrowserWait::until($page, '() => window.pongGame.state().music.ducked === false && window.pongGame.state().music.gain > 0.35', 10_000);

    // The settings: the music volume lowers the bus and is kept; the switch stops the playlist and the credit goes.
    $page->locator('[data-test=pong-settings-btn]')->click();
    $panel = $page->evaluate('() => { const r = document.querySelector("[data-test=pong-settings]").getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), bottom: Math.round(r.bottom) }; }');
    expect($panel['left'])->toBeGreaterThanOrEqual(0)
        ->and($panel['right'])->toBeLessThanOrEqual($width)
        ->and($panel['bottom'])->toBeLessThanOrEqual($height)
        ->and($page->evaluate('() => document.querySelector("[data-test=pong-setting-music-volume]").value'))->toBe('100');
    pongMusicShot($page, "pong-music-{$width}-settings");
    $page->evaluate('() => { const r = document.querySelector("[data-test=pong-setting-music-volume]"); r.value = "30"; r.dispatchEvent(new Event("input", { bubbles: true })); }');
    BrowserWait::until($page, '() => Math.abs(window.pongGame.state().music.gain - 0.126) < 0.01', 5_000);
    expect(json_decode((string) $page->evaluate('() => localStorage.getItem("pong-sound")'), true))->toMatchArray(['musicVol' => 0.3, 'music' => true]);

    $page->locator('[data-test=pong-setting-music]')->click();
    BrowserWait::until($page, '() => !window.__midi.playing && document.querySelector("[data-test=pong-now-playing]").hidden', 3_000);
    $stopped = $page->evaluate('() => window.__midi.scheduled');
    $page->evaluate('() => new Promise((r) => setTimeout(r, 800))');
    expect($page->evaluate('() => window.__midi.scheduled'))->toBe($stopped)
        ->and($page->evaluate('() => window.pongGame.state().music.gain'))->toBeLessThan(0.02)
        ->and(json_decode((string) $page->evaluate('() => localStorage.getItem("pong-sound")'), true))->toMatchArray(['music' => false, 'musicVol' => 0.3]);

    // A reload keeps both; the music stays off after a gesture.
    $page->reload();
    BrowserWait::until($page, '() => document.body.dataset.ready === "1"', 10_000);
    $page->locator('body')->press('Shift');
    $page->evaluate('() => new Promise((r) => setTimeout(r, 600))');
    expect($page->evaluate('() => window.__midi === undefined || !window.__midi.playing'))->toBeTrue()
        ->and($page->evaluate('() => [document.querySelector("[data-test=pong-setting-music]").checked, document.querySelector("[data-test=pong-setting-music-volume]").value]'))->toBe([false, '30']);

    $errors = [...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)];
    expect($errors)->toBe([]);

    // Positive control: an uncaught error reaches the collector.
    $page->evaluate('() => { setTimeout(() => { throw new Error("pong-music-probe"); }, 0); }');
    BrowserWait::until($page, '() => (window.__errors ?? []).some((e) => String(e).includes("pong-music-probe"))', 5_000);
})->with([
    'phone upright 390x844' => [390, 844],
    'desktop 1440x900' => [1440, 900],
]);
