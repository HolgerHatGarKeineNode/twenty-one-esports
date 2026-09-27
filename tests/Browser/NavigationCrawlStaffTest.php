<?php

use Illuminate\Support\Facades\Http;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Navigation crawl (P16), second half: organizer and admin
|--------------------------------------------------------------------------
|
| The same walk and the same rule as tests/Browser/NavigationCrawlTest.php
| (every page a role needs at most 2 clicks from the chrome, depth 3 below a
| detail page, at 1440, 1024 and 375 px, every crawled page clean), for the
| two roles with the most pages. Split off so each file stays inside one
| shard's minute (scripts/test-browser.sh): all five roles in one file took
| 71 s on 2026-09-27.
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

test('every page an organizer or admin needs is at most two clicks from the chrome or its overview, at every width, and every crawled page is clean', function () {
    expect(navCrawlRoles(['organizer', 'admin']))->toBe([]);
});
