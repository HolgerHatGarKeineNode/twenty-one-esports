<?php

use App\Models\User;
use App\Support\Nostr\NostrKeys;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The rules (P28) and the open protocol (P29)
|--------------------------------------------------------------------------
|
| From lg a sticky list of the sections that marks the one in view; below
| lg every section an accordion that an anchor opens. Measured at 1440 and
| 375, the rules in German at 375 too: nothing wider than the window, no
| target under 44 px, nothing cut; console and answers clean, with a
| positive control.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
});

function docPage(string $path, int $width, int $height, ?User $user = null): Page
{
    $page = visit($user ? BrowserLogin::url($user) : '/robots.txt')->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    // The server URL rewrite drops a fragment: put it back after.
    [$path, $fragment] = array_pad(explode('#', $path, 2), 2, null);
    $page->goto(ComputeUrl::from($path).($fragment === null ? '' : '#'.$fragment));
    BrowserWait::until($page, '() => window.Alpine && document.querySelector("[data-doc-section]") !== null && document.fonts.status === "loaded"', 10_000);

    return $page;
}

function docShot(Page $page, string $name, bool $fullPage = true): void
{
    $dir = getenv('CASUAL_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot($fullPage, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/**
 * @return array<string, mixed>
 */
function docGeometry(Page $page): array
{
    return $page->evaluate('() => {
        const main = document.querySelector("[data-test$=-page]");
        const targets = [...main.querySelectorAll("a[href], button")].filter((el) => el.checkVisibility());
        return {
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            small: targets.filter((el) => el.getBoundingClientRect().height < 44).map((el) => (el.dataset.test || el.innerText.trim().slice(0, 30)) + " " + Math.round(el.getBoundingClientRect().height)),
            clipped: [...main.querySelectorAll("*")].filter((el) => el.checkVisibility() && el.children.length === 0 && el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).overflowX === "visible" && getComputedStyle(el).textOverflow !== "ellipsis").map((el) => el.dataset.test || el.tagName + ":" + el.innerText.slice(0, 30)),
            outside: [...main.querySelectorAll("[data-doc-section]")].filter((el) => { const r = el.getBoundingClientRect(); return r.left < 0 || r.right > window.innerWidth + 0.5; }).map((el) => el.id),
        };
    }');
}

function docControl(Page $page): void
{
    $page->evaluate('() => { setTimeout(() => { throw new Error("probe-throw"); }); return fetch("/__test/server-error"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("probe-throw")) && window.__errors.some((e) => e.startsWith("500 ")) && performance.getEntries().some((e) => e.name.includes("/__test/server-error") && e.responseStatus === 500)', 5_000);
    $page->evaluate('() => { window.__errors = []; performance.clearResourceTimings(); }');
}

test('the rules: a sticky section list on desktop that follows the reader, accordions on a phone that an anchor opens', function () {
    $wide = docPage('/rules', 1440, 900);
    docControl($wide);
    $desk = docGeometry($wide);
    docShot($wide, 'rules-1440');

    $wide->locator('[data-test=doc-nav] a[href="#prizes"]')->click();
    BrowserWait::until($wide, '() => document.querySelector("[data-test=doc-nav] a[aria-current=location]")?.getAttribute("href") === "#prizes"', 5_000);
    $sticky = $wide->evaluate('() => { const r = document.querySelector("[data-test=doc-nav] ol").getBoundingClientRect(); return { top: Math.round(r.top), visible: r.bottom > 0 && r.top < innerHeight, heading: Math.round(document.querySelector("#prizes").getBoundingClientRect().top) }; }');
    docShot($wide, 'rules-prizes-1440', false);

    $narrow = docPage('/rules', 375, 812);
    $closed = $narrow->evaluate('() => [...document.querySelectorAll("[data-test=doc-body]")].filter((el) => el.checkVisibility()).length');
    $narrow->locator('#casual-1v1 [data-test=doc-toggle]')->click();
    BrowserWait::until($narrow, '() => document.querySelector("#casual-1v1 [data-test=doc-body]").checkVisibility()', 5_000);
    $phone = docGeometry($narrow);
    docShot($narrow, 'rules-375');

    $anchored = docPage('/rules#prizes', 375, 812);
    $opened = $anchored->evaluate('() => document.querySelector("#prizes [data-test=doc-body]").checkVisibility() && document.querySelector("#prizes [data-test=doc-toggle]").getAttribute("aria-expanded")');

    $german = docPage('/rules#casual-cups', 375, 812, User::factory()->create(['locale' => 'de']));
    $de = docGeometry($german);
    docShot($german, 'rules-de-375');

    expect($desk)->toMatchArray(['overflow' => 0, 'small' => [], 'clipped' => [], 'outside' => []])
        ->and($sticky['visible'])->toBeTrue()
        ->and($sticky['top'])->toBeLessThanOrEqual(120)
        ->and($sticky['heading'])->toBeLessThan(200)
        ->and($closed)->toBe(0)
        ->and($phone)->toMatchArray(['overflow' => 0, 'small' => [], 'clipped' => [], 'outside' => []])
        ->and($opened)->toBe('true')
        ->and($de)->toMatchArray(['overflow' => 0, 'small' => [], 'clipped' => [], 'outside' => []])
        ->and($german->evaluate('() => document.querySelector("#casual-cups-h").innerText.trim()'))->toBe('Casual Cups');

    foreach ([$wide, $narrow, $anchored, $german] as $page) {
        expect($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }
});

test('the open protocol: kinds, keys with their npub, relays and the nak commands, at 1440 and 375', function () {
    $league = new TestSigner;
    config(['esports.league.nsec' => $league->secret, 'esports.relays' => ['wss://league.example']]);

    $wide = docPage('/protocol', 1440, 900);
    docControl($wide);
    $desk = docGeometry($wide);
    $state = $wide->evaluate('() => ({
        kinds: document.querySelectorAll("[data-test=protocol-kinds] li").length,
        npubs: [...document.querySelectorAll("[data-test=protocol-npub]")].map((el) => el.innerText),
        command: document.querySelector("[data-test=protocol-command]").innerText,
    })');
    docShot($wide, 'protocol-1440');

    $narrow = docPage('/protocol#verify', 375, 812);
    $phone = docGeometry($narrow);
    docShot($narrow, 'protocol-375');

    expect($state['kinds'])->toBeGreaterThan(10)
        ->and($state['npubs'][0])->toBe(NostrKeys::hexToNpub($league->pubkey))
        ->and($state['command'])->toStartWith('nak req -k 31923 -a npub1')
        ->and($desk)->toMatchArray(['overflow' => 0, 'small' => [], 'clipped' => [], 'outside' => []])
        ->and($phone)->toMatchArray(['overflow' => 0, 'small' => [], 'clipped' => [], 'outside' => []]);

    foreach ([$wide, $narrow] as $page) {
        expect($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }
});
