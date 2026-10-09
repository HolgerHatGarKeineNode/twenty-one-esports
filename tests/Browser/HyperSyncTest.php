<?php

use App\Enums\HyperMatchStatus;
use App\Models\User;
use App\Support\Hyper\HyperMatches;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\HyperOn;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Every page at a multiplayer table keeps up with the server (user 2026-10-09)
|--------------------------------------------------------------------------
|
| Three players and a bot at one live table, each page in its own context with the animations at their
| normal pace and no test tapping anything: after each move the time until every page shows it is
| measured. A page more than 3 s behind lands the rest at once (resources/js/hyper/pace.js), a caption
| shows without holding the moves, nothing waits for a click at a multiplayer table, the page whose
| clock runs lands the others' moves at once, and a page in a hidden tab catches up at once. Measured
| 2026-10-09: 0.03-0.5 s for most moves, up to ~5 s for a player defending in the bot's battle.
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

    HyperOn::play();
});

/** A player's page at its normal pace: every scene and caption on, no tap from the test. */
function syncPage(User $user, string $path, int $width, int $height): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript('try { localStorage.setItem("hb-settings", '.json_encode((string) json_encode(['scenes' => true, 'music' => false, 'fx' => false, 'board' => false, 'speed' => 1])).'); } catch (e) {}');
    $page->context()->addInitScript(TestSigner::browserStub($user));
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($path));
    BrowserWait::until($page, '() => document.body.dataset.ready === "1" && document.body.dataset.live === "1"', 15_000);

    return $page;
}

test('three players at a live table see a move within 1 s as a rule and 5.5 s at the most, a hidden tab at once', function () {
    [$anna, $bert, $carl] = User::factory()->count(3)->create();
    $matches = app(HyperMatches::class);
    $match = $matches->create([
        ['user' => $anna, 'faction' => 'bitcoiner'], ['user' => $bert, 'faction' => 'fed'], ['user' => $carl, 'faction' => 'ezb'], ['bot' => true, 'faction' => 'goldbug'],
    ], seed: 11, creator: $anna);
    $path = route('hyper.match', $match, false);
    $pages = ['anna' => syncPage($anna, $path, 1440, 900), 'bert' => syncPage($bert, $path, 1440, 900), 'carl' => syncPage($carl, $path, 390, 844)];
    $players = [$anna, $bert, $carl];
    $lags = [];

    for ($step = 0; $step < 8; $step++) {
        $match->refresh();

        if ($match->status !== HyperMatchStatus::Active || ! isset($players[$match->current_seat])) {
            break;
        }

        // From the fifth move on Carl's tab is in the background.
        if ($step === 4) {
            $pages['carl']->evaluate('() => { Object.defineProperty(document, "hidden", { configurable: true, get: () => true }); }');
        }

        // The seat to move ends its turn; the bot's whole turn follows in the same answer, a long batch to show.
        $matches->act($match, $players[$match->current_seat], ['type' => 'end_turn']);
        $ply = $match->refresh()->ply;
        $start = microtime(true);
        $reached = [];

        while (count($reached) < count($pages) && microtime(true) - $start < 20) {
            foreach ($pages as $name => $page) {
                if (! isset($reached[$name]) && (int) $page->evaluate('() => window.hyperGame.state().ply ?? -1') >= $ply) {
                    $reached[$name] = (int) round((microtime(true) - $start) * 1000);
                }
            }
            usleep(100_000);
        }

        $lags[$step] = $reached;
        // Let the pages settle before the next move, as players would.
        usleep(1_500_000);
    }

    fwrite(STDERR, "\nhyper sync, ms until each page shows the move: ".json_encode($lags)."\n");
    $visible = collect($lags)->flatMap(fn (array $step, int $index): array => array_values($index >= 4 ? array_diff_key($step, ['carl' => 0]) : $step));
    $hidden = collect($lags)->filter(fn (array $step, int $index): bool => $index >= 4)->pluck('carl');

    expect(count($lags))->toBeGreaterThanOrEqual(6)
        ->and(collect($lags)->every(fn (array $step): bool => count($step) === 3))->toBeTrue()
        // The bound is 3 s behind the server plus the draw; a player defending in a battle watches its roll to the end.
        ->and($visible->max())->toBeLessThanOrEqual(5500)
        ->and($visible->median())->toBeLessThanOrEqual(1000)
        ->and($hidden->max())->toBeLessThanOrEqual(1500);

    foreach ($pages as $page) {
        expect([...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)])->toBe([]);
    }
});
