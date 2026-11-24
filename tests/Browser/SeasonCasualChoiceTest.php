<?php

use App\Games\Blockli;
use App\Models\User;
use App\Support\SeasonChain\Opponents;
use App\Support\SeasonChain\TrustFacts;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BlockliOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;
use Tests\Support\TrustedFacts;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Season 1 or Casual in the lobby, in the browser (plan "Season vs. Casual", P3)
|--------------------------------------------------------------------------
|
| The choice is on the first screen of a phone without a click, and the
| player's pick survives the lobby's server roundtrips: five `$refresh`
| morphs of the component keep the chosen option checked (the choice is
| Alpine state on the component's root; a morph that rebuilt it would drop
| it). The console and every answer stay clean throughout.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    config(['session.driver' => 'database', 'esports.board_games.rated_queue' => true, 'esports.chess.rated_queue' => true,
        'esports.chess.lobby_poll_seconds' => 3600, 'esports.chess.queue.range.every_seconds' => 3600]);
    BlockliOn::play();
    app()->bind(TrustFacts::class, TrustedFacts::class);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
});

/** A Trusted player who lists each other with one other player: rated is open for them. */
function choiceBrowserPlayer(): User
{
    $opponents = app(Opponents::class);
    $players = [];

    foreach (['Anna', 'Bert'] as $name) {
        $signer = new TestSigner;
        $players[] = [User::factory()->withPubkey($signer->pubkey)->create(['name' => $name]), $signer];
    }

    foreach ([[$players[0], $players[1]], [$players[1], $players[0]]] as [[$player, $signer], [$opponent]]) {
        $opponents->add($player, $opponent, $signer->signTemplates($opponents->prepareAdd($player, $opponent)));
        test()->travel(1)->seconds();
    }

    return $players[0][0];
}

function choiceBrowserPage(User $user, string $to): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize(390, 844);
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Alpine !== undefined && window.Livewire !== undefined', 10_000);

    return $page;
}

/** The two options: on screen without a click (inside the first screen), and which one is checked. */
const CHOICE_READ = <<<'JS'
    () => Object.fromEntries(['kind-casual', 'kind-rated'].map((key) => {
        const el = document.querySelector(`[data-test=${key}]`);
        const r = el.getBoundingClientRect();
        return [key, { shown: el.checkVisibility(), bottom: Math.round(r.bottom), fold: innerHeight, checked: el.getAttribute('aria-checked') }];
    }))
    JS;

/** Five awaited `$refresh` roundtrips of the component the choice sits in. */
const CHOICE_REFRESH = <<<'JS'
    async () => {
        const root = document.querySelector('[data-test=kind-choice]').closest('[wire\\:id]');
        for (let i = 0; i < 5; i++) {
            await Livewire.find(root.getAttribute('wire:id')).$refresh();
        }
        return document.querySelectorAll('[data-test=kind-choice]').length;
    }
    JS;

test('the choice is on a phone\'s first screen without a click, and the pick stays after five $refresh roundtrips', function (string $lobby, string $pick, string $other) {
    openSeason();
    $page = choiceBrowserPage(choiceBrowserPlayer(), $lobby === 'chess' ? route('chess.lobby', [], false) : route('board.lobby', Blockli::SLUG, false));
    BrowserWait::until($page, '() => document.querySelector("[data-test=kind-rated]")?.disabled === false', 10_000);

    $before = $page->evaluate(CHOICE_READ);

    expect($before['kind-casual']['shown'])->toBeTrue()->and($before['kind-rated']['shown'])->toBeTrue()
        ->and($before['kind-rated']['bottom'])->toBeLessThanOrEqual($before['kind-rated']['fold']);

    $page->locator('[data-test='.$pick.']')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test='.$pick.']").getAttribute("aria-checked") === "true"', 5_000);

    expect($page->evaluate(CHOICE_REFRESH))->toBe(1);

    $after = $page->evaluate(CHOICE_READ);

    expect([$after[$pick]['checked'], $after[$other]['checked']])->toBe(['true', 'false'])
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
})->with([
    // The board lobby starts on Casual: Season 1 is picked. The chess lobby preselects rated where open: Casual is picked.
    'board lobby, Season 1 picked' => ['board', 'kind-rated', 'kind-casual'],
    'chess lobby, Casual picked' => ['chess', 'kind-casual', 'kind-rated'],
]);
