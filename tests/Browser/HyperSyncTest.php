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
| The bound is asserted on the page's own clock, split into its parts, not as one wall-clock ceiling: a
| ceiling on the whole wait failed at 5.6-5.8 s on a busy workstation although pace.js did what it should.
| The parts are the page's pacing (at most 3 s before its last event at the normal pace begins, at once
| after), the one scene running then (3.5-3.6 s measured at load 2-15, capped at 4.5 s), and the way to
| the page (3 s). game.js hands them over in `hyperGame.state().paced`.
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

/**
 * Runs on every page: when each ply reached it (the table's broadcast, `hyper.updated`) and when it showed it, both on
 * the page's own clock (Date.now(), the same host clock as the test's microtime). Splits the wait for a move into the
 * way to the page (server, Reverb, a busy machine) and the page's own pacing (pace.js).
 */
const HYPER_SYNC_CLOCK = <<<'JS'
(() => {
    const clock = { arrived: {}, shown: {}, last: 0 };
    window.__hyperClock = clock;
    const tick = setInterval(() => {
        const game = window.hyperGame;
        if (!game) return;
        if (!game.__clocked) {
            game.__clocked = true;
            const onUpdated = game.onUpdated;
            game.onUpdated = (p) => { clock.arrived[p.ply] ??= Date.now(); return onUpdated(p); };
            clock.last = game.state().ply ?? 0;
        }
        const ply = game.state().ply ?? clock.last;
        for (let i = clock.last + 1; i <= ply; i++) clock.shown[i] = Date.now();
        clock.last = Math.max(clock.last, ply);
    }, 20);
    window.addEventListener('pagehide', () => clearInterval(tick));
})();
JS;

/** A player's page at its normal pace: every scene and caption on, no tap from the test. */
function syncPage(User $user, string $path, int $width, int $height): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript(HYPER_SYNC_CLOCK);
    $page->context()->addInitScript('try { localStorage.setItem("hb-settings", '.json_encode((string) json_encode(['scenes' => true, 'music' => false, 'fx' => false, 'board' => false, 'speed' => 1])).'); } catch (e) {}');
    $page->context()->addInitScript(TestSigner::browserStub($user));
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($path));
    BrowserWait::until($page, '() => document.body.dataset.ready === "1" && document.body.dataset.live === "1"', 15_000);

    return $page;
}

test('three players at a live table see a move within 1 s as a rule, held up by the 3 s lag bound and the scene then running at most, a hidden tab at once', function () {
    [$anna, $bert, $carl] = User::factory()->count(3)->create();
    $matches = app(HyperMatches::class);
    $match = $matches->create([
        ['user' => $anna, 'faction' => 'bitcoiner'], ['user' => $bert, 'faction' => 'fed'], ['user' => $carl, 'faction' => 'ezb'], ['bot' => true, 'faction' => 'goldbug'],
    ], seed: 11, creator: $anna);
    $path = route('hyper.match', $match, false);
    $pages = ['anna' => syncPage($anna, $path, 1440, 900), 'bert' => syncPage($bert, $path, 1440, 900), 'carl' => syncPage($carl, $path, 390, 844)];
    $players = [$anna, $bert, $carl];
    $steps = [];
    $hiddenFrom = null;

    for ($step = 0; $step < 8; $step++) {
        $match->refresh();

        if ($match->status !== HyperMatchStatus::Active || ! isset($players[$match->current_seat])) {
            break;
        }

        // From the fifth move on Carl's tab is in the background.
        if ($step === 4) {
            $pages['carl']->evaluate('() => { Object.defineProperty(document, "hidden", { configurable: true, get: () => true }); }');
            $hiddenFrom = (int) round(microtime(true) * 1000);
        }

        // The seat to move ends its turn; the bot's whole turn follows in the same answer, a long batch to show.
        $matches->act($match, $players[$match->current_seat], ['type' => 'end_turn']);
        $ply = $match->refresh()->ply;
        $start = microtime(true);
        $steps[$step] = ['ply' => $ply, 'start' => (int) round($start * 1000)];
        $reached = [];

        // Only waits; what is measured is read from the pages' own clocks below.
        while (count($reached) < count($pages) && microtime(true) - $start < 20) {
            foreach ($pages as $name => $page) {
                if (! isset($reached[$name]) && (int) $page->evaluate('() => window.hyperGame.state().ply ?? -1') >= $ply) {
                    $reached[$name] = true;
                }
            }
            usleep(100_000);
        }

        // Let the pages settle before the next move, as players would.
        usleep(1_500_000);
    }

    // Per move and page, on the page's own clock (the same host clock as the test's microtime), in two parts: the way
    // to the page (the server's answer, the broadcast, a catch-up fetch of a long one) and the page's own pacing
    // (pace.js) from the moment it queued the events until it shows them.
    $moves = [];
    $batches = [];
    foreach ($pages as $name => $page) {
        $clock = $page->evaluate('() => window.__hyperClock');
        $paced = $page->evaluate('() => window.hyperGame.state().paced');
        $previous = 0;
        $batches[$name] = $paced;

        foreach ($steps as $index => $move) {
            $own = array_values(array_filter($paced, fn (array $batch): bool => $batch['to'] > $previous && $batch['to'] <= $move['ply']));
            $moves[$name][$index] = [
                'live' => isset($clock['arrived'][$move['ply']]),
                'delivery' => $own === [] ? null : max(0, min(array_column($own, 'at')) - $move['start']),
                'pacing' => $own === [] ? null : max(array_column($own, 'end')) - min(array_column($own, 'at')),
                'hidden' => $name === 'carl' && $index >= 4,
            ];
            $previous = $move['ply'];
        }
    }

    fwrite(STDERR, "\nhyper sync, ms per move and page: ".json_encode($moves)."\n");

    // Each batch splits into the wait before its last event at the normal pace began (pace.js: at most MAX_LAG_MS, also
    // while it queues behind the batch before), that event itself (a scene already running is not cut short), and the
    // rest, which lands at once.
    $parts = collect($batches)->flatMap(fn (array $list, string $name): array => array_map(fn (array $batch): array => [
        'page' => $name,
        'hidden' => $name === 'carl' && $batch['at'] >= $hiddenFrom,
        'slow' => $batch['slowAt'] !== null,
        'lead' => $batch['slowAt'] === null ? 0 : $batch['slowAt'] - $batch['at'],
        'scene' => $batch['slowAt'] === null ? 0 : $batch['slowEnd'] - $batch['slowAt'],
        'rest' => $batch['end'] - ($batch['slowEnd'] ?? $batch['began']),
    ], $list))->values();
    fwrite(STDERR, "\nhyper sync, per batch max lead/scene/rest: ".json_encode(['lead' => $parts->max('lead'), 'scene' => $parts->max('scene'), 'rest' => $parts->max('rest')])."\n");

    $all = collect($moves)->flatten(1);
    $visible = $all->reject(fn (array $move): bool => $move['hidden']);

    expect(count($steps))->toBeGreaterThanOrEqual(6)
        // The table's broadcast reached every page for every move, not a poll or a reload.
        ->and($all->every(fn (array $move): bool => $move['live']))->toBeTrue()
        ->and($all->every(fn (array $move): bool => $move['pacing'] !== null))->toBeTrue()
        ->and($all->max('delivery'))->toBeLessThanOrEqual(3000)
        // pace.js: no event plays at its normal pace later than MAX_LAG_MS (3 s) after its batch reached the page ...
        ->and($parts->max('lead'))->toBeLessThanOrEqual(3000 + 200)
        // ... the one scene already running then is not cut short (a player defending in a battle watches its roll to the end) ...
        ->and($parts->max('scene'))->toBeLessThanOrEqual(4500)
        // ... and the rest of the batch lands at once.
        ->and($parts->max('rest'))->toBeLessThanOrEqual(1500)
        ->and($visible->max('pacing'))->toBeLessThanOrEqual(3000 + 4500)
        ->and($visible->median('pacing'))->toBeLessThanOrEqual(1000)
        // A page in a hidden tab plays nothing at its pace at all.
        ->and($parts->where('hidden', true)->where('slow', true)->all())->toBe([])
        ->and($parts->where('hidden', true)->max('rest'))->toBeLessThanOrEqual(1500);

    foreach ($pages as $page) {
        expect([...$page->evaluate('() => window.__errors ?? ["collector missing"]'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)])->toBe([]);
    }
});
