<?php

use App\Models\StackerRun;
use App\Models\User;
use App\Support\Scores\ScoreLeaderboards;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\StackerRuns;
use App\Support\Stacker\StackerSettings;
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
| the texts are in the page's language. A real ranked run (the recorded
| 40-line run fed through the test hook, replayed by the Node verifier)
| puts a new player on the board without a reload. Console, uncaught errors
| and every answer >= 400 are collected (BrowserConsole) and stay empty; a
| positive control shows the collector sees a throw and a failed answer.
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

test('this week\'s board on /blockfill: the table, your place and last week\'s winner, no overflow, clean console', function (string $locale, int $width, int $height) {
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

    expect($page->evaluate('() => window.__errors'))->toBe([])
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
        ->and($page->evaluate('() => document.querySelector("[data-test=score-running] h2").innerText'))->toBe($locale === 'de' ? 'Blockfill Woche 41, 2026' : 'Blockfill Week 41, 2026')
        ->and($page->evaluate('() => document.querySelector("[data-test=score-running] [data-test=score-row] a").innerText.trim()'))->toBe('Hal Finney Fan')
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    foreach ($measure['rows'] as [$left, $right]) {
        expect($left)->toBeGreaterThanOrEqual(0)->and($right)->toBeLessThanOrEqual($width);
    }

    // The week's card: its title whole (not cut, inside the card), the mode by its name, not its slug.
    $card = $page->evaluate('() => { const c = [...document.querySelectorAll("[data-test=score-board-card]")].find((li) => li.querySelector("[data-test=score-board-title]").innerText.includes("41")); const t = c.querySelector("[data-test=score-board-title]"); const cr = c.getBoundingClientRect(); const tr = t.getBoundingClientRect(); c.scrollIntoView({ block: "center" }); return { title: t.innerText, mode: c.querySelector("[data-test=score-board-mode]").innerText.trim(), scroll: t.scrollWidth, client: t.clientWidth, card: [Math.round(cr.left), Math.round(cr.right)], titleBox: [Math.round(tr.left), Math.round(tr.right)] }; }');
    fwrite(STDERR, "blockfill week card {$locale} {$width}: ".json_encode($card).PHP_EOL);
    expect($card['title'])->toBe($locale === 'de' ? 'Blockfill Woche 41, 2026' : 'Blockfill Week 41, 2026')
        ->and($card['mode'])->toBe($locale === 'de' ? '40 Blöcke' : '40 blocks')
        ->and($card['scroll'])->toBeLessThanOrEqual($card['client'])
        ->and($card['titleBox'][0])->toBeGreaterThanOrEqual($card['card'][0])
        ->and($card['titleBox'][1])->toBeLessThanOrEqual($card['card'][1])
        ->and($card['card'][1])->toBeLessThanOrEqual($width);
    shellShot($page, "blockfill-scores-card-{$locale}-{$width}");

    $page->evaluate(BLOCKFILL_WEEK_MEASURE, '[data-test=score-running]');
    shellShot($page, "blockfill-scores-{$locale}-{$width}");
})->with([
    'en 375' => ['en', 375, 812],
    'en 1440' => ['en', 1440, 900],
    'de 375' => ['de', 375, 812],
]);

test('the week\'s pages and scores/blockfill offer Play and say the best run counts automatically, never "Submit your value", no overflow, clean console', function (string $locale, int $width, int $height) {
    $week = app(BlockfillWeeks::class)->current();
    $paths = [route('tournaments.show', $week, false), route('tournaments.scores', $week, false), route('scores.show', 'blockfill', false)];
    $page = blockfillWeekPage($this->me, $locale, $width, $height, $paths[0]);

    foreach ($paths as $index => $path) {
        if ($index > 0) {
            $page->goto(ComputeUrl::from($path));
        }
        BrowserWait::until($page, '() => document.querySelector("[data-test=score-play-button]") !== null', 10_000);
        $play = $page->evaluate('() => { const b = document.querySelector("[data-test=score-play-button]"); const n = document.querySelector("[data-test=score-play-note]"); b.scrollIntoView({ block: "center" }); const r = b.getBoundingClientRect(), m = n.getBoundingClientRect(); return { label: b.innerText.trim(), note: n.innerText.trim(), href: b.getAttribute("href"), button: [Math.round(r.left), Math.round(r.right), Math.round(r.height)], noteBox: [Math.round(m.left), Math.round(m.right)], submit: document.querySelector("[data-test=to-submit], [data-test=score-submit]") !== null, scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }; }');
        fwrite(STDERR, "blockfill play {$locale} {$width} {$path}: ".json_encode($play).PHP_EOL);

        expect($play['label'])->toBe($locale === 'de' ? 'Spielen' : 'Play', $path)
            ->and($play['note'])->toBe($locale === 'de' ? 'Dein bester geprüfter Lauf zählt automatisch' : 'Your best verified run counts automatically')
            ->and($play['href'])->toBe(route('stacker.play'))
            ->and($play['submit'])->toBeFalse()
            ->and($play['button'][0])->toBeGreaterThanOrEqual(0)
            ->and($play['button'][1])->toBeLessThanOrEqual($width)
            ->and($play['button'][2])->toBeGreaterThanOrEqual(44)
            ->and($play['noteBox'][1])->toBeLessThanOrEqual($width)
            ->and($play['scroll'])->toBeLessThanOrEqual($play['client']);
        shellShot($page, 'blockfill-play-'.$index.'-'.$locale.'-'.$width);
    }

    expect($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
})->with([
    'en 375' => ['en', 375, 812],
    'en 1440' => ['en', 1440, 900],
    'de 375' => ['de', 375, 812],
    'de 1440' => ['de', 1440, 900],
]);

test('a real ranked run puts a new player on this week\'s board without a reload', function () {
    $forty = json_decode((string) file_get_contents(base_path('tests/Fixtures/stacker/forty-lines.json')), true, flags: JSON_THROW_ON_ERROR);
    config(['esports.blockfill.testing_seed' => $forty['seed']]);
    $newcomer = User::factory()->create(['name' => 'Fresh Stacker', 'stacker_settings' => $forty['settings'] + ['keys' => StackerSettings::DEFAULT_KEYS]]);

    $page = blockfillWeekPage($newcomer, 'en', 1440, 900, route('stacker.play', [], false));
    BrowserWait::until($page, '() => window.__stacker !== undefined', 10_000);
    // Marks this document: a reload would lose it.
    $page->evaluate('() => { window.__sameDocument = true; }');
    expect($page->evaluate('() => document.querySelector("[data-test=stacker-week-mine]").innerText'))->toContain('Your first verified ranked run this week puts you on the board.')
        ->and($page->evaluate('() => document.querySelector("[data-test=stacker-week-place]")'))->toBeNull();

    // The real flow: start, countdown, the recorded run instead of the keyboard, submitted after the played time.
    $page->locator('[data-test=start-ranked]')->click();
    BrowserWait::until($page, '() => window.__stacker.state().mode === "countdown" && window.__stacker.state().kind === "ranked" && document.querySelector("[data-test=countdown]") !== null', 5_000);
    expect($page->evaluate('(inputs) => window.__stacker.feed(inputs, { hold: true })', $forty['inputs']))->toBe('queued');
    BrowserWait::until($page, '() => window.__stacker.state().result?.status === "held"', 8_000);
    $this->travel(17)->seconds();
    $page->evaluate('() => window.__stacker.release()');
    BrowserWait::until($page, '() => window.__stacker.state().result?.status === "verified"', 15_000);

    // The board reads itself again on the verdict: first place, the same time as the result screen.
    BrowserWait::until($page, '() => document.querySelector("[data-test=stacker-week-place]")?.innerText === "#1"', 10_000);
    $first = $page->evaluate('() => { const r = document.querySelector("[data-test=stacker-week] [data-test=score-row]"); return [r.dataset.place, r.querySelector("a").innerText.trim(), r.querySelector("[data-test=score-value]").innerText]; }');

    expect($page->evaluate('() => window.__sameDocument === true'))->toBeTrue()
        ->and($first)->toBe(['1', 'Fresh Stacker', '0:15.966'])
        ->and($page->evaluate('() => document.querySelector("[data-test=result-time]").innerText'))->toBe('0:15.966')
        ->and($page->evaluate('() => document.querySelector("[data-test=best]").innerText'))->toBe('0:15.966')
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    $page->evaluate(BLOCKFILL_WEEK_MEASURE, '[data-test=stacker-week]');
    shellShot($page, 'blockfill-week-ranked-1440');
});
