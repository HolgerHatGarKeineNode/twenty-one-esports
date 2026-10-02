<?php

use App\Enums\StackerRunStatus;
use App\Games\Blockfill;
use App\Models\Admin;
use App\Models\StackerRun;
use App\Models\User;
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
| The Blockfill replay viewer (plan "Blockfill", P5)
|--------------------------------------------------------------------------
|
| The player opens the replay of their verified reference run (forty-lines,
| 958 ticks, hash 6102773e): it plays at 4x, a click on a cube of the chain
| jumps to the tick that block was mined, comma/full stop and the buttons
| step one tick, and the end is the verified hash. Seeking to a tick in
| Chromium gives the hash playing reached at that tick. Measured at 375 and
| 1440 px in English and at 375 px in German: nothing overflows, every
| control is at least 44 px high. The admin review list is measured too.
| Console, uncaught errors and every answer >= 400 stay empty, with a
| positive control.
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
    leagueWeeksApproved(Blockfill::SLUG);
});

/**
 * The player's verified reference run with its replay; `$hints` holds it for review instead; `$ownSeed` for a second copy.
 *
 * @param  list<string>  $hints
 */
function replayFixtureRun(User $user, array $hints = [], bool $ownSeed = false): StackerRun
{
    $forty = BlockfillOn::fixture('forty-lines');

    return StackerRun::factory()->for($user)->create([
        // Seeds are unique: a second copy of the run takes the factory's own (the viewer plays the replay, not the seed).
        ...($ownSeed ? [] : ['seed' => $forty['seed']]),
        'status' => $hints === [] ? StackerRunStatus::Verified : StackerRunStatus::Review,
        'ticks' => 958,
        'state_hash' => '6102773e',
        'replay' => $forty['replay'],
        'settings' => $forty['settings'],
        'submitted_at' => now()->subHour(),
        'verified_at' => $hints === [] ? now() : null,
        'week' => StackerRuns::weekOf(now()),
        'flags' => ['hints' => ['flags' => $hints, 'pps' => 6.45, 'maxPressesPerTick' => 1, 'timingCv' => 0.78, 'finesse' => ['perfect' => 39, 'of' => 69]]],
    ]);
}

function replayPage(User $user, string $locale, int $width, int $height, string $path): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    $page->goto(ComputeUrl::from($path));

    return $page;
}

/** Widths of the document, the boxes of the well and the chain, and every control smaller than 44 px or outside the viewport. */
const REPLAY_MEASURE = <<<'JS'
    () => {
        const box = (selector) => { const r = document.querySelector(selector).getBoundingClientRect(); return { left: Math.round(r.left), right: Math.round(r.right), width: Math.round(r.width), height: Math.round(r.height) }; };
        const small = [...document.querySelectorAll('[data-test=replay] button, [data-test=replay] a, [data-test=replay-chain]')]
            .filter((el) => el.offsetParent !== null)
            .map((el) => ({ test: el.dataset.test || el.textContent.trim().slice(0, 20), r: el.getBoundingClientRect() }))
            .filter(({ r }) => r.height < 44 || r.left < 0 || r.right > document.documentElement.clientWidth)
            .map(({ test, r }) => `${test} ${Math.round(r.width)}x${Math.round(r.height)} @${Math.round(r.left)}`);
        return {
            scroll: document.documentElement.scrollWidth,
            client: document.documentElement.clientWidth,
            well: box('[data-test=replay-well]'),
            chain: box('[data-test=replay-chain]'),
            small,
            nameLines: Math.round(document.querySelector('[data-test=replay-player] b').getBoundingClientRect().height / parseFloat(getComputedStyle(document.querySelector('[data-test=replay-player] b')).lineHeight)),
        };
    }
    JS;

test('the viewer plays, seeks along the chain and steps tick by tick, ends on the verified hash; measured, clean console', function (string $locale, int $width, int $height) {
    $user = User::factory()->create(['name' => 'Satoshi Stacker']);
    $run = replayFixtureRun($user);

    $page = replayPage($user, $locale, $width, $height, route('stacker.replay', $run, false));
    BrowserWait::until($page, '() => window.__stackerReplay !== undefined', 10_000);

    $start = $page->evaluate('() => window.__stackerReplay.state()');
    expect([$start['tick'], $start['total'], $start['lines'], $start['playing'], $start['ended']])->toBe([0, 958, 0, false, true])
        ->and(count($start['blockTicks']))->toBe(40)
        ->and($page->evaluate('() => document.querySelectorAll("[data-test=replay-cube]").length'))->toBe(40);

    // play at 4x: about 240 ticks a second
    $page->locator('[data-test=replay-speed-4]')->click();
    $page->locator('[data-test=replay-play]')->click();
    BrowserWait::until($page, '() => window.__stackerReplay.state().tick >= 300', 5_000);
    $page->locator('[data-test=replay-play]')->click();
    $played = $page->evaluate('() => window.__stackerReplay.state()');
    expect($played['playing'])->toBeFalse()->and($played['speed'])->toEqual(4);

    // Chromium: seeking away and back to the tick playing reached gives the same state
    $back = $page->evaluate('(tick) => { window.__stackerReplay.seek(0); return window.__stackerReplay.seek(tick); }', $played['tick']);
    expect($back['hash'])->toBe($played['hash'])->and($back['tick'])->toBe($played['tick']);

    // the chain is the seek bar: a click on the 20th cube's column jumps to the tick its block was mined
    $block20 = $start['blockTicks'][19];
    $track = $page->evaluate('() => { const r = document.querySelector("[data-test=replay-chain]").getBoundingClientRect(); return [r.width, r.height]; }');
    $page->locator('[data-test=replay-chain]')->click(['position' => ['x' => $block20 / 958 * $track[0], 'y' => $track[1] - 10]]);
    $seek = $page->evaluate('() => window.__stackerReplay.state()');
    expect($seek['tick'])->toBe($block20)->and($seek['lines'])->toBeGreaterThanOrEqual(20);

    // one tick on with the full stop, one back with the button, and the arrow key to the next block
    $page->locator('[data-test=replay-chain]')->press('.');
    expect($page->evaluate('() => window.__stackerReplay.state().tick'))->toBe($block20 + 1);
    $page->locator('[data-test=replay-back]')->click();
    $page->locator('[data-test=replay-back]')->click();
    expect($page->evaluate('() => window.__stackerReplay.state().tick'))->toBe($block20 - 1);
    $page->locator('[data-test=replay-chain]')->press('ArrowRight');
    expect($page->evaluate('() => window.__stackerReplay.state().tick'))->toBe($block20);

    // the end is the verified hash
    $page->locator('[data-test=replay-chain]')->press('End');
    $end = $page->evaluate('() => window.__stackerReplay.state()');
    expect([$end['tick'], $end['hash'], $end['lines']])->toBe([958, '6102773e', 40])
        ->and($page->evaluate('() => document.querySelector("[data-test=replay-tick]").innerText'))->toBe('Tick 958');

    // back to the middle for the picture
    $page->evaluate('() => window.__stackerReplay.seek(560)');
    $measure = $page->evaluate(REPLAY_MEASURE);
    fwrite(STDERR, "replay {$locale} {$width}: ".json_encode($measure).PHP_EOL);

    expect($measure['scroll'])->toBeLessThanOrEqual($measure['client'])
        ->and($measure['well']['width'] * 22)->toBe($measure['well']['height'] * 10)
        ->and($measure['well']['left'])->toBeGreaterThanOrEqual(0)
        ->and($measure['well']['right'])->toBeLessThanOrEqual($width)
        ->and($measure['chain']['right'])->toBeLessThanOrEqual($width)
        ->and($measure['small'])->toBe([])
        ->and($measure['nameLines'])->toBe(1)
        ->and($page->evaluate('() => document.querySelector("[data-test=replay-title]").innerText'))->toBe($locale === 'de' ? 'Replay von 0:15.966' : 'Replay of 0:15.966')
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    $page->evaluate('() => window.scrollTo(0, 0)');
    shellShot($page, "blockfill-replay-{$locale}-{$width}");
    $page->evaluate('() => document.querySelector("[data-test=replay-chain]").scrollIntoView({ block: "center" })');
    shellShot($page, "blockfill-replay-{$locale}-{$width}-chain");

    // positive control: the collector sees a throw and a failed answer
    $page->evaluate('() => { setTimeout(() => { throw new Error("replay positive control"); }); fetch("/blockfill/replays/nothing-here"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("replay positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
})->with([
    'en 375' => ['en', 375, 812],
    'en 1440' => ['en', 1440, 900],
    'de 375' => ['de', 375, 812],
]);

test('the admin review list shows a held run with its hints and its replay link, measured', function (int $width, int $height) {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $held = replayFixtureRun(User::factory()->create(['name' => 'Fast Fingers']), ['pps', 'finesse']);

    $page = replayPage($admin, 'en', $width, $height, route('admin.blockfill', [], false));
    BrowserWait::until($page, '() => document.querySelector("[data-test=blockfill-run]") !== null', 10_000);

    [$scroll, $client] = $page->evaluate(BrowserConsole::WIDTHS);
    $row = $page->evaluate('() => { const r = document.querySelector("[data-test=blockfill-run]").getBoundingClientRect(); return [Math.round(r.left), Math.round(r.right)]; }');
    expect($scroll)->toBeLessThanOrEqual($client)
        ->and($row[0])->toBeGreaterThanOrEqual(0)
        ->and($row[1])->toBeLessThanOrEqual($width)
        ->and($page->evaluate('() => document.querySelector("[data-test=blockfill-hints]").innerText'))->toContain('More than 5 pieces per second (6.45)')
        ->and($page->evaluate('() => document.querySelector("[data-test=blockfill-watch]").getAttribute("href")'))->toBe(route('stacker.replay', $held))
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    shellShot($page, "blockfill-admin-review-{$width}");

    // the admin opens the held run's replay: it plays like any other
    $page->goto(ComputeUrl::from(route('stacker.replay', $held, false)));
    BrowserWait::until($page, '() => window.__stackerReplay !== undefined', 10_000);
    $page->evaluate('() => window.__stackerReplay.seek(400)');
    [$heldScroll, $heldClient] = $page->evaluate(BrowserConsole::WIDTHS);
    $chip = $page->evaluate('() => { const r = document.querySelector("[data-test=replay-held]").getBoundingClientRect(); return [Math.round(r.right), document.querySelector("[data-test=replay-held]").scrollWidth <= document.querySelector("[data-test=replay-held]").clientWidth]; }');
    expect($heldScroll)->toBeLessThanOrEqual($heldClient)
        ->and($chip[0])->toBeLessThanOrEqual($width)
        ->and($chip[1])->toBeTrue()
        ->and($page->evaluate('() => document.querySelector("[data-test=replay-pieces]").innerText'))->toBe('103');
    expect($page->evaluate('() => document.querySelector("[data-test=replay-hints]").innerText'))->toContain('Every piece placed with the fewest presses (39 of 69)')
        ->and($page->evaluate('() => window.__errors'))->toBe([]);
    shellShot($page, "blockfill-replay-admin-{$width}");
})->with([
    'phone 375' => [375, 812],
    'desktop 1440' => [1440, 900],
]);

/*
| The replays page, the board's play squares and a shared moment's page
| (the Replays tab, 2026-10-01). Week 40 (2026-09-28 to 2026-10-04) has
| ended with eleven players, the longest name the profile allows among them;
| it is Wednesday of week 41.
*/

/**
 * Week 40's board of eleven, the admin second; this week one run of the admin and one held run of another player.
 *
 * @return array{admin: User, lastWeek: list<StackerRun>, adminNow: StackerRun, held: StackerRun}
 */
function replayShelfWorld(): array
{
    $forty = BlockfillOn::fixture('forty-lines');
    $admin = User::factory()->create(['name' => 'Ada Admin Who Watches Every Replay Here']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $names = ['SatoshiNakamotoStackedFortyBlocksFirst', 'Ada Admin Who Watches Every Replay Here', 'Hal Finney Fan', 'Lightning Larry', 'HalvingHodler21',
        'Nakamoto Institute Night Shift', 'Orange Pill Academy', 'Blockspace Bob', 'Mempool Mia', 'Tenth Place Tim', 'Eleventh Place Eve'];

    test()->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00'));
    leagueWeeksApproved(Blockfill::SLUG);
    $lastWeek = [];

    foreach ($names as $i => $name) {
        $user = $i === 1 ? $admin : User::factory()->create(['name' => $name]);
        $run = StackerRun::factory()->for($user)->verified(958 + 7 * $i)->create([
            'replay' => $forty['replay'], 'settings' => $forty['settings'], 'state_hash' => '6102773e',
            'submitted_at' => now()->subHours(20 - $i),
        ]);
        app(BlockfillWeeks::class)->record($run, now());
        $lastWeek[] = $run;
    }

    test()->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00'));
    leagueWeeksApproved(Blockfill::SLUG);
    $adminNow = replayFixtureRun($admin);
    $held = replayFixtureRun(User::factory()->create(['name' => 'Fast Fingers']), ['pps', 'finesse'], ownSeed: true);

    return compact('admin', 'lastWeek', 'adminNow', 'held');
}

/** The page's measure: widths, every link and button smaller than 44 px or outside the window, and the names squeezed below 48 px. */
const REPLAYS_MEASURE = <<<'JS'
    () => {
        const width = document.documentElement.clientWidth;
        const main = document.querySelector('[data-test=replays-page]');
        const small = [...main.querySelectorAll('a, button')].filter((el) => el.checkVisibility())
            .map((el) => ({ test: el.dataset.test || el.textContent.trim().slice(0, 24), r: el.getBoundingClientRect() }))
            .filter(({ r }) => r.height < 44 || r.width < 44 || r.left < 0 || r.right > width)
            .map(({ test, r }) => `${test} ${Math.round(r.width)}x${Math.round(r.height)} @${Math.round(r.left)}`);
        const names = [...main.querySelectorAll('[data-test=replays-top-row] b.truncate, [data-test=replays-featured-name], [data-test=replays-held-row] b.truncate, [data-test=replay-row-player] b')]
            .map((b) => Math.round(b.getBoundingClientRect().width));
        const squares = [...main.querySelectorAll('[data-test$=-row] .size-11')].map((s) => [Math.round(s.getBoundingClientRect().width), Math.round(s.getBoundingClientRect().height)]);
        return { scroll: document.documentElement.scrollWidth, client: width, small, names, squares,
            words: (main.innerText.match(/\S+/g) || []).length };
    }
    JS;

test('the replays page: your replays, the ended week\'s first ten with the winner large, everybody\'s newest, the held runs; measured, clean console', function (string $locale, int $width, int $height) {
    ['admin' => $admin, 'lastWeek' => $lastWeek, 'adminNow' => $adminNow, 'held' => $held] = replayShelfWorld();

    $page = replayPage($admin, $locale, $width, $height, route('stacker.replays', absolute: false));
    BrowserWait::until($page, '() => document.querySelector("[data-test=replays-page]") !== null && document.readyState === "complete"', 10_000);

    $shelves = $page->evaluate(<<<'JS'
        () => ({
            mine: [...document.querySelectorAll('[data-test=replays-mine-row]')].map((a) => [a.getAttribute('href'), a.dataset.place ?? null]),
            featured: document.querySelector('[data-test=replays-featured]')?.getAttribute('href'),
            top: [...document.querySelectorAll('[data-test=replays-top-row]')].map((a) => [a.getAttribute('href'), a.dataset.place]),
            held: [...document.querySelectorAll('[data-test=replays-held-row]')].map((a) => a.getAttribute('href')),
            latest: [...document.querySelectorAll('[data-test=replays-latest-row]')].map((a) => [a.getAttribute('href'), a.querySelector('[data-test=replay-row-player] b').innerText.trim()]),
            title: document.querySelector('#top-h').innerText.trim(),
        })
        JS);
    $measure = $page->evaluate(REPLAYS_MEASURE);
    fwrite(STDERR, "replays {$locale} {$width}: ".json_encode([$shelves, $measure]).PHP_EOL);

    expect($shelves['mine'])->toBe([[route('stacker.replay', $adminNow), null], [route('stacker.replay', $lastWeek[1]), '2']])
        ->and($shelves['featured'])->toBe(route('stacker.replay', $lastWeek[0]))
        ->and($shelves['top'])->toBe(array_map(fn (int $i): array => [route('stacker.replay', $lastWeek[$i]), (string) ($i + 1)], range(1, 9)))
        ->and($shelves['held'])->toBe([route('stacker.replay', $held)])
        // everybody's newest: the admin's run of this week, then week 40's from its newest; never the held run
        ->and($shelves['latest'])->toBe([
            [route('stacker.replay', $adminNow), 'Ada Admin Who Watches Every Replay Here'],
            ...array_map(fn (int $i): array => [route('stacker.replay', $lastWeek[$i]), $lastWeek[$i]->user->name], range(10, 0, -1)),
        ])
        ->and($shelves['title'])->toBe($locale === 'de' ? 'Top-Replays der Woche 40, 2026' : 'Top replays of Week 40, 2026')
        ->and($measure['scroll'])->toBeLessThanOrEqual($measure['client'])
        ->and($measure['small'])->toBe([])
        ->and(min($measure['names']))->toBeGreaterThanOrEqual(48)
        ->and(array_unique(array_map('json_encode', $measure['squares'])))->toBe(['[44,44]'])
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    $page->evaluate('() => window.scrollTo(0, 0)');
    shellShot($page, "blockfill-replays-{$locale}-{$width}");
    $page->evaluate('() => document.querySelector("[data-test=replays-top]").scrollIntoView({ block: "start" })');
    shellShot($page, "blockfill-replays-{$locale}-{$width}-top");

    // The winner's tile opens the replay, and it plays.
    $page->locator('[data-test=replays-featured]')->click();
    BrowserWait::until($page, '() => window.__stackerReplay !== undefined && window.__stackerReplay.state().total === 958', 10_000);
    expect($page->evaluate('() => location.pathname'))->toBe(route('stacker.replay', $lastWeek[0], false))
        ->and($page->evaluate('() => window.__errors'))->toBe([]);

    // positive control: the collector sees a throw and a failed answer
    $page->evaluate('() => { setTimeout(() => { throw new Error("replays positive control"); }); fetch("/blockfill/replays/nothing-here"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("replays positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
})->with([
    'en 375' => ['en', 375, 812],
    'en 1440' => ['en', 1440, 900],
    'de 375' => ['de', 375, 812],
]);

test('an ended week\'s board: a 44 px play square in every row that keeps its replay, the eleventh too, none where it was pruned; measured, clean console', function (string $locale, int $width, int $height) {
    ['lastWeek' => $lastWeek] = replayShelfWorld();
    $lastWeek[4]->forceFill(['replay' => null])->save();
    $guest = visit(BrowserLogin::LANDING)->page();
    $guest->context()->addInitScript(BrowserConsole::COLLECTOR);
    $guest->setViewportSize($width, $height);
    $guest->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    $week = app(BlockfillWeeks::class)->find(BlockfillWeeks::startOf($lastWeek[0]->submitted_at));
    $guest->goto(ComputeUrl::from(route('tournaments.scores', $week, false)));
    BrowserWait::until($guest, '() => document.querySelectorAll("[data-test=score-row]").length === 11', 10_000);

    $rows = $guest->evaluate(<<<'JS'
        () => [...document.querySelectorAll('[data-test=score-row]')].map((row) => {
            const play = row.querySelector('[data-test=score-replay]');
            const r = play?.getBoundingClientRect();
            const name = row.querySelector('a.truncate, span.truncate');
            return [row.dataset.place, play ? [Math.round(r.width), Math.round(r.height), Math.round(r.left), Math.round(r.right), play.getAttribute('aria-label')] : null,
                Math.round(name.getBoundingClientRect().width)];
        })
        JS);
    $hero = $guest->evaluate('() => { const a = document.querySelector("[data-test=hero-replays]"); const r = a.getBoundingClientRect(); return [a.getAttribute("href"), Math.round(r.height), Math.round(r.right)]; }');
    [$scroll, $client] = $guest->evaluate(BrowserConsole::WIDTHS);
    fwrite(STDERR, "board replays {$locale} {$width}: ".json_encode([$rows, $hero]).PHP_EOL);

    foreach ($rows as $i => $row) {
        if ($i === 4) {
            continue;
        }

        expect($row[1])->not->toBeNull("place {$row[0]} has no play square")
            ->and([$row[1][0], $row[1][1]])->toBe([44, 44])
            ->and($row[1][2])->toBeGreaterThanOrEqual(0)
            ->and($row[1][3])->toBeLessThanOrEqual($width)
            ->and($row[2])->toBeGreaterThanOrEqual(40);
    }

    expect($rows[0][1][4])->toBe(($locale === 'de' ? 'Replay von ' : 'Watch the replay of ').'SatoshiNakamotoStackedFortyBlocksFirst'.($locale === 'de' ? ' ansehen' : ''))
        ->and($rows[4][1])->toBeNull()
        ->and($hero[0])->toBe(route('stacker.replays', ['week' => $week->slug]))
        ->and($hero[1])->toBeGreaterThanOrEqual(44)
        ->and($hero[2])->toBeLessThanOrEqual($width)
        ->and($scroll)->toBeLessThanOrEqual($client)
        ->and($guest->evaluate('() => window.__errors'))->toBe([])
        ->and($guest->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    $guest->evaluate('() => document.querySelector("[data-test=score-leaderboard]").scrollIntoView({ block: "start" })');
    shellShot($guest, "blockfill-board-replays-{$locale}-{$width}");
})->with([
    'en 375' => ['en', 375, 812],
    'en 1440' => ['en', 1440, 900],
    'de 375' => ['de', 375, 812],
]);

test('a shared moment\'s link plays the run for a guest, under its headline with Play; measured, clean console', function (string $locale, int $width, int $height) {
    $player = User::factory()->create(['name' => 'SatoshiNakamotoStackedFortyBlocksFirst']);
    $run = replayFixtureRun($player);
    app(BlockfillWeeks::class)->record($run, now());
    $other = replayFixtureRun(User::factory()->create(), ownSeed: true);
    $held = replayFixtureRun(User::factory()->create(), ['pps'], ownSeed: true);

    $guest = visit(BrowserLogin::LANDING)->page();
    $guest->context()->addInitScript(BrowserConsole::COLLECTOR);
    $guest->setViewportSize($width, $height);
    $guest->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    $guest->goto(ComputeUrl::from(route('stacker.moment', $run->id, false)));
    BrowserWait::until($guest, '() => window.__stackerReplay !== undefined && window.__stackerReplay.state().total === 958', 10_000);

    // it plays: 4x, then the end is the verified hash
    $guest->locator('[data-test=replay-speed-4]')->click();
    $guest->locator('[data-test=replay-play]')->click();
    BrowserWait::until($guest, '() => window.__stackerReplay.state().tick >= 200', 5_000);
    $guest->locator('[data-test=replay-play]')->click();
    $guest->locator('[data-test=replay-chain]')->press('End');
    $end = $guest->evaluate('() => window.__stackerReplay.state()');

    $head = $guest->evaluate(<<<'JS'
        () => {
            const box = (s) => { const r = document.querySelector(s).getBoundingClientRect(); return [Math.round(r.left), Math.round(r.right), Math.round(r.top), Math.round(r.bottom), Math.round(r.height)]; };
            const name = document.querySelector('[data-test=blockfill-moment-player]');
            return { headline: document.querySelector('[data-test=blockfill-moment-headline]').innerText.trim(), play: box('[data-test=blockfill-moment-play]'),
                well: box('[data-test=replay-well]'), nameClipped: name.scrollWidth > name.clientWidth, nameWidth: Math.round(name.getBoundingClientRect().width),
                share: !! document.querySelector('[data-test=blockfill-moment-share]') };
        }
        JS);
    [$scroll, $client] = $guest->evaluate(BrowserConsole::WIDTHS);
    fwrite(STDERR, "moment {$locale} {$width}: ".json_encode([$head, $end['tick'], $end['hash']]).PHP_EOL);

    expect([$end['tick'], $end['hash']])->toBe([958, '6102773e'])
        ->and($head['headline'])->toBe($locale === 'de' ? 'Neuer erster Platz der Woche' : 'New first place of the week')
        ->and($head['play'][4])->toBeGreaterThanOrEqual(44)
        ->and($head['play'][0])->toBeGreaterThanOrEqual(0)
        ->and($head['play'][1])->toBeLessThanOrEqual($width)
        ->and($head['well'][1])->toBeLessThanOrEqual($width)
        ->and($head['nameWidth'])->toBeGreaterThanOrEqual(48)
        ->and($head['share'])->toBeFalse()
        ->and($scroll)->toBeLessThanOrEqual($client)
        ->and($guest->evaluate('() => window.__errors'))->toBe([])
        ->and($guest->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    $guest->evaluate('() => { window.__stackerReplay.seek(560); window.scrollTo(0, 0); }');
    shellShot($guest, "blockfill-moment-{$locale}-{$width}");

    // Every verified run's replay page is open to the guest too; a held run's stays closed.
    expect($guest->evaluate('(path) => fetch(path).then((r) => r.status)', route('stacker.replay', $other, false)))->toBe(200)
        ->and($guest->evaluate('(path) => fetch(path).then((r) => r.status)', route('stacker.replay', $run, false)))->toBe(200)
        ->and($guest->evaluate('(path) => fetch(path).then((r) => r.status)', route('stacker.replay', $held, false)))->toBe(403);
})->with([
    'en 375' => ['en', 375, 812],
    'en 1440' => ['en', 1440, 900],
    'de 375' => ['de', 375, 812],
]);
