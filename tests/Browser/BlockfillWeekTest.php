<?php

use App\Models\StackerRun;
use App\Models\User;
use App\Support\Scores\ScoreLeaderboards;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\StackerRuns;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BlockfillOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Blockfill's weekly leaderboard in the browser (plan "Blockfill", P4)
|--------------------------------------------------------------------------
|
| This week's board below the game on /blockfill (the table, your place,
| last week's winner) and the running week on scores/blockfill, in English
| and German at 375 and 1440 px, one name long enough to need truncating.
| Measured: the document and every leaderboard row stay inside the viewport,
| the texts are in the page's language. Console, uncaught errors and every
| answer >= 400 are collected (BrowserConsole) and stay empty, also after
| the board's own Livewire roundtrip (the game's `stacker-verified` event);
| a positive control shows the collector sees a throw and a failed answer.
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

    BlockfillOn::play();
    $this->freezeTime();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00'));

    $weeks = app(BlockfillWeeks::class);
    $verified = function (User $user, int $ticks, CarbonImmutable $at) use ($weeks): void {
        $run = StackerRun::factory()->for($user)->verified($ticks)->create(['submitted_at' => $at, 'week' => StackerRuns::weekOf($at)]);
        $weeks->record($run, $at);
    };

    // Last week's winner, then this week's board: five players, one of them with a very long name.
    $verified(User::factory()->create(['name' => 'Satoshi Stacker']), 2710, CarbonImmutable::parse('2026-10-01 18:00:00'));
    $this->players = [
        User::factory()->create(['name' => 'Hal Finney Fan']),
        $this->me = User::factory()->create(['name' => 'Ada Blockspace']),
        User::factory()->create(['name' => 'A very long Nostr display name that has to be cut off somewhere']),
        User::factory()->create(['name' => 'Nakamoto']),
        User::factory()->create(['name' => 'Mempool Max']),
    ];
    foreach ($this->players as $index => $player) {
        $verified($player, 2900 + 37 * $index, now()->subHours(20 - $index));
    }

    // The hourly score tick has ended last week (its review time is over): its winner is final.
    expect(app(ScoreLeaderboards::class)->tick()['finalized'])->toBe(1);
});

function blockfillWeekPage(User $user, string $locale, int $width, int $height, string $path): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    $page->goto(ComputeUrl::from($path));

    return $page;
}

/** The document's widths and, for every leaderboard row, its left and right edge. */
const BLOCKFILL_WEEK_MEASURE = <<<'JS'
    (selector) => {
        const section = document.querySelector(selector);
        section.scrollIntoView({ block: 'start' });
        const rows = [...section.querySelectorAll('[data-test=score-row]')].map((row) => {
            const r = row.getBoundingClientRect();
            return [Math.round(r.left), Math.round(r.right)];
        });
        const box = section.getBoundingClientRect();
        return {
            scroll: document.documentElement.scrollWidth,
            client: document.documentElement.clientWidth,
            section: [Math.round(box.left), Math.round(box.right), Math.round(box.height)],
            rows,
            lang: document.documentElement.lang,
        };
    }
    JS;

test('this week\'s board on /blockfill: the table, your place and last week\'s winner, no overflow, clean console after a roundtrip', function (string $locale, int $width, int $height) {
    $page = blockfillWeekPage($this->me, $locale, $width, $height, route('stacker.play', [], false));
    BrowserWait::until($page, '() => window.__stacker !== undefined && document.querySelector("[data-test=stacker-week]") !== null', 10_000);

    $measure = $page->evaluate(BLOCKFILL_WEEK_MEASURE, '[data-test=stacker-week]');
    $names = $page->evaluate('() => [...document.querySelectorAll("[data-test=stacker-week] [data-test=score-row]")].map((r) => [r.dataset.place, r.querySelector("a")?.innerText.trim()])');
    fwrite(STDERR, "blockfill week /blockfill {$locale} {$width}: ".json_encode($measure).PHP_EOL);

    expect($measure['lang'])->toBe($locale)
        ->and($measure['scroll'])->toBeLessThanOrEqual($measure['client'])
        ->and($measure['section'][0])->toBeGreaterThanOrEqual(0)
        ->and($measure['section'][1])->toBeLessThanOrEqual($width)
        ->and(count($measure['rows']))->toBe(5)
        ->and($names[0])->toBe(['1', 'Hal Finney Fan'])
        ->and($names[1])->toBe(['2', 'Ada Blockspace'])
        ->and($page->evaluate('() => document.querySelector("[data-test=stacker-week-place]").innerText'))->toBe('#2')
        ->and($page->evaluate('() => document.querySelector("[data-test=stacker-week-winner-name]").innerText'))->toBe('Satoshi Stacker')
        ->and($page->evaluate('() => document.querySelector("#stacker-week-h").innerText'))->toBe($locale === 'de' ? 'Die Jagd dieser Woche' : 'This week\'s hunt')
        ->and($page->evaluate('() => document.querySelector("[data-test=best-all-time]") !== null'))->toBeTrue();
    foreach ($measure['rows'] as [$left, $right]) {
        expect($left)->toBeGreaterThanOrEqual(0)->and($right)->toBeLessThanOrEqual($width);
    }

    shellShot($page, "blockfill-week-{$locale}-{$width}");

    // The game's verdict event makes the board read itself again: one Livewire roundtrip, answered 200.
    $before = $page->evaluate('() => performance.getEntriesByType("resource").filter((e) => e.name.includes("/update")).length');
    $page->evaluate('() => window.dispatchEvent(new CustomEvent("stacker-verified"))');
    BrowserWait::until($page, "() => performance.getEntriesByType('resource').filter((e) => e.name.includes('/update')).length > {$before}", 5_000);
    $page->evaluate('() => new Promise((resolve) => setTimeout(resolve, 300))');

    expect($page->evaluate('() => document.querySelectorAll("[data-test=stacker-week] [data-test=score-row]").length'))->toBe(5)
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    // Positive control: the collector sees a throw and a failed answer on this very page.
    $page->evaluate('() => { setTimeout(() => { throw new Error("blockfill week positive control"); }); fetch("/blockfill/nothing-here"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("blockfill week positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
})->with([
    'en 375' => ['en', 375, 812],
    'en 1440' => ['en', 1440, 900],
    'de 375' => ['de', 375, 812],
    'de 1440' => ['de', 1440, 900],
]);

test('the running week on scores/blockfill: its table between the leaderboards and the points ladder, no overflow, clean console', function (string $locale, int $width, int $height) {
    $page = blockfillWeekPage($this->me, $locale, $width, $height, route('scores.show', 'blockfill', false));
    BrowserWait::until($page, '() => document.querySelector("[data-test=score-running]") !== null', 10_000);

    $measure = $page->evaluate(BLOCKFILL_WEEK_MEASURE, '[data-test=score-running]');
    fwrite(STDERR, "blockfill week scores/blockfill {$locale} {$width}: ".json_encode($measure).PHP_EOL);

    expect($measure['lang'])->toBe($locale)
        ->and($measure['scroll'])->toBeLessThanOrEqual($measure['client'])
        ->and($measure['section'][0])->toBeGreaterThanOrEqual(0)
        ->and($measure['section'][1])->toBeLessThanOrEqual($width)
        ->and(count($measure['rows']))->toBe(5)
        ->and($page->evaluate('() => document.querySelector("[data-test=score-running] h2").innerText'))->toBe('Blockfill Week 41, 2026')
        ->and($page->evaluate('() => document.querySelector("[data-test=score-running] [data-test=score-row] a").innerText.trim()'))->toBe('Hal Finney Fan')
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    foreach ($measure['rows'] as [$left, $right]) {
        expect($left)->toBeGreaterThanOrEqual(0)->and($right)->toBeLessThanOrEqual($width);
    }

    shellShot($page, "blockfill-scores-{$locale}-{$width}");
})->with([
    'en 375' => ['en', 375, 812],
    'en 1440' => ['en', 1440, 900],
    'de 375' => ['de', 375, 812],
]);
