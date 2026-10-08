<?php

use App\Models\Admin;
use App\Models\HyperMatch;
use App\Models\HyperTable;
use App\Models\User;
use App\Support\Hyper\HyperMatches;
use App\Support\Hyper\HyperStats;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\HyperOn;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The Hyperbitcoinization lobby and replay in the browser (plan "Hyperbitcoinization", P3)
|--------------------------------------------------------------------------
|
| Two players at one lobby table over Reverb, each in their own context: Bert sees Anna's new table appear,
| joins it, both pick factions (Anna's page greys out Bert's), Anna fills the free seats with bots, and the
| match opens in a new tab on both pages (window.open is recorded, not followed: a test cannot hold a
| second tab; the page then goes to the match itself). And a finished match replays to its end and jumps
| back. Both pages are measured as an admin in German (lobby 390/1440/1920, replay 390/1440): overflow,
| clipped texts, where the primary action is; every page carries BrowserConsole's collector (console errors,
| uncaught errors, answers >= 400), proved by a positive control. The numbers go to STDERR for the report.
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

function lobbyPage(User $user, string $path, int $width = 1440, int $height = 900): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    // A new tab is recorded instead of opened; the cinematics and sounds of the table stay off.
    $page->context()->addInitScript('window.__opened = []; window.open = (url) => { window.__opened.push(String(url)); return null; };');
    $page->context()->addInitScript('try { localStorage.setItem("hb-settings", '.json_encode((string) json_encode(['scenes' => false, 'music' => false, 'fx' => false, 'board' => false, 'speed' => 20])).'); } catch (e) {}');
    $page->goto(ComputeUrl::from(route('locale.switch', 'de', false)));
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($path));
    BrowserWait::until($page, '() => document.querySelector("[data-test=hyper-match]") ? document.body.dataset.ready === "1" : document.readyState === "complete" && !!window.Livewire', 15_000);

    return $page;
}

/**
 * @return list<string>
 */
function lobbyErrors(Page $page): array
{
    return [...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)];
}

/** The positive control: the collector on this page sees a thrown error and a failed answer. */
function lobbyControl(Page $page): void
{
    $page->evaluate('() => { setTimeout(() => { throw new Error("hyper p3 positive control"); }); fetch("/hyperbitcoinization/m/0"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("hyper p3 positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
}

/**
 * The lobby's numbers at one size: document overflow, texts cut off without an ellipsis by design, names
 * ellipsised by design, and the box of the primary action.
 *
 * @return array<string, mixed>
 */
function lobbyMeasure(Page $page, int $width, int $height, string $primary): array
{
    $page->setViewportSize($width, $height);
    $page->evaluate('() => new Promise((done) => setTimeout(done, 300))');

    return $page->evaluate(<<<JS
        () => {
            const de = document.documentElement;
            const box = (el) => { if (!el) return null; const r = el.getBoundingClientRect(); return [Math.round(r.left), Math.round(r.top), Math.round(r.right), Math.round(r.bottom)]; };
            const texts = [...document.querySelectorAll('[data-test=hyper-index] h1, [data-test=hyper-lobby] h2, [data-test=hyper-lobby] b, [data-test=hyper-lobby] span, [data-test=hyper-lobby] button, [data-test=hyper-lobby] a, [data-test=hyper-lobby] p')]
                .filter((el) => el.offsetParent !== null && el.scrollWidth > el.clientWidth + 1);
            const cut = (el) => (el.dataset.test || el.tagName.toLowerCase()) + ': ' + el.innerText.trim().slice(0, 40);
            return {
                size: innerWidth + 'x' + innerHeight,
                scroll: [de.scrollWidth, de.clientWidth],
                clipped: texts.filter((el) => getComputedStyle(el).textOverflow !== 'ellipsis').map(cut),
                ellipsised: texts.filter((el) => getComputedStyle(el).textOverflow === 'ellipsis').map(cut),
                primary: box(document.querySelector('{$primary}')),
                fold: innerHeight,
            };
        }
        JS);
}

test('two players join a table, pick factions, bots fill it, and the match opens in a new tab for both; the lobby measured', function () {
    expect(config('broadcasting.default'))->toBe('reverb', 'Run this through scripts/test-browser.sh, which starts Reverb.');
    config(['esports.hyper.lobby_fill_seconds' => 600]);

    [$anna, $bert] = User::factory()->count(2)->create();
    Admin::query()->create(['pubkey' => $anna->pubkey]);
    $index = route('hyper.index', absolute: false);
    $annas = lobbyPage($anna, $index);
    $berts = lobbyPage($bert, $index);
    $rows = [];

    foreach ([[390, 844], [1440, 900], [1920, 1080]] as [$width, $height]) {
        $rows[] = ['state' => 'new table', ...lobbyMeasure($annas, $width, $height, '[data-test=hyper-lobby-open]')];
    }
    $annas->setViewportSize(1440, 900);

    // Anna opens a table of four; Bert's lobby shows it without a reload (hyper.lobby over Reverb).
    $annas->locator('[data-test=hyper-lobby-seats][data-seats="4"]')->click();
    $annas->locator('[data-test=hyper-lobby-open]')->click();
    BrowserWait::until($annas, '() => !!document.querySelector("[data-test=hyper-lobby-mine]")', 8_000);
    BrowserWait::until($berts, '() => !!document.querySelector("[data-test=hyper-lobby-table] [data-test=hyper-lobby-join]")', 12_000);

    $berts->locator('[data-test=hyper-lobby-join]')->click();
    BrowserWait::until($berts, '() => !!document.querySelector("[data-test=hyper-lobby-mine]")', 8_000);
    BrowserWait::until($annas, '() => document.querySelector("[data-test=hyper-lobby-count]")?.innerText.startsWith("2/4")', 12_000);

    // Factions: each picks one, and Anna's page greys out Bert's.
    $annas->locator('[data-test=hyper-lobby-faction][data-faction=fed]')->click();
    BrowserWait::until($annas, '() => document.querySelector("[data-test=hyper-lobby-faction][data-faction=fed]").getAttribute("aria-pressed") === "true"', 8_000);
    $berts->locator('[data-test=hyper-lobby-faction][data-faction=goldbug]')->click();
    BrowserWait::until($berts, '() => document.querySelector("[data-test=hyper-lobby-faction][data-faction=goldbug]").getAttribute("aria-pressed") === "true"', 8_000);
    BrowserWait::until($annas, '() => document.querySelector("[data-test=hyper-lobby-faction][data-faction=goldbug]").disabled', 12_000);
    expect($berts->evaluate('() => document.querySelector("[data-test=hyper-lobby-faction][data-faction=fed]").disabled'))->toBeTrue();

    foreach ([[390, 844], [1440, 900], [1920, 1080]] as [$width, $height]) {
        $rows[] = ['state' => 'own table', ...lobbyMeasure($annas, $width, $height, '[data-test=hyper-lobby-fill]')];
    }
    $annas->setViewportSize(1440, 900);

    // Bots take the two free seats: the match opens in a new tab on both pages.
    $annas->locator('[data-test=hyper-lobby-fill]')->click();
    BrowserWait::until($annas, '() => window.__opened.length === 1', 10_000);
    BrowserWait::until($berts, '() => window.__opened.length === 1', 12_000);
    BrowserWait::until($berts, '() => !!document.querySelector("[data-test=hyper-lobby-open-match]")', 12_000);

    $table = HyperTable::query()->latest('id')->firstOrFail();
    $match = $table->match()->with('seats')->firstOrFail();
    $url = route('hyper.match', $match);
    expect($annas->evaluate('() => window.__opened'))->toBe([$url])
        ->and($berts->evaluate('() => window.__opened'))->toBe([$url])
        ->and($berts->evaluate('() => document.querySelector("[data-test=hyper-lobby-open-match]").target'))->toBe('_blank')
        ->and($match->seats->pluck('faction')->take(2)->all())->toBe(['fed', 'goldbug'])
        ->and($match->seats->pluck('bot')->all())->toBe([false, false, true, true])
        ->and(lobbyErrors($annas))->toBe([])
        ->and(lobbyErrors($berts))->toBe([]);

    // The new tab: Anna's match, her seat.
    $annas->goto(ComputeUrl::from(route('hyper.match', $match, false)));
    BrowserWait::until($annas, '() => document.body.dataset.ready === "1" && document.body.dataset.live === "1"', 15_000);
    expect($annas->evaluate('() => window.hyperGame.state().me'))->toBe(0)
        ->and(lobbyErrors($annas))->toBe([]);

    fwrite(STDERR, "\nhyper lobby measured: ".json_encode($rows, JSON_UNESCAPED_UNICODE)."\n");

    foreach ($rows as $row) {
        $where = $row['state'].' '.$row['size'];
        expect($row['scroll'][0])->toBe($row['scroll'][1], $where)
            ->and($row['clipped'])->toBe([], $where)
            ->and($row['primary'])->not->toBeNull($where);
    }

    // Above the fold at every size: the primary action of a new table.
    foreach (array_filter($rows, fn (array $row): bool => $row['state'] === 'new table') as $row) {
        expect($row['primary'][3])->toBeLessThanOrEqual($row['fold'], $row['size']);
    }

    lobbyControl($berts);
});

test('a finished match replays to its end, every hand open, and jumps back; the replay measured', function () {
    config(['esports.hyper.bot_round_cap' => 2]);
    $anna = User::factory()->create();
    Admin::query()->create(['pubkey' => $anna->pubkey]);
    $matches = app(HyperMatches::class);
    $match = $matches->create([['user' => $anna, 'faction' => 'bitcoiner'], ['bot' => true], ['bot' => true]], seed: 5, creator: $anna);
    $matches->leave($match, $anna);
    $match = HyperMatch::query()->findOrFail($match->id);
    expect($match->isActive())->toBeFalse();
    $last = $match->ply;

    $page = lobbyPage($anna, route('hyper.replay', $match, false));
    $rows = [];
    $measure = <<<'JS'
        () => {
            const de = document.documentElement;
            const box = (sel) => { const el = document.querySelector(sel); if (!el || getComputedStyle(el).display === 'none') return null; const r = el.getBoundingClientRect(); return r.width && r.height ? { x: Math.round(r.x), y: Math.round(r.y), r: Math.round(r.right), b: Math.round(r.bottom) } : null; };
            const hud = { bar: box('#replay-bar'), play: box('#rp-play'), scrub: box('#rp-scrub'), roster: box('#roster'), legend: box('#legend'), ticker: box('#ticker'), topbar: box('#topbar') };
            const hit = (a, b) => a && b && a.x < b.r && b.x < a.r && a.y < b.b && b.y < a.b;
            const overlaps = ['roster', 'legend', 'ticker', 'topbar'].filter((n) => hit(hud.bar, hud[n])).map((n) => 'bar×' + n);
            const outside = Object.entries(hud).filter(([, r]) => r && (r.x < 0 || r.y < 0 || r.r > innerWidth || r.b > innerHeight)).map(([n]) => n);
            // The play button's sweep (.big::after) is translated outside it on purpose: its label must fit, not the sweep.
            const inside = (child, parent) => { const c = child.getBoundingClientRect(); const p = parent.getBoundingClientRect(); return c.left >= p.left - 1 && c.right <= p.right + 1; };
            const clipped = [...document.querySelectorAll('#replay-bar button:not(.big), #rp-ply')].filter((el) => el.scrollWidth > el.clientWidth + 1).map((el) => el.id || el.innerText);
            if (!inside(document.querySelector('#rp-play-t'), document.querySelector('#rp-play'))) clipped.push('rp-play-t');
            return { size: innerWidth + 'x' + innerHeight, scroll: [de.scrollWidth, de.clientWidth], hud, overlaps, outside, clipped, hands: document.querySelectorAll('[data-test=hyper-replay-hand]').length };
        }
        JS;

    foreach ([[390, 844], [1440, 900]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->evaluate('() => new Promise((done) => setTimeout(done, 400))');
        $rows[] = $page->evaluate($measure);
    }
    $page->setViewportSize(1440, 900);

    expect($page->evaluate('() => window.hyperGame.state().ply'))->toBe(0)
        ->and($page->evaluate('() => document.querySelector("[data-test=hyper-replay-scrub]").max'))->toBe((string) $last);

    // Instant pace, play: every ply is shown, the end screen comes, the table is the stored end.
    $page->locator('#rp-speed [data-s="20"]')->click();
    $page->locator('[data-test=hyper-replay-play]')->click();
    BrowserWait::until($page, '() => document.body.dataset.replayPly === "'.$last.'" && !window.hyperReplay.state().loop && !window.hyperReplay.state().busy && !document.querySelector("[data-test=hyper-end]").hidden', 150_000);
    $end = $page->evaluate('() => window.hyperGame.state()');
    expect($end['ply'])->toBe($last)
        ->and($end['over'])->toBeTrue()
        ->and($page->evaluate('() => document.querySelectorAll("[data-test=hyper-replay-hand]").length'))->toBe(3)
        ->and($page->evaluate('() => document.querySelector("[data-test=hyper-rematch]")'))->toBeNull();

    // Back to the middle: the table at that ply, the end screen gone.
    $half = intdiv($last, 2);
    $page->evaluate('() => { const s = document.querySelector("#rp-scrub"); s.value = "'.$half.'"; s.dispatchEvent(new Event("change")); }');
    BrowserWait::until($page, '() => { const s = window.hyperGame.state(); return s.ply === '.$half.' && s.syncPly === '.$half.' && document.querySelector("[data-test=hyper-end]").hidden; }', 10_000);

    // One step forward.
    $page->locator('[data-test=hyper-replay-step]')->click();
    BrowserWait::until($page, '() => window.hyperGame.state().ply === '.($half + 1), 10_000);

    fwrite(STDERR, "\nhyper replay measured (last ply {$last}): ".json_encode($rows, JSON_UNESCAPED_UNICODE)."\n");

    foreach ($rows as $row) {
        expect($row['scroll'][0])->toBe($row['scroll'][1], $row['size'])
            ->and($row['overlaps'])->toBe([], $row['size'])
            ->and($row['outside'])->toBe([], $row['size'])
            ->and($row['clipped'])->toBe([], $row['size'])
            ->and($row['hands'])->toBe(3, $row['size']);
    }

    expect(lobbyErrors($page))->toBe([]);
    lobbyControl($page);
});

test('after a finished match the statistics play by themselves, can be skipped, and every page is reachable; every page measured', function () {
    config(['esports.hyper.bot_round_cap' => 2]);
    $anna = User::factory()->create();
    Admin::query()->create(['pubkey' => $anna->pubkey]);
    $matches = app(HyperMatches::class);
    $match = $matches->create([['user' => $anna, 'faction' => 'bitcoiner'], ['bot' => true], ['bot' => true]], seed: 5, creator: $anna);
    $matches->leave($match, $anna);
    $match = HyperMatch::query()->findOrFail($match->id);
    expect($match->isActive())->toBeFalse();

    // The numbers are the match's own; every moment is added, so that each scene is shown and measured (the
    // moments themselves are triggered in tests/Feature/Hyper/HyperStatsTest.php).
    $stats = HyperStats::compute($match);
    $stats['moments'] = [
        ['key' => 'finale', 'round' => $stats['rounds'], 'seat' => 1, 'faction' => $stats['factions'][1], 'by_limit' => true],
        ['key' => 'first_bank', 'round' => 1, 'seat' => 1, 'territory' => 'ny', 'loser' => 0],
        ['key' => 'zone', 'round' => 2, 'seat' => 2, 'zone' => 'afro'],
        ['key' => 'comeback', 'round' => 2, 'seat' => 1, 'zone' => 'ozean'],
        ['key' => 'perfect_dice', 'round' => 1, 'seat' => 0, 'territory' => 'zuerich'],
        ['key' => 'knockout', 'round' => 2, 'seat' => 1, 'victim' => 0],
        ['key' => 'lost_keys', 'round' => 1, 'seat' => 2, 'victim' => 1, 'sats' => 12.3],
        ['key' => 'salvador_lost', 'round' => 1, 'seat' => 0],
        ['key' => 'pizza', 'round' => 2, 'seat' => 2],
        ['key' => 'attack51', 'round' => 2, 'seat' => 1, 'territory' => 'suedostasien'],
        ['key' => 'bitcoin_dead', 'round' => 2, 'seat' => 1],
    ];
    Cache::put('hyper:stats:v'.HyperStats::VERSION.':'.$match->ulid, $stats, 600);

    $page = lobbyPage($anna, route('hyper.match', $match, false), 1600, 900);

    // The page opened on a finished match: after the end screen the sequence plays by itself, charts first.
    BrowserWait::until($page, '() => { const s = window.hyperStats.state(); return s.open && s.auto && s.page === "charts"; }', 10_000);
    $plan = $page->evaluate('() => window.hyperStats.state()');
    expect($plan['pages'])->toHaveCount(13)
        ->and($plan['total'])->toBeGreaterThanOrEqual(30_000)
        ->and($plan['total'])->toBeLessThanOrEqual(60_000)
        ->and($page->evaluate('() => document.querySelectorAll("#st-chart .st-line").length'))->toBe(3);
    BrowserWait::until($page, '() => window.hyperStats.state().page === "leaders"', 16_000);

    // Skipped: the end screen is back, its buttons are free to click.
    $page->locator('[data-test=hyper-stats-skip]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=hyper-stats]").hidden', 3_000);
    expect($page->evaluate('() => { const b = document.querySelector("#again-btn").getBoundingClientRect(); return document.elementFromPoint(b.x + b.width / 2, b.y + b.height / 2)?.closest("#again-btn") !== null; }'))->toBeTrue()
        ->and($page->evaluate('() => window.hyperStats.state().auto'))->toBeFalse();

    // Opened again from the end screen: browsing, no autoplay; the tabs reach the leaders and the moments.
    $page->locator('[data-test=hyper-stats-open]')->click();
    BrowserWait::until($page, '() => { const s = window.hyperStats.state(); return s.open && !s.auto && s.page === "charts"; }', 3_000);
    $page->locator('[data-test=hyper-stats-tab]:nth-child(2)')->click();
    BrowserWait::until($page, '() => window.hyperStats.state().page === "leaders" && document.querySelectorAll("[data-leader]").length === 5', 3_000);
    $page->locator('[data-test=hyper-stats-tab]:nth-child(3)')->click();
    BrowserWait::until($page, '() => window.hyperStats.state().page === "moment:finale"', 3_000);
    $page->locator('[data-test=hyper-stats-tab]:nth-child(1)')->click();
    BrowserWait::until($page, '() => window.hyperStats.state().page === "charts"', 3_000);

    $measure = <<<'JS'
        () => {
            const de = document.documentElement;
            const page = document.querySelector('#st-page');
            const box = (el) => { if (!el || el.offsetParent === null) return null; const r = el.getBoundingClientRect(); return r.width && r.height ? { x: Math.round(r.x), y: Math.round(r.y), r: Math.round(r.right), b: Math.round(r.bottom) } : null; };
            const hit = (a, b) => a && b && a.x < b.r - 1 && b.x < a.r - 1 && a.y < b.b - 1 && b.y < a.b - 1;
            const boxes = { chart: box(document.querySelector('#st-chart svg')), skip: box(document.querySelector('#st-skip')), tabs: box(document.querySelector('#st-tabs')), foot: box(document.querySelector('.st-foot')), art: box(document.querySelector('.sc-art')), text: box(document.querySelector('.sc-txt')) };
            const texts = [...document.querySelectorAll('#stats h2, #stats h3, #stats p, #stats small, #stats b, #stats .chip, #stats button, #stats .st-who, #stats output')]
                .filter((el) => el.offsetParent !== null && el.scrollWidth > el.clientWidth + 1);
            const cut = (el) => (el.id || el.className || el.tagName) + ': ' + el.innerText.trim().slice(0, 40);
            const outside = Object.entries(boxes).filter(([, r]) => r && (r.x < 0 || r.y < 0 || r.r > innerWidth || r.b > innerHeight)).map(([n]) => n);
            const overlaps = [['art', 'text'], ['chart', 'skip'], ['tabs', 'skip'], ['chart', 'foot']].filter(([a, b]) => hit(boxes[a], boxes[b])).map((p) => p.join('×'));
            return {
                page: window.hyperStats.state().page,
                size: innerWidth + 'x' + innerHeight,
                scroll: [de.scrollWidth, de.clientWidth],
                inner: [page.scrollWidth, page.clientWidth, page.scrollHeight, page.clientHeight],
                clipped: texts.filter((el) => getComputedStyle(el).textOverflow !== 'ellipsis').map(cut),
                ellipsised: texts.filter((el) => getComputedStyle(el).textOverflow === 'ellipsis').map(cut),
                overlaps,
                outside,
                chart: boxes.chart,
                skip: boxes.skip,
            };
        }
        JS;
    $rows = [];
    // Pictures for the report, only when HYPER_SHOTS names a directory.
    $shot = function (string $name) use ($page): void {
        $dir = getenv('HYPER_SHOTS');

        if (is_string($dir) && $dir !== '') {
            File::ensureDirectoryExists($dir);
            $page->screenshot(false, 'hyper-stats-'.$name);
            File::move(base_path('tests/Browser/Screenshots/hyper-stats-'.$name.'.png'), $dir.'/hyper-stats-'.$name.'.png');
        }
    };

    foreach ([[1600, 900], [390, 844]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->locator('[data-test=hyper-stats-tab]:nth-child(1)')->click();
        BrowserWait::until($page, '() => window.hyperStats.state().page === "charts"', 3_000);

        foreach (range(0, 12) as $index) {
            if ($index > 0) {
                $page->locator('[data-test=hyper-stats-next]')->click();
                BrowserWait::until($page, '() => window.hyperStats.state().index === '.$index, 3_000);
            }
            // The scene's sprite and texts settle (0.7 s), the chart lines draw (2.4 s from their start).
            $page->evaluate('() => new Promise((done) => setTimeout(done, '.($index === 0 ? 2900 : 900).'))');
            $rows[] = $page->evaluate($measure);
            $shot($width.'-'.str_replace(':', '-', (string) $rows[array_key_last($rows)]['page']));
        }
    }

    fwrite(STDERR, "\nhyper stats measured: ".json_encode($rows, JSON_UNESCAPED_UNICODE)."\n");

    expect(array_column($rows, 'page'))->toBe([...$plan['pages'], ...$plan['pages']]);

    foreach ($rows as $row) {
        $where = $row['page'].' '.$row['size'];
        expect($row['scroll'][0])->toBe($row['scroll'][1], $where)
            ->and($row['inner'][0])->toBeLessThanOrEqual($row['inner'][1], $where)
            ->and($row['clipped'])->toBe([], $where)
            ->and($row['overlaps'])->toBe([], $where)
            ->and($row['outside'])->toBe([], $where)
            ->and($row['skip'])->not->toBeNull($where);

        if ($row['page'] === 'charts') {
            expect($row['chart'])->not->toBeNull($where);
        }

        if (str_starts_with($row['page'], 'moment:')) {
            // A scene fits its page: nothing to scroll.
            expect($row['inner'][2])->toBeLessThanOrEqual($row['inner'][3] + 1, $where);
        }
    }

    // Escape closes it, as Skip does.
    $page->locator('#st-skip')->press('Escape');
    BrowserWait::until($page, '() => document.querySelector("[data-test=hyper-stats]").hidden', 3_000);

    expect(lobbyErrors($page))->toBe([]);
    lobbyControl($page);
});
