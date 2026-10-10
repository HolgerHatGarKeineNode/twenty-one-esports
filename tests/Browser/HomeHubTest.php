<?php

use App\Games\Blockfill;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\Rating;
use App\Models\StackerRun;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\StackerRuns;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\Support\BlockfillOn;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Home as the engagement hub (2026-09-27)
|--------------------------------------------------------------------------
|
| Home at 375 x 667 and 1440 x 900, guest and player, with a seeded league:
| a chess tournament with 12 of 16 seats taken and a pot, a later Rocket
| League cup, live boards and a couple of results. Measured in the first
| viewport (it ends at the phone's tab bar): the rects of the hero, its
| cover, its seats and its call to action, what a tap at the centre of each
| call to action hits, the visible words and the pictures. No page overflows
| sideways; the console, uncaught errors, rejected promises and every
| response >= 400 are collected, with a positive control below.
|
| The longer German copy is held to the same first viewport and strip.
|
| With Blockfill on and no featured tournament, a guest's band is the
| Blockfill week (revamp P3, HomeGuest.dc.html) with this week's time to
| beat, in English and German at both widths, nothing overflowing.
|
| HOME_SHOTS=<dir> additionally writes the English screenshots there;
| HOME_TAG=<before|after> runs the measurement of the first viewport (words,
| figures, pictures and the boxes of the hero) into home-metrics.jsonl.
|
*/

const HOME_HUB_COLLECTOR = <<<'JS'
    window.__errors = [];
    const push = (entry) => window.__errors.push(entry);
    const originalError = console.error;
    console.error = function (...args) { push('console.error: ' + args.map(String).join(' ')); originalError.apply(console, args); };
    window.addEventListener('error', (e) => push('error: ' + (e.message || (e.target && (e.target.src || e.target.href)) || 'unknown')), true);
    window.addEventListener('unhandledrejection', (e) => push('unhandledrejection: ' + String(e.reason)));
    const originalFetch = window.fetch;
    window.fetch = (...args) => originalFetch(...args).then((r) => { if (r.status >= 400) push(r.status + ' ' + r.url); return r; });
    const originalOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (method, url, ...rest) {
        this.addEventListener('loadend', () => { if (this.status >= 400 || this.status === 0) push('xhr ' + this.status + ' ' + url); });
        return originalOpen.call(this, method, url, ...rest);
    };
    JS;

const HOME_HUB_BAD_RESPONSES = <<<'JS'
    () => performance.getEntries()
        .filter((e) => typeof e.responseStatus === 'number' && e.responseStatus >= 400)
        .map((e) => e.responseStatus + ' ' + e.name)
    JS;

/**
 * Where the fixed chrome at the bottom starts: the shell's tab bar (below
 * lg) and, for a player with open matches, the match dock's bar above it;
 * else the window height.
 */
const HOME_HUB_FLOOR = <<<'JS'
    () => Math.min(innerHeight, ...['[data-test=tab-bar]', '[data-test=dock-mobile-bar]'].map((s) => document.querySelector(s)).filter((el) => el && el.checkVisibility()).map((el) => Math.round(el.getBoundingClientRect().top)))
    JS;

/**
 * The first viewport of <main>, from the top of the page to the floor: the
 * words of every visible text node with a line box inside it (a node that
 * starts above the floor counts whole), and every picture (img, a cover's
 * fallback tile) of at least 16 px with its area inside it.
 */
const HOME_HUB_FIRST_VIEWPORT = <<<'JS'
    (floor) => {
        window.scrollTo(0, 0);
        const main = document.getElementById('content');
        const inView = (r) => r.width > 1 && r.height > 1 && r.bottom > 0 && r.top < floor && r.right > 0 && r.left < innerWidth;
        // Text only a screen reader gets (sr-only: a 1 px box that clips it) is not read on screen.
        const hidden = (el) => { for (let a = el; a && a !== main; a = a.parentElement) { const r = a.getBoundingClientRect(); if (r.width <= 1 && r.height <= 1) return true; } return false; };
        let words = 0;
        let numbers = 0;
        const sample = [];
        const walker = document.createTreeWalker(main, NodeFilter.SHOW_TEXT);
        while (walker.nextNode()) {
            const node = walker.currentNode;
            const text = node.textContent.replace(/\s+/g, ' ').trim();
            if (!text || !node.parentElement.checkVisibility({ opacityProperty: true, visibilityProperty: true }) || hidden(node.parentElement)) continue;
            const range = document.createRange();
            range.selectNodeContents(node);
            if (![...range.getClientRects()].some(inView)) continue;
            // A word has a letter; a figure (12, 5+3, 04:11:26) is counted apart.
            const tokens = text.split(' ');
            const count = tokens.filter((w) => /\p{L}/u.test(w)).length;
            numbers += tokens.filter((w) => !/\p{L}/u.test(w) && /\p{N}/u.test(w)).length;
            words += count;
            if (count > 0) sample.push(text);
        }
        let pictures = 0;
        let area = 0;
        for (const el of main.querySelectorAll('img, [data-game-cover-fallback]')) {
            const r = el.getBoundingClientRect();
            if (r.width < 16 || r.height < 16 || !inView(r) || !el.checkVisibility({ opacityProperty: true, visibilityProperty: true })) continue;
            pictures++;
            area += Math.max(0, Math.min(r.bottom, floor) - Math.max(r.top, 0)) * Math.max(0, Math.min(r.right, innerWidth) - Math.max(r.left, 0));
        }
        return { words, numbers, pictures, pictureShare: Math.round(1000 * area / (innerWidth * floor)) / 10, sample };
    }
    JS;

/** The box of the first visible match, rounded, or null when none shows. */
const HOME_HUB_BOX = <<<'JS'
    (selector) => { const el = [...document.querySelectorAll(selector)].find((x) => x.checkVisibility()) ?? null; if (!el) return null; const r = el.getBoundingClientRect(); return { top: Math.round(r.top), bottom: Math.round(r.bottom), left: Math.round(r.left), right: Math.round(r.right), height: Math.round(r.height) }; }
    JS;

/** Every visible element matching the selector, and what a tap at its centre hits. */
const HOME_HUB_HITS = <<<'JS'
    (selector) => [...document.querySelectorAll(selector)].filter((el) => el.checkVisibility()).map((el) => {
        const r = el.getBoundingClientRect();
        const top = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
        return { test: el.dataset.test, top: Math.round(r.top), bottom: Math.round(r.bottom), width: Math.round(r.width), height: Math.round(r.height),
            hit: !!top && (top === el || el.contains(top)), over: top ? (top.dataset?.test || top.closest('[data-test]')?.dataset.test || top.tagName) : null };
    })
    JS;

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    config(['session.driver' => 'database']);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
});

/**
 * The league as a new visitor finds it on a good evening: Blitz Night with
 * 12 of 16 seats taken and a pot in its own wallet, RL Sunday later with
 * nobody in yet, two live blitz boards, a daily game and three results.
 *
 * @return array{tournament: Tournament, player: User}
 */
function homeHubSeed(): array
{
    $tournament = openTournament(['name' => 'Blitz Night Kempten', 'capacity' => 16, 'starts_at' => now()->addDays(3)->setTime(20, 0)]);
    $names = ['lena.k', 'pillpusher', 'satoshi.b', 'hodlqueen', 'nakamoto21', 'stackerin', 'blockzeit', 'orangepill', 'kempten.k', 'zapmaster', 'lnurl.lu', 'fiatfrei'];
    $entrants = [];

    foreach ($names as $name) {
        [$user, $signer] = keyedPlayer();
        $user->forceFill(['name' => $name, 'locale' => 'en'])->save();
        soloSignup($tournament, $user, $signer);
        $entrants[] = $user;
    }

    $tournament->forceFill([
        'pool_opened_at' => now(), 'pot_source' => Tournament::POT_WALLET, 'pot_balance_sats' => 210_000,
        'pot_balance_at' => now(), 'prize_target_sats' => 500_000,
    ])->save();

    openTournament(['starts_at' => now()->addDays(6)->setTime(18, 0)], rocketLeague: true);

    ChessGame::factory()->create(['white_id' => $entrants[0]->id, 'black_id' => $entrants[1]->id]);
    ChessGame::factory()->create(['white_id' => $entrants[2]->id, 'black_id' => $entrants[3]->id]);
    ChessGame::factory()->daily()->create(['white_id' => $entrants[4]->id, 'black_id' => $entrants[5]->id]);
    ChessGame::factory()->finished('1-0')->create(['white_id' => $entrants[6]->id, 'black_id' => $entrants[7]->id]);
    ChessGame::factory()->finished('0-1')->create(['white_id' => $entrants[8]->id, 'black_id' => $entrants[9]->id]);
    ChessGame::factory()->finished('1/2-1/2')->create(['white_id' => $entrants[10]->id, 'black_id' => $entrants[11]->id]);

    // The casual blitz ladder so far, and two clans.
    foreach ([[3, 1284], [7, 1231], [0, 1196], [9, 1102]] as [$index, $elo]) {
        Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$entrants[$index]->id, 'user_id' => $entrants[$index]->id, 'rating' => $elo, 'results' => 6]);
    }

    Clan::factory()->create(['name' => 'Laser Eyes', 'clantag' => 'LSR', 'meetup_city' => 'Kempten']);
    Clan::factory()->create(['name' => 'Orange Pill Allgäu', 'clantag' => 'OPA', 'meetup_city' => 'Memmingen']);

    return ['tournament' => $tournament->refresh(), 'player' => $entrants[0]];
}

function homeHubPage(?User $user, int $width, int $height, string $lang = 'en'): Page
{
    $to = route('home', ['lang' => $lang], false);
    $page = visit($user === null ? $to : route('testing.login', ['user' => $user, 'to' => route('robots', absolute: false)]))->page();
    $page->context()->addInitScript(HOME_HUB_COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Livewire !== undefined', 10_000);
    // Covers and avatars are lazy below the fold only; the first viewport's pictures have loaded.
    BrowserWait::until($page, '() => [...document.querySelectorAll("#content img")].filter((i) => i.getBoundingClientRect().top < innerHeight && i.loading !== "lazy").every((i) => i.complete)', 10_000);
    // The load sequence has ended: the seats have dropped in (CSS) and the count has counted up (900 ms, script).
    BrowserWait::until($page, '() => document.getAnimations().filter((a) => a.effect?.target?.closest?.(".hh-seat")).every((a) => a.playState !== "running")', 10_000);
    BrowserWait::until($page, '() => [...document.querySelectorAll("[data-count]")].every((el) => el.textContent.trim() === el.dataset.count)', 10_000);

    return $page;
}

function homeHubShot(Page $page, string $name, bool $fullPage = false): void
{
    $dir = getenv('HOME_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot($fullPage, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/**
 * @param  array<string, mixed>  $metrics
 */
function homeHubRecord(string $label, array $metrics): void
{
    fwrite(STDERR, "\n[home] {$label}: ".json_encode($metrics)."\n");
    $dir = getenv('HOME_SHOTS');

    if (is_string($dir) && $dir !== '') {
        File::ensureDirectoryExists($dir);
        File::append($dir.'/home-metrics.jsonl', json_encode(['label' => $label, ...$metrics])."\n");
    }
}

function homeHubClean(Page $page, string $label): void
{
    $sizes = $page->evaluate('() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]');

    expect($page->evaluate('() => window.__errors'))->toBe([], "{$label}: console")
        ->and($page->evaluate(HOME_HUB_BAD_RESPONSES))->toBe([], "{$label}: responses")
        ->and($sizes[0])->toBeLessThanOrEqual($sizes[1], "{$label}: sideways overflow");
}

test('measure the first viewport of home (before and after)', function () {
    ['player' => $player] = homeHubSeed();
    $tag = getenv('HOME_TAG') ?: 'now';

    // Undated (the test default) and with Block 0 set five days ahead.
    foreach ([null, now()->addDays(5)->setTime(21, 0)->toIso8601String()] as $block0) {
        planBlock0($block0);
        $dated = $block0 === null ? 'undated' : 'dated';

        foreach ([[null, 'guest'], [$player, 'player']] as [$user, $who]) {
            foreach ([[375, 667], [1440, 900]] as [$width, $height]) {
                $page = homeHubPage($user, $width, $height);
                $floor = $page->evaluate(HOME_HUB_FLOOR);
                $view = $page->evaluate(HOME_HUB_FIRST_VIEWPORT, $floor);
                $boxes = [];

                foreach (['countdown', 'next-tournament-teaser', 'home-hero', 'hero-cover', 'hero-seats', 'hero-cta', 'block0-strip', 'play-now'] as $test) {
                    $boxes[$test] = $page->evaluate(HOME_HUB_BOX, "[data-test={$test}]");
                }

                homeHubRecord("{$tag} {$dated} {$who} {$width}x{$height}", ['floor' => $floor, 'words' => $view['words'], 'numbers' => $view['numbers'], 'pictures' => $view['pictures'], 'pictureShare' => $view['pictureShare'], 'boxes' => array_filter($boxes), 'sample' => $view['sample']]);
                homeHubShot($page, "{$tag}-{$dated}-{$who}-{$width}");

                if ($who === 'guest') {
                    homeHubShot($page, "{$tag}-{$dated}-{$who}-{$width}-full", fullPage: true);
                }
            }
        }
    }

    expect(true)->toBeTrue();
})->skip(! getenv('HOME_TAG'), 'a measurement, not a check: HOME_TAG=<before|after> HOME_SHOTS=<dir>');

/**
 * Every call to action of the first viewport takes a tap at its centre; the
 * others take it once scrolled to the middle of the window (nothing fixed,
 * not the tab bar, not the match dock, lies over them).
 */
function homeHubTaps(Page $page, string $label): void
{
    $selector = '[data-test=hero-cta], [data-test=hero-live], [data-test=notify-block0], [data-test=chain-present], [data-test=login-google], [data-test=login-nostr]';
    $floor = $page->evaluate(HOME_HUB_FLOOR);
    $hits = $page->evaluate(HOME_HUB_HITS, $selector);
    $first = array_values(array_filter($hits, fn (array $hit): bool => $hit['bottom'] <= $floor && $hit['top'] >= 0));

    foreach ($first as $hit) {
        expect($hit['hit'])->toBeTrue("{$label}: {$hit['test']} at {$hit['top']} is covered by {$hit['over']}")
            ->and(min($hit['width'], $hit['height']))->toBeGreaterThanOrEqual(24, "{$label}: {$hit['test']} is smaller than 24 px");
    }

    $count = count($hits);

    for ($index = 0; $index < $count; $index++) {
        $hit = $page->evaluate('([selector, index]) => { const el = [...document.querySelectorAll(selector)].filter((e) => e.checkVisibility())[index]; el.scrollIntoView({ block: "center" }); const r = el.getBoundingClientRect(); const top = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return { test: el.dataset.test, hit: !!top && (top === el || el.contains(top)), over: top ? (top.closest("[data-test]")?.dataset.test || top.tagName) : null }; }', [$selector, $index]);
        expect($hit['hit'])->toBeTrue("{$label}: {$hit['test']} #{$index} is covered by {$hit['over']} in the middle of the window");
    }

    $page->evaluate('() => window.scrollTo(0, 0)');
    fwrite(STDERR, "\n[home] {$label}: ".count($first).' calls to action in the first viewport, '.$count." checked in the middle\n");
}

test('the next tournament leads home at 375 and 1440 px, for a guest and a player', function () {
    ['tournament' => $tournament, 'player' => $player] = homeHubSeed();
    planBlock0(now()->addDays(5)->setTime(21, 0)->toIso8601String());

    foreach ([[null, 'guest'], [$player, 'player']] as [$user, $who]) {
        foreach ([[375, 667], [1440, 900]] as [$width, $height]) {
            $label = "{$who} {$width}x{$height}";
            $page = homeHubPage($user, $width, $height);
            $floor = $page->evaluate(HOME_HUB_FLOOR);

            // The featured tournament is the band (Main.dc.html "Frame Hero", rule R9).
            expect($page->evaluate('() => document.querySelector("[data-test=home-hero]").dataset.tournament'))->toBe((string) $tournament->id);

            // A player with an open match or a signed-up tournament has "Your next event" (UpcomingEvents) on top of home, ahead of the band (93653e8a, 9c809992).
            $upcoming = $page->evaluate(HOME_HUB_BOX, '[data-test=upcoming-card]');
            $heroTop = $page->evaluate(HOME_HUB_BOX, '[data-test=home-hero]');
            expect($upcoming !== null)->toBe($user !== null, "{$label}: the next-event card is there for the player only");

            if ($upcoming !== null) {
                expect($upcoming['top'])->toBeGreaterThanOrEqual(0)->and($upcoming['bottom'])->toBeLessThanOrEqual($heroTop['top'] + 1, "{$label}: the card comes before the band");
            }

            // The pot and the call to action lie inside the first viewport; the seats beside them from lg. Behind the card on a phone the band begins in it.
            foreach (['hero-pot', 'hero-cta', ...($width >= 1024 ? ['hero-taken', 'seats'] : [])] as $test) {
                $box = $page->evaluate(HOME_HUB_BOX, "[data-test=home-hero] [data-test={$test}]");
                fwrite(STDERR, "\n[home] {$label} {$test}: ".json_encode($box)."\n");
                expect($box)->not->toBeNull("{$label}: {$test} is not shown")
                    ->and($box['top'])->toBeGreaterThanOrEqual(0, "{$label}: {$test} starts above the window");

                if ($upcoming !== null && $width < 1024) {
                    continue;
                }

                expect($box['bottom'])->toBeLessThanOrEqual($floor, "{$label}: {$test} ends at {$box['bottom']}, under the floor {$floor}");
            }

            expect($page->evaluate('() => document.querySelector("[data-test=hero-taken]").textContent.trim()'))->toBe('12/16')
                ->and($page->evaluate('() => document.querySelector("[data-test=hero-pot]").innerText'))->toContain('Sats');

            homeHubTaps($page, $label);
            homeHubClean($page, $label);
            homeHubShot($page, "after-{$who}-{$width}");
        }
    }

    // A Livewire roundtrip on home (the next-event card) answers without an error.
    $page = homeHubPage($player, 1440, 900);
    $updates = '() => performance.getEntriesByType("resource").filter((e) => e.initiatorType === "fetch" && /livewire.*\/update/.test(e.name)).length';
    expect($page->evaluate($updates))->toBe(0);
    $page->evaluate('() => Livewire.all().find((c) => c.el.matches("[data-test=upcoming-card]") || c.el.querySelector("[data-test=upcoming-card]")).$wire.$refresh()');
    BrowserWait::until($page, "() => ({$updates})() === 1", 10_000);
    homeHubClean($page, 'player 1440 after a roundtrip');
});

test('the longer German copy fits the same first viewport', function () {
    homeHubSeed();
    planBlock0(now()->addDays(5)->setTime(21, 0)->toIso8601String());

    foreach ([[375, 667], [1440, 900]] as [$width, $height]) {
        $label = "de {$width}x{$height}";
        $page = homeHubPage(null, $width, $height, 'de');
        $floor = $page->evaluate(HOME_HUB_FLOOR);
        $cta = $page->evaluate(HOME_HUB_BOX, '[data-test=home-hero] [data-test=hero-cta]');
        fwrite(STDERR, "\n[home] {$label}: hero-cta ".json_encode($cta)."\n");

        expect($page->evaluate('() => document.documentElement.lang'))->toBe('de')
            ->and($cta['bottom'])->toBeLessThanOrEqual($floor);
        homeHubTaps($page, $label);
        homeHubClean($page, $label);
        homeHubShot($page, "after-de-{$width}");
    }
});

test('without an open tournament home leads with the mempool and the games at 375 and 1440 px', function () {
    planBlock0(null);

    foreach ([[375, 667], [1440, 900]] as [$width, $height]) {
        $label = "no tournament {$width}x{$height}";
        $page = homeHubPage(null, $width, $height);
        $floor = $page->evaluate(HOME_HUB_FLOOR);

        // No band without a featured tournament or a Blockfill week: the mempool comes first, the games follow.
        expect($page->evaluate('() => document.querySelector("[data-test=home-hero]")'))->toBeNull();
        $mempool = $page->evaluate(HOME_HUB_BOX, '[data-test=home-mempool]');
        $games = $page->evaluate(HOME_HUB_BOX, '[data-test=home-games]');
        fwrite(STDERR, "\n[home] {$label}: mempool ".json_encode($mempool).' games '.json_encode($games)."\n");
        expect($mempool['top'])->toBeLessThan($floor)
            ->and($games['top'])->toBeGreaterThan($mempool['top']);

        homeHubTaps($page, $label);
        homeHubClean($page, $label);
        homeHubShot($page, "after-no-tournament-{$width}");
    }
});

test('a guest meets the Blockfill week as the band, with this week\'s time to beat from lg, in English and German', function () {
    BlockfillOn::play();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00'));
    leagueWeeksApproved(Blockfill::SLUG);
    app(BlockfillWeeks::class)->open();

    // 958 ticks = 0:15.966; one name long enough to need truncating at 375 px.
    foreach ([['zapmaster', 958], ['A very long Nostr display name that has to be cut off somewhere', 1100], ['lena.k', 1200]] as [$name, $ticks]) {
        $run = StackerRun::factory()->for(User::factory()->create(['name' => $name]))->verified($ticks)->create(['submitted_at' => now()->subHours(2), 'week' => StackerRuns::weekOf(now())]);
        app(BlockfillWeeks::class)->record($run);
    }

    foreach ([['en', 375, 667], ['en', 1440, 900], ['de', 375, 667], ['de', 1440, 900]] as [$lang, $width, $height]) {
        $label = "blockfill {$lang} {$width}x{$height}";
        $page = homeHubPage(null, $width, $height, $lang);
        $floor = $page->evaluate(HOME_HUB_FLOOR);
        $band = $page->evaluate('() => { const h = document.querySelector("[data-test=home-hero]"); const t = document.querySelector("[data-test=time-to-beat]"); return { hero: h?.dataset.hero ?? null, time: t && t.checkVisibility() ? t.innerText : null, overflow: [...h.querySelectorAll("*")].filter((el) => el.checkVisibility() && el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).textOverflow !== "ellipsis" && getComputedStyle(el).overflowX !== "auto").map((el) => el.dataset.test || el.tagName) }; }');
        $cta = $page->evaluate(HOME_HUB_BOX, '[data-test=home-hero] [data-test=hero-cta]');
        homeHubRecord($label, ['band' => $band, 'cta' => $cta]);

        expect($band['hero'])->toBe('blockfill')
            ->and($band['overflow'])->toBe([], "{$label}: something in the band overflows")
            ->and($cta['bottom'])->toBeLessThanOrEqual($floor);

        if ($width >= 1024) {
            expect($band['time'])->toContain('0:15.966')->toContain('zapmaster');
        }

        homeHubClean($page, $label);
        homeHubShot($page, "blockfill-{$lang}-{$width}");
    }
});

test('the home collector sees a thrown error and a missing asset (positive control)', function () {
    $page = homeHubPage(null, 1440, 900);
    $page->evaluate('() => { const img = new Image(); img.src = "/__home-missing.png"; document.body.append(img); }');
    $page->evaluate('() => setTimeout(() => { throw new Error("positive control"); })');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("positive control"))', 5_000);
    BrowserWait::until($page, '() => performance.getEntries().some((e) => e.name.endsWith("/__home-missing.png") && e.responseStatus === 404)', 5_000);

    expect(implode("\n", $page->evaluate(HOME_HUB_BAD_RESPONSES)))->toMatch('#^404 http://\S+/__home-missing\.png$#m')
        ->and(fn () => homeHubClean($page, 'positive control'))->toThrow(ExpectationFailedException::class);
});
