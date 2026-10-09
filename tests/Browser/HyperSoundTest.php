<?php

use App\Models\Admin;
use App\Models\User;
use App\Support\Hyper\HyperEmotes;
use App\Support\Nostr\NostrKeys;
use App\Support\Settings\LeagueSettings;
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
| emotes takes the clips out of the emote panel. Every page carries BrowserConsole's collector (console errors,
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
            \Illuminate\Support\Facades\File::ensureDirectoryExists($dir);
            \Illuminate\Support\Facades\File::move(base_path("tests/Browser/Screenshots/hyper-start-{$w}.png"), "{$dir}/hyper-start-{$w}.png");
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
