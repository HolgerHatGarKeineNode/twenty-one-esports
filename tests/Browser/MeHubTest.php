<?php

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| "Your page", /me (P30)
|--------------------------------------------------------------------------
|
| A player with something in every part (tests/Support/me.php) and a
| brand-new one, at 1440 and 375, and German at 375: nothing wider than the
| window, no target under 44 px, no text cut, the last results as one row of
| blocks from lg and a sideways chain on a phone, the countdowns counting,
| and the console and the answers clean, with a positive control.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
});

function meHubPage(User $user, int $width, int $height): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from('/me'));
    BrowserWait::until($page, '() => window.Alpine && window.Livewire && document.querySelector("[data-test=me-hub]") !== null && document.fonts.status === "loaded"', 10_000);

    return $page;
}

function meHubShot(Page $page, string $name, ?string $element = null): void
{
    $dir = getenv('ME_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $element === null ? $page->screenshot(true, $name) : $page->screenshotElement($element, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/**
 * @return array<string, mixed>
 */
function meHubGeometry(Page $page): array
{
    return $page->evaluate('() => {
        const hub = document.querySelector("[data-test=me-hub]");
        const parts = [...hub.querySelectorAll("section")];
        const inside = parts.every((el) => { const r = el.getBoundingClientRect(); return r.left >= 0 && r.right <= window.innerWidth + 0.5; });
        const targets = [...hub.querySelectorAll("a[href], button")].filter((el) => el.checkVisibility() && getComputedStyle(el).display !== "inline");
        const small = targets.filter((el) => { const r = el.getBoundingClientRect(); return r.height < 44 || r.width < 44; }).map((el) => (el.dataset.test || el.innerText.trim().slice(0, 30)) + " " + Math.round(el.getBoundingClientRect().width) + "x" + Math.round(el.getBoundingClientRect().height));
        const clipped = [...hub.querySelectorAll("*")].filter((el) => el.checkVisibility() && el.children.length === 0 && el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).textOverflow !== "ellipsis" && getComputedStyle(el).overflowX === "visible").map((el) => el.dataset.test || el.tagName + ":" + el.innerText.slice(0, 20));
        const blocks = [...document.querySelectorAll("[data-test=me-result]")].map((el) => el.getBoundingClientRect());
        const chain = document.querySelector("[data-test=me-chain]");
        return {
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            inside, small, clipped,
            chainRows: new Set(blocks.map((r) => Math.round(r.top))).size,
            blockHeights: [...new Set(blocks.map((r) => Math.round(r.height)))],
            chainScrolls: chain ? chain.scrollWidth > chain.clientWidth : null,
            needs: [...document.querySelectorAll("[data-test=me-need]")].map((el) => Math.round(el.getBoundingClientRect().height)),
        };
    }');
}

function meHubControl(Page $page): void
{
    $page->evaluate('() => { setTimeout(() => { throw new Error("probe-throw"); }); return fetch("/__test/server-error"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("probe-throw")) && window.__errors.some((e) => e.startsWith("500 ")) && performance.getEntries().some((e) => e.name.includes("/__test/server-error") && e.responseStatus === 500)', 5_000);
    $page->evaluate('() => { window.__errors = []; performance.clearResourceTimings(); }');
}

test('a player with something in every part: needs, running and upcoming, ratings, the chain of results, clan, at 1440 and 375', function () {
    $player = meHubPlayer();

    $wide = meHubPage($player['me'], 1440, 900);
    meHubControl($wide);
    $desk = meHubGeometry($wide);
    // The countdowns count: the daily game's clock changes within two seconds.
    $clock = $wide->evaluate('() => document.querySelector("[data-test=me-need-clock]").innerText');
    BrowserWait::until($wide, '() => document.querySelector("[data-test=me-need-clock]").innerText !== '.json_encode($clock), 3_000);
    $order = $wide->evaluate('() => [...document.querySelectorAll("[data-test=me-hub] > section, [data-test=me-hub] > div > section, [data-test=me-hub] > [data-test=invite-module]")].map((el) => el.dataset.test)');
    meHubShot($wide, 'me-1440');
    meHubShot($wide, 'me-results-1440', '[data-test=me-results]');

    // A Livewire round trip (the countdown's refresh at zero) answers 200 and leaves the console quiet.
    $wide->evaluate('() => Livewire.first().$refresh()');
    BrowserWait::until($wide, '() => performance.getEntries().some((e) => e.name.includes("/update") && e.responseStatus === 200)', 5_000);

    $narrow = meHubPage($player['me'], 375, 812);
    $phone = meHubGeometry($narrow);
    meHubShot($narrow, 'me-375');

    expect($order)->toBe(['me-needs', 'me-going', 'me-ratings', 'me-results', 'follows-here', 'me-clan', 'me-looking', 'me-settings', 'invite-module'])
        ->and($desk)->toMatchArray(['overflow' => 0, 'inside' => true, 'small' => [], 'clipped' => [], 'chainRows' => 1, 'chainScrolls' => false])
        ->and($phone)->toMatchArray(['overflow' => 0, 'inside' => true, 'small' => [], 'clipped' => [], 'chainRows' => 1, 'chainScrolls' => true])
        // Every block of the chain the same height; the one card that needs the player at most 160 px.
        ->and($desk['blockHeights'])->toHaveCount(1)
        ->and($desk['needs'])->toHaveCount(1)
        ->and(max($desk['needs']))->toBeLessThanOrEqual(160);

    foreach ([$wide, $narrow] as $page) {
        expect($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }
});

test('a brand-new player gets the first steps at the top, in German at 375 too', function () {
    $new = User::factory()->create(['name' => 'Newbie']);
    $german = User::factory()->create(['name' => 'Neuling', 'locale' => 'de']);

    $wide = meHubPage($new, 1440, 900);
    meHubControl($wide);
    $desk = meHubGeometry($wide);
    $steps = $wide->evaluate('() => ({
        first: document.querySelector("[data-test=me-hub] > section").dataset.test,
        rows: new Set([...document.querySelectorAll("[data-test=me-step]")].map((el) => Math.round(el.getBoundingClientRect().top))).size,
        next: [...document.querySelectorAll("[data-test=me-step]")].map((el) => getComputedStyle(el).backgroundColor),
        height: Math.round(document.querySelector("[data-test=me-hub]").getBoundingClientRect().height),
    })');
    meHubShot($wide, 'me-new-1440');

    $de = meHubPage($german, 375, 812);
    $phone = meHubGeometry($de);
    $heading = $de->evaluate('() => document.querySelector("#me-steps-h").innerText');
    // No label breaks inside a word: each settings label stands on one line ("Benachrichtigungen" broke in two).
    $labelLines = $de->evaluate('() => [...document.querySelectorAll("[data-test=me-settings-label]")].map((el) => { const r = document.createRange(); r.selectNodeContents(el); return new Set([...r.getClientRects()].map((q) => Math.round(q.top))).size; })');
    meHubShot($de, 'me-new-de-375');

    expect($steps['first'])->toBe('me-steps')
        ->and($steps['rows'])->toBe(1)
        // One orange step: the first one.
        ->and(array_values(array_unique($steps['next'])))->toHaveCount(2)
        ->and($steps['next'][0])->toBe('rgb(247, 147, 26)')
        ->and($desk)->toMatchArray(['overflow' => 0, 'inside' => true, 'small' => [], 'clipped' => []])
        ->and($phone)->toMatchArray(['overflow' => 0, 'inside' => true, 'small' => [], 'clipped' => []])
        ->and($heading)->toBe('Deine ersten Schritte')
        ->and($labelLines)->toBe([1, 1, 1, 1]);

    foreach ([$wide, $de] as $page) {
        expect($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }
});
