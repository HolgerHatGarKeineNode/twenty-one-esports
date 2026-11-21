<?php

use App\Models\Admin;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| /mining in a live season without a block, in the browser
|--------------------------------------------------------------------------
|
| Plan "mining-page-clarity" (P2 to P5): an admin reads the Season page in
| German at 390 and 1440 px, every disclosure closed. The primary button ends
| inside the first screen (above the tab bar on a phone), nothing is wider
| than the window or than its card, the page stays within 2532 px at 390 and
| 2250 px at 1440, no module is taller than one phone screen, "How mining
| works" opens the reference at the end, and the console, the uncaught errors
| and every answer >= 400 stay empty on the first load and after a Livewire
| round-trip, with a positive control on the same page.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));

    config(['session.driver' => 'database', 'esports.chess.rated_queue' => true, 'esports.board_games.rated_queue' => true]);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
});

/** The page's geometry: the fold, the primary button, the height, every module and every card that runs wider than itself. */
const MINING_LIVE_READ = <<<'JS'
() => {
    const root = document.querySelector('[data-test=mining]');
    const probe = document.createElement('div');
    probe.style.cssText = 'position:fixed;bottom:0;height:var(--tabbar-h);width:1px;visibility:hidden';
    document.body.appendChild(probe);
    const tabbar = Math.round(probe.getBoundingClientRect().height);
    probe.remove();
    const primary = document.querySelector('[data-test=mining-primary]')?.getBoundingClientRect();
    const shown = (el) => el.checkVisibility();
    const modules = [...root.children].filter(shown).map((el) => [el.dataset.test || el.tagName.toLowerCase(), Math.round(el.getBoundingClientRect().height)]);
    // The parts of the two-column block, for the report: every card in it with its height.
    const parts = [...root.querySelectorAll(':scope > .grid > div > *')].filter(shown).map((el) => [el.dataset.test || el.tagName.toLowerCase(), Math.round(el.getBoundingClientRect().height)]);
    const wide = [...root.querySelectorAll('.rv-card, details, section, article')].filter(shown)
        .filter((el) => el.scrollWidth - el.clientWidth > 0)
        .map((el) => (el.dataset.test || el.tagName.toLowerCase()) + ' ' + el.scrollWidth + '/' + el.clientWidth);
    return {
        lang: document.documentElement.lang,
        state: root.dataset.state,
        fold: window.innerHeight - tabbar,
        tabbar,
        primary: primary ? [Math.round(primary.top), Math.round(primary.bottom), Math.round(primary.height)] : null,
        height: Math.max(document.documentElement.scrollHeight, document.body.scrollHeight),
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        open: [...root.querySelectorAll('details[open]')].map((el) => el.dataset.test || el.id),
        empty: root.querySelectorAll('[data-test=chain-empty]').length,
        modules,
        parts,
        wide,
    };
}
JS;

test('in German an admin reads the live season without a block: the primary button in the first screen, no overflow, the page short, every module within one phone screen, and the page quiet', function (int $width, int $height, int $maxHeight) {
    $admin = User::factory()->create(['name' => 'Erdöpfökasbrot', 'timezone' => 'UTC']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    openSeason();

    $page = visit(BrowserLogin::url($admin))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('locale.switch', 'de', false)));
    $page->goto(ComputeUrl::from(route('mining', [], false)));
    $page->waitForLoadState('networkidle');
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Alpine !== undefined && document.fonts.status === "loaded"', 10_000);

    // Positive control: a thrown error and a failed answer reach the collector on this very page.
    $page->evaluate('() => { setTimeout(() => { throw new Error("mining live positive control"); }); fetch("/mining-live-positive-control-missing"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("mining live positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
    $page->evaluate('() => { window.__errors = []; performance.clearResourceTimings(); }');
    // The first load, less the control: a reload with the collector emptied by it.
    $page->reload();
    $page->waitForLoadState('networkidle');
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Alpine !== undefined && document.fonts.status === "loaded"', 10_000);
    $page->evaluate('() => new Promise((r) => setTimeout(r, 300))');

    $read = $page->evaluate(MINING_LIVE_READ);
    $read['first load'] = [...$page->evaluate('() => window.__errors'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)];

    $page->evaluate('() => { window.__errors = []; performance.clearResourceTimings(); window.Livewire?.all().forEach((c) => c.$wire.$refresh()); }');
    $page->waitForLoadState('networkidle');
    $page->evaluate('() => new Promise((r) => setTimeout(r, 300))');
    $read['round-trip'] = [...$page->evaluate('() => window.__errors'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)];

    // "So funktioniert Mining" in the hero opens the reference at the end.
    $page->evaluate('() => document.querySelector("[data-test=mining-how]").click()');
    $page->evaluate('() => new Promise((r) => setTimeout(r, 300))');
    $read['reference open'] = (bool) $page->evaluate('() => document.getElementById("season-reference").open');
    $read['after open'] = [...$page->evaluate('() => window.__errors'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)];

    fwrite(STDERR, "mining live de {$width}x{$height}: ".json_encode($read, JSON_UNESCAPED_UNICODE).PHP_EOL);

    $tallest = max(array_column($read['modules'], 1));

    expect($read['lang'])->toBe('de')
        ->and($read['state'])->toBe('live')
        ->and($read['empty'])->toBe(1)
        ->and($read['open'])->toBe([])
        ->and($read['primary'])->not->toBeNull()
        ->and($read['primary'][1])->toBeLessThanOrEqual($read['fold'], 'the primary button ends below the first screen')
        ->and($read['overflow'])->toBe(0)
        ->and($read['wide'])->toBe([])
        ->and($read['height'])->toBeLessThanOrEqual($maxHeight)
        ->and($width < 1024 ? $tallest : 0)->toBeLessThanOrEqual(844, 'a module is taller than one phone screen')
        ->and($read['reference open'])->toBeTrue()
        ->and($read['first load'])->toBe([])
        ->and($read['round-trip'])->toBe([])
        ->and($read['after open'])->toBe([]);
})->with([
    'phone 390' => [390, 844, 2532],
    'desktop 1440' => [1440, 900, 2250],
]);
