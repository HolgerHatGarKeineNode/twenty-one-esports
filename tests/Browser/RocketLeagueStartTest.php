<?php

use App\Enums\PayoutStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\Tournament;
use App\Models\TournamentPayout;
use App\Models\User;
use App\Support\Tournaments\CasualCups;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The Rocket League page at four widths (plan "RL-Startseite", P4)
|--------------------------------------------------------------------------
|
| User, 2026-10-07: the game chat now stands as a column on the right of a
| desktop, so the page's own column is narrower; squeezed tiles, cut labels,
| wrapped buttons and cards narrower than their content are defects. At 390,
| 1024, 1280 and 1440 px every tile, tab, card and button of the new parts
| is measured: no sideways overflow of the page, no text wider than its box,
| buttons at least 44 px high, the Tournaments tile in the first screen at
| 390 and the tile, the tab and "All Rocket League tournaments" in the first
| screen at 1440. The console stays empty (thrown errors and console.error,
| with a positive control) and no response is 400 or above.
|
*/

const RL_START_COLLECTOR = <<<'JS'
    window.__errors = [];
    const push = (entry) => window.__errors.push(entry);
    const originalError = console.error;
    console.error = function (...args) { push('console.error: ' + args.map(String).join(' ')); originalError.apply(console, args); };
    window.addEventListener('error', (e) => push('error: ' + (e.message || (e.target && (e.target.src || e.target.href)) || 'unknown')), true);
    window.addEventListener('unhandledrejection', (e) => push('unhandledrejection: ' + String(e.reason)));
    const originalFetch = window.fetch;
    window.fetch = (...args) => originalFetch(...args).then((r) => { if (r.status >= 400) push(r.status + ' ' + r.url); return r; });
    JS;

const RL_START_BAD_RESPONSES = <<<'JS'
    () => performance.getEntries()
        .filter((e) => typeof e.responseStatus === 'number' && e.responseStatus >= 400)
        .map((e) => e.responseStatus + ' ' + e.name)
    JS;

/** Boxes of the new parts, the page's overflow, text wider than its box, low buttons and the first-screen checks. */
const RL_START_MEASURE = <<<'JS'
    () => {
        const box = (el) => { const r = el.getBoundingClientRect(); return { x: Math.round(r.left), y: Math.round(r.top + scrollY), w: Math.round(r.width), h: Math.round(r.height) }; };
        const one = (s) => { const el = document.querySelector(s); return el && el.checkVisibility() ? box(el) : null; };
        const regions = [...document.querySelectorAll('[data-test=game-start], [data-test=prize-band]')];
        const inside = (s) => regions.flatMap((r) => [...r.querySelectorAll(s)]).filter((el) => el.checkVisibility());
        const floor = (() => { const bar = document.querySelector('[data-test=tab-bar]'); return bar && bar.checkVisibility() ? Math.round(bar.getBoundingClientRect().top) : innerHeight; })();
        const firstScreen = (s) => { const el = document.querySelector(s); if (!el || !el.checkVisibility()) return false; const r = el.getBoundingClientRect(); return r.top >= 0 && r.bottom <= floor; };
        const label = (el) => (el.dataset.test ?? el.tagName.toLowerCase()) + (el.dataset.tile ? '=' + el.dataset.tile : '') + ' "' + (el.innerText || '').trim().replace(/\s+/g, ' ').slice(0, 40) + '"';
        return {
            vw: innerWidth, vh: innerHeight, floor,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            start: one('[data-test=game-start]'), band: one('[data-test=prize-band]'), chat: one('[data-test=game-chat]'),
            tiles: [...document.querySelectorAll('[data-test=start-tile]')].map((el) => ({ tile: el.dataset.tile, ...box(el) })),
            tabs: [...document.querySelectorAll('[data-test=ctx-prizes]')].map((el) => ({ tab: el.innerText.trim().replace(/\s+/g, ' '), ...box(el) })),
            cards: ['next-tournament-empty', 'next-tournament', 'prize-last', 'prize-cups'].map((t) => ({ card: t, box: one('[data-test=' + t + ']') })).filter((c) => c.box !== null),
            buttons: inside('a.btn-p, a.btn-s, a.btn-w, button, [data-test=start-tile], [data-test=section-tab], [data-test=section-tab-tournaments]').map((el) => ({ el: label(el), ...box(el) })),
            low: inside('a.btn-p, a.btn-s, a.btn-w, button, [data-test=start-tile], [data-test=section-tab], [data-test=section-tab-tournaments], [data-test=prize-last-name]').filter((el) => el.getBoundingClientRect().height < 44).map(label),
            cut: inside('*').filter((el) => !(el instanceof SVGElement) && el.clientWidth > 0 && el.scrollWidth > el.clientWidth + 1).map(label),
            wrapped: inside('a.btn-p, a.btn-s, a.btn-w, button').filter((el) => el.getBoundingClientRect().height > 46).map(label),
            first: { tile: firstScreen('[data-tile=tournaments]'), tab: firstScreen('[data-test=ctx-prizes]'), all: firstScreen('[data-test=prize-band-all]') },
            order: [...document.querySelectorAll('[data-test=start-tile]')].map((el) => el.dataset.tile),
            // Squeezed text (user, 2026-10-07: widths cramped beside the chat): a sentence of more than 6 words wrapped into a box under 240 px wide and over 60 px high.
            squeezed: [...document.querySelectorAll('main p, main h2, main h3')].filter((el) => el.checkVisibility() && el.innerText.trim().split(/\s+/).length > 6 && el.getBoundingClientRect().width < 240 && el.getBoundingClientRect().height > 60).map(label),
        };
    }
    JS;

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    config(['session.driver' => 'database', 'esports.league.nsec' => (new TestSigner)->secret]);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
});

/** Tournament #2 as it ran: a finished Rocket League knockout, 23 310 sats paid to Industrie_KPI, AncapCrab and El Presidento Ben. */
function rlStartWorld(): void
{
    $tournament = runningChess(TournamentFormat::SingleElimination, 4);
    playOutAsDirector($tournament);

    foreach ($tournament->participants()->orderBy('seed')->get() as $i => $entry) {
        $entry->forceFill(['name' => ['Industrie_KPI', 'AncapCrab', 'El Presidento Ben', 'Mempool Max'][$i]])->save();
    }

    $tournament->forceFill(['name' => 'Rocket League Cup #2', 'game' => 'rocket-league', 'mode' => '3v3', 'published_at' => now(),
        'pot_source' => Tournament::POT_LEAGUE, 'prize_target_sats' => 23_310, 'starts_at' => now()->subDays(2)])->save();

    foreach ([1 => 11_655, 2 => 6_993, 3 => 4_662] as $place => $sats) {
        $pubkey = str_pad((string) $place, 64, 'b');
        TournamentPayout::query()->create(['tournament_id' => $tournament->id, 'pubkey' => $pubkey, 'name' => "Place {$place}", 'place' => $place,
            'amount_sats' => $sats, 'idempotency_key' => TournamentPayout::keyFor($tournament->id, $pubkey, $place), 'status' => PayoutStatus::Paid]);
    }
}

function rlStartPage(User $user, int $width, int $height, string $lang = 'en'): Page
{
    $to = route('games.rocket-league', ['lang' => $lang], false);
    $page = visit(route('testing.login', ['user' => $user, 'to' => route('robots', absolute: false)]))->page();
    $page->context()->addInitScript(RL_START_COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from($to));
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Livewire !== undefined && document.querySelector("[data-test=prize-band]") !== null', 10_000);

    return $page;
}

function rlStartShot(Page $page, string $name): void
{
    $dir = getenv('RL_START_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(false, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
    // The band on its own, below the first screen.
    $page->screenshotElement('[data-test=prize-band]', $name.'-band');
    File::move(base_path('tests/Browser/Screenshots/'.$name.'-band.png'), $dir.'/'.$name.'-band.png');
}

test('the Rocket League page at 390, 1024, 1280 and 1440: nothing squeezed, cut or overflowing, Tournaments & prizes first and in the first screen, a quiet console', function () {
    rlStartWorld();
    $player = User::factory()->create();
    $report = [];

    foreach ([[390, 844], [1024, 768], [1280, 800], [1440, 900]] as [$width, $height]) {
        $page = rlStartPage($player, $width, $height);
        $m = $page->evaluate(RL_START_MEASURE);
        $report[$width] = $m;
        rlStartShot($page, "rl-start-{$width}");

        if (is_string($file = getenv('RL_START_REPORT')) && $file !== '') {
            file_put_contents($file, json_encode($report, JSON_PRETTY_PRINT));
        }
        $label = "{$width}x{$height}";

        expect($m['overflow'])->toBe(0, "{$label}: sideways overflow")
            ->and($m['order'])->toBe(['tournaments', 'play', 'ladder', 'latest'], "{$label}: tile order")
            ->and($m['cut'])->toBe([], "{$label}: text wider than its box")
            ->and($m['low'])->toBe([], "{$label}: buttons below 44 px")
            ->and($m['wrapped'])->toBe([], "{$label}: a button wrapped onto two lines")
            ->and($m['first']['tile'])->toBeTrue("{$label}: Tournaments tile in the first screen");

        // No tile narrower than 150 px or squeezed below its content; all four the same width in a row.
        foreach ($m['tiles'] as $tile) {
            expect($tile['w'])->toBeGreaterThanOrEqual(150, "{$label}: tile {$tile['tile']} {$tile['w']} px wide");
        }

        // A card never runs under the chat column.
        if ($m['chat'] !== null && $width >= 1280) {
            foreach ([...$m['tiles'], ...array_column($m['cards'], 'box')] as $box) {
                expect($box['x'] + $box['w'])->toBeLessThanOrEqual($m['chat']['x'], "{$label}: a box runs under the chat");
            }
        }

        expect($m['squeezed'])->toBe([], "{$label}: squeezed text");

        if ($width === 1440) {
            expect($m['first']['tab'])->toBeTrue("{$label}: the accented Tournaments & prizes entry in the game bar")
                ->and($m['first']['all'])->toBeTrue("{$label}: All Rocket League tournaments in the first screen");
        }

        // A roundtrip: the console stays quiet after it too.
        $page->evaluate('() => { window.__refreshed = false; let el = document.querySelector("[data-test=game-page]"); while (el && !el.hasAttribute("wire:id")) { el = el.parentElement; } const id = el.getAttribute("wire:id"); Livewire.find(id).$refresh().then(() => { window.__refreshed = true; }); }');
        BrowserWait::until($page, '() => window.__refreshed === true', 10_000);
        expect($page->evaluate('() => window.__errors'))->toBe([], "{$label}: console")
            ->and($page->evaluate(RL_START_BAD_RESPONSES))->toBe([], "{$label}: responses >= 400");
    }

    // The band's button leads to the Rocket League tournaments.
    $page->locator('[data-test=prize-band-all]')->click();
    BrowserWait::until($page, '() => location.pathname === "/tournaments" && new URLSearchParams(location.search).get("game") === "rocket-league" && document.querySelector("[data-test=tournaments-game-filter]") !== null', 10_000);
});

test('an open tournament\'s poster in the band fits the narrow column beside the chat at 390, 1024 and 1280', function () {
    rlStartWorld();
    Tournament::factory()->rocketLeague()->create(['name' => 'Rocket League Friday Night Open', 'status' => TournamentStatus::Signup, 'published_at' => now(),
        'slug' => 'rl-friday-night-open', 'signup_closes_at' => now()->addDays(2), 'starts_at' => now()->addDays(3), 'pot_source' => Tournament::POT_LEAGUE, 'prize_target_sats' => 50_000]);
    $player = User::factory()->create();

    foreach ([[390, 844], [1024, 768], [1280, 800]] as [$width, $height]) {
        $page = rlStartPage($player, $width, $height);
        $m = $page->evaluate(RL_START_MEASURE);
        rlStartShot($page, "rl-start-open-{$width}");
        $label = "open {$width}x{$height}";

        expect($m['cards'][0]['card'] ?? null)->toBe('next-tournament', $label)
            ->and($m['overflow'])->toBe(0, "{$label}: sideways overflow")
            ->and($m['cut'])->toBe([], "{$label}: text wider than its box")
            ->and($m['low'])->toBe([], "{$label}: buttons below 44 px")
            ->and($page->evaluate('() => window.__errors'))->toBe([], "{$label}: console")
            ->and($page->evaluate(RL_START_BAD_RESPONSES))->toBe([], "{$label}: responses >= 400");
    }
});

test('the collector sees a thrown error, a console error and a failed response (positive control)', function () {
    rlStartWorld();
    $page = rlStartPage(User::factory()->create(), 1440, 900);
    $page->evaluate('() => { const img = new Image(); img.src = "/__rl-start-missing.png"; document.body.append(img); }');
    $page->evaluate('() => { console.error("console control"); setTimeout(() => { throw new Error("thrown control"); }); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("thrown control")) && window.__errors.some((e) => e.includes("console control"))', 5_000);
    BrowserWait::until($page, '() => performance.getEntries().some((e) => e.name.endsWith("/__rl-start-missing.png") && e.responseStatus === 404)', 5_000);

    expect(implode("\n", $page->evaluate(RL_START_BAD_RESPONSES)))->toMatch('#^404 http://\S+/__rl-start-missing\.png$#m');
});

test('as an admin in German, the longest copy and the extra Create tournament button squeeze nothing beside the chat', function () {
    // User, 2026-10-07: "Nächstes Turnier" one word a line next to Create tournament + watch, and the cup explainer squeezed; the player/English run missed both.
    rlStartWorld();
    $admin = User::factory()->withPubkey(str_repeat('ad', 32))->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    // Rocket League's casual cups, so the cup head (explainer + countdown) renders as on prod.
    foreach (array_keys(CasualCups::regions()) as $region) {
        app(CasualCups::class)->ensure('rocket-league', $region);
    }

    // 1600 and 1920 too: the band crosses 42rem there (680 px at 1600), where the old row layout squeezed the text.
    foreach ([[390, 844], [1024, 768], [1280, 800], [1440, 900], [1600, 900], [1920, 1080]] as [$width, $height]) {
        $m = rlStartPage($admin, $width, $height, 'de')->evaluate(RL_START_MEASURE);
        $label = "admin de {$width}x{$height}";

        expect($m['overflow'])->toBe(0, "{$label}: sideways overflow")
            ->and($m['squeezed'])->toBe([], "{$label}: squeezed text")
            ->and($m['cut'])->toBe([], "{$label}: text wider than its box")
            ->and($m['wrapped'])->toBe([], "{$label}: a button wraps");
    }
});
