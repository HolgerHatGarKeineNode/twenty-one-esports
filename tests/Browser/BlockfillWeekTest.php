<?php

use App\Games\Blockfill;
use App\Models\Admin;
use App\Models\StackerRun;
use App\Models\User;
use App\Support\Scores\ScoreLeaderboards;
use App\Support\Scores\ScoreWindow;
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
    // The reference bot plays faster than a human: without this its runs would be held for review (P5 hints).
    config(['esports.blockfill.hints' => ['pps' => 7]]);
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
    leagueWeeksApproved(Blockfill::SLUG);

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

/**
 * The week's own page (plan "Restposten nach TMNF", P4): Play now against the viewport and the phone's tab bar, the
 * podium's names, every box of the hero and the board inside the viewport, the facts and the way onward.
 */
const BLOCKFILL_WEEK_PAGE_MEASURE = <<<'JS'
    () => {
        const box = (el) => { const r = el.getBoundingClientRect(); return [Math.round(r.left), Math.round(r.top), Math.round(r.right), Math.round(r.bottom)]; };
        const play = document.querySelector('[data-test=play-now]');
        const bar = document.querySelector('[data-test=tab-bar]');
        const podium = [...document.querySelectorAll('[data-test=week-podium] > li')].map((li) => {
            const name = li.querySelector('a.truncate, span.truncate');
            return { place: li.dataset.test, box: box(li), name: name ? [name.clientWidth, name.scrollWidth, name.innerText] : null };
        });
        const scope = [...document.querySelectorAll('[data-test=blockfill-hero], [data-test=blockfill-steps], [data-test=tournament-leaderboard], [data-test=week-facts], [data-test=blockfill-nav]')];
        const outside = scope.flatMap((root) => [root, ...root.querySelectorAll('*')]).filter((el) => {
            const r = el.getBoundingClientRect();
            return el.checkVisibility() && (r.left < -0.5 || r.right > innerWidth + 0.5);
        }).map((el) => el.tagName + '.' + (el.dataset.test || el.className).toString().slice(0, 40));
        return {
            scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth,
            play: box(play), playText: play.innerText.trim(), href: play.getAttribute('href'),
            tabBar: bar && bar.getClientRects().length > 0 ? Math.round(bar.getBoundingClientRect().top) : null,
            podium, rows: [...document.querySelectorAll('[data-test=tournament-leaderboard] [data-test=score-row]')].map((r) => r.dataset.place),
            rules: [...document.querySelectorAll('[data-test=chip-rule]')].map((c) => c.innerText.trim()),
            facts: [...document.querySelectorAll('[data-test=week-facts] li')].map((c) => c.innerText.trim()),
            nav: [...document.querySelectorAll('[data-test=blockfill-nav] a')].map((a) => a.dataset.test),
            walls: ['how-it-works', 'facts', 'faq', 'entries', 'to-blockfill', 'score-play-button'].filter((t) => document.querySelector(`[data-test=${t}]`) && !(t === 'how-it-works' && document.querySelector('[data-test=how-it-works]').tagName === 'A')),
            outside, lang: document.documentElement.lang,
        };
    }
    JS;

test('the week\'s page leads with the cover and Play now above the fold, the rules as chips, the podium and the board fit, the way onward, clean console', function (string $locale, int $width, int $height) {
    $week = app(BlockfillWeeks::class)->current();
    $page = blockfillWeekPage($this->me, $locale, $width, $height, route('tournaments.show', $week, false));
    BrowserWait::until($page, '() => document.querySelector("[data-test=week-podium]") !== null && document.querySelector("[data-game-cover=blockfill] img")?.complete', 10_000);
    $page->evaluate('() => window.scrollTo(0, 0)');

    $m = $page->evaluate(BLOCKFILL_WEEK_PAGE_MEASURE);
    fwrite(STDERR, "blockfill week page {$locale} {$width}: ".json_encode($m).PHP_EOL);

    // Below lg the tab bar covers the window's bottom: Play now stands whole above it in the first screen.
    $fold = $m['tabBar'] ?? $height;
    expect($m['lang'])->toBe($locale)
        ->and($m['tabBar'] !== null)->toBe($width < 1024)
        ->and($m['scroll'])->toBeLessThanOrEqual($m['client'])
        ->and($m['outside'])->toBe([])
        ->and($m['play'][1])->toBeGreaterThan(0)
        ->and($m['play'][3])->toBeLessThanOrEqual($fold)
        ->and($m['play'][3] - $m['play'][1])->toBeGreaterThanOrEqual(44)
        ->and($m['playText'])->toBe($locale === 'de' ? 'Jetzt spielen' : 'Play now')
        ->and($m['href'])->toBe(route('stacker.play'))
        ->and($m['rules'])->toBe($locale === 'de' ? ['40 Blöcke', 'Gleichbleibend 1 Reihe/s'] : ['40 blocks', 'Steady 1 row/s'])
        ->and(count($m['facts']))->toBe(4)
        ->and($m['nav'])->toBe(['nav-last-week', 'nav-points', 'nav-calendar', 'nav-runs'])
        ->and($m['walls'])->toBe([])
        ->and(array_column($m['podium'], 'place'))->toBe(['podium-1', 'podium-2', 'podium-3'])
        ->and($m['rows'])->toBe(['4', '5']);
    // Every podium name keeps room to show (the long name truncates, never squeezes to 0 px).
    foreach ($m['podium'] as $place) {
        expect($place['name'][0])->toBeGreaterThan(40);
    }

    shellShot($page, "blockfill-week-page-{$locale}-{$width}");
    $page->evaluate('() => document.querySelector("[data-test=tournament-leaderboard]").scrollIntoView({ block: "start" })');
    shellShot($page, "blockfill-week-page-board-{$locale}-{$width}");
    $page->evaluate('() => document.querySelector("[data-test=week-facts]").scrollIntoView({ block: "start" })');
    shellShot($page, "blockfill-week-page-onward-{$locale}-{$width}");

    expect($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    // A Livewire roundtrip (the page polls) keeps the console clean and the podium in place.
    $page->evaluate('() => { window.__refreshed = false; const c = Livewire.all().find((c) => c.el.matches("[data-test=tournament-show]")); c.$wire.$refresh().then(() => { window.__refreshed = true; }); }');
    BrowserWait::until($page, '() => window.__refreshed === true && document.querySelector("[data-test=week-podium]") !== null', 5_000);
    expect($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    // Positive control: the collector sees a throw and a failed answer on this very page.
    $page->evaluate('() => { setTimeout(() => { throw new Error("blockfill week page positive control"); }); fetch("/tournaments/nothing-here"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("blockfill week page positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
})->with([
    'en 375' => ['en', 375, 812],
    'en 1440' => ['en', 1440, 900],
    'de 375' => ['de', 375, 812],
    'de 1440' => ['de', 1440, 900],
]);

test('a real ranked run puts a new player on this week\'s board without a reload', function () {
    $forty = json_decode((string) file_get_contents(base_path('tests/Fixtures/stacker/forty-lines.json')), true, flags: JSON_THROW_ON_ERROR);
    config(['esports.blockfill.testing_seed' => $forty['seed']]);
    // P5: the reference run is a program at 6.45 pieces per second; under a bound of 7 it carries no cheat hint and counts at once
    config(['esports.blockfill.hints' => ['pps' => 7]]);
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

test('an admin reviews the closed week\'s top 3 on its leaderboard page (P7): the box and its button fit, the confirmation is one round-trip, clean console', function (string $locale, int $width, int $height) {
    $admin = User::factory()->create(['name' => 'Reviewer']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $week = app(BlockfillWeeks::class)->current();
    // The window has closed; the board runs until its own review time is over.
    $this->travelTo(ScoreWindow::of($week)->end->addHours(2));

    $page = blockfillWeekPage($admin, $locale, $width, $height, route('tournaments.scores', $week, false));
    // wire:confirm asks the browser; the test answers yes.
    $page->evaluate('() => { window.confirm = () => true; }');
    BrowserWait::until($page, '() => document.querySelector("[data-test=score-review-confirm]") !== null', 10_000);
    $box = '() => { const b = document.querySelector("[data-test=score-review]"); b.scrollIntoView({ block: "center" }); const r = b.getBoundingClientRect(); const c = document.querySelector("[data-test=score-review-confirm]")?.getBoundingClientRect(); return { box: [Math.round(r.left), Math.round(r.right), Math.round(r.height)], button: c ? [Math.round(c.left), Math.round(c.right), Math.round(c.height)] : null, text: b.innerText, scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth, lang: document.documentElement.lang }; }';
    $before = $page->evaluate($box);
    shellShot($page, "blockfill-review-{$locale}-{$width}");

    $page->locator('[data-test=score-review-confirm]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=score-review-confirm]") === null && document.querySelector("[data-test=score-flash]") !== null', 10_000);
    $after = $page->evaluate($box);
    shellShot($page, "blockfill-review-done-{$locale}-{$width}");
    fwrite(STDERR, "blockfill review {$locale} {$width}: ".json_encode(compact('before', 'after')).PHP_EOL);

    expect($before['lang'])->toBe($locale)
        ->and($before['text'])->toContain($locale === 'de' ? 'Top 3 geprüft' : 'Top 3 reviewed')
        ->and($after['text'])->toContain($locale === 'de' ? 'sind geprüft' : 'are reviewed')
        ->and(app(ScoreLeaderboards::class)->reviewed($week->refresh()))->toBeTrue()
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    foreach (['before' => $before, 'after' => $after] as $where => $m) {
        expect($m['scroll'])->toBeLessThanOrEqual($m['client'], $where)
            ->and($m['box'][0])->toBeGreaterThanOrEqual(0, $where)
            ->and($m['box'][1])->toBeLessThanOrEqual($width, $where);
    }

    expect($before['button'][0])->toBeGreaterThanOrEqual($before['box'][0])
        ->and($before['button'][1])->toBeLessThanOrEqual($before['box'][1])
        ->and($before['button'][2])->toBeGreaterThanOrEqual(44);

    // Positive control: the collector sees a throw and a failed answer on this very page.
    $page->evaluate('() => { setTimeout(() => { throw new Error("blockfill review positive control"); }); fetch("/blockfill-review-positive-control-missing"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("blockfill review positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
})->with([
    'en 390' => ['en', 390, 844],
    'en 1440' => ['en', 1440, 900],
    'de 390' => ['de', 390, 844],
]);
