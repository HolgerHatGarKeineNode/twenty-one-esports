<?php

use App\Enums\BoardGameStatus;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Games\Checkers;
use App\Games\NineMensMorris;
use App\Models\ChessGame;
use App\Models\SeasonAttestation;
use App\Models\SeasonBlockVoid;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Matches\MempoolStrip;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\CheckersGame;
use Tests\Support\NineMensMorrisOn;

/*
|--------------------------------------------------------------------------
| The mempool strip on /matches (plan "Mempool-Streifen")
|--------------------------------------------------------------------------
|
| The browser half of tests/Feature/Matches/MempoolStripTest.php: /matches
| at 320, 375 and 1280 px in English and German, with every game in the
| strip and the table, a mined block with a four-digit height, a voided
| block, a block of an older season and a win that mined none. The page
| does not scroll sideways; no stamp, note, time or table row is cut; the
| console, uncaught errors and every answer >= 400 stay empty on the first
| load and after a Livewire round trip, with a positive control.
|
| MEMPOOL_SHOTS=<dir> additionally writes the screenshots there.
|
*/

/** Every piece of the strip that must not be cut, and the board rows of the table. */
const MEMPOOL_CUTS = <<<'JS'
    () => {
        const cut = (el) => el.scrollWidth > el.clientWidth + 1;
        const out = [];
        document.querySelectorAll('[data-test=block-strip] .bs-stamp, [data-test=block-strip] .bs-stamp-text, [data-test=block-strip] .bs-note, [data-test=block-strip] .bs-when, [data-test=block-strip] .bs-r1, [data-test=block-strip] .bs-num, [data-test=board-row]').forEach((el) => {
            if (cut(el)) { out.push(el.className + ': ' + el.innerText.replace(/\s+/g, ' ') + ' (' + el.scrollWidth + ' > ' + el.clientWidth + ')'); }
        });
        return out;
    }
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
    NineMensMorrisOn::play();
    CheckersGame::play();
});

function mempoolPage(User $user, int $width, string $locale): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    $page->goto(ComputeUrl::from(route('matches.index', absolute: false)));
    BrowserWait::until($page, '() => document.readyState === "complete" && document.fonts.status === "loaded"', 10_000);

    return $page;
}

test('/matches keeps the mempool strip and the table whole at 320, 375 and 1280 px, in English and German, with a clean console', function () {
    $older = openSeason(['slug' => 'pre-season', 'genesis_at' => now()->subDays(60), 'ends_at' => now()->subDays(30)]);
    $season = openSeason(['slug' => 'season-1', 'genesis_at' => now()->subDay(), 'ends_at' => now()->addDays(30)]);

    // Finished, oldest first: a block of the older season, a voided block, a win that mined none, a mined board game.
    $then = mempoolSeries(['rated' => true, 'finished_at' => now()->subMinutes(50)]);
    $voided = ChessGame::factory()->rated()->finished('1-0')->create(['ended_at' => now()->subMinutes(40)]);
    $rejected = ChessGame::factory()->rated()->finished('0-1')->create(['ended_at' => now()->subMinutes(30)]);
    mempoolSeries(['status' => SeriesStatus::Resolved, 'winner' => null, 'resolution' => SeriesResolution::Void, 'result_games' => [], 'finished_at' => now()->subMinutes(20)]);
    $morris = mempoolBoard(NineMensMorris::SLUG, ['status' => BoardGameStatus::Finished, 'result' => '1-0', 'ended_at' => now()->subMinutes(10), 'rated' => true]);
    mempoolAttest($older, SeasonAttestation::SERIES, $then->id, 2048);
    $void = mempoolAttest($season, SeasonAttestation::CHESS, $voided->id, 1813);
    SeasonBlockVoid::query()->create(['season_id' => $season->id, 'height' => 1813, 'season_attestation_id' => $void->id, 'reason' => 'Farmed between two accounts.', 'voided_by_pubkey' => str_repeat('a', 64)]);
    mempoolAttest($season, SeasonAttestation::CHESS, $rejected->id, null, 'pairing-season-limit');
    mempoolAttest($season, SeasonAttestation::BOARD, $morris->id, 1024);

    // Running and next.
    ChessGame::factory()->create(['ply' => 23]);
    mempoolBoard(Checkers::SLUG, ['ply' => 31]);
    mempoolBoard(NineMensMorris::SLUG, ['mode' => 'correspondence', 'ply' => 12]);
    SeriesMatch::factory()->accepted()->create(['start_at' => now()->addHour()]);

    $viewer = User::factory()->create();
    $shots = getenv('MEMPOOL_SHOTS');
    $casual = MempoolStrip::build(null, 'casual');
    $casualCubes = count($casual['finished']) + count($casual['running']);
    expect($casualCubes)->toBeGreaterThan(0)->toBeLessThan(9);

    foreach (['en', 'de'] as $locale) {
        foreach ([320, 375, 1280] as $width) {
            $where = "/matches {$locale} at {$width}";
            $page = mempoolPage($viewer, $width, $locale);

            expect($page->evaluate('() => document.documentElement.lang'))->toBe($locale, $where)
                ->and($page->evaluate('() => document.querySelectorAll("[data-test=strip-cube]").length'))->toBe(9, $where)
                ->and($page->evaluate('() => document.querySelectorAll("[data-test=strip-block-note]").length'))->toBe(2, "{$where}: void and older season notes")
                ->and($page->evaluate('() => document.querySelectorAll("[data-test=board-row]").length'))->toBe(3, $where);

            [$scroll, $client] = $page->evaluate(BrowserConsole::WIDTHS);
            expect($scroll)->toBeLessThanOrEqual($client, "{$where}: the page scrolls sideways ({$scroll} > {$client})")
                ->and($page->evaluate(MEMPOOL_CUTS))->toBe([], "{$where}: cut text");

            if (is_string($shots) && $shots !== '') {
                File::ensureDirectoryExists($shots);
                $page->screenshot(true, "mempool-{$locale}-{$width}");
                File::move(base_path("tests/Browser/Screenshots/mempool-{$locale}-{$width}.png"), "{$shots}/mempool-{$locale}-{$width}.png");
                // The strip opens on the divider; the oldest stamps sit at its start.
                $page->evaluate('() => { document.querySelector("[data-test=block-strip] .bs-scroll").scrollLeft = 0; }');
                $page->screenshot(false, "mempool-{$locale}-{$width}-start");
                File::move(base_path("tests/Browser/Screenshots/mempool-{$locale}-{$width}-start.png"), "{$shots}/mempool-{$locale}-{$width}-start.png");
            }

            // A Livewire round trip (the status filter), then the same channels again.
            $page->locator('[data-test=status-done]')->click();
            BrowserWait::until($page, '() => document.querySelector("[data-test=status-done]").getAttribute("aria-pressed") === "true"', 10_000);

            // The Chain filter (P4): a second round trip narrows the strip to the casual matches; its buttons are never cut.
            $page->locator('[data-test=chain-casual]')->click();
            BrowserWait::until($page, '() => document.querySelector("[data-test=chain-casual]").getAttribute("aria-pressed") === "true"', 10_000);
            $chain = $page->evaluate('() => ({ cubes: document.querySelectorAll("[data-test=strip-cube]").length, cut: [...document.querySelectorAll("[data-test=chain-filter], [data-test=chain-filter] button")].filter((el) => el.scrollWidth > el.clientWidth + 1).map((el) => el.dataset.test || el.innerText), url: location.search })');
            expect($chain)->toBe(['cubes' => $casualCubes, 'cut' => [], 'url' => '?status=done&chain=casual'], "{$where}: casual chain");

            expect($page->evaluate('() => window.__errors'))->toBe([], "{$where}: console")
                ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], "{$where}: responses")
                ->and($page->evaluate(BrowserConsole::WIDTHS)[0])->toBeLessThanOrEqual($client, "{$where}: after the round trip");
        }
    }

    // Positive control: the collector sees a thrown error and a broken image on this page.
    $page->evaluate('() => { setTimeout(() => { throw new Error("mempool positive control"); }); const img = new Image(); img.src = "/images/games/missing-mempool.jpg"; document.body.appendChild(img); }');
    BrowserWait::until($page, '() => window.__errors.length >= 2', 5_000);

    expect(implode(' ', $page->evaluate('() => window.__errors')))->toContain('mempool positive control')
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->not->toBe([]);
});
