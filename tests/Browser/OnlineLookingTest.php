<?php

use App\Models\User;
use App\Support\Pong\PongInvites;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\PongOn;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| "Looking to play" in the shared online list, for a player who joins later (plan "Proof of Pong", P4)
|--------------------------------------------------------------------------
|
| The online list (components/lobby/online-now) reads the `online` presence channel. A member's data on that channel
| is what they had when they joined it, so a player who switched "Looking to play" on (or off) before another player
| joined showed to that player as they were at their own join: not looking (or looking), until their next page
| load. The regression: Anna switches, then Bert opens the lobby, and Bert sees Anna as she is now, in the Proof of
| Pong lobby (resources/js/boardLobby.js, the board games' too) and in the chess lobby (resources/js/chess.js). The
| console and the answers stay clean on both pages, proved by a positive control.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    config(['session.driver' => 'database', 'esports.chess.lobby_poll_seconds' => 3600]);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });

    PongOn::play();
});

function onlineLookingLobby(User $user, string $path): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize(1440, 900);
    $page->goto(ComputeUrl::from($path));
    BrowserWait::until($page, '() => document.readyState === "complete" && !!document.querySelector("[data-test=looking-toggle]") && !!window.Livewire', 10_000);

    return $page;
}

/** Turn the page's switch to `$on` and wait until the server has it. */
function onlineLookingSwitch(Page $page, bool $on): void
{
    $switch = 'document.querySelector("[data-test=looking-toggle]")';

    if ($page->evaluate('() => '.$switch.'.getAttribute("aria-checked")') !== ($on ? 'true' : 'false')) {
        $page->evaluate('() => '.$switch.'.click()');
    }

    BrowserWait::until($page, '() => { const d = Alpine.$data('.$switch.'); return d.looking === '.($on ? 'true' : 'false').' && !d.savingLooking && d.looking === d.savedLooking; }', 8_000);
}

/** The JS expression: Anna's row on this page, and whether it carries the "looking" tag. */
function onlineLookingState(User $user): string
{
    return '(() => { const row = [...document.querySelectorAll("[data-test=online-player]")].find((r) => r.querySelector("[data-test=online-name]")?.textContent.trim() === '.json_encode($user->displayName()).');
        if (!row) return "absent";
        const tag = row.querySelector("[data-test=online-looking]");
        return tag && getComputedStyle(tag).display !== "none" ? "looking" : "idle"; })()';
}

/**
 * @return list<string>
 */
function onlineLookingErrors(Page $page): array
{
    return [...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)];
}

test('a player who switched looking on before another joined shows as looking to them, in the Proof of Pong and the chess lobby; switched off, as not looking', function (string $path, string $key) {
    expect(config('broadcasting.default'))->toBe('reverb', 'Run this through scripts/test-browser.sh, which starts Reverb.');

    $anna = User::factory()->create(['name' => 'Anna Early']);
    $bert = User::factory()->create(['name' => 'Bert Late']);
    $carl = User::factory()->create(['name' => 'Carl Later']);

    // Anna joins not looking and switches on; Bert joins only now: he sees her looking, with an Invite.
    $annaPage = onlineLookingLobby($anna, $path);
    onlineLookingSwitch($annaPage, true);
    expect($anna->refresh()->looking_to_play)->toBe($key);
    $bertPage = onlineLookingLobby($bert, $path);
    BrowserWait::until($bertPage, '() => '.onlineLookingState($anna).' !== "absent"', 15_000);
    $bertPage->evaluate('() => new Promise((resolve) => setTimeout(resolve, 500))');
    expect($bertPage->evaluate('() => '.onlineLookingState($anna)))->toBe('looking');

    // Anna loads the lobby again (she joins as looking now) and switches off; Bert, who is there, sees it change, and
    // Carl, who joins only now, sees her not looking.
    $annaPage->reload();
    BrowserWait::until($annaPage, '() => document.readyState === "complete" && !!window.Alpine && Alpine.$data(document.querySelector("[data-test=looking-toggle]")).looking === true', 10_000);
    onlineLookingSwitch($annaPage, false);
    BrowserWait::until($bertPage, '() => '.onlineLookingState($anna).' === "idle"', 8_000);
    $carlPage = onlineLookingLobby($carl, $path);
    BrowserWait::until($carlPage, '() => '.onlineLookingState($anna).' !== "absent"', 15_000);
    $carlPage->evaluate('() => new Promise((resolve) => setTimeout(resolve, 500))');
    expect($carlPage->evaluate('() => '.onlineLookingState($anna)))->toBe('idle');

    $errors = [...onlineLookingErrors($annaPage), ...onlineLookingErrors($bertPage), ...onlineLookingErrors($carlPage)];

    // Positive control: the collector sees a thrown error and a failed request on this page.
    $carlPage->evaluate('() => { setTimeout(() => { throw new Error("positive control"); }); fetch("/positive-control-404"); }');
    BrowserWait::until($carlPage, '() => (window.__errors ?? []).length >= 2', 5_000);

    expect($errors)->toBe([]);
})->with([
    'Proof of Pong' => ['/proof-of-pong', PongInvites::LOOKING],
    'chess' => ['/chess', 'chess/blitz'],
]);
