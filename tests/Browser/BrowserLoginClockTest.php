<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| A travelled clock must not log the browser out
|--------------------------------------------------------------------------
|
| Laravel stamps the session cookie's Expires with the app's clock, Chromium
| judges it by the wall clock. A test that travels to a day more than
| session.lifetime behind "now" used to get a cookie that was dead on
| arrival: the login and the chosen locale were gone on the next page, and a
| dozen week tests went red the day after the date they pin
| (2026-10-09, b80beef0). tests/Pest.php makes it a browser session cookie
| for browser tests; this holds that: a day far in the past, a login, the
| locale switch, an account page that requires the login.
|
*/

test('a login and a chosen locale survive a clock set days behind the wall clock', function () {
    // The database sessions the week tests use (with the array driver the login happened to survive; unexplained).
    config(['session.driver' => 'database']);
    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
    $this->freezeTime();
    $this->travelTo(CarbonImmutable::parse('2020-01-01 12:00:00'));
    $user = User::factory()->create();

    $page = visit(BrowserLogin::url($user))->page();
    $page->goto(ComputeUrl::from(route('locale.switch', 'de', false)));
    $page->goto(ComputeUrl::from(route('gaming.edit', [], false)));
    BrowserWait::until($page, '() => document.documentElement.lang === "de"', 10_000);

    expect($page->evaluate('() => location.pathname'))->toBe('/settings/gaming')
        ->and($page->evaluate('() => document.documentElement.lang'))->toBe('de');
});
