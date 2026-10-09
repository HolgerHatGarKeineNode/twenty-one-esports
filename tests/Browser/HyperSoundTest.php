<?php

use App\Models\Admin;
use App\Models\User;
use App\Support\Hyper\HyperEmotes;
use App\Support\Nostr\NostrKeys;
use App\Support\Settings\LeagueSettings;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\HyperOn;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Hyperbitcoinization's sound settings in the browser (plan "Hyperbitcoinization", P6)
|--------------------------------------------------------------------------
|
| The viewer's switches and volume on the match page (resources/js/hyper/sounds.js): set, kept over a reload in
| `hb-settings`, a broken store read as the defaults without an error; the league-wide switch of soundboard
| emotes takes the clips out of the emote panel. With music on, the match plays the MIDI playlist
| (public/music/midi/manifest.json) after the first gesture and the music switch stops it. Every page carries BrowserConsole's collector (console errors,
| uncaught errors, answers >= 400), proved by a positive control.
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

    HyperOn::play();
});

/** The match page as `$user`, with `$stored` in `hb-settings` before the page's script runs (null leaves it alone). */
function hyperSoundPage(User $user, string $path, ?string $stored): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);

    if ($stored !== null) {
        // Only before the first load: a reload keeps what the page itself saved.
        $page->context()->addInitScript('if (!sessionStorage.getItem("seeded")) { sessionStorage.setItem("seeded", "1"); localStorage.setItem("hb-settings", '.json_encode($stored).'); }');
    }

    $page->context()->addInitScript(TestSigner::browserStub($user));
    $page->setViewportSize(1440, 900);
    $page->goto(ComputeUrl::from($path));
    BrowserWait::until($page, '() => document.body.dataset.ready === "1"', 15_000);

    return $page;
}

/** @return list<string> */
function hyperSoundErrors(Page $page): array
{
    return [...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)];
}

test('the viewer mutes the soundboard and sets the volume, a reload keeps both, a broken store is the defaults', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $match = HyperOn::versus($anna, $bert, bots: 1);
    $path = route('hyper.match', $match, false);
    $controls = '() => ({ board: document.querySelector("#board-btn").getAttribute("aria-pressed"), fx: document.querySelector("#sound-btn").getAttribute("aria-pressed"), music: document.querySelector("#music-btn").getAttribute("aria-pressed"), vol: document.querySelector("#vol").value })';

    // A store that holds garbage: the page starts with everything on at 80 %, no error.
    $page = hyperSoundPage($anna, $path, '{not json');

    expect($page->evaluate($controls))->toBe(['board' => 'true', 'fx' => 'true', 'music' => 'true', 'vol' => '80']);

    $page->evaluate('() => { document.querySelector("#board-btn").click(); const v = document.querySelector("#vol"); v.value = "35"; v.dispatchEvent(new Event("input", { bubbles: true })); }');
    $saved = json_decode((string) $page->evaluate('() => localStorage.getItem("hb-settings")'), true);

    expect($saved)->toMatchArray(['board' => false, 'fx' => true, 'vol' => 0.35]);

    $page->reload();
    BrowserWait::until($page, '() => document.body.dataset.ready === "1"', 15_000);

    expect($page->evaluate($controls))->toBe(['board' => 'false', 'fx' => 'true', 'music' => 'true', 'vol' => '35'])
        ->and($page->evaluate('() => document.querySelectorAll("#emote-clips button").length'))->toBeGreaterThan(100)
        ->and(hyperSoundErrors($page))->toBe([]);

    // Positive control: an uncaught error reaches the collector.
    $page->evaluate('() => { setTimeout(() => { throw new Error("hyper-sound-probe"); }, 0); }');
    BrowserWait::until($page, '() => (window.__errors ?? []).some((e) => String(e).includes("hyper-sound-probe"))', 5_000);
});

test('soundboard emotes muted league-wide: the emote panel offers stickers only, without the clip search', function () {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    config(['esports.board' => [NostrKeys::hexToNpub($admin->pubkey)]]);
    LeagueSettings::save($admin, [HyperEmotes::CLIPS_SETTING => 'off']);
    LeagueSettings::forget();
    [$anna, $bert] = User::factory()->count(2)->create();
    $match = HyperOn::versus($anna, $bert, bots: 1);

    $page = hyperSoundPage($anna, route('hyper.match', $match, false), null);
    $page->evaluate('() => document.querySelector("[data-test=hyper-emote-open]").click()');

    expect($page->evaluate('() => document.querySelectorAll("#emote-clips button").length'))->toBe(0)
        ->and($page->evaluate('() => document.querySelectorAll("#emote-stickers button").length'))->toBe(6)
        ->and($page->evaluate('() => document.querySelector("#emote-filter") === null'))->toBeTrue()
        ->and(hyperSoundErrors($page))->toBe([]);
});

test('the start page plays its theme with a music switch and a volume as on Blockfill, kept for the next visit', function () {
    $anna = User::factory()->create();
    $page = visit(BrowserLogin::url($anna))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize(390, 844);
    $page->goto(ComputeUrl::from(route('hyper.index', [], false)));
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Alpine !== undefined', 10_000);
    $state = '() => { const d = Alpine.$data(document.querySelector("[data-test=hyper-index-sound]")); return { on: d.on, playing: d.playing, volume: d.volume, gain: d.audio ? Math.round(d.audio.volume * 100) / 100 : null, paused: d.audio ? d.audio.paused : null, loop: d.audio ? d.audio.loop : null }; }';

    // On load where the browser lets it, else from the first tap anywhere but the controls.
    $page->locator('#hyper-h')->click();
    BrowserWait::until($page, '() => Alpine.$data(document.querySelector("[data-test=hyper-index-sound]")).playing', 5_000);
    expect($page->evaluate($state))->toMatchArray(['on' => true, 'playing' => true, 'volume' => 50, 'paused' => false, 'loop' => true]);

    // The volume: 80 % is louder than 50 %, the audio follows at once and the value is kept.
    $before = $page->evaluate($state)['gain'];
    $page->evaluate('() => { const r = document.querySelector("[data-test=hyper-index-volume]"); r.value = 80; r.dispatchEvent(new Event("input", { bubbles: true })); }');
    $louder = $page->evaluate($state);
    expect($louder['volume'])->toBe(80)->and($louder['gain'])->toBeGreaterThan($before);

    // The switch turns it off; the next visit stays silent after a tap, with the volume where it was.
    $page->locator('[data-test=hyper-index-music]')->click();
    BrowserWait::until($page, '() => !Alpine.$data(document.querySelector("[data-test=hyper-index-sound]")).playing', 3_000);
    expect($page->evaluate('() => JSON.parse(localStorage.getItem("hb-settings"))'))->toMatchArray(['music' => false, 'startVolume' => 80]);
    $page->reload();
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Alpine !== undefined', 10_000);
    $page->locator('#hyper-h')->click();
    $page->evaluate('() => new Promise((r) => setTimeout(r, 500))');

    if (is_string($dir = getenv('HYPER_SHOTS')) && $dir !== '') {
        foreach ([[390, 844], [1440, 900]] as [$w, $h]) {
            $page->setViewportSize($w, $h);
            $page->screenshot(false, "hyper-start-{$w}");
            File::ensureDirectoryExists($dir);
            File::move(base_path("tests/Browser/Screenshots/hyper-start-{$w}.png"), "{$dir}/hyper-start-{$w}.png");
        }
        $page->setViewportSize(390, 844);
    }

    $box = $page->evaluate('() => { const r = document.querySelector("[data-test=hyper-index-sound]").getBoundingClientRect(); return [Math.round(r.right), Math.round(r.height), document.documentElement.scrollWidth - document.documentElement.clientWidth]; }');

    expect($page->evaluate($state))->toMatchArray(['on' => false, 'playing' => false, 'volume' => 80])
        ->and($box[0])->toBeLessThanOrEqual(390)
        ->and($box[1])->toBeGreaterThanOrEqual(44)
        ->and($box[2])->toBeLessThanOrEqual(0)
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
});

test('with music on, the match plays the MIDI playlist after the first gesture with its credit line, and the music switch stops it', function (int $width, int $height) {
    [$anna, $bert] = User::factory()->count(2)->create();
    $match = HyperOn::versus($anna, $bert, bots: 1);
    $page = hyperSoundPage($anna, route('hyper.match', $match, false), null);
    $page->setViewportSize($width, $height);

    // music on by default, but nothing sounds before a gesture
    expect($page->evaluate('() => document.querySelector("#music-btn").getAttribute("aria-pressed")'))->toBe('true')
        ->and($page->evaluate('() => window.__midi === undefined || window.__midi.scheduled === 0'))->toBeTrue();

    // a key press that does nothing in the game is the gesture
    $page->locator('body')->press('Shift');
    BrowserWait::until($page, '() => window.__midi !== undefined && window.__midi.playing && window.__midi.scheduled > 20', 10_000);
    $track = $page->evaluate('() => window.__midi.track');
    expect($page->evaluate('() => window.__midi.tracks'))->toBe(10)
        ->and($track)->toBeIn(['Airport Attack', 'Chase!', 'Elegy for the Summer of 4096', 'Fun is Infinite at AGM', 'Neon Flames', 'Punch Your Way Through', 'The Journey Continues', 'Gods of Trance', 'Happy Sunset', 'Quantum 2']);

    // the credit line: under the tools, inside the viewport, over no other HUD element
    BrowserWait::until($page, '() => !document.querySelector("#now-playing").hidden', 3_000);
    $credit = $page->evaluate(<<<'JS'
        () => {
            const box = (el) => { const r = el.getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), top: Math.round(r.top), bottom: Math.round(r.bottom) }; };
            const line = document.querySelector('#now-playing');
            const hit = (a, b) => a.left < b.right && b.left < a.right && a.top < b.bottom && b.top < a.bottom;
            const mine = box(line);
            const covered = [...document.querySelectorAll('.hud > *, .hud .frame')].filter((el) => el !== line && !line.contains(el) && !el.contains(line) && el.offsetParent !== null).filter((el) => hit(box(el), mine)).map((el) => el.id || el.className);
            return { ...mine, text: line.innerText.trim(), href: document.querySelector('#now-playing-title').getAttribute('href'), covered };
        }
        JS);
    [$scrollWidth, $clientWidth] = $page->evaluate(BrowserConsole::WIDTHS);
    fwrite(STDERR, "hyper credit {$width}: ".json_encode($credit).PHP_EOL);
    expect($credit['text'])->toStartWith('Playing now: '.$track.' – ')
        ->and($credit['href'])->toStartWith('https://opengameart.org/content/')
        ->and($credit['left'])->toBeGreaterThanOrEqual(0)
        ->and($credit['right'])->toBeLessThanOrEqual($width)
        ->and($credit['covered'])->toBe([])
        ->and($scrollWidth)->toBeLessThanOrEqual($clientWidth);
    shellShot($page, "hyper-now-playing-{$width}");

    // the switch off: the playlist stops, nothing more is scheduled, the credit goes, the choice is kept
    $page->locator('#music-btn')->click();
    BrowserWait::until($page, '() => !window.__midi.playing && document.querySelector("#now-playing").hidden', 3_000);
    $stopped = $page->evaluate('() => window.__midi.scheduled');
    $page->evaluate('() => new Promise((r) => setTimeout(r, 1000))');
    expect($page->evaluate('() => window.__midi.scheduled'))->toBe($stopped)
        ->and(json_decode((string) $page->evaluate('() => localStorage.getItem("hb-settings")'), true))->toMatchArray(['music' => false]);

    // on again: it plays on
    $page->locator('#music-btn')->click();
    BrowserWait::until($page, '() => window.__midi.playing && window.__midi.scheduled > '.$stopped, 5_000);

    // a hidden page stops it
    $page->evaluate('() => { Object.defineProperty(document, "hidden", { configurable: true, get: () => true }); document.dispatchEvent(new Event("visibilitychange")); }');
    BrowserWait::until($page, '() => !window.__midi.playing', 3_000);

    expect(hyperSoundErrors($page))->toBe([]);

    $page->evaluate('() => { setTimeout(() => { throw new Error("hyper-midi-probe"); }, 0); }');
    BrowserWait::until($page, '() => (window.__errors ?? []).some((e) => String(e).includes("hyper-midi-probe"))', 5_000);
})->with([
    'phone 390' => [390, 844],
    'tablet 1024' => [1024, 768],
    'desktop 1440' => [1440, 900],
]);
