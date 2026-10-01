<?php

use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\SeasonChain\ChainDraft;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\ScoreDemoOn;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| A score game on the admin season page (plan "AoE2 und Trackmania", P7)
|--------------------------------------------------------------------------
|
| A score game that is switched on is a row of the chain draft without a
| weight and a proposal box next to the table, with the solo rules it would
| mine under. The board fills the proposal in and saves it through a Livewire
| round-trip; until then nothing mines and no other share moves. Measured at
| 390 and 1440 px, in English and German; console, uncaught errors and every
| answer >= 400 are collected (BrowserConsole) and stay empty, with a
| positive control on the same page.
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

    ScoreDemoOn::play();
});

function scoreMiningAdminPage(User $user, int $width, int $height, string $locale): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    $page->goto(ComputeUrl::from(route('admin.season', [], false)));
    BrowserWait::until($page, '() => document.querySelector("[data-test=admin-season]") !== null', 10_000);

    return $page;
}

/**
 * Language, sideways overflow, the collector, and the boxes of the given
 * `data-test` elements (left, right, width, height), null when missing.
 *
 * @param  list<string>  $boxes
 * @return array{lang: string, scroll: int, client: int, errors: list<string>, bad: list<string>, boxes: array<string, list<int>|null>}
 */
function scoreMiningAdminMeasure(Page $page, array $boxes): array
{
    [$scroll, $client] = $page->evaluate(BrowserConsole::WIDTHS);

    return [
        'lang' => (string) $page->evaluate('() => document.documentElement.lang'),
        'scroll' => (int) $scroll,
        'client' => (int) $client,
        'errors' => (array) $page->evaluate('() => window.__errors'),
        'bad' => (array) $page->evaluate(BrowserConsole::BAD_RESPONSES),
        'boxes' => (array) $page->evaluate('() => Object.fromEntries('.json_encode($boxes).'.map((name) => { const e = document.querySelector(`[data-test=${name}]`); if (! e) return [name, null]; const r = e.getBoundingClientRect(); return [name, [Math.round(r.left), Math.round(r.right), Math.round(r.width), Math.round(r.height)]]; }))'),
    ];
}

test('the board fills in a score game\'s proposal on the chain draft and saves it; the page stays clean and inside the viewport', function (int $width, int $height, string $locale) {
    $board = User::factory()->create(['name' => 'vorstand', 'timezone' => 'UTC']);
    config(['esports.board' => [NostrKeys::hexToNpub($board->pubkey)]]);

    $page = scoreMiningAdminPage($board, $width, $height, $locale);
    $before = scoreMiningAdminMeasure($page, ['draft-share-score-demo', 'draft-daily-score-demo', 'draft-weight-score-demo-time-trial', 'score-demo-proposal', 'fill-score-demo-proposal', 'save-draft']);
    $text = (string) $page->evaluate('() => document.querySelector("[data-test=score-demo-proposal]").innerText');
    shellShot($page, "score-mining-draft-{$locale}-{$width}");

    $page->locator('[data-test=fill-score-demo-proposal]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=draft-share-score-demo]").value === "5"', 10_000);
    $filled = $page->evaluate('() => ["chess", "rocket-league", "ea-sports-fc", "score-demo"].map((key) => document.querySelector(`[data-test=draft-share-${key}]`).value)');
    $page->locator('[data-test=save-draft]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=score-demo-proposal]") === null', 10_000);
    $after = scoreMiningAdminMeasure($page, ['draft-share-score-demo', 'save-draft']);
    shellShot($page, "score-mining-draft-saved-{$locale}-{$width}");

    fwrite(STDERR, "score mining admin {$locale} {$width}x{$height}: ".json_encode(compact('before', 'after', 'filled')).PHP_EOL);

    expect($text)->toContain($locale === 'de' ? 'mindestens 5 vertrauenswürdige Spieler' : 'at least 5 trusted players')
        ->and($text)->toContain($locale === 'de' ? '48 Stunden' : '48 hours')
        ->and($filled)->toBe(['33', '38', '24', '5'])
        ->and(ChainDraft::stored()['shares'])->toBe(['chess' => 33, 'rocket-league' => 38, 'ea-sports-fc' => 24, 'score-demo' => 5])
        ->and(ChainDraft::stored()['weights'])->toMatchArray(['score-demo/time-trial' => 1000, 'score-demo/highscore' => 1000, 'chess/blitz' => 1000]);

    foreach (['before' => $before, 'after' => $after] as $where => $m) {
        expect($m['lang'])->toBe($locale, $where)
            ->and($m['scroll'])->toBeLessThanOrEqual($m['client'], $where)
            ->and($m['errors'])->toBe([], $where)
            ->and($m['bad'])->toBe([], $where);

        foreach ($m['boxes'] as $name => $box) {
            expect($box)->not->toBeNull("{$where}: {$name}")
                ->and($box[0])->toBeGreaterThanOrEqual(0, "{$where}: {$name}")
                ->and($box[3])->toBeGreaterThan(0, "{$where}: {$name}");

            // The draft table scrolls inside its own box on a phone; the rest stays inside the viewport.
            if (! str_starts_with($name, 'draft-share') && ! str_starts_with($name, 'draft-daily') && ! str_starts_with($name, 'draft-weight')) {
                expect($box[1])->toBeLessThanOrEqual($width, "{$where}: {$name}");
            }
        }
    }

    // Positive control: the collector sees a throw and a failed answer on this very page.
    $page->evaluate('() => { setTimeout(() => { throw new Error("score mining admin positive control"); }); fetch("/score-mining-admin-positive-control-missing"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("score mining admin positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
})->with([
    'phone 390, en' => [390, 844, 'en'],
    'desktop 1440, en' => [1440, 900, 'en'],
    'phone 390, de' => [390, 844, 'de'],
    'desktop 1440, de' => [1440, 900, 'de'],
]);
