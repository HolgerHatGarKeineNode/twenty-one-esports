<?php

use App\Models\StackerRun;
use App\Models\User;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\StackerRuns;
use Carbon\CarbonImmutable;
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
| Blockfill in the shell (plan "Blockfill", P6)
|--------------------------------------------------------------------------
|
| Switched on, Blockfill is a game like the others: its tile on home, its
| row on /play and its card in the game hub, each leading to /blockfill;
| and /blockfill and scores/blockfill share Blockfill's own context bar (from
| lg) and tab bar (below lg): Play, Leaderboard, Rules. In English and German
| at 375 and 1440 px. Measured: nothing wider than the window, the tile, the
| row and the bars inside it. Console, uncaught errors and every answer
| >= 400 are collected on every page (BrowserConsole) and stay empty; a
| positive control shows the collector sees a throw and a failed answer.
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
    $this->freezeTime();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00'));

    // This week's board with two players, so scores/blockfill and the game page show a running week.
    foreach (['Hal Finney Fan' => 2900, 'Ada Blockspace' => 3000] as $name => $ticks) {
        $run = StackerRun::factory()->for(User::factory()->create(['name' => $name]))->verified($ticks)->create(['submitted_at' => now()->subHour(), 'week' => StackerRuns::weekOf(now())]);
        app(BlockfillWeeks::class)->record($run, now());
    }
    $this->player = User::factory()->create(['name' => 'Shell Walker']);
});

/** A page of `$user` in `$locale` at `$width` x `$height`, with the console collector installed before it loads. */
function blockfillShellPage(?User $user, string $locale, int $width, int $height): Page
{
    // A guest starts on the landing page without a login.
    $page = visit($user === null ? BrowserLogin::LANDING : BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));

    return $page;
}

/** Opens `$path`, waits for the shell, and checks the page: the document's language, no overflow, a clean console. */
function blockfillShellGo(Page $page, string $path, string $locale): void
{
    $page->goto(ComputeUrl::from($path));
    BrowserWait::until($page, '() => document.querySelector("[data-test=tab-bar]") !== null && document.readyState === "complete"', 10_000);
    [$scroll, $client] = $page->evaluate(BrowserConsole::WIDTHS);

    expect($page->evaluate('() => document.documentElement.lang'))->toBe($locale)
        ->and($scroll)->toBeLessThanOrEqual($client, "{$path} is wider than the window")
        ->and($page->evaluate('() => window.__errors'))->toBe([], "console on {$path}")
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], "answers on {$path}");
}

/** The box of the first element matching `$selector`, scrolled into view: [left, right, top, bottom]. */
const BLOCKFILL_SHELL_BOX = <<<'JS'
    (selector) => {
        const el = document.querySelector(selector);
        if (! el) { return null; }
        el.scrollIntoView({ block: 'center' });
        const r = el.getBoundingClientRect();
        return [Math.round(r.left), Math.round(r.right), Math.round(r.top), Math.round(r.bottom)];
    }
    JS;

test('Blockfill is on home, on /play and in the game hub, each leading to /blockfill', function (string $locale, int $width, int $height) {
    $page = blockfillShellPage($this->player, $locale, $width, $height);
    $game = route('stacker.play');

    // Home: its tile among the games, its cover and main action leading to the game.
    blockfillShellGo($page, route('home', [], false), $locale);
    $tile = $page->evaluate(BLOCKFILL_SHELL_BOX, '[data-test=play-tile][data-game=blockfill]');
    $tileLinks = $page->evaluate('() => [...document.querySelectorAll("[data-test=play-tile][data-game=blockfill] a")].map((a) => a.href)');
    $cover = $page->evaluate('() => { const img = document.querySelector("[data-test=play-tile][data-game=blockfill] img"); return img ? [img.currentSrc, img.naturalWidth] : null; }');
    fwrite(STDERR, "blockfill shell home {$locale} {$width}: ".json_encode([$tile, $cover]).PHP_EOL);
    expect($tile)->not->toBeNull()
        ->and($tile[0])->toBeGreaterThanOrEqual(0)->and($tile[1])->toBeLessThanOrEqual($width)
        ->and(array_unique($tileLinks))->toBe([$game])
        ->and($cover[0])->toContain('/images/games/blockfill-')
        ->and($cover[1])->toBeGreaterThan(0);
    shellShot($page, "blockfill-shell-home-{$locale}-{$width}");

    // /play: its row, first button to the game, then the leaderboard and the rules.
    blockfillShellGo($page, route('play', [], false), $locale);
    $row = $page->evaluate(BLOCKFILL_SHELL_BOX, '[data-test=play-game-blockfill]');
    $buttons = $page->evaluate('() => [...document.querySelectorAll("[data-test=play-game-blockfill] .flex.flex-wrap.items-center.gap-2 a")].map((a) => [a.innerText.trim(), a.getAttribute("href")])');
    fwrite(STDERR, "blockfill shell play {$locale} {$width}: ".json_encode([$row, $buttons]).PHP_EOL);
    expect($row)->not->toBeNull()
        ->and($row[0])->toBeGreaterThanOrEqual(0)->and($row[1])->toBeLessThanOrEqual($width)
        ->and(array_column($buttons, 1))->toBe([$game, route('scores.show', 'blockfill'), route('rules').'#blockfill'])
        ->and($buttons[1][0])->toBe($locale === 'de' ? 'Leaderboard' : 'Leaderboard');
    shellShot($page, "blockfill-shell-play-{$locale}-{$width}");

    // The game hub: its card with the game page.
    $page->locator($width >= 1024 ? '[data-test=games-menu]' : '[data-test=mobile-games-menu]')->click();
    BrowserWait::until($page, '() => { const hub = document.querySelector("#game-hub"); return hub && hub.getClientRects().length > 0 && [...hub.querySelectorAll("a")].some((a) => a.href.endsWith("/blockfill")); }', 5_000);
    $hubCard = $page->evaluate('() => { const a = [...document.querySelectorAll("#game-hub a")].find((a) => a.href.endsWith("/blockfill")); const r = a.getBoundingClientRect(); return [Math.round(r.left), Math.round(r.right), a.innerText.trim()]; }');
    expect($hubCard[0])->toBeGreaterThanOrEqual(0)->and($hubCard[1])->toBeLessThanOrEqual($width)
        ->and($page->evaluate('() => window.__errors'))->toBe([]);
    shellShot($page, "blockfill-shell-hub-{$locale}-{$width}");
})->with([
    'en 375' => ['en', 375, 812],
    'en 1440' => ['en', 1440, 900],
    'de 375' => ['de', 375, 812],
    'de 1440' => ['de', 1440, 900],
]);

test('/blockfill and scores/blockfill share Blockfill\'s context bar and tab bar', function (string $locale, int $width, int $height) {
    $page = blockfillShellPage($this->player, $locale, $width, $height);
    $bars = [];

    foreach (['game' => route('stacker.play', [], false), 'scores' => route('scores.show', 'blockfill', false)] as $key => $path) {
        blockfillShellGo($page, $path, $locale);
        $bars[$key] = $page->evaluate(<<<'JS'
            () => {
                const visible = (el) => !! el && el.getClientRects().length > 0 && getComputedStyle(el).visibility !== "hidden";
                const ctx = document.querySelector('[data-test=context-bar]');
                const tabbar = document.querySelector('[data-test=tab-bar]');
                const box = (el) => { const r = el.getBoundingClientRect(); return [Math.round(r.left), Math.round(r.right)]; };
                return {
                    ctxGame: ctx.dataset.game,
                    ctxVisible: visible(ctx),
                    ctx: [...ctx.querySelectorAll('a')].map((a) => [a.dataset.test, a.getAttribute('href')]),
                    ctxBox: box(ctx),
                    tabVisible: visible(tabbar),
                    tabs: [...tabbar.querySelectorAll('a')].map((a) => [a.dataset.test, a.getAttribute('href')]),
                    tabBox: box(tabbar),
                };
            }
            JS);
        fwrite(STDERR, "blockfill shell bars {$key} {$locale} {$width}: ".json_encode($bars[$key]).PHP_EOL);
        shellShot($page, "blockfill-shell-{$key}-{$locale}-{$width}");
    }

    $expectedCtx = [['ctx-play', route('stacker.play')], ['ctx-leaderboard', route('scores.show', 'blockfill')], ['ctx-rules', route('rules').'#blockfill']];
    $expectedTabs = [['tab-play', route('stacker.play')], ['tab-ladder', route('scores.show', 'blockfill')], ['tab-tournaments', route('tournaments.index')]];

    foreach ($bars as $bar) {
        expect($bar['ctxGame'])->toBe('blockfill')
            ->and($bar['ctx'])->toBe($expectedCtx)
            ->and($bar['tabs'])->toBe($expectedTabs)
            // One of the two at each width: the context bar from lg, the tab bar below.
            ->and($bar['ctxVisible'])->toBe($width >= 1024)
            ->and($bar['tabVisible'])->toBe($width < 1024);
        $shown = $width >= 1024 ? $bar['ctxBox'] : $bar['tabBox'];
        expect($shown[0])->toBeGreaterThanOrEqual(0)->and($shown[1])->toBeLessThanOrEqual($width);
    }

    // Positive control: the collector sees a throw and a failed answer on this very page.
    $page->evaluate('() => { setTimeout(() => { throw new Error("blockfill shell positive control"); }); fetch("/blockfill/nothing-here"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("blockfill shell positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
})->with([
    'en 375' => ['en', 375, 812],
    'en 1440' => ['en', 1440, 900],
    'de 375' => ['de', 375, 812],
    'de 1440' => ['de', 1440, 900],
]);

test('the page of a week: the Play button to /blockfill in the first screen, how a week works, no sign-up or invite row', function (string $locale, int $width, int $height, bool $guest = false, bool $empty = false) {
    // An empty week: the next one, opened before anybody played it (every Monday until the first verified run).
    if ($empty) {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00:00'));
        app(BlockfillWeeks::class)->open();
    }
    $page = blockfillShellPage($guest ? null : $this->player, $locale, $width, $height);
    $week = app(BlockfillWeeks::class)->current();
    $suffix = ($guest ? '-guest' : '').($empty ? '-empty' : '');
    blockfillShellGo($page, route('tournaments.show', $week, false), $locale);

    // [left, right, bottom, href, text, the top of what covers the window's bottom: the tab bar below lg, else the window's edge]
    $play = $page->evaluate('() => { const a = document.querySelector("[data-test=to-blockfill]"); const r = a.getBoundingClientRect(); const bar = document.querySelector("[data-test=tab-bar]"); const floor = bar && bar.getClientRects().length > 0 ? bar.getBoundingClientRect().top : window.innerHeight; return [Math.round(r.left), Math.round(r.right), Math.round(r.bottom), a.getAttribute("href"), a.innerText.trim(), Math.round(floor)]; }');
    $how = $page->evaluate('() => document.querySelector("[data-test=how-it-works]").innerText');
    fwrite(STDERR, "blockfill week page {$locale} {$width}: ".json_encode($play).PHP_EOL);

    expect($play[0])->toBeGreaterThanOrEqual(0)->and($play[1])->toBeLessThanOrEqual($width)
        // Whole in the first screen, above the tab bar.
        ->and($play[2])->toBeLessThanOrEqual($play[5])
        ->and($play[3])->toBe(route('stacker.play'))
        ->and($play[4])->toBe($locale === 'de' ? 'Blockfill spielen' : 'Play Blockfill')
        ->and($how)->toContain($locale === 'de' ? 'Die Liga spielt deinen Lauf' : 'The league replays your run')
        ->and($page->evaluate('() => ["to-signup", "who-is-in", "places-meter", "tournament-share", "nostr-bar"].filter((t) => document.querySelector(`[data-test=${t}]`))'))->toBe([])
        ->and($page->evaluate('() => document.querySelector("h1").innerText.trim()'))->toBe(($locale === 'de' ? 'Blockfill Woche ' : 'Blockfill Week ').($empty ? '42' : '41').', 2026')
        ->and($page->evaluate('() => document.querySelector("[data-test=entries-empty]")?.innerText ?? null'))->toBe(! $empty ? null
            : ($locale === 'de' ? 'Diese Woche noch kein geprüfter Lauf. Spiel den ersten.' : 'No verified run yet this week. Play the first one.'));

    shellShot($page, "blockfill-week-page-{$locale}-{$width}{$suffix}");
    $page->evaluate('() => document.querySelector("[data-test=entries]").scrollIntoView({ block: "start" })');
    shellShot($page, "blockfill-week-page-entries-{$locale}-{$width}{$suffix}");
    $page->evaluate('() => document.querySelector("[data-test=how-it-works]").scrollIntoView({ block: "start" })');
    shellShot($page, "blockfill-week-page-how-{$locale}-{$width}{$suffix}");
    expect($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
})->with([
    'en 375' => ['en', 375, 812],
    'en 1440' => ['en', 1440, 900],
    'de 375' => ['de', 375, 812],
    'de 1440' => ['de', 1440, 900],
    // The guest's "New here?" banner sits above the page: the button stays above the tab bar.
    'guest en 375' => ['en', 375, 812, true],
    'guest de 375' => ['de', 375, 812, true],
    'empty week en 375' => ['en', 375, 812, false, true],
    'empty week en 1440' => ['en', 1440, 900, false, true],
    'empty week guest de 375' => ['de', 375, 812, true, true],
]);
