<?php

use App\Models\ChessGame;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Execution;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| A late Livewire answer after wire:navigate
|--------------------------------------------------------------------------
|
| The match dock and the notification bell refresh themselves. When such an
| answer comes back after wire:navigate took their page away, Livewire must
| not morph it into the detached elements: their Alpine scope is gone, and
| the morph evaluated `open` as window.open ("Illegal invocation") and
| `announcement` as undefined (resources/js/livewireDetached.js). Seen at
| load ~20 in BunkerSessionTest; here made certain by holding every Livewire
| update answer back for 1.5 s in the page, so the refresh always lands
| after the navigation. The negative control shows the hold is real: the
| same refresh without a navigation arrives and morphs. A caller awaiting
| the dropped refresh still gets it resolved.
|
*/

/** Holds every Livewire update answer back by `window.__holdUpdates` ms. */
const RACE_HOLD = <<<'JS'
    (() => {
        const original = window.fetch;
        window.__updates = 0;
        window.fetch = async function (input, init) {
            const url = String(input?.url ?? input);
            const response = await original.call(window, input, init);
            if (/\/livewire[^/]*\/update/.test(url)) {
                window.__updates += 1;
                await new Promise((resolve) => setTimeout(resolve, window.__holdUpdates ?? 0));
            }
            return response;
        };
    })();
    JS;

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

test('a dock and bell refresh that lands after wire:navigate is dropped, not morphed into the old page', function () {
    $player = User::factory()->member()->create(['name' => 'Pia Player']);
    ChessGame::factory()->daily()->create(['white_id' => $player->id]);

    $page = visit(route('testing.login', ['user' => $player, 'to' => '/robots.txt']))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript(RACE_HOLD);
    $page->setViewportSize(1440, 900);
    $page->goto(ComputeUrl::from('/clans'));
    BrowserWait::until($page, '() => !!window.Livewire && !!window.Alpine && document.querySelector("[data-test=match-dock-root]") !== null', 10_000);

    $wire = fn (string $name): string => sprintf('Livewire.getByName(%s)[0]', json_encode($name));

    // Negative control: held back but on the same page, the refresh arrives and is applied.
    $page->evaluate('() => { window.__holdUpdates = 1500; window.__morphed = 0; Livewire.hook("morphed", () => { window.__morphed += 1; }); }');
    $page->evaluate('() => '.$wire('match-dock').'.$refresh()');
    BrowserWait::until($page, '() => window.__morphed > 0', 10_000);

    // The race: both refreshes leave, the page is navigated away before they come back.
    $page->evaluate('() => { window.__morphed = 0; window.__sent = window.__updates; window.__settled = "pending"; '.$wire('match-dock').'.$refresh().then(() => { window.__settled = "resolved"; }, () => { window.__settled = "rejected"; }); '.$wire('notification-bell').'.$refresh(); window.__navigated = false; document.addEventListener("livewire:navigated", () => { window.__navigated = true; }, { once: true }); Livewire.navigate("/rules"); }');
    BrowserWait::until($page, '() => window.__navigated === true && location.pathname === "/rules"', 10_000);
    // Past the hold: the late answers have come back by now.
    Execution::instance()->wait(2.5);

    expect($page->evaluate('() => window.__updates - window.__sent'))->toBeGreaterThanOrEqual(1)
        // Whoever awaited the refresh gets its answer, not a rejection: the page left, the call did not fail.
        ->and($page->evaluate('() => window.__settled'))->toBe('resolved')
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
});
