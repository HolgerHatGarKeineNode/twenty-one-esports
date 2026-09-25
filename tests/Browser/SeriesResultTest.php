<?php

use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Models\Lineup;
use App\Models\NostrEvent;
use App\Models\SeriesMatch;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Rocket League result: submit and accept, two captains (plan "RL result")
|--------------------------------------------------------------------------
|
| Captain A (desktop) enters the goals per game in the match room and
| submits the final score through the dialog; captain B (375 px) sees the
| result and accepts it; both end on the win moment. A casual series, so
| nothing is signed and no event is stored. Same two-context session setup
| as tests/Browser/ChatAndDailyTest.php.
|
| The full-flow test below drives the two earlier steps, Challenge and
| Accept, through the same two UIs first (reviewer blocker: those two steps
| were only ever reached by factory state, never by a captain clicking
| through challenges/create and the match room's "Accept challenge").
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

const P6_COLLECTOR = <<<'JS'
    window.__errors = [];
    const push = (entry) => window.__errors.push(entry);
    const originalError = console.error;
    console.error = function (...args) { push('console.error: ' + args.map(String).join(' ')); originalError.apply(console, args); };
    window.addEventListener('error', (e) => push('error: ' + e.message));
    window.addEventListener('unhandledrejection', (e) => push('unhandledrejection: ' + String(e.reason)));
    const originalFetch = window.fetch;
    window.fetch = (...args) => originalFetch(...args).then((r) => { if (r.status >= 400) push(r.status + ' ' + r.url); return r; });
    JS;

function captainPage(User $user, string $to, int $width): Page
{
    $page = visit(route('testing.login', ['user' => $user, 'to' => $to]))->page();
    $page->context()->addInitScript(P6_COLLECTOR);
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from($to));

    return $page;
}

function enterGoals(Page $page, int $game, int $challenger, int $challenged): void
{
    $page->locator("[data-test=goals-{$game}-c]")->fill((string) $challenger);
    $page->locator("[data-test=goals-{$game}-c]")->press('Tab');
    $page->locator("[data-test=goals-{$game}-d]")->fill((string) $challenged);
    $page->locator("[data-test=goals-{$game}-d]")->press('Tab');
}

test('one captain challenges, the other accepts, then submits and accepts the final score', function () {
    $challenger = Lineup::factory()->ready()->create();
    $challenged = Lineup::factory()->ready()->create();
    $a = $challenger->clan->owner;
    $b = $challenged->clan->owner;

    $pageA = captainPage($a, route('challenges.create', [], false), 1440);
    BrowserWait::until($pageA, '() => document.querySelector("[data-test=pick-opponent]") !== null', 10_000);
    $pageA->locator('[data-test=pick-opponent]')->click();
    // "Challenge now" (start in esports.series.now_minutes) keeps the reply
    // deadline a few minutes out instead of tomorrow: enough real time for
    // the round trip below, short enough to travel() past below.
    $pageA->locator('[data-test=challenge-now]')->click();
    BrowserWait::until($pageA, '() => document.querySelector("[data-test=send-challenge]") && ! document.querySelector("[data-test=send-challenge]").disabled', 5_000);
    $pageA->locator('[data-test=send-challenge]')->click();
    BrowserWait::until($pageA, '() => document.querySelector("[data-test=withdraw-challenge]") !== null', 10_000);

    $match = SeriesMatch::query()->sole();
    expect($match->status)->toBe(SeriesStatus::Open)
        ->and($match->challenger_lineup_id)->toBe($challenger->id)
        ->and($match->challenged_lineup_id)->toBe($challenged->id);

    $room = route('matches.room', $match, false);
    $pageB = captainPage($b, $room, 375);
    BrowserWait::until($pageB, '() => document.querySelector("[data-test=accept-challenge]") !== null', 10_000);
    $pageB->locator('[data-test=accept-challenge]')->click();
    // The "Open" answer card, accept-challenge included, only renders while
    // the match is still open: its removal is the real signal the accept
    // round trip landed, unlike the goals inputs below (those render at
    // every status, only their "disabled" flips).
    BrowserWait::until($pageB, '() => document.querySelector("[data-test=accept-challenge]") === null', 10_000);

    expect($match->refresh()->status)->toBe(SeriesStatus::Accepted);

    // The match cannot be scored before its picked start passes: travel just
    // beyond it (LaravelHttpServer runs in-process, so Carbon::setTestNow()
    // reaches every request Playwright makes from here on).
    $this->travel((int) config('esports.series.now_minutes', 10) + 1)->minutes();

    $pageA->reload();
    BrowserWait::until($pageA, '() => !! document.querySelector("[data-test=open-submit]")', 10_000);

    enterGoals($pageA, 0, 3, 1);
    BrowserWait::until($pageA, '() => document.querySelector("[data-test=series-score]")?.innerText.trim() === "1 : 0"', 10_000);
    enterGoals($pageA, 1, 2, 0);
    BrowserWait::until($pageA, '() => document.querySelector("[data-test=series-score]")?.innerText.trim() === "2 : 0"', 10_000);

    $pageA->locator('[data-test=open-submit]')->click();
    BrowserWait::until($pageA, '() => document.querySelector("[data-test=submit-dialog]")?.offsetParent !== null', 5_000);
    $pageA->locator('[data-test=confirm-submit]')->click();
    BrowserWait::until($pageA, '() => document.querySelector("[data-test=waiting-for-ok]") !== null', 10_000);

    expect($match->refresh()->status)->toBe(SeriesStatus::Reported);

    $pageB->reload();
    BrowserWait::until($pageB, '() => document.querySelector("[data-test=reported-score]")?.innerText.includes("3 : 1, 2 : 0")', 10_000);
    $pageB->locator('[data-test=accept-result]')->click();
    BrowserWait::until($pageB, '() => document.querySelector("[data-test=win-moment]") !== null', 10_000);

    $pageA->reload();
    BrowserWait::until($pageA, '() => document.querySelector("[data-test=win-moment]")?.innerText.includes('.json_encode($match->challenger_name).')', 10_000);

    $match->refresh();

    expect($match->status)->toBe(SeriesStatus::Confirmed)
        ->and($match->resolution)->toBe(SeriesResolution::Confirmed)
        ->and($match->winner)->toBe('challenger')
        ->and(NostrEvent::query()->count())->toBe(0)
        ->and($pageA->evaluate('() => window.__errors'))->toBe([])
        ->and($pageB->evaluate('() => window.__errors'))->toBe([])
        ->and($pageB->evaluate('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth'))->toBeTrue();
});
