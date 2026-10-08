<?php

use App\Models\Admin;
use App\Models\User;
use App\Support\GameChat\GameChannels;
use App\Support\Hyper\HyperGame;
use App\Support\Hyper\HyperMatches;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\HyperOn;
use Tests\Support\TestSigner;
use Tests\Support\WaitForPort;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| A live Hyperbitcoinization match in the browser (plan "Hyperbitcoinization", P2b)
|--------------------------------------------------------------------------
|
| Two players at one table over Reverb, each in their own context: the 51% attack ends the match and both
| pages show the end screen (the winner's and the loser's); an emote of one rises over their portrait on
| the other's page; the table chat goes over the in-memory relay (tests/Support/MiniRelay.php) with a
| reaction back. Every page carries BrowserConsole's collector (console errors, uncaught errors, answers
| >= 400), proved by a positive control. Cinematics are off (`hb-settings`): the 3D scenes need WebGL and
| take seconds; they are the prototype's, unchanged.
|
| Two more tests run only on demand (they take minutes): HYPER_PLAY=1 plays a match against three bots
| through the page to round 5, HYPER_SHOTS=<dir> measures the pages at 390/1440/1920 in German and English
| as an admin and writes screenshots and numbers to <dir>.
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

/**
 * @param  array<string, mixed>  $settings  the page's saved settings (hb-settings)
 */
function hyperPage(?User $user, string $path, int $width = 1440, int $height = 900, array $settings = ['scenes' => false, 'music' => false, 'fx' => false, 'board' => false, 'speed' => 20], ?string $locale = null): Page
{
    $page = visit($user ? BrowserLogin::url($user) : BrowserLogin::LANDING)->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript('try { localStorage.setItem("hb-settings", '.json_encode((string) json_encode($settings)).'); } catch (e) {}');

    if ($user) {
        $page->context()->addInitScript(TestSigner::browserStub($user));
    }

    if ($locale !== null) {
        $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    }

    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($path));
    // The match page says when its table is drawn and its channel subscribed; any other page when it loaded.
    BrowserWait::until($page, '() => document.querySelector("[data-test=hyper-match]") ? document.body.dataset.ready === "1" && document.body.dataset.live === "1" : document.readyState === "complete"', 15_000);

    return $page;
}

/**
 * @return list<string>
 */
function hyperErrors(Page $page): array
{
    return [...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)];
}

function hyperIdle(Page $page, int $timeout = 15_000): void
{
    BrowserWait::until($page, '() => { const s = window.hyperGame.state(); return s.idle && !s.running && s.queued === 0; }', $timeout);
}

/** A click on a territory's pin (an SVG group: d3 listens there). */
function hyperTap(Page $page, string $id): void
{
    $page->evaluate('() => document.querySelector("#pin-'.$id.'").dispatchEvent(new MouseEvent("click", { bubbles: true }))');
}

/** A picture for the report, only when HYPER_SHOTS names a directory. */
function hyperShot(Page $page, string $name): void
{
    $dir = getenv('HYPER_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/**
 * @return array{0: InvokedProcess, 1: string}
 */
function hyperRelay(): array
{
    $seed = (string) tempnam(sys_get_temp_dir(), 'hyper-seed');
    file_put_contents($seed, '[]');
    $port = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $relay = Process::path(base_path())->start(['php', 'tests/Support/mini-relay.php', (string) $port, $seed]);
    WaitForPort::open('127.0.0.1', $port);
    config(['esports.chat.relays' => ['ws://127.0.0.1:'.$port], 'esports.profile_relays' => []]);

    return [$relay, $seed];
}

test('the 51% attack ends a live match on both pages, an emote reaches the other player, and the console stays clean', function () {
    expect(config('broadcasting.default'))->toBe('reverb', 'Run this through scripts/test-browser.sh, which starts Reverb.');

    [$anna, $bert] = User::factory()->count(2)->create();
    $match = HyperOn::endgame($anna, $bert);
    $path = route('hyper.match', $match, false);
    $winner = hyperPage($anna, $path);
    $loser = hyperPage($bert, $path);

    expect($winner->evaluate('() => window.hyperGame.state().me'))->toBe(0)
        ->and($loser->evaluate('() => window.hyperGame.state().me'))->toBe(1)
        // The full-screen table, not the league's shell.
        ->and($winner->evaluate('() => document.querySelector("[data-test=shell-match-dock], body > footer") === null'))->toBeTrue();

    // An emote from Anna rises over her portrait (seat 0) on Bert's page, sent over Reverb.
    hyperIdle($winner);
    $winner->locator('[data-test=hyper-emote-open]')->click();
    $winner->locator('#emote-stickers [data-emote=gg]')->click();
    BrowserWait::until($loser, '() => document.querySelector("[data-test=hyper-emote][data-emote=gg][data-seat=\"0\"]") !== null', 8_000);

    // The card is in Anna's hand, Mexico is its only target: two clicks, and Bert's last territory falls.
    $winner->evaluate('() => document.querySelector("#hand .card[data-card=attack51]").click()');
    BrowserWait::until($winner, '() => window.hyperGame.state().legal && document.querySelector("#pin-mexiko .target-ring").dataset.on === "1"', 5_000);
    hyperTap($winner, 'mexiko');

    BrowserWait::until($winner, '() => !document.querySelector("[data-test=hyper-end]").hidden', 20_000);
    BrowserWait::until($loser, '() => !document.querySelector("[data-test=hyper-end]").hidden', 20_000);
    hyperShot($winner, 'hyper-end-win');
    hyperShot($loser, 'hyper-end-defeat');

    expect($match->refresh()->winner_seat)->toBe(0)
        ->and($winner->evaluate('() => document.querySelector("[data-test=hyper-end]").dataset.result'))->toBe('win')
        ->and($loser->evaluate('() => document.querySelector("[data-test=hyper-end]").dataset.result'))->toBe('defeat')
        ->and($winner->evaluate('() => document.querySelector("#end-h").innerText'))->toContain('Hyperbitcoinization')
        ->and($loser->evaluate('() => document.querySelector("#end-h").innerText'))->toContain($anna->displayName())
        // The loot credited to the seat is the one the server stored.
        ->and($winner->evaluate('() => document.querySelector("[data-test=hyper-end-loot]").innerText'))->toContain('+'.rtrim(rtrim(number_format((float) $match->seats->firstWhere('seat', 0)->loot, 1), '0'), '.'))
        ->and(hyperErrors($winner))->toBe([])
        ->and(hyperErrors($loser))->toBe([]);

    // Positive control: the same collector sees a thrown error and a failed answer on this page.
    $winner->evaluate('() => { setTimeout(() => { throw new Error("hyper positive control"); }); fetch("/hyperbitcoinization/m/0"); }');
    BrowserWait::until($winner, '() => window.__errors.some((e) => e.includes("hyper positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
});

test('a guest watches live, and after a lost connection the page catches up every ply once', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $match = HyperOn::versus($anna, $bert);
    $path = route('hyper.match', $match, false);
    $player = hyperPage($anna, $path);
    $guest = hyperPage(null, $path);
    $place = function () use ($player): string {
        hyperIdle($player);
        $territory = $player->evaluate('() => window.hyperGame.state().legal.deploy[0]');
        hyperTap($player, $territory);
        BrowserWait::until($player, '() => !window.hyperGame.state().idle', 3_000);
        hyperIdle($player);

        return $territory;
    };

    expect($guest->evaluate('() => window.hyperGame.state().me'))->toBeNull()
        ->and($guest->evaluate('() => document.body.classList.contains("spectator") && document.querySelector("#treasury").offsetParent === null'))->toBeTrue();

    // Live: Anna's placement reaches the guest by broadcast.
    $first = $place();
    BrowserWait::until($guest, '() => { const s = window.hyperGame.state(); return s.ply === 1 && !s.running && s.queued === 0; }', 8_000);

    // The guest's socket drops; two more placements happen meanwhile.
    $guest->evaluate('() => window.Echo.connector.pusher.disconnect()');
    BrowserWait::until($guest, '() => document.body.dataset.live === "0"', 5_000);
    $place();
    $place();
    expect($match->refresh()->ply)->toBe(3)
        ->and($guest->evaluate('() => window.hyperGame.state().ply'))->toBe(1);

    // Back online: the missed plies come from the catch-up, each shown once, and the table ends where the server is.
    $guest->evaluate('() => window.Echo.connector.pusher.connect()');
    BrowserWait::until($guest, '() => { const s = window.hyperGame.state(); return s.ply === 3 && s.snapshotPly === 3 && !s.running && s.queued === 0; }', 10_000);

    expect($guest->evaluate('() => window.hyperGame.state().shown'))->toBe([1, 2, 3])
        ->and($player->evaluate('() => window.hyperGame.state().shown'))->toBe([1, 2, 3])
        ->and((int) $guest->evaluate('() => document.querySelector("#pin-'.$first.'").dataset.units'))->toBe(app(HyperMatches::class)->game($match->refresh())->units(HyperGame::territoryIndex($first)))
        ->and(hyperErrors($player))->toBe([])
        ->and(hyperErrors($guest))->toBe([]);
});

test('players and a spectator talk in the table chat over Nostr, and a reaction comes back', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    TestSigner::forBrowser($anna);
    TestSigner::forBrowser($bert);
    config(['esports.game_chat.creator' => (new TestSigner)->pubkey]);
    [$relay, $seed] = hyperRelay();

    try {
        $match = HyperOn::versus($anna, $bert);
        $path = route('hyper.match', $match, false);
        $pages = [hyperPage($anna, $path, 390, 844), hyperPage($bert, $path), hyperPage(null, $path)];

        foreach ($pages as $page) {
            $page->evaluate('() => window.hyperChat.open()');
            BrowserWait::until($page, '() => document.querySelector("[data-test=hyper-chat]").dataset.status === "live"', 10_000);
        }

        expect($pages[2]->evaluate('() => document.querySelector("#chat-form").hidden'))->toBeTrue();

        $pages[0]->locator('[data-test=hyper-chat-input]')->fill('gm table');
        $pages[0]->locator('[data-test=hyper-chat-send]')->click();

        foreach ($pages as $page) {
            BrowserWait::until($page, '() => [...document.querySelectorAll("[data-test=hyper-chat-text]")].some((p) => p.innerText === "gm table")', 10_000);
        }

        // Bert reacts with 🔥; everyone sees the count under Anna's message.
        $pages[1]->evaluate('() => document.querySelector("[data-test=hyper-chat-message] [data-test=hyper-chat-react]").click()');
        $pages[1]->evaluate('() => document.querySelector(".cm-picker [data-emoji=\"🔥\"]").click()');

        foreach ($pages as $page) {
            BrowserWait::until($page, '() => document.querySelector("[data-test=hyper-chat-reaction][data-emoji=\"🔥\"]")?.innerText === "🔥 1"', 10_000);
        }

        expect($pages[0]->evaluate('() => document.querySelector("[data-test=hyper-chat-message] .cm-name").innerText'))->toBe($anna->displayName())
            ->and(GameChannels::matchChannelId($match->ulid))->toBe($pages[0]->evaluate('() => JSON.parse(document.querySelector("#hyper-config").textContent).chat.channel'));

        foreach ($pages as $page) {
            expect(hyperErrors($page))->toBe([]);
        }
    } finally {
        $relay->stop();
        @unlink($seed);
    }
});

test('a whole live match against three bots, played through the page to round 5', function () {
    [$anna] = User::factory()->count(1)->create();
    $match = app(HyperMatches::class)->create([['user' => $anna, 'faction' => 'bitcoiner'], ['bot' => true], ['bot' => true], ['bot' => true]], 0, 7, $anna);
    $page = hyperPage($anna, route('hyper.match', $match, false), 1440, 900);
    $started = microtime(true);
    $actions = 0;
    $did = ['deploy' => 0, 'attack' => 0, 'move_in' => 0, 'end_phase' => 0];
    $bought = [];

    while ($page->evaluate('() => { const s = window.hyperGame.state(); return s.round < 5 && !s.over; }')) {
        BrowserWait::until($page, '() => { const s = window.hyperGame.state(); return s.over || (s.idle && s.legal !== null); }', 60_000);
        $s = $page->evaluate('() => window.hyperGame.state()');

        if ($s['over']) {
            break;
        }

        $legal = $s['legal'];

        if ($legal['pending_move'] !== null) {
            BrowserWait::until($page, '() => !document.querySelector("[data-test=hyper-move]").hidden', 5_000);
            if ($did['move_in'] === 0) {
                hyperShot($page, 'hyper-move-in');
            }
            $page->locator('[data-test=hyper-move-ok]')->click();
            $did['move_in']++;
        } elseif ($s['phase'] === 'buy' && ($legal['free_plebs'] > 0 || ($legal['affordable']['pleb'] && ! isset($bought[$s['round']])))) {
            // Free plebs first, then once a turn every pleb the fiat buys, on the territory the server lists first.
            $bought[$s['round']] = true;
            $page->evaluate('() => document.querySelector(".qty [data-q=max]").click()');
            hyperTap($page, $legal['deploy'][0]);
            $did['deploy']++;
        } elseif ($s['phase'] === 'attack' && $legal['attack'] !== [] && $did['attack'] < 2 * $s['round']) {
            $did['attack']++;
            $from = array_key_first($legal['attack']);
            // Esc clears a selection left from the last conquest, so the click selects instead of toggling it off.
            $page->evaluate('() => document.body.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }))');
            hyperTap($page, $from);
            hyperTap($page, $legal['attack'][$from][0]);
            BrowserWait::until($page, '() => !document.querySelector("[data-test=hyper-battle]").hidden && !document.querySelector("[data-test=hyper-blitz]").disabled', 5_000);
            if ($did['attack'] === 1) {
                hyperShot($page, 'hyper-battle');
            }
            $page->locator('[data-test=hyper-blitz]')->click();
            BrowserWait::until($page, '() => window.hyperGame.state().idle', 30_000);
            $page->evaluate('() => { if (!document.querySelector("[data-test=hyper-battle]").hidden) document.querySelector("#retreat-btn").click(); }');
        } else {
            $page->locator('[data-test=hyper-main]')->click();
            $did['end_phase']++;
        }

        $actions++;
        expect($actions)->toBeLessThan(400);
    }

    // The last bot turns may still be on screen: the page ends where the server is once they are shown.
    BrowserWait::until($page, '() => { const s = window.hyperGame.state(); return !s.running && s.queued === 0 && s.ply === s.snapshotPly && s.ply === '.$match->refresh()->ply.'; }', 60_000);
    $state = $page->evaluate('() => window.hyperGame.state()');
    fwrite(STDERR, sprintf("\nhyper playthrough: round %d, ply %d, %d actions %s, %.1f s, over: %s\n", $state['round'], $state['ply'], $actions, json_encode($did), microtime(true) - $started, $state['over'] ? 'yes' : 'no'));

    if (is_string(getenv('HYPER_SHOTS')) && getenv('HYPER_SHOTS') !== '') {
        $page->screenshot(true, 'hyper-playthrough-round-'.$state['round']);
        File::move(base_path('tests/Browser/Screenshots/hyper-playthrough-round-'.$state['round'].'.png'), getenv('HYPER_SHOTS').'/hyper-playthrough-round-'.$state['round'].'.png');
    }
    expect($state['round'] >= 5 || $state['over'])->toBeTrue()
        ->and($state['ply'])->toBe($match->refresh()->ply)
        ->and(hyperErrors($page))->toBe([]);
})->skip(fn (): bool => getenv('HYPER_PLAY') !== '1', 'On demand: HYPER_PLAY=1.');

test('the pages measured at 390, 1440 and 1920 in German and English as an admin', function () {
    $dir = (string) getenv('HYPER_SHOTS');
    File::ensureDirectoryExists($dir);
    [$anna, $bert] = User::factory()->count(2)->create();
    Admin::query()->create(['pubkey' => $anna->pubkey]);
    $rows = [];
    $measure = <<<'JS'
        () => {
            const rect = (sel) => { const el = document.querySelector(sel); if (!el || el.hidden || getComputedStyle(el).display === 'none' || el.offsetParent === null && getComputedStyle(el).position !== 'fixed') return null; const r = el.getBoundingClientRect(); return r.width && r.height ? { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height), r: Math.round(r.right), b: Math.round(r.bottom) } : null; };
            const vw = innerWidth; const vh = innerHeight;
            const hud = { topbar: rect('#topbar'), roster: rect('#roster'), treasury: rect('#treasury'), cta: rect('#cta'), legend: rect('#legend'), ticker: rect('#ticker'), clock: rect('#turn-clock'), tools: rect('#topbar .tools'), steps: rect('#stepper') };
            const names = Object.keys(hud).filter((k) => hud[k] && !['topbar', 'tools', 'steps', 'clock'].includes(k));
            const hit = (a, b) => a.x < b.r && b.x < a.r && a.y < b.b && b.y < a.b;
            const overlaps = [];
            for (let i = 0; i < names.length; i++) for (let j = i + 1; j < names.length; j++) if (hit(hud[names[i]], hud[names[j]])) overlaps.push(names[i] + '×' + names[j]);
            ['roster', 'treasury', 'cta', 'legend', 'ticker'].forEach((n) => { if (hud[n] && hud.steps && hit(hud[n], hud.steps)) overlaps.push(n + '×stepper'); if (hud[n] && hud.tools && hit(hud[n], hud.tools)) overlaps.push(n + '×tools'); });
            const outside = Object.entries(hud).filter(([, r]) => r && (r.x < 0 || r.y < 0 || r.r > vw || r.b > vh)).map(([n]) => n);
            const map = document.querySelector('#map').getBoundingClientRect();
            return { vw, vh, scroll: [document.documentElement.scrollWidth, document.documentElement.clientWidth, document.documentElement.scrollHeight, document.documentElement.clientHeight], map: [Math.round(map.width), Math.round(map.height)], hud, overlaps, outside, clockVisible: !!hud.clock };
        }
        JS;

    foreach (['de', 'en'] as $locale) {
        foreach ([[390, 844], [1440, 900], [1920, 1080]] as [$width, $height]) {
            $match = HyperOn::versus($anna, $bert, bots: 2, seed: 11);
            $page = hyperPage($anna, route('hyper.match', $match, false), $width, $height, ['scenes' => false, 'music' => false, 'fx' => false, 'board' => false, 'speed' => 20], $locale);
            hyperIdle($page);
            // The pins' entrance (about 1.3 s) is over before the picture.
            $page->evaluate('() => new Promise((done) => setTimeout(done, 1500))');
            $load = $page->evaluate($measure);
            $page->screenshot(true, "hyper-match-{$locale}-{$width}");
            // One roundtrip: a free pleb placed.
            $legal = $page->evaluate('() => window.hyperGame.state().legal');
            hyperTap($page, $legal['deploy'][0]);
            BrowserWait::until($page, '() => window.hyperGame.state().ply > '.$match->ply, 10_000);
            hyperIdle($page);
            $after = $page->evaluate($measure);
            $page->evaluate('() => window.hyperChat.open()');
            $chat = $page->evaluate('() => { const r = document.querySelector("#chat").getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height), Math.round(r.right)]; }');
            $page->screenshot(true, "hyper-match-{$locale}-{$width}-chat");
            $page->evaluate('() => { window.hyperChat.close(); document.querySelector("#emote-btn").click(); }');
            $emotes = $page->evaluate('() => { const r = document.querySelector("#emotes").getBoundingClientRect(); return [Math.round(r.x), Math.round(r.y), Math.round(r.right), Math.round(r.bottom)]; }');
            $page->screenshot(true, "hyper-match-{$locale}-{$width}-emotes");
            $rows[] = ['page' => 'match', 'locale' => $locale, 'size' => "{$width}x{$height}", 'load' => $load, 'after' => $after, 'chat' => $chat, 'emotes' => $emotes, 'errors' => hyperErrors($page)];

            $index = hyperPage($anna, route('hyper.index', absolute: false), $width, $height, locale: $locale);
            $rows[] = ['page' => 'index', 'locale' => $locale, 'size' => "{$width}x{$height}", 'scroll' => $index->evaluate('() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]'), 'play' => $index->evaluate('() => { const r = document.querySelector("[data-test=hyper-play-bots]").getBoundingClientRect(); return [Math.round(r.top), Math.round(r.bottom)]; }'), 'clipped' => $index->evaluate('() => [...document.querySelectorAll("[data-test=hyper-index] h1, [data-test=hyper-index] label span, [data-test=hyper-index] li")].filter((el) => el.scrollWidth > el.clientWidth + 1).map((el) => el.innerText.trim())'), 'errors' => hyperErrors($index)];
            $index->screenshot(true, "hyper-index-{$locale}-{$width}");
        }
    }

    foreach (File::glob(base_path('tests/Browser/Screenshots/hyper-*.png')) as $shot) {
        File::move($shot, $dir.'/'.basename($shot));
    }
    File::put($dir.'/numbers.json', (string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    foreach ($rows as $row) {
        $where = $row['page'].' '.$row['locale'].' '.$row['size'];
        expect($row['errors'])->toBe([], $where);

        if ($row['page'] === 'match') {
            expect($row['load']['scroll'][0])->toBe($row['load']['scroll'][1], $where)
                ->and($row['load']['overlaps'])->toBe([], $where)->and($row['after']['overlaps'])->toBe([], $where)
                ->and($row['load']['outside'])->toBe([], $where)
                ->and($row['load']['clockVisible'])->toBeTrue();
        } else {
            expect($row['scroll'][0])->toBe($row['scroll'][1], $where)->and($row['clipped'])->toBe([], $where);
        }
    }
})->skip(fn (): bool => ! is_string(getenv('HYPER_SHOTS')) || getenv('HYPER_SHOTS') === '', 'On demand: HYPER_SHOTS=<dir>.');
