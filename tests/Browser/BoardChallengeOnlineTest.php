<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BlockliOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| "Challenge" next to everyone online in a board game's lobby (plan "Blockli-Optimierung", P3)
|--------------------------------------------------------------------------
|
| Players wrote that "Challenge a player · 7 online" only opened profiles: board games are correspondence only, so
| nobody looks for a live game there and the Invite never showed. Every row of the online list now has
| "Challenge", which opens the correspondence page with that player picked; the name still opens the player card.
| The tile "Challenge a player" puts the focus on the first of them. Phone and desktop, in German.
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

    BlockliOn::play();
});

function boardChallengePage(User $user, string $to, int $width, int $height): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('locale.switch', 'de', false)));
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Alpine !== undefined && window.Livewire !== undefined', 15_000);

    return $page;
}

test('a tap on Challenge next to a player online opens the correspondence page with that player picked', function (int $width, int $height) {
    $anna = User::factory()->create();
    $bert = User::factory()->create(['name' => 'Bert Blockli']);
    $lobby = route('board.lobby', 'blockli', false);

    $bertPage = boardChallengePage($bert, $lobby, 1440, 900);
    BrowserWait::until($bertPage, '() => window.esportsPresence?.ready === true', 10_000);
    $page = boardChallengePage($anna, $lobby, $width, $height);
    BrowserWait::until($page, '() => [...document.querySelectorAll("[data-test=online-player] [data-test=challenge]")].some((a) => a.checkVisibility())', 10_000);

    // The tile scrolls to the list and puts the focus on the first Challenge.
    $page->locator('[data-test=play-challenge]')->click();
    BrowserWait::until($page, '() => document.activeElement?.dataset.test === "challenge"', 3_000);

    $row = $page->evaluate('() => { const a = document.querySelector("[data-test=online-player] [data-test=challenge]"); const r = a.getBoundingClientRect(); return { text: a.innerText.trim(), href: a.getAttribute("href"), h: Math.round(r.height), right: Math.round(r.right), invites: document.querySelectorAll("[data-test=online-now] [data-test=invite]").length, overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth }; }');

    $page->locator('[data-test=online-player] [data-test=challenge]')->click();
    BrowserWait::until($page, '() => location.pathname.endsWith("/correspondence") && document.querySelector("[data-test=send-challenge]") !== null', 10_000);

    expect($row['text'])->toStartWith('Herausfordern')
        ->and($row['href'])->toBe(route('board.correspondence', 'blockli').'?to='.$bert->id)
        ->and($row['h'])->toBeGreaterThanOrEqual(44)
        ->and($row['right'])->toBeLessThanOrEqual($width)
        ->and($row['invites'])->toBe(0)
        ->and($row['overflow'])->toBeLessThanOrEqual(0)
        ->and($page->evaluate('() => document.querySelector("[data-test=send-challenge]").innerText.trim()'))->toBe('Bert Blockli herausfordern')
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
})->with([
    'phone 390' => [390, 844],
    'desktop 1440' => [1440, 900],
]);
