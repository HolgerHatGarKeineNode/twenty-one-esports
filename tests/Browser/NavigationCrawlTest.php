<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Navigation crawl (P16): no orphan pages, for any role, at any width
|--------------------------------------------------------------------------
|
| A crawler in the browser, one per role (guest, player, captain, organizer,
| admin). It opens a page, reads every same-origin link a person can reach
| there, and walks on breadth first. A link counts when it is visible, or
| when it sits behind a visible opener: a Flux dropdown, the mobile menu or a
| <details>. Depth 1 is every link outside <main> (header, menus, footer),
| the chrome that is on every page; depth 2 is a link on such a page, and so
| on. One URL per route is opened (the first one found). Every page is read
| at 1440, 1024 and 375 px wide, each width its own walk: a link only the
| desktop shows does not count for the phone.
|
| The rule (plan P16, DoD 2): every page a role needs is at most 2 clicks
| from the chrome, and a page below a detail (a match room, a tournament's
| draw, a dispute) at most 2 clicks from the overview that owns it, which is
| depth 3. NAV_PAGES below is the inventory: a route that is neither in it
| nor excluded with a reason in NAV_NOT_PAGES fails the test, so a new page
| has to say who needs it and where it is linked.
|
| Every opened page is also measured: console.error, uncaught errors,
| rejected promises, fetch/XHR and resource answers >= 400 (BrowserConsole),
| with its positive control at the end of this file.
|
| NAV_INVENTORY=<file> writes the measured inventory as JSON,
| NAV_SHOTS=<dir> the English screenshots.
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
});

test('every page a role needs is at most two clicks from the chrome or its overview, at every width, and every crawled page is clean', function () {
    $world = navWorld();
    $inventory = [];
    $failures = [];

    // A route that is neither in the inventory nor excluded fails: every new page says who needs it.
    $known = array_merge(array_keys(NAV_PAGES), array_keys(NAV_NOT_PAGES));
    foreach (Route::getRoutes() as $route) {
        $name = $route->getName();
        if (! in_array('GET', $route->methods(), true) || $route->isFallback || $name === null || str_starts_with($name, 'testing.')) {
            continue;
        }
        foreach (NAV_VENDOR_PREFIXES as $prefix) {
            if (str_starts_with($route->uri(), $prefix)) {
                continue 2;
            }
        }
        if (! in_array($name, $known, true)) {
            $failures[] = "route {$name} ({$route->uri()}) is not in NAV_PAGES or NAV_NOT_PAGES";
        }
    }

    foreach ($world['users'] as $role => $user) {
        $started = microtime(true);
        $crawl = navCrawl(navPage($user), array_keys(NAV_VIEWS));
        $inventory[$role] = $crawl['routes'];
        fwrite(STDERR, sprintf("\n[nav-crawl] %s: %d pages opened, routes per width %s, %.1f s", $role, $crawl['pages'],
            json_encode(array_map('count', $crawl['routes'])), microtime(true) - $started));

        foreach ($crawl['problems'] as $problem) {
            $failures[] = "{$role}: {$problem}";
        }

        foreach ($crawl['routes'] as $width => $routes) {
            foreach (NAV_PAGES as $name => $rule) {
                if (! in_array($role, $rule['roles'], true)) {
                    continue;
                }
                $depth = $routes[$name]['depth'] ?? null;
                if ($depth === null) {
                    $failures[] = "{$role} @{$width}px: {$name} is an orphan (not reached within 3 clicks)";
                } elseif ($depth > $rule['max']) {
                    $failures[] = "{$role} @{$width}px: {$name} takes {$depth} clicks, at most {$rule['max']} allowed";
                }
            }
        }
    }

    $file = getenv('NAV_INVENTORY');
    if (is_string($file) && $file !== '') {
        File::put($file, json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    expect($failures)->toBe([]);
});
