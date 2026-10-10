<?php

use App\Models\Admin;
use App\Models\HyperMatch;
use App\Models\User;
use App\Support\Hyper\HyperLobby;
use App\Support\Hyper\HyperMatches;
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
| Hyperbitcoinization on the league's surfaces in the browser (plan "Hyperbitcoinization", P6)
|--------------------------------------------------------------------------
|
| Every surface that took the game up (home, /matches, /play, the rules section, the player page) and the polish
| of the gates (faction labels on a phone, the bots' countdown, the Rated/Unrated chip on a phone, a declined
| rematch on the end screen, the leaderboard chips' single gap), measured as an admin at 390 and 1440 px: document overflow, texts
| cut off, where the game's element sits; German everywhere, and English for the game's own pages (lobby, match,
| replay, statistics). Every page carries BrowserConsole's collector (console errors, uncaught errors, answers
| >= 400), proved by a positive control. The numbers go to STDERR for the report.
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

const HYPER_SURFACE_SIZES = [[390, 844], [1440, 900]];

/** A page of the user in `$locale`, the collector on, the table's cinematics and sounds off. */
function surfacePage(User $user, string $path, string $locale = 'de'): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript('window.__opened = []; window.open = (url) => { window.__opened.push(String(url)); return null; };');
    $page->context()->addInitScript('try { localStorage.setItem("hb-settings", '.json_encode((string) json_encode(['scenes' => false, 'music' => false, 'fx' => false, 'board' => false, 'speed' => 20])).'); } catch (e) {}');
    $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    $page->setViewportSize(1440, 900);
    surfaceGo($page, $path);

    return $page;
}

function surfaceGo(Page $page, string $path): void
{
    $page->goto(ComputeUrl::from($path));
    BrowserWait::until($page, '() => document.querySelector("[data-test=hyper-match]") ? document.body.dataset.ready === "1" : document.readyState === "complete" && !!window.Livewire', 15_000);
}

/**
 * @return list<string>
 */
function surfaceErrors(Page $page): array
{
    return [...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)];
}

/** The positive control: the collector on this page sees a thrown error and a failed answer. */
function surfaceControl(Page $page): void
{
    $page->evaluate('() => { setTimeout(() => { throw new Error("hyper p6 positive control"); }); fetch("/hyperbitcoinization/m/0"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("hyper p6 positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
}

/**
 * One element at one size: document overflow, the element's box, its texts cut off without an ellipsis, and how
 * many of its links open a new tab.
 *
 * @return array<string, mixed>
 */
function surfaceMeasure(Page $page, string $selector, int $width, int $height): array
{
    $page->setViewportSize($width, $height);
    $page->evaluate('() => new Promise((done) => setTimeout(done, 300))');

    return $page->evaluate(<<<JS
        () => {
            const de = document.documentElement;
            const el = [...document.querySelectorAll('{$selector}')].find((x) => x.checkVisibility()) ?? null;
            const r = el ? el.getBoundingClientRect() : null;
            const cut = el ? [...el.querySelectorAll('b, span, a, button, h2, h3, p, dd, dt, small')]
                .filter((x) => x.offsetParent !== null && !x.classList.contains('sr-only') && x.scrollWidth > x.clientWidth + 1 && getComputedStyle(x).textOverflow !== 'ellipsis')
                .map((x) => (x.dataset.test || x.tagName.toLowerCase()) + ': ' + x.innerText.trim().slice(0, 40)) : ['missing'];
            return {
                size: innerWidth + 'x' + innerHeight,
                scroll: [de.scrollWidth, de.clientWidth],
                box: r ? [Math.round(r.left), Math.round(r.top + scrollY), Math.round(r.width), Math.round(r.height)] : null,
                inside: r ? r.left >= 0 && r.right <= innerWidth + 1 : false,
                clipped: cut,
            };
        }
        JS);
}

test('every surface shows the game at 390 and 1440 as an admin in German: no overflow, nothing cut off, matches open a new tab', function () {
    $anna = User::factory()->create(['name' => 'Anna Admin']);
    Admin::query()->create(['pubkey' => $anna->pubkey]);
    $bert = User::factory()->create(['name' => 'Bert Pleb']);
    $done = HyperOn::finishTable(HyperOn::versus($anna, $bert, bots: 2), [0, 1, 2, 3]);
    HyperOn::versus($anna, $bert, bots: 1);
    app(HyperMatches::class)->create([['user' => $anna, 'faction' => 'goldbug'], ['user' => $bert, 'faction' => 'nocoiner']], seed: 9, creator: $anna, mode: HyperMatch::CORRESPONDENCE);

    $surfaces = [
        // The desktop tile, or the phone's list row (Main.dc.html / HomePhone.dc.html): the one that shows.
        'home tile' => [route('home', absolute: false), '[data-test=home-games] a[data-game=hyperbitcoinization]'],
        'matches row' => [route('matches.index', absolute: false), '[data-test=hyper-row]'],
        // The cube itself: the column's "casual" tag above it is the strip's, the same for every game.
        'matches cube' => [route('matches.index', absolute: false), '[data-test=strip-cube][data-game=hyperbitcoinization] a.bs-cube'],
        'play card' => [route('play', absolute: false), '[data-test=play-game-hyperbitcoinization]'],
        'rules section' => [route('rules', absolute: false), '[data-test=doc-section-hyperbitcoinization]'],
        'profile card' => [route('players.show', $anna->npub, false), '[data-test=player-hyper]'],
    ];
    $page = surfacePage($anna, route('home', absolute: false));
    $rows = [];
    $errors = [];
    $at = null;

    foreach ($surfaces as $name => [$path, $selector]) {
        if ($path !== $at) {
            surfaceGo($page, $path);
            $at = $path;
        }

        foreach (HYPER_SURFACE_SIZES as [$width, $height]) {
            $rows[] = ['surface' => $name, ...surfaceMeasure($page, $selector, $width, $height)];
        }

        $errors[$name] = surfaceErrors($page);
    }

    // The match list opens every match full-screen in a new tab: rows and cubes alike.
    surfaceGo($page, route('matches.index', absolute: false));
    $links = $page->evaluate('() => [...document.querySelectorAll("[data-test=hyper-row], [data-test=strip-cube][data-game=hyperbitcoinization] a.bs-cube")].map((a) => [a.target, a.getAttribute("href")])');
    $german = $page->evaluate('() => document.querySelector("[data-test=hyper-row]").innerText');

    fwrite(STDERR, "\nhyper surfaces measured: ".json_encode($rows, JSON_UNESCAPED_UNICODE)."\nlinks: ".json_encode($links)."\n");

    foreach ($rows as $row) {
        $where = $row['surface'].' '.$row['size'];
        expect($row['box'])->not->toBeNull($where)
            ->and($row['scroll'][0])->toBe($row['scroll'][1], $where)
            ->and($row['clipped'])->toBe([], $where);

        // The mempool strip scrolls sideways by design: a cube may sit beyond the screen's edge.
        if ($row['surface'] !== 'matches cube') {
            expect($row['inside'])->toBeTrue($where);
        }
    }

    expect($links)->toHaveCount(2) // 1 row, 1 cube: only the won match is listed (user 2026-10-09)
        ->and(array_unique(array_column($links, 0)))->toBe(['_blank'])
        ->and($links)->toContain(['_blank', route('hyper.match', $done)])
        ->and($german)->toContain('Sieger')->not->toContain('Runde')
        ->and(array_filter($errors))->toBe([]);

    surfaceControl($page);
});

test('the lobby at 390 and 1440, German and English: faction labels whole, the bots\' countdown ticks, nothing overflows', function () {
    config(['esports.hyper.lobby_fill_seconds' => 600]);
    $anna = User::factory()->create(['name' => 'Anna Admin']);
    Admin::query()->create(['pubkey' => $anna->pubkey]);
    app(HyperLobby::class)->open($anna, 4, HyperMatch::LIVE, 0, bots: true);
    $rows = [];

    foreach (['de', 'en'] as $locale) {
        $page = surfacePage($anna, route('hyper.index', absolute: false), $locale);

        foreach (HYPER_SURFACE_SIZES as [$width, $height]) {
            $row = surfaceMeasure($page, '[data-test=hyper-index]', $width, $height);
            $row['labels'] = $page->evaluate('() => [...document.querySelectorAll("[data-test=hyper-lobby-faction] span:last-child, [data-test=hyper-quick] label > span:last-child")].map((s) => [s.innerText.trim(), s.scrollWidth, s.clientWidth])');
            $row['cutLabels'] = array_values(array_filter($row['labels'], fn (array $label): bool => $label[1] > $label[2] + 1));
            $rows[] = ['locale' => $locale, ...$row];
        }

        // The countdown ticks every second: two readings two seconds apart.
        $first = $page->evaluate('() => document.querySelector("[data-test=hyper-lobby-autofill]").innerText.trim()');
        $page->evaluate('() => new Promise((done) => setTimeout(done, 2100))');
        $second = $page->evaluate('() => document.querySelector("[data-test=hyper-lobby-autofill]").innerText.trim()');
        $rows[] = ['locale' => $locale, 'countdown' => [$first, $second]];

        expect($first)->not->toBe($second)
            ->and($first)->toMatch($locale === 'de' ? '/^Bots besetzen die freien Plätze in \d+:\d\d\.$/' : '/^Bots fill the free seats in \d+:\d\d\.$/')
            ->and(surfaceErrors($page))->toBe([]);
    }

    fwrite(STDERR, "\nhyper lobby measured: ".json_encode($rows, JSON_UNESCAPED_UNICODE)."\n");

    foreach (array_filter($rows, fn (array $row): bool => isset($row['size'])) as $row) {
        $where = $row['locale'].' '.$row['size'];
        expect($row['scroll'][0])->toBe($row['scroll'][1], $where)
            ->and($row['labels'])->toHaveCount(14, $where)
            ->and($row['cutLabels'])->toBe([], $where);
    }

    surfaceControl($page);
});

test('a finished match at 390 and 1440, German and English: the Rated/Unrated chip on a phone, the rematch declined, single gaps in the leaderboards, and the replay', function () {
    config(['esports.hyper.bot_round_cap' => 2]);
    $anna = User::factory()->create(['name' => 'Anna Admin']);
    Admin::query()->create(['pubkey' => $anna->pubkey]);
    $bert = User::factory()->create(['name' => 'Bert Pleb']);
    $matches = app(HyperMatches::class);
    $match = $matches->create([['user' => $anna, 'faction' => 'bitcoiner'], ['user' => $bert, 'faction' => 'fed'], ['bot' => true]], seed: 5, creator: $anna);
    $matches->leave($match, $bert);
    $matches->leave($match->refresh(), $anna);
    $match = HyperMatch::query()->findOrFail($match->id);
    expect($match->isActive())->toBeFalse();
    $rows = [];

    foreach (['de', 'en'] as $locale) {
        $page = surfacePage($anna, route('hyper.match', $match, false), $locale);
        // The statistics open after the end screen: skipped, so the end screen is on top.
        BrowserWait::until($page, '() => window.hyperStats?.state().open', 10_000);
        $page->locator('[data-test=hyper-stats-skip]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=hyper-stats]").hidden && !document.querySelector("[data-test=hyper-end]").hidden', 5_000);

        foreach (HYPER_SURFACE_SIZES as [$width, $height]) {
            $row = surfaceMeasure($page, '[data-test=hyper-rated]', $width, $height);
            $row['end'] = surfaceMeasure($page, '[data-test=hyper-end] .panel', $width, $height)['box'];
            // The chip's row must not push the top bar into the roster under it (both fixed).
            $row['bars'] = $page->evaluate('() => [Math.round(document.querySelector("#topbar").getBoundingClientRect().bottom), Math.round(document.querySelector("#roster").getBoundingClientRect().top)]');
            $rows[] = ['locale' => $locale, 'page' => 'match', ...$row];
        }

        // The leaderboards: each chip's number and word one space apart (P6: the flex gap added to the space).
        $page->setViewportSize(1440, 900);
        $page->locator('[data-test=hyper-stats-open]')->click();
        BrowserWait::until($page, '() => window.hyperStats.state().open', 3_000);
        $page->locator('[data-test=hyper-stats-tab]:nth-child(2)')->click();
        BrowserWait::until($page, '() => window.hyperStats.state().page === "leaders" && document.querySelectorAll("[data-leader]").length === 5', 5_000);
        $page->evaluate('() => new Promise((done) => setTimeout(done, 1500))');
        $gaps = $page->evaluate(<<<'JS'
            () => [...document.querySelectorAll('.chip .st-num')].map((num) => {
                const text = num.nextSibling;
                if (!text || text.nodeType !== 3) return null;
                const range = document.createRange();
                range.setStart(text, 1);
                range.setEnd(text, 2);
                return Math.round((range.getBoundingClientRect().left - num.getBoundingClientRect().right) * 10) / 10;
            }).filter((gap) => gap !== null)
            JS);
        $space = $page->evaluate('() => { const c = document.createElement("canvas").getContext("2d"); c.font = "700 12px " + getComputedStyle(document.querySelector(".chip")).fontFamily; return Math.round(c.measureText(" ").width * 10) / 10; }');
        $rows[] = ['locale' => $locale, 'page' => 'leaders', 'gaps' => $gaps, 'space' => $space];
        expect($gaps)->not->toBeEmpty();

        foreach ($gaps as $gap) {
            expect(abs($gap - $space))->toBeLessThanOrEqual(1.5, "gap {$gap} px against a space of {$space} px ({$locale})");
        }

        $page->locator('[data-test=hyper-stats-skip]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=hyper-stats]").hidden', 3_000);

        // Statistics at both sizes in this language: no overflow.
        foreach (HYPER_SURFACE_SIZES as [$width, $height]) {
            $page->setViewportSize($width, $height);
            $page->locator('[data-test=hyper-stats-open]')->click();
            BrowserWait::until($page, '() => window.hyperStats.state().open', 3_000);
            $rows[] = ['locale' => $locale, 'page' => 'stats', ...surfaceMeasure($page, '[data-test=hyper-stats] .st-box', $width, $height)];
            $page->locator('[data-test=hyper-stats-skip]')->click();
            BrowserWait::until($page, '() => document.querySelector("[data-test=hyper-stats]").hidden', 3_000);
        }

        expect(surfaceErrors($page))->toBe([]);

        // The replay in this language at both sizes.
        surfaceGo($page, route('hyper.replay', $match, false));

        foreach (HYPER_SURFACE_SIZES as [$width, $height]) {
            $rows[] = ['locale' => $locale, 'page' => 'replay', ...surfaceMeasure($page, '#replay-bar', $width, $height)];
        }

        expect(surfaceErrors($page))->toBe([]);
    }

    // The rematch on Anna's end screen (English): open with its end time; Bert declines, and her screen says so at
    // once (`hyper.rematch` over Reverb) and after a reload, the buttons gone.
    // The statistics opened once on this browser already (stats.js remembers it): the end screen is on top.
    surfaceGo($page, route('hyper.match', $match, false));
    BrowserWait::until($page, '() => !document.querySelector("[data-test=hyper-end]").hidden && document.querySelector("[data-test=hyper-stats]").hidden', 10_000);
    $asked = $page->evaluate('() => document.querySelector("[data-test=hyper-rematch-note]").innerText.trim()');
    app(HyperLobby::class)->declineRematch($match, $bert);

    // The push is reported, not required: the reload below is the assertion.
    try {
        BrowserWait::until($page, '() => document.querySelector("[data-test=hyper-rematch]").hidden', 10_000);
    } catch (Throwable) {
        // Not pushed within 10 s.
    }

    $pushed = $page->evaluate('() => document.querySelector("[data-test=hyper-rematch-note]").innerText.trim()');
    surfaceGo($page, route('hyper.match', $match, false));
    BrowserWait::until($page, '() => !document.querySelector("[data-test=hyper-end]").hidden && document.querySelector("[data-test=hyper-stats]").hidden', 10_000);
    $declined = $page->evaluate('() => [document.querySelector("[data-test=hyper-rematch-note]").innerText.trim(), document.querySelector("[data-test=hyper-rematch]").hidden, document.querySelector("[data-test=hyper-rematch-decline]").hidden]');

    fwrite(STDERR, "\nhyper match measured: ".json_encode($rows, JSON_UNESCAPED_UNICODE)."\nrematch: ".json_encode([$asked, $pushed, $declined], JSON_UNESCAPED_UNICODE)."\n");

    foreach (array_filter($rows, fn (array $row): bool => isset($row['size'])) as $row) {
        $where = $row['locale'].' '.$row['page'].' '.$row['size'];
        expect($row['box'])->not->toBeNull($where)
            ->and($row['scroll'][0])->toBe($row['scroll'][1], $where)
            ->and($row['inside'])->toBeTrue($where);

        if ($row['page'] === 'match') {
            // The chip on a phone too, whole and inside the screen.
            expect($row['clipped'])->toBe([], $where)->and($row['box'][2])->toBeGreaterThan(20, $where);
        }

        // Below 820 px the roster sits under the wrapped top bar (hyper.css): the chip's row must leave it clear. Wider,
        // the roster is a column of its own beside the top bar's middle (measured 2026-10-09: bar 103, roster 92 at 1440).
        if ($row['page'] === 'match' && str_starts_with($row['size'], '390x')) {
            expect($row['bars'][0])->toBeLessThanOrEqual($row['bars'][1], $where);
        }
    }

    expect($asked)->toStartWith('Open until ')
        ->and($declined)->toBe(['Bert Pleb declined the rematch.', true, true])
        ->and(surfaceErrors($page))->toBe([]);

    surfaceControl($page);
});
