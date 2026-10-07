<?php

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\CupBoard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserWait;
use Tests\Support\CheckersGame;
use Tests\Support\NineMensMorrisOn;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| EU and US casual cups side by side (user, 2026-09-28)
|--------------------------------------------------------------------------
|
| The league opens a Rocket League cup per region. The game page lists both,
| the EU cup's own page names the US one; a guest's browser in New York
| sees each start on its own clock. Measured at 375 and 1440: nothing wider
| than the window, every row a 44 px target, EU and US next to each other
| from lg and stacked below; the console and the answers clean, with a
| positive control.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    Queue::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.casual_cups.enabled' => ['rocket-league']]);
    // Monday 5 October 2026: the EU cup starts Saturday 20:00 Berlin (14:00 New York), the US cup Saturday 20:00 New York.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));
    app(CasualCups::class)->tick();
});

function cupRegionsPage(string $path, int $width, int $height, string $ready): Page
{
    $page = visit('/robots.txt')->withTimezone('America/New_York')->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($path));
    BrowserWait::until($page, '() => window.Alpine && document.querySelector("'.$ready.'") !== null && document.fonts.status === "loaded"', 10_000);

    return $page;
}

function cupRegionsShot(Page $page, string $name): void
{
    $dir = getenv('CASUAL_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/**
 * The cup rows: name, start as shown, box; and the page's overflow.
 *
 * @return array{overflow: int, rows: list<array{name: string, start: string, left: int, right: int, top: int, bottom: int, height: int}>, width: int}
 */
function cupRegionsRows(Page $page): array
{
    return $page->evaluate('() => ({
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        width: window.innerWidth,
        rows: [...document.querySelectorAll("[data-test=cup-mention]")].filter((el) => el.checkVisibility()).map((el) => {
            const r = el.getBoundingClientRect();
            const name = [...el.querySelectorAll("span")].map((s) => s.textContent).find((t) => t.includes("Casual Cup")) || "";
            const start = (el.querySelector("[data-test=cup-mention-start]")?.innerText || "").replace(/\s+/g, " ").trim();
            return { name, start, left: Math.round(r.left), right: Math.round(r.right), top: Math.round(r.top), bottom: Math.round(r.bottom), height: Math.round(r.height) };
        }),
    })');
}

function cupRegionsControl(Page $page): void
{
    $page->evaluate('() => { setTimeout(() => { throw new Error("probe-throw"); }); return fetch("/__test/server-error"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("probe-throw")) && window.__errors.some((e) => e.startsWith("500 ")) && performance.getEntries().some((e) => e.name.includes("/__test/server-error") && e.responseStatus === 500)', 5_000);
    $page->evaluate('() => { window.__errors = []; performance.clearResourceTimings(); }');
}

test('the game page lists the EU and the US cup, each start on the viewer\'s clock, side by side from lg and stacked on a phone', function () {
    $wide = cupRegionsPage('/games/rocket-league', 1440, 900, '[data-test=cup-mentions]');
    cupRegionsControl($wide);
    $desk = cupRegionsRows($wide);
    cupRegionsShot($wide, 'cup-regions-game-1440');

    $narrow = cupRegionsPage('/games/rocket-league', 375, 812, '[data-test=cup-mentions]');
    $phone = cupRegionsRows($narrow);
    cupRegionsShot($narrow, 'cup-regions-game-375');

    foreach ([$desk, $phone] as $measured) {
        expect($measured['overflow'])->toBe(0)
            ->and(array_column($measured['rows'], 'name'))->toBe(['Rocket League Casual Cup EU #1', 'Rocket League Casual Cup US #1'])
            // The browser's own zone (New York), not the league's: 20:00 Berlin is 14:00 there.
            ->and(array_column($measured['rows'], 'start'))->toBe(['2:00 PM Sat, Oct 10, New York', '8:00 PM Sat, Oct 10, New York'])
            ->and(collect($measured['rows'])->every(fn (array $row): bool => $row['left'] >= 0 && $row['right'] <= $measured['width'] && $row['height'] >= 44))->toBeTrue();
    }

    [$eu, $us] = $desk['rows'];
    [$euPhone, $usPhone] = $phone['rows'];

    expect($us['top'])->toBe($eu['top'])
        ->and($us['left'])->toBeGreaterThan($eu['right'])
        ->and($usPhone['top'])->toBeGreaterThanOrEqual($euPhone['bottom']);

    foreach ([$wide, $narrow] as $page) {
        expect($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }
});

test('the EU cup\'s page names the US cup with its start on the viewer\'s clock, at 1440 and 375', function () {
    $eu = Tournament::query()->where('cup_open_series', 'rocket-league-eu')->sole();

    $wide = cupRegionsPage(route('tournaments.show', $eu, false), 1440, 900, '[data-test=cup-other-regions] [data-test=cup-mention]');
    cupRegionsControl($wide);
    $desk = cupRegionsRows($wide);
    $title = $wide->evaluate('() => document.querySelector("#t-name").innerText');
    cupRegionsShot($wide, 'cup-regions-page-1440');

    $narrow = cupRegionsPage(route('tournaments.show', $eu, false), 375, 812, '[data-test=cup-other-regions] [data-test=cup-mention]');
    $phone = cupRegionsRows($narrow);
    cupRegionsShot($narrow, 'cup-regions-page-375');

    expect($title)->toBe('Rocket League Casual Cup EU #1');

    foreach ([$desk, $phone] as $measured) {
        expect($measured['overflow'])->toBe(0)
            ->and($measured['rows'])->toHaveCount(1)
            ->and($measured['rows'][0]['name'])->toBe('Rocket League Casual Cup US #1')
            ->and($measured['rows'][0]['start'])->toBe('8:00 PM Sat, Oct 10, New York')
            ->and($measured['rows'][0]['right'])->toBeLessThanOrEqual($measured['width'])
            ->and($measured['rows'][0]['height'])->toBeGreaterThanOrEqual(44);
    }

    foreach ([$wide, $narrow] as $page) {
        expect($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }
});

/*
| The cup board on the tournaments page (P53): grouped by game with the
| cover, each game's EU and US as a pair, and a filter bar that works without
| a reload. Measured at 1440 and 375 in English and at 375 in German: no
| overflow, every control a 44 px target, the cover in each group; the
| filters hide and reorder in place (no navigation), and the console and the
| answers stay clean, with a positive control.
*/

/**
 * The board as the browser shows it: visible groups in page order with their
 * visible rows, the count line, the controls under 44 px and the overflow.
 *
 * @return array{groups: list<array{game: string, rows: list<string>, cover: bool}>, count: string, small: list<string>, overflow: int, url: string}
 */
function cupBoardState(Page $page): array
{
    return $page->evaluate('() => ({
        groups: [...document.querySelectorAll("[data-cup-group]")].filter((g) => g.checkVisibility()).map((g) => ({
            game: g.dataset.game,
            rows: [...g.querySelectorAll("[data-cup-row]")].filter((r) => r.checkVisibility()).map((r) => r.dataset.region),
            cover: (g.querySelector("[data-test=cup-group-cover]")?.getBoundingClientRect().width || 0) >= 90,
        })),
        count: document.querySelector("[data-test=cup-filter-count]").innerText.trim(),
        small: [...document.querySelectorAll("[data-test=cup-filters] button, [data-test=cup-filters] select, [data-test=cup-filters] label:has(input), [data-test=cup-mention]")]
            .filter((el) => el.checkVisibility()).filter((el) => el.getBoundingClientRect().height < 44).map((el) => el.dataset.test || el.tagName),
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        url: location.href,
    })');
}

test('the tournaments page groups the cups by game with covers and filters them in place, at 1440 and 375, German at 375', function () {
    config(['esports.casual_cups.enabled' => ['chess', 'rocket-league', 'ea-sports-fc-26']]);
    app(CasualCups::class)->tick();
    // The Rocket League US cup is running: no free places, so "free places only" hides it.
    Tournament::query()->where('cup_open_series', 'rocket-league-us')->sole()->forceFill(['status' => TournamentStatus::Running])->save();
    $all = ['chess' => ['eu', 'us'], 'rocket-league' => ['eu', 'us'], 'ea-sports-fc-26' => ['eu', 'us']];

    foreach ([[1440, 900, 'en'], [375, 812, 'en'], [375, 812, 'de']] as [$width, $height, $lang]) {
        $page = cupRegionsPage('/tournaments', $width, $height, '[data-test=cup-filters]');

        if ($lang === 'de') {
            $page->goto(ComputeUrl::from('/locale/de'));
            $page->goto(ComputeUrl::from('/tournaments'));
            BrowserWait::until($page, '() => window.Alpine && document.querySelector("[data-test=cup-filters]") !== null && document.fonts.status === "loaded"', 10_000);
        }

        if ($width === 1440) {
            cupRegionsControl($page);
        }

        $start = cupBoardState($page);
        cupRegionsShot($page, "cup-board-{$width}-{$lang}");

        expect(collect($start['groups'])->mapWithKeys(fn (array $g): array => [$g['game'] => $g['rows']])->all())->toBe($all)
            ->and(collect($start['groups'])->every(fn (array $g): bool => $g['cover']))->toBeTrue()
            ->and($start['count'])->toBe($lang === 'de' ? '6 Cups' : '6 cups')
            ->and([$width, $lang, $start['small'], $start['overflow']])->toBe([$width, $lang, [], 0]);

        // Region US: one row per game, no reload.
        $page->locator('[data-test=cup-filter-region-us]')->click();
        $us = cupBoardState($page);
        expect(collect($us['groups'])->every(fn (array $g): bool => $g['rows'] === ['us']))->toBeTrue()
            ->and($us['count'])->toBe($lang === 'de' ? '3 Cups' : '3 cups')
            ->and($us['url'])->toBe($start['url']);

        // Plus free places only: the running Rocket League US cup goes, and its group with it.
        $page->locator('[data-test=cup-filter-free]')->click();
        expect(array_column(cupBoardState($page)['groups'], 'game'))->toBe(['chess', 'ea-sports-fc-26']);

        // One game: the select below sm, the covered buttons from sm.
        $width < 640
            ? $page->evaluate('() => { const s = document.querySelector("[data-test=cup-filter-game-select]"); s.value = "rocket-league"; s.dispatchEvent(new Event("change")); }')
            : $page->locator('[data-test=cup-filter-game-rocket-league]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=cup-filter-empty]").checkVisibility()', 3_000);
        expect(cupBoardState($page)['groups'])->toBe([])
            ->and($page->evaluate('() => document.querySelector("[data-test=cup-filter-count]").innerText.trim()'))->toBe($lang === 'de' ? '0 Cups' : '0 cups');

        // "Show every cup" resets game, region and free places.
        $page->locator('[data-test=cup-filter-empty] button')->click();
        expect(collect(cupBoardState($page)['groups'])->mapWithKeys(fn (array $g): array => [$g['game'] => $g['rows']])->all())->toBe($all);

        expect($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }
});

test('by start orders the game groups by their earliest cup and the rows by start', function () {
    config(['esports.casual_cups.enabled' => ['chess', 'rocket-league']]);
    app(CasualCups::class)->tick();
    // The chess EU cup moves two days later: chess's earliest cup is then its US one (Sun 02:00 Berlin), after Rocket League's EU cup (Sat 20:00).
    $chessEu = Tournament::query()->where('cup_open_series', 'chess-eu')->sole();
    $chessEu->forceFill(['starts_at' => $chessEu->starts_at->addDays(2)])->save();

    $page = cupRegionsPage('/tournaments', 1440, 900, '[data-test=cup-filters]');
    expect(array_column(cupBoardState($page)['groups'], 'game'))->toBe(['chess', 'rocket-league']);

    $page->locator('[data-test=cup-sort-start]')->click();
    $state = cupBoardState($page);

    expect(array_column($state['groups'], 'game'))->toBe(['rocket-league', 'chess'])
        ->and($state['groups'][1]['rows'])->toBe(['us', 'eu'])
        ->and($page->evaluate('() => document.querySelector("[data-test=cup-sort-start]").getAttribute("aria-pressed")'))->toBe('true')
        ->and($page->evaluate('() => window.__errors'))->toBe([]);
});

test('the game filter of the cup board stays inside the window with every cup game, from 640 to 1920 px in English and German', function () {
    // The board games run no cup since 2026-10-07 (correspondence only): five games, the board games listed in vain.
    NineMensMorrisOn::play();
    CheckersGame::play();
    config(['esports.casual_cups.enabled' => ['chess', 'rocket-league', 'ea-sports-fc-26', 'ea-sports-fc-27', 'age-of-empires-2', 'nine-mens-morris', 'checkers']]);
    app(CasualCups::class)->tick();

    $page = cupRegionsPage('/tournaments', 640, 900, '[data-test=cup-filters]');
    cupRegionsControl($page);
    $measured = [];

    foreach (['en', 'de'] as $lang) {
        if ($lang === 'de') {
            $page->goto(ComputeUrl::from('/locale/de'));
            $page->goto(ComputeUrl::from('/tournaments'));
            BrowserWait::until($page, '() => window.Alpine && document.querySelector("[data-test=cup-filters]") !== null && document.fonts.status === "loaded"', 10_000);
        }

        foreach ([640, 1024, 1280, 1440, 1536, 1920] as $width) {
            $page->setViewportSize($width, 900);
            // Past: px of a button beyond the window or cut off by its overflow-hidden group; clipped: a label cut inside its button (the sr-only name is
            // cut on purpose); the buttons' names as a screen reader gets them (an aria-label, else the text outside aria-hidden) and the text they show.
            $measured["{$lang} {$width}"] = $page->evaluate('() => {
                const buttons = [...document.querySelectorAll("[data-test^=cup-filter-game-]:not([data-test=cup-filter-game-select])")];
                const edge = Math.min(window.innerWidth, buttons[0].parentElement.getBoundingClientRect().right);
                return {
                    overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
                    past: Math.max(0, ...buttons.map((b) => Math.round(b.getBoundingClientRect().right - edge))),
                    clipped: buttons.filter((b) => b.scrollWidth > b.clientWidth + 1 || [...b.querySelectorAll("span:not(.sr-only)")].some((s) => s.checkVisibility() && s.scrollWidth > s.clientWidth + 1)).map((b) => b.dataset.test),
                    names: buttons.slice(1).map((b) => b.getAttribute("aria-label") ?? [...b.querySelectorAll("span")].filter((s) => s.checkVisibility() && !s.closest("[aria-hidden=true]")).map((s) => s.textContent).join("")),
                    shown: buttons.slice(1).map((b) => [...b.querySelectorAll("span:not(.sr-only)")].filter((s) => s.checkVisibility()).map((s) => s.textContent).join("")),
                };
            }');
            cupRegionsShot($page, "cup-filter-seven-{$width}-{$lang}");
        }
    }

    fwrite(STDERR, json_encode($measured).PHP_EOL);

    foreach ($measured as $at => $state) {
        expect([$at, $state['overflow'], $state['past'], $state['clipped']])->toBe([$at, 0, 0, []])
            ->and(count($state['names']))->toBe(5);

        // Label in name (WCAG 2.5.3): every name starts with the text its button shows.
        foreach ($state['names'] as $index => $name) {
            expect(str_starts_with($name, $state['shown'][$index]))->toBeTrue("{$at}: \"{$name}\" does not start with \"{$state['shown'][$index]}\"");
        }
    }

    // The cup board follows the games menu's order since b45c7907 (the registry's, GameRegistry::ordered()): FC27 before FC26.
    expect($measured['en 1440']['names'])->toBe(['Chess', 'RL, Rocket League', 'FC27, EA Sports FC 27', 'FC26, EA Sports FC 26', 'AoE2, Age of Empires II: Definitive Edition', "Morris, Nine Men's Morris", 'Checkers'])
        ->and($measured['en 1536']['names'])->toBe(['Chess', 'Rocket League', 'EA Sports FC 27', 'EA Sports FC 26', 'Age of Empires II: Definitive Edition', "Nine Men's Morris", 'Checkers'])
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
});

/*
 * The organizers' tournaments above the cup board (user, 2026-09-28: "alle
 * manuell angelegten Turniere sind die WICHTIGSTEN ... oben groß und nicht
 * unten klein"): the hero and every card, with its cover drawn in 16:9,
 * before the first casual cup, at 1440 and 375, in English and German.
 */
const ORGANIZER_STATE = <<<'JS'
    () => {
        const box = (el) => { const r = el.getBoundingClientRect(); return { top: Math.round(r.top + scrollY), bottom: Math.round(r.bottom + scrollY), left: Math.round(r.left), right: Math.round(r.right), width: Math.round(r.width), height: Math.round(r.height) }; };
        const cover = (el) => { const img = el.querySelector('[data-game-cover] img'); if (!img) return null; const r = img.getBoundingClientRect(); return { loaded: img.complete && img.naturalWidth > 0, ratio: Math.round(100 * r.width / r.height) / 100, height: Math.round(r.height), loading: img.getAttribute('loading') }; };
        const hero = document.querySelector('[data-test=next-tournament]');
        return {
            lang: document.documentElement.lang,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            width: window.innerWidth,
            hero: hero ? { id: hero.dataset.tournament, ...box(hero), cover: cover(hero) } : null,
            cards: [...document.querySelectorAll('[data-test=organizer-card]')].map((el) => ({ id: el.dataset.tournament, state: el.dataset.state, ...box(el), cover: cover(el), city: el.querySelector('[data-test=organizer-card-start] .text-ink-3')?.textContent.trim() })),
            board: box(document.querySelector('[data-test=cup-mentions]')),
        };
    }
    JS;

test('the organizers\' tournaments stand above the casual cups, large and with their covers, at 1440 and 375 in English and German', function () {
    config(['esports.casual_cups.enabled' => ['chess', 'rocket-league', 'ea-sports-fc-26']]);
    app(CasualCups::class)->tick();
    $now = CarbonImmutable::now();
    $made = function (string $name, TournamentStatus $status, CarbonImmutable $startsAt, array $attributes = []): Tournament {
        $tournament = Tournament::factory()->create(['name' => $name, 'starts_at' => $startsAt, 'created_by_id' => organizer()->id, ...$attributes]);
        $tournament->forceFill(['status' => $status, 'published_at' => now()->subDay(), 'signup_closes_at' => $startsAt->subHour()])->save();

        return $tournament;
    };
    $hero = $made('EINUNDZWANZIG Fifa 2026', TournamentStatus::Signup, $now->addDays(3), ['game' => 'ea-sports-fc-26', 'mode' => '1v1']);
    $open = $made('21,000 Sats, Zero Ball Control', TournamentStatus::Signup, $now->addDays(4), ['game' => 'rocket-league', 'mode' => '3v3']);
    $running = $made('Friday Blitz at the Bitcoin Bar Berlin', TournamentStatus::Running, $now->subHour());
    $finished = $made('Blitz Night Leipzig', TournamentStatus::Finished, $now->subDays(8));
    $cards = [(string) $open->id, (string) $running->id, (string) $finished->id];
    $endClock = function (string $lang) use ($hero): string {
        app()->setLocale($lang);
        $clock = CupBoard::start($hero->expectedEnd()['typical'], 'America/New_York')['clock'];
        app()->setLocale('en');

        return $clock;
    };

    foreach ([[1440, 900, 'en'], [375, 812, 'en'], [1440, 900, 'de'], [375, 812, 'de']] as [$width, $height, $lang]) {
        $page = cupRegionsPage('/tournaments', $width, $height, '[data-test=organizer-card]');

        if ($lang === 'de') {
            $page->goto(ComputeUrl::from('/locale/de'));
            $page->goto(ComputeUrl::from('/tournaments'));
            BrowserWait::until($page, '() => window.Alpine && document.querySelector("[data-test=organizer-card]") !== null && document.fonts.status === "loaded"', 10_000);
        }

        if ($width === 1440 && $lang === 'en') {
            cupRegionsControl($page);
        }

        // Every cover drawn: scroll the lazy ones into view, then back.
        $page->evaluate('() => new Promise((resolve) => { window.scrollTo(0, document.body.scrollHeight); setTimeout(() => { window.scrollTo(0, 0); resolve(true); }, 400); })');
        BrowserWait::until($page, '() => [...document.querySelectorAll("[data-test=organizer-tournaments] [data-game-cover] img")].every((img) => img.complete && img.naturalWidth > 0)', 10_000);
        $state = $page->evaluate(ORGANIZER_STATE);
        cupRegionsShot($page, "organizers-first-{$width}-{$lang}");

        expect([$width, $lang, $state['lang'], $state['overflow']])->toBe([$width, $lang, $lang, 0])
            ->and($state['hero']['id'])->toBe((string) $hero->id)
            ->and($state['hero']['cover']['loaded'])->toBeTrue()
            ->and($state['hero']['cover']['loading'])->toBe('eager')
            ->and($state['hero']['bottom'])->toBeLessThan($state['board']['top'])
            ->and(array_column($state['cards'], 'id'))->toBe($cards)
            ->and(array_column($state['cards'], 'state'))->toBe(['open', 'running', 'finished'])
            // A guest in New York reads each start on their own clock, the hero's expected end too.
            ->and(array_unique(array_column($state['cards'], 'city')))->toBe(['New York'])
            ->and($page->evaluate('() => document.querySelector("[data-test=open-end]").innerText'))->toContain($endClock($lang));

        foreach ($state['cards'] as $card) {
            expect($card['bottom'])->toBeLessThan($state['board']['top'])
                ->and($card['cover']['loaded'])->toBeTrue()
                ->and($card['cover']['loading'])->toBe('lazy')
                ->and($card['cover']['ratio'])->toBeGreaterThanOrEqual(1.76)->toBeLessThanOrEqual(1.79)
                ->and($card['right'])->toBeLessThanOrEqual($state['width']);
        }

        // Large: at 1440 the hero's cover is at least 400 px high and a card's 170; on a phone every card spans the column.
        $width === 1440
            ? expect($state['hero']['cover']['height'])->toBeGreaterThanOrEqual(400)->and(min(array_column(array_column($state['cards'], 'cover'), 'height')))->toBeGreaterThanOrEqual(170)
            : expect(array_unique(array_column($state['cards'], 'width')))->toBe([343])->and($state['hero']['width'])->toBe(343);

        expect($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }
});

/*
 * The cups' head under the organizers' tournaments (P3 of plan
 * mempool-streifen; user, 2026-09-29: the cups "gehen ... total unter" and
 * nobody reads them as tournaments, "Trotzdem haben die anderen Turniere oben
 * drüber absolute Prio"). At 320, 375 and 1280 in English and German: the
 * organizers' hero and card come first and stay larger, the head says
 * tournament, shows the winner and the next cup with faces and a countdown
 * that ticks; at 375 and 1280 its sign-up button is in the head's first
 * screen. No overflow, no text box cut, and after a Livewire round trip the
 * console and the answers stay clean (positive control first).
 */
const CUP_HEAD_STATE = <<<'JS'
    () => {
        const box = (el) => { if (!el) return null; const r = el.getBoundingClientRect(); return { top: Math.round(r.top + scrollY), bottom: Math.round(r.bottom + scrollY), width: Math.round(r.width), height: Math.round(r.height) }; };
        const size = (el) => el ? parseFloat(getComputedStyle(el).fontSize) : 0;
        // Every element that holds text of its own, inside the head and the board rows: cut when its content is wider than its box.
        const own = (el) => [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim() !== '');
        const scope = [...document.querySelectorAll('[data-test=cup-hall], [data-test=cup-group]')];
        const cut = scope.flatMap((root) => [root, ...root.querySelectorAll('*')])
            .filter((el) => own(el) && el.checkVisibility() && !el.closest('.sr-only') && getComputedStyle(el).display !== 'inline' && el.scrollWidth > el.clientWidth + 1)
            .map((el) => (el.dataset.test || el.tagName) + ': ' + el.innerText.trim().slice(0, 40));
        // The floor of the screen: a bar fixed to the bottom (the phone's tab bar), else the window's bottom.
        const floor = Math.min(innerHeight, ...[...document.querySelectorAll('body *')].filter((el) => getComputedStyle(el).position === 'fixed' && el.checkVisibility() && Math.abs(el.getBoundingClientRect().bottom - innerHeight) < 2 && el.getBoundingClientRect().width > innerWidth / 2).map((el) => el.getBoundingClientRect().top));
        return {
            lang: document.documentElement.lang,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            floor: Math.round(floor),
            organizers: box(document.querySelector('[data-test=organizer-tournaments]')),
            heroName: size(document.querySelector('[data-test=next-tournament-name]')),
            heroCover: box(document.querySelector('[data-test=next-tournament-cover]')),
            hall: box(document.querySelector('[data-test=cup-hall]')),
            kind: document.querySelector('[data-test=cup-kind]')?.innerText.trim(),
            explainer: document.querySelector('[data-test=cup-explainer]')?.innerText.trim(),
            winner: document.querySelector('[data-test=cup-winner-name]')?.innerText.trim(),
            nextName: size(document.querySelector('[data-test=cup-next-name]')),
            nextCover: box(document.querySelector('[data-test=cup-next-cover]')),
            faces: [...document.querySelectorAll('[data-test=cup-next-face] img')].filter((img) => img.checkVisibility() && img.getBoundingClientRect().width >= 24).length,
            rowFaces: document.querySelectorAll('[data-test=cup-faces] img').length,
            cta: box(document.querySelector('[data-test=cup-next-cta]')),
            countdown: document.querySelector('[data-test=cup-next-countdown]')?.innerText.trim(),
            cut,
        };
    }
    JS;

test('the cups\' head says tournament and brings the winner and the next cup under the organizers\' tournaments, at 320, 375 and 1280 in English and German', function () {
    config(['esports.casual_cups.enabled' => ['chess', 'rocket-league', 'ea-sports-fc-26']]);
    // 39 characters without a break (review of P3: a long name widened the page from 21 characters at 320 px).
    wonCasualCup(User::factory()->create(['name' => 'SatoshiNakamotoTheFirstMinerOfBlockZero']), User::factory()->create(['name' => 'hal_finney']));
    app(CasualCups::class)->tick();
    // The FC 26 EU cup (Friday 18:00 Berlin) starts first, so it is the head's next cup.
    cupSignups(Tournament::query()->where('cup_open_series', 'ea-sports-fc-26-eu')->sole(), 3);
    $now = CarbonImmutable::now();
    $made = function (string $name, TournamentStatus $status, CarbonImmutable $startsAt, array $attributes = []): Tournament {
        $tournament = Tournament::factory()->create(['name' => $name, 'starts_at' => $startsAt, 'created_by_id' => organizer()->id, ...$attributes]);
        $tournament->forceFill(['status' => $status, 'published_at' => now()->subDay(), 'signup_closes_at' => $startsAt->subHour()])->save();

        return $tournament;
    };
    $made('EINUNDZWANZIG Fifa 2026', TournamentStatus::Signup, $now->addDays(3), ['game' => 'ea-sports-fc-26', 'mode' => '1v1']);
    $made('21,000 Sats, Zero Ball Control', TournamentStatus::Signup, $now->addDays(4), ['game' => 'rocket-league', 'mode' => '3v3']);
    $words = [
        'en' => ['Tournaments', 'The league opens a cup for every game and region on its own. Its games are casual and move only your casual Elo.'],
        'de' => ['Turniere', 'Die Liga eröffnet für jedes Spiel und jede Region selbst einen Cup. Seine Partien sind casual und bewegen nur dein Casual-Elo.'],
    ];

    foreach ([[1280, 900, 'en'], [375, 812, 'en'], [320, 640, 'en'], [1280, 900, 'de'], [375, 812, 'de'], [320, 640, 'de']] as [$width, $height, $lang]) {
        $page = cupRegionsPage('/tournaments', $width, $height, '[data-test=cup-hall]');
        // Every page of a run shares its cookies (the locale too), so each one says its language.
        $page->goto(ComputeUrl::from('/locale/'.$lang));
        $page->goto(ComputeUrl::from('/tournaments'));
        BrowserWait::until($page, '() => window.Alpine && document.querySelector("[data-test=cup-hall]") !== null && document.fonts.status === "loaded"', 10_000);

        if ($width === 1280 && $lang === 'en') {
            cupRegionsControl($page);
        }

        $page->evaluate('() => document.querySelector("[data-test=cup-hall]").scrollIntoView()');
        $state = $page->evaluate(CUP_HEAD_STATE);
        cupRegionsShot($page, "cup-head-{$width}-{$lang}");

        expect([$width, $lang, $state['lang'], $state['overflow'], $state['cut']])->toBe([$width, $lang, $lang, 0, []])
            ->and([$state['kind'], $state['explainer']])->toBe($words[$lang])
            ->and($state['winner'])->toBe('SatoshiNakamotoTheFirstMinerOfBlockZero')
            // The organizers first and larger: their block ends before the head, their hero's name and cover are bigger.
            ->and($state['organizers']['bottom'])->toBeLessThan($state['hall']['top'])
            ->and($state['heroName'])->toBeGreaterThan($state['nextName'])
            ->and($state['heroCover']['height'])->toBeGreaterThan($state['nextCover']['height'])
            // Faces: three in the next cup's seats, three in its board row.
            ->and([$state['faces'], $state['rowFaces']])->toBe([3, 3])
            ->and($state['cta']['height'])->toBeGreaterThanOrEqual(44);

        // The sign-up button in the head's first screen, above a phone's tab bar.
        if ($width >= 375) {
            expect($state['cta']['bottom'] - $state['hall']['top'])->toBeLessThanOrEqual($state['floor']);
        }

        // The countdown ticks in the browser.
        BrowserWait::until($page, '() => document.querySelector("[data-test=cup-next-countdown]").innerText.trim() !== '.json_encode($state['countdown']), 3_000);

        // At zero (the event the countdown sends) the button goes and the head says sign-up closed.
        $page->evaluate('() => document.querySelector("[data-test=cup-next-countdown]").dispatchEvent(new CustomEvent("countdown-zero", { bubbles: true }))');
        BrowserWait::until($page, '() => document.querySelector("[data-test=cup-next-closed]").checkVisibility() && !document.querySelector("[data-test=cup-next-cta]").checkVisibility()', 3_000);
        expect($page->evaluate(CUP_HEAD_STATE)['overflow'])->toBe(0)
            ->and($page->evaluate(CUP_HEAD_STATE)['cut'])->toBe([]);

        // A Livewire round trip on the page: the head stays, the console and the answers stay clean.
        $page->evaluate('() => window.Livewire.find(document.querySelector("[data-test=tournaments-index]").closest("[wire\\\\:id]").getAttribute("wire:id")).$refresh()');
        BrowserWait::until($page, '() => performance.getEntries().some((e) => e.name.includes("/livewire") && e.name.includes("update") && e.responseStatus > 0)', 5_000);
        expect($page->evaluate('() => document.querySelector("[data-test=cup-hall]") !== null'))->toBeTrue()
            ->and($page->evaluate('() => window.__errors'))->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }
});

/*
| A cup's format follows its sign-ups (user, 2026-10-03: "Am besten mit
| Heartbeat und ständiger Anpassung, damit die Live-Bracket-Preview auch
| stimmig bleibt"): the page is open while other players sign up, and the
| preview, the note and the format chip change by push (TournamentChanged),
| without a reload. Measured at 1440 and 375 in English and at 375 in German.
*/
const PLANNED_STATE = <<<'JS'
    () => {
        const preview = document.querySelector('[data-test=bracket-preview]');
        const note = document.querySelector('[data-test=format-follows]');
        const r = note ? note.getBoundingClientRect() : null;
        const svg = [...(preview?.querySelectorAll('svg') ?? [])].find((s) => s.checkVisibility());
        return {
            lang: document.documentElement.lang,
            format: preview?.dataset.format ?? null,
            players: Number(preview?.dataset.players ?? 0),
            note: note?.innerText.trim() ?? null,
            chip: document.querySelector('[data-test=tournament-format]')?.innerText.trim() ?? null,
            matches: document.querySelectorAll('[data-test=projected-match]').length,
            openSpots: [...document.querySelectorAll('[data-test=bracket]')].some((b) => b.innerText.includes('Open spot') || b.innerText.includes('Offener Platz')),
            svgWidth: svg ? Math.round(svg.getBoundingClientRect().width) : 0,
            noteInside: r !== null && r.left >= 0 && r.right <= window.innerWidth,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        };
    }
    JS;

test('the page of a cup follows the sign-ups by push, without a reload: round robin of 6, of 7, then a double elimination of 9', function () {
    // The push is the trigger; the 15 s poll only the fallback.
    expect(config('broadcasting.default'))->toBe('reverb', 'Run this through `composer test:browser`, which starts Reverb.');
    config(['esports.casual_cups.enabled' => ['chess']]);
    app(CasualCups::class)->tick();
    $cup = Tournament::query()->where('cup_open_series', 'chess-eu')->sole();
    cupSignups($cup, 6);
    $path = route('tournaments.show', $cup, false);

    $page = cupRegionsPage($path, 1440, 900, '[data-test=bracket-preview]');
    cupRegionsControl($page);
    $six = $page->evaluate(PLANNED_STATE);
    cupRegionsShot($page, 'cup-planned-6-1440-en');

    // Subscribed before anyone signs up; the page counts the pushes it hears.
    BrowserWait::until($page, '() => window.Echo?.connector.pusher.connection.state === "connected" && window.Echo.connector.channels["tournament.'.$cup->id.'"]?.subscription?.subscribed === true', 10_000);
    $page->evaluate('() => { window.__pushes = 0; window.Echo.connector.pusher.connection.bind("message", (m) => { if (m.event === "tournament.changed") window.__pushes++; }); }');
    $page->evaluate('() => { window.__sameDocument = "same-document"; }');

    // A 7th player signs up elsewhere: still a round robin, now of 7, within a few seconds (well under the 15 s poll).
    cupSignups($cup, 1);
    BrowserWait::until($page, '() => document.querySelector("[data-test=bracket-preview]")?.dataset.players === "7"', 5_000);
    $seven = $page->evaluate(PLANNED_STATE);

    // Two more: 9 players play a double elimination of 9.
    cupSignups($cup, 2);
    BrowserWait::until($page, '() => document.querySelector("[data-test=bracket-preview]")?.dataset.players === "9"', 5_000);
    $nine = $page->evaluate(PLANNED_STATE);
    cupRegionsShot($page, 'cup-planned-9-1440-en');

    expect($six)->toMatchArray(['format' => 'round-robin', 'players' => 6, 'note' => 'Format follows the sign-ups: 6 players → Round Robin', 'chip' => 'Round Robin', 'matches' => 3, 'openSpots' => false, 'overflow' => 0, 'noteInside' => true])
        ->and($six['svgWidth'])->toBeGreaterThan(300)
        ->and($seven)->toMatchArray(['format' => 'round-robin', 'players' => 7, 'note' => 'Format follows the sign-ups: 7 players → Round Robin', 'openSpots' => false])
        ->and($nine)->toMatchArray(['format' => 'double-elimination', 'players' => 9, 'note' => 'Format follows the sign-ups: 9 players → Double Elimination', 'chip' => 'Double Elimination', 'openSpots' => false, 'overflow' => 0])
        // No reload: the same document, and every step came by push.
        ->and($page->evaluate('() => window.__sameDocument'))->toBe('same-document')
        ->and($page->evaluate('() => window.__pushes'))->toBeGreaterThanOrEqual(2)
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

    // On a phone, in English and in German: the same plan, nothing wider than the window.
    foreach (['en', 'de'] as $lang) {
        $narrow = cupRegionsPage($path, 375, 812, '[data-test=bracket-preview]');

        if ($lang === 'de') {
            $narrow->goto(ComputeUrl::from('/locale/de'));
            $narrow->goto(ComputeUrl::from($path));
            BrowserWait::until($narrow, '() => window.Alpine && document.querySelector("[data-test=bracket-preview]") !== null && document.fonts.status === "loaded"', 10_000);
        }

        $narrow->evaluate('() => document.querySelector("[data-test=bracket]").scrollIntoView()');
        $state = $narrow->evaluate(PLANNED_STATE);
        cupRegionsShot($narrow, "cup-planned-9-375-{$lang}");

        expect([$lang, $state['lang'], $state['format'], $state['players'], $state['overflow'], $state['noteInside'], $state['openSpots']])->toBe([$lang, $lang, 'double-elimination', 9, 0, true, false])
            ->and($state['note'])->toBe($lang === 'de' ? 'Das Format folgt den Anmeldungen: 9 Spieler → Double Elimination' : 'Format follows the sign-ups: 9 players → Double Elimination')
            ->and($state['svgWidth'])->toBeGreaterThan(250)->toBeLessThanOrEqual(375)
            ->and($narrow->evaluate('() => window.__errors'))->toBe([])
            ->and($narrow->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }
});
