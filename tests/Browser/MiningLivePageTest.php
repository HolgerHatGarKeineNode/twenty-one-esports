<?php

use App\Models\Admin;
use App\Models\NostrEvent;
use App\Models\Season;
use App\Models\TrustRank;
use App\Models\TrustRun;
use App\Models\User;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\AnchoredTrustFacts;
use App\Support\SeasonChain\TrustFacts;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Support\ComputeUrl;
use Pest\Browser\Support\Screenshot;
use Tests\Support\BlockliOn;
use Tests\Support\BlockspaceOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\CheckersGame;
use Tests\Support\GoOn;
use Tests\Support\HyperOn;
use Tests\Support\NineMensMorrisOn;
use Tests\Support\TestSigner;

require_once __DIR__.'/../Support/key_eras.php';

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| /mining in a live season on the production share set, in the browser
|--------------------------------------------------------------------------
|
| Plan "mining-page-clarity" (P2 to P5), fix round after the review of
| 2026-11-21: the season runs on ChainDraft::defaults() with every game that
| is on in production switched on (chess 50, board games 30, Hyperbitcoinization
| 20), read by a guest and by a logged-in player whose standing chips show
| (an admin below the trust minimum without a mutual opponent: the longest
| chips), with no block and with 8 blocks (and a win that did not mine), in German at 390 and 1440 px and in
| English at 390 once, every disclosure closed. The primary button ends inside
| the first screen (above the tab bar on a phone), nothing is wider than the
| window or its card, the page stays within 2532 px at 390 and 2250 px at
| 1440, no module is taller than one phone screen, every block cube renders at
| its 108 px, a #block-N link opens the folded block list (on a hash change
| and on load), "How mining works" opens the reference at the end, and the
| console, the uncaught errors and every answer >= 400 stay empty on the first
| load and after a Livewire round-trip, with a positive control on the page.
|
| MINING_SHOTS=<dir> writes a full-page screenshot per run into that directory.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));

    config(['session.driver' => 'database', 'esports.chess.rated_queue' => true, 'esports.board_games.rated_queue' => true]);
    app()->bind(TrustFacts::class, AnchoredTrustFacts::class);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
});

/**
 * The live season as production runs it: every board game and Hyperbitcoinization switched on (ESPORTS_BOARD_GAMES,
 * ESPORTS_BOARD_GAME_*, ESPORTS_HYPER), the genesis on ChainDraft::defaults(), and `$blocks` wins of the three share
 * groups attested through the chain rules.
 */
function miningProdSeason(int $blocks): Season
{
    NineMensMorrisOn::play();
    CheckersGame::play();
    BlockliOn::play();
    BlockspaceOn::play();
    GoOn::play();
    HyperOn::play();

    $season = openSeason(['parameters' => [...Season::factory()->make()->parameters, ...seasonGenesisWithout()['parameters']]]);

    $keys = ['chess/blitz', 'blockli/blitz', 'hyperbitcoinization/live', 'chess/rapid', 'checkers/correspondence', 'chess/correspondence', 'go-9/blitz', 'chess/blitz'];
    keyWins($season, array_slice($keys, 0, $blocks));

    if ($blocks > 0) {
        // Production has wins that did not mine as well: their folded line belongs in the measurement.
        $season->attestations()->latest('id')->firstOrFail()->replicate()->forceFill([
            'source_id' => 799, 'height' => null, 'rule' => 2, 'reason' => 'too-few-moves', 'era' => null, 'reward_per_player' => 0, 'reward' => 0, 'event_id' => hash('sha256', 'mining-live-rejected'),
        ])->save();
    }

    return $season;
}

/** A trust run of the live season that ranks `$user` at `$rank`, so the trust chip names the rank (not "not computed yet"). */
function miningTrustRun(User $user, int $rank): void
{
    $trust = new TestSigner;
    $anchors = NostrEvent::fromSigned(SignedEvent::fromInput($trust->sign(30000, [['d', 'esports/x/anchors'], ['alt', 'anchors']])));
    $run = TrustRun::query()->create(['season_id' => Season::query()->latest('id')->value('id'), 'trust_pubkey' => $trust->pubkey, 'anchor_list_nostr_event_id' => $anchors->id,
        'anchors' => 0, 'lists' => 0, 'ranked' => 1, 'published' => 1, 'computed_at' => now()]);
    $assertion = NostrEvent::fromSigned(SignedEvent::fromInput($trust->sign(30382, [['d', $user->pubkey], ['p', $user->pubkey], ['rank', (string) $rank], ['alt', 'rank']])));
    TrustRank::query()->create(['pubkey' => $user->pubkey, 'rank' => $rank, 'raw' => 1.0, 'anchor_list_event_id' => $anchors->event_id,
        'trust_run_id' => $run->id, 'nostr_event_id' => $assertion->id, 'event_id' => $assertion->event_id]);
}

/** The page's geometry: the fold, the primary button, the height, every module, every block cube and every card that runs wider than itself. */
const MINING_LIVE_READ = <<<'JS'
() => {
    const root = document.querySelector('[data-test=mining]');
    const probe = document.createElement('div');
    probe.style.cssText = 'position:fixed;bottom:0;height:var(--tabbar-h);width:1px;visibility:hidden';
    document.body.appendChild(probe);
    const tabbar = Math.round(probe.getBoundingClientRect().height);
    probe.remove();
    const primary = document.querySelector('[data-test=mining-primary]')?.getBoundingClientRect();
    const shown = (el) => el.checkVisibility();
    const name = (el) => el.dataset.test || el.id || el.tagName.toLowerCase();
    // A module is a child of the page, except the two-column grid, a layout wrapper: its cards are the modules.
    const modules = [...root.children].filter(shown).flatMap((el) => el.dataset.test === 'mining-columns' ? [...el.querySelectorAll(':scope > div > *')].filter(shown) : [el])
        .map((el) => [name(el), Math.round(el.getBoundingClientRect().height)]);
    const wide = [...root.querySelectorAll('.rv-card, details, section, article')].filter(shown)
        .filter((el) => el.scrollWidth - el.clientWidth > 0)
        .map((el) => name(el) + ' ' + el.scrollWidth + '/' + el.clientWidth);
    const cubes = [...root.querySelectorAll('[data-test=chain-cube] .rv-cube')].map((el) => { const r = el.getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)]; });
    const cubeViewport = document.querySelector('[data-test=chain-cubes] .rv-xs-vp')?.getBoundingClientRect();
    return {
        lang: document.documentElement.lang,
        state: root.dataset.state,
        fold: window.innerHeight - tabbar,
        tabbar,
        primary: primary ? [Math.round(primary.top), Math.round(primary.bottom), Math.round(primary.height)] : null,
        height: Math.max(document.documentElement.scrollHeight, document.body.scrollHeight),
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        open: [...root.querySelectorAll('details[open]')].map(name),
        empty: root.querySelectorAll('[data-test=chain-empty]').length,
        chips: root.querySelectorAll('[data-test=mining-standing] li').length,
        anchors: [...root.querySelectorAll('[id^=block-]')].map((el) => el.id),
        cubes,
        cubeViewport: cubeViewport ? Math.round(cubeViewport.height) : null,
        games: (() => { const g = document.querySelector('[data-test=mining-games]'); return g ? g.scrollWidth - g.clientWidth : null; })(),
        modules,
        wide,
    };
}
JS;

test('the live season on the production share set reads within its limits: the primary button in the first screen, no overflow, the page short, every module within one phone screen, the cubes at size, the page quiet', function (string $viewer, int $blocks, string $locale, int $width, int $height) {
    $season = miningProdSeason($blocks);
    $maxHeight = $width < 1024 ? 2532 : 2250;

    if ($viewer === 'player') {
        // Logged in with the longest chips: below the trust minimum and no mutual opponent, both red with a link.
        $user = User::factory()->create(['name' => 'Erdöpfökasbrot', 'timezone' => 'UTC']);
        Admin::query()->create(['pubkey' => $user->pubkey]);
        miningTrustRun($user, max(0, $season->minimum_trust - 38));
        $page = visit(BrowserLogin::url($user))->page();
    } else {
        $page = visit(ComputeUrl::from(route('mining', [], false)))->page();
    }

    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('locale.switch', $locale, false)));
    $page->goto(ComputeUrl::from(route('mining', [], false)));
    $page->waitForLoadState('networkidle');
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Alpine !== undefined && document.fonts.status === "loaded"', 10_000);

    // Positive control: a thrown error and a failed answer reach the collector on this very page.
    $page->evaluate('() => { setTimeout(() => { throw new Error("mining live positive control"); }); fetch("/mining-live-positive-control-missing"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("mining live positive control")) && window.__errors.some((e) => e.startsWith("404 "))', 5_000);
    $page->evaluate('() => { window.__errors = []; performance.clearResourceTimings(); }');
    // The first load, less the control: a reload with the collector emptied by it.
    $page->reload();
    $page->waitForLoadState('networkidle');
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Alpine !== undefined && document.fonts.status === "loaded"', 10_000);
    $page->evaluate('() => new Promise((r) => setTimeout(r, 300))');

    $read = $page->evaluate(MINING_LIVE_READ);
    $read['squeezed'] = $page->evaluate(BrowserConsole::SQUEEZED, ['[data-test=mining]']);
    $read['first load'] = [...$page->evaluate('() => window.__errors'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)];

    if (($dir = getenv('MINING_SHOTS')) !== false && $dir !== '') {
        $page->screenshot(true, 'mining-live-tmp');
        rename(Screenshot::path('mining-live-tmp'), $dir."/mining-{$width}x{$height}-{$locale}-{$viewer}-{$blocks}blocks.png");
    }

    $page->evaluate('() => { window.__errors = []; performance.clearResourceTimings(); window.Livewire?.all().forEach((c) => c.$wire.$refresh()); }');
    $page->waitForLoadState('networkidle');
    $page->evaluate('() => new Promise((r) => setTimeout(r, 300))');
    $read['round-trip'] = [...$page->evaluate('() => window.__errors'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)];

    // "How mining works" in the hero opens the reference at the end.
    $page->evaluate('() => document.querySelector("[data-test=mining-how]").click()');
    $page->evaluate('() => new Promise((r) => setTimeout(r, 300))');
    $read['reference open'] = (bool) $page->evaluate('() => document.getElementById("season-reference").open');
    $read['after open'] = [...$page->evaluate('() => window.__errors'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)];

    if ($blocks > 0) {
        // The block anchors stay reachable with the list folded: a hash change (a cube, the Mempool chain) and a page
        // loaded with the hash both open the list and show the row.
        $reached = '([id]) => ({ open: document.getElementById(id)?.closest("details")?.open ?? false, shown: document.getElementById(id)?.checkVisibility() ?? false })';
        $page->evaluate('() => { location.hash = "#block-1"; return new Promise((r) => setTimeout(r, 300)); }');
        $read['anchor on hash change'] = $page->evaluate($reached, ['block-1']);
        $page->goto(ComputeUrl::from(route('mining', ['from' => 'mempool'], false)).'#block-2');
        $page->waitForLoadState('networkidle');
        BrowserWait::until($page, '() => document.readyState === "complete" && window.Alpine !== undefined', 10_000);
        $page->evaluate('() => new Promise((r) => setTimeout(r, 300))');
        $read['anchor on load'] = $page->evaluate($reached, ['block-2']);
        $read['after anchors'] = [...$page->evaluate('() => window.__errors'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)];
    }

    fwrite(STDERR, "mining live {$locale} {$viewer} {$blocks} blocks {$width}x{$height}: ".json_encode($read, JSON_UNESCAPED_UNICODE).PHP_EOL);

    $tallest = collect($read['modules'])->sortByDesc(1)->first();

    expect($read['lang'])->toBe($locale)
        ->and($read['state'])->toBe('live')
        ->and($read['empty'])->toBe($blocks === 0 ? 1 : 0)
        ->and($read['chips'])->toBe($viewer === 'player' ? 3 : 0)
        ->and($read['open'])->toBe([])
        ->and($read['primary'])->not->toBeNull()
        ->and($read['primary'][1])->toBeLessThanOrEqual($read['fold'], 'the primary button ends below the first screen')
        ->and($read['overflow'])->toBe(0)
        ->and($read['wide'])->toBe([])
        ->and($read['squeezed'])->toBe([])
        ->and($read['height'])->toBeLessThanOrEqual($maxHeight)
        ->and($width < 1024 ? $tallest[1] : 0)->toBeLessThanOrEqual(844, 'a module is taller than one phone screen: '.$tallest[0])
        // Every block cube at its 108 px (it rendered 0 x 0 inside an inline link), and every block keeps its anchor.
        ->and($read['cubes'])->toHaveCount(min($blocks, 6))
        ->and(array_unique(array_map(fn (array $size): string => implode('x', $size), $read['cubes'])))->toBe($blocks === 0 ? [] : ['108x108'])
        // The games line scrolls under an edge fade on a phone only; from 640 px on it wraps and hides no game.
        ->and($width >= 640 ? $read['games'] : 0)->toBe(0)
        ->and($read['anchors'])->toHaveCount($blocks)
        ->and($read['reference open'])->toBeTrue()
        ->and($read['first load'])->toBe([])
        ->and($read['round-trip'])->toBe([])
        ->and($read['after open'])->toBe([])
        ->and($read['anchor on hash change'] ?? null)->toBe($blocks === 0 ? null : ['open' => true, 'shown' => true])
        ->and($read['anchor on load'] ?? null)->toBe($blocks === 0 ? null : ['open' => true, 'shown' => true])
        ->and($read['after anchors'] ?? [])->toBe([]);
})->with([
    'guest, no block, German 390' => ['guest', 0, 'de', 390, 844],
    'guest, no block, German 1440' => ['guest', 0, 'de', 1440, 900],
    'player, no block, German 390' => ['player', 0, 'de', 390, 844],
    'player, no block, German 1440' => ['player', 0, 'de', 1440, 900],
    'guest, 8 blocks, German 390' => ['guest', 8, 'de', 390, 844],
    'guest, 8 blocks, German 1440' => ['guest', 8, 'de', 1440, 900],
    'player, 8 blocks, German 390' => ['player', 8, 'de', 390, 844],
    'player, 8 blocks, German 1440' => ['player', 8, 'de', 1440, 900],
    'player, 8 blocks, English 390' => ['player', 8, 'en', 390, 844],
]);
