<?php

use App\Models\Tournament;
use App\Support\Tournaments\CasualCups;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| EU and US casual cups side by side (user, 2026-09-28)
|--------------------------------------------------------------------------
|
| The league opens a Rocket League cup per region. The game page lists both,
| the EU cup's own page names the US one; a guest's browser in New York
| sees each start on its own clock. Measured at 375 and 1440: nothing wider
| than the window, every row a 44 px target, EU and US next to each other
| from lg and stacked below; the console and the answers clean, with a
| positive control.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    Queue::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.casual_cups.enabled' => ['rocket-league']]);
    // Monday 5 October 2026: the EU cup starts Saturday 20:00 Berlin (14:00 New York), the US cup Saturday 20:00 New York.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));
    app(CasualCups::class)->tick();
});

function cupRegionsPage(string $path, int $width, int $height, string $ready): Page
{
    $page = visit('/robots.txt')->withTimezone('America/New_York')->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($path));
    BrowserWait::until($page, '() => window.Alpine && document.querySelector("'.$ready.'") !== null && document.fonts.status === "loaded"', 10_000);

    return $page;
}

function cupRegionsShot(Page $page, string $name): void
{
    $dir = getenv('CASUAL_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/**
 * The cup rows: name, start as shown, box; and the page's overflow.
 *
 * @return array{overflow: int, rows: list<array{name: string, start: string, left: int, right: int, top: int, bottom: int, height: int}>, width: int}
 */
function cupRegionsRows(Page $page): array
{
    return $page->evaluate('() => ({
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        width: window.innerWidth,
        rows: [...document.querySelectorAll("[data-test=cup-mention]")].filter((el) => el.checkVisibility()).map((el) => {
            const r = el.getBoundingClientRect();
            const name = [...el.querySelectorAll("span")].map((s) => s.innerText).find((t) => t.includes("Casual Cup")) || "";
            const start = (el.querySelector("[data-test=cup-mention-start]")?.innerText || "").replace(/\s+/g, " ").trim();
            return { name, start, left: Math.round(r.left), right: Math.round(r.right), top: Math.round(r.top), bottom: Math.round(r.bottom), height: Math.round(r.height) };
        }),
    })');
}

function cupRegionsControl(Page $page): void
{
    $page->evaluate('() => { setTimeout(() => { throw new Error("probe-throw"); }); return fetch("/__test/server-error"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("probe-throw")) && window.__errors.some((e) => e.startsWith("500 ")) && performance.getEntries().some((e) => e.name.includes("/__test/server-error") && e.responseStatus === 500)', 5_000);
    $page->evaluate('() => { window.__errors = []; performance.clearResourceTimings(); }');
}

test('the game page lists the EU and the US cup, each start on the viewer\'s clock, side by side from lg and stacked on a phone', function () {
    $wide = cupRegionsPage('/games/rocket-league', 1440, 900, '[data-test=cup-mentions]');
    cupRegionsControl($wide);
    $desk = cupRegionsRows($wide);
    cupRegionsShot($wide, 'cup-regions-game-1440');

    $narrow = cupRegionsPage('/games/rocket-league', 375, 812, '[data-test=cup-mentions]');
    $phone = cupRegionsRows($narrow);
    cupRegionsShot($narrow, 'cup-regions-game-375');

    foreach ([$desk, $phone] as $measured) {
        expect($measured['overflow'])->toBe(0)
            ->and(array_column($measured['rows'], 'name'))->toBe(['Rocket League Casual Cup EU #1', 'Rocket League Casual Cup US #1'])
            // The browser's own zone (New York), not the league's: 20:00 Berlin is 14:00 there.
            ->and(array_column($measured['rows'], 'start'))->toBe(['Sat, Oct 10, 2:00 PM EDT', 'Sat, Oct 10, 8:00 PM EDT'])
            ->and(collect($measured['rows'])->every(fn (array $row): bool => $row['left'] >= 0 && $row['right'] <= $measured['width'] && $row['height'] >= 44))->toBeTrue();
    }

    [$eu, $us] = $desk['rows'];
    [$euPhone, $usPhone] = $phone['rows'];

    expect($us['top'])->toBe($eu['top'])
        ->and($us['left'])->toBeGreaterThan($eu['right'])
        ->and($usPhone['top'])->toBeGreaterThanOrEqual($euPhone['bottom']);

    foreach ([$wide, $narrow] as $page) {
        expect($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }
});

test('the EU cup\'s page names the US cup with its start on the viewer\'s clock, at 1440 and 375', function () {
    $eu = Tournament::query()->where('cup_open_series', 'rocket-league-eu')->sole();

    $wide = cupRegionsPage(route('tournaments.show', $eu, false), 1440, 900, '[data-test=cup-other-regions] [data-test=cup-mention]');
    cupRegionsControl($wide);
    $desk = cupRegionsRows($wide);
    $title = $wide->evaluate('() => document.querySelector("#t-name").innerText');
    cupRegionsShot($wide, 'cup-regions-page-1440');

    $narrow = cupRegionsPage(route('tournaments.show', $eu, false), 375, 812, '[data-test=cup-other-regions] [data-test=cup-mention]');
    $phone = cupRegionsRows($narrow);
    cupRegionsShot($narrow, 'cup-regions-page-375');

    expect($title)->toBe('Rocket League Casual Cup EU #1');

    foreach ([$desk, $phone] as $measured) {
        expect($measured['overflow'])->toBe(0)
            ->and($measured['rows'])->toHaveCount(1)
            ->and($measured['rows'][0]['name'])->toBe('Rocket League Casual Cup US #1')
            ->and($measured['rows'][0]['start'])->toBe('Sat, Oct 10, 8:00 PM EDT')
            ->and($measured['rows'][0]['right'])->toBeLessThanOrEqual($measured['width'])
            ->and($measured['rows'][0]['height'])->toBeGreaterThanOrEqual(44);
    }

    foreach ([$wide, $narrow] as $page) {
        expect($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }
});
