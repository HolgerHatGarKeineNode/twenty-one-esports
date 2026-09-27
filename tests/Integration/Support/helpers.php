<?php

/*
 * Global helpers for tests/Integration (loaded from tests/Pest.php),
 * mirroring tests/Browser's own helpers (BrowserLogin, TestSigner) but
 * pointed at Tests\Integration\Support\Stack's real, separate app server
 * instead of Pest\Browser's in-process one.
 */

use App\Models\NostrEvent;
use App\Models\Season;
use App\Models\TrustRank;
use App\Models\TrustRun;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\Opponents;
use App\Support\SeasonChain\SeasonChains;
use App\Support\SeasonChain\Seasons;
use Illuminate\Support\Facades\Cache;
use Pest\Browser\Playwright\Client;
use Pest\Browser\Playwright\Page;
use Tests\Integration\Support\Stack;
use Tests\Support\BrowserConsole;
use Tests\Support\TestSigner;

/**
 * A throwaway-keyed player, as a real User row in the stack's own DB (this
 * test process is attach()ed to the same SQLite file the app server reads).
 *
 * @return array{0: User, 1: TestSigner}
 */
function integrationPlayer(string $name): array
{
    $signer = new TestSigner;
    $user = User::factory()->create(['name' => $name, 'pubkey' => $signer->pubkey, 'npub' => NostrKeys::hexToNpub($signer->pubkey), 'locale' => 'en']);
    Cache::forever('test-nostr-secret:'.$user->id, $signer->secret);

    return [$user, $signer];
}

/** A relative path ('/foo') or an already-absolute URL becomes absolute against Stack::baseUrl. */
function integrationUrl(string $urlOrPath): string
{
    return str_starts_with($urlOrPath, 'http://') || str_starts_with($urlOrPath, 'https://')
        ? $urlOrPath
        : Stack::instance()->baseUrl.$urlOrPath;
}

/**
 * route($name, $params, false) — the relative form every OTHER browser test
 * in this repo already uses for the same reason: measured 2026-09-27, this
 * process's own route($name) (absolute, the default) can return a HOST that
 * does not match Stack::$baseUrl (a stale root URL the generator resolved
 * before this test's own config(['app.url' => ...]) took effect), sending a
 * page to a port nothing in this run is listening on — or, once, to a
 * plain `/login` that only looks like the right server. integrationPage()/
 * integrationGoto() already turn a relative path into baseUrl+path, so
 * building it relative here removes host resolution from the equation
 * entirely instead of trying to make it agree with Stack::$baseUrl.
 *
 * @param  mixed  $parameters
 */
function integrationRoute(string $name, $parameters = []): string
{
    return (string) route($name, $parameters, false);
}

/**
 * Pest\Browser's own in-process server proxies public/build/* through a
 * cached route (routes/testing.php, Tests\Support\BrowserAssets) precisely
 * because serving it plainly is slow; Stack's real `php artisan serve` has
 * no such proxy and serves every font/asset request through one
 * single-threaded PHP process. Measured 2026-09-27: landing the login
 * redirect DIRECTLY on a heavy Livewire page (challenges/create) inside one
 * `visit()` call intermittently ends up back on /login even with a raised
 * navigation timeout (plain HTTP through the same route + session was
 * always 200 with the right content — a client-side issue, not the
 * fixture), while landing on a static file first, then a SEPARATE, explicit
 * goto() to the real target — the same two-step shape
 * Tests\Support\BrowserLogin already uses for the in-process server — did
 * not reproduce it in the same number of tries. Kept as two steps here for
 * that reason, and the nav timeout raised regardless since asset weight
 * still varies per page.
 */
const INTEGRATION_NAV_TIMEOUT_MS = 60_000;

/** Served by the real app server straight from public/, no app/session/Livewire request. */
const INTEGRATION_LANDING = '/robots.txt';

/**
 * A fresh browser context logged in as $user against the real app server
 * (never Pest\Browser's own in-process one: routes/testing.php's login
 * fixture works the same over real HTTP, only APP_ENV=testing gates it),
 * with the stubbed window.nostr signer wired to the real server's
 * `/__test/nostr/{user}/sign`, on $urlOrPath.
 */
/**
 * @param  array{width?: int, height?: int}  $viewport  set on the CONTEXT
 *                                                      before any page load, not via a later setViewportSize() call
 */
function integrationPage(User $user, string $urlOrPath = '/', array $viewport = []): Page
{
    $stack = Stack::instance();
    $loginUrl = $stack->baseUrl.'/__test/login/'.$user->id.'?to='.rawurlencode(INTEGRATION_LANDING);

    $options = ['timeout' => INTEGRATION_NAV_TIMEOUT_MS];

    if ($viewport !== []) {
        $options['viewport'] = ['width' => $viewport['width'] ?? 1280, 'height' => $viewport['height'] ?? 800];
    }

    $page = visit($loginUrl, $options)->page();
    // Installed BEFORE TestSigner's stub for the same reason both are
    // addInitScript() calls at all: the real app server is a separate
    // process, so a signing/Livewire failure here shows up nowhere a
    // server-side test could see it (tests/Browser/ClanEditTest.php's own
    // collector, reused here — BrowserWait::until() reads window.__errors on
    // a timeout so a hung wait names the JS error instead of just the
    // selector that never appeared).
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript(TestSigner::browserStub($user));

    // Page::reload() (used after a real-server round trip) sends no per-call
    // timeout of its own, so it falls back to the Playwright Client's own
    // 5s default (Client::$timeout) — too short for the same asset-weight
    // reason goto() needs INTEGRATION_NAV_TIMEOUT_MS. Raising it here, once
    // the driver connection exists (visit() above just created it), covers
    // every later reload()/click()/etc. this page or any other makes.
    Client::instance()->setTimeout(INTEGRATION_NAV_TIMEOUT_MS);

    if ($urlOrPath !== INTEGRATION_LANDING) {
        integrationGoto($page, $urlOrPath);
    }

    return $page;
}

/** Navigate an existing integration page to another path/URL on the same real server. */
function integrationGoto(Page $page, string $urlOrPath): void
{
    $page->goto(integrationUrl($urlOrPath), ['timeout' => INTEGRATION_NAV_TIMEOUT_MS]);
}

/**
 * A live season (Block 0 an hour ago), signed with the SAME league key the
 * real app server was started with (Stack::$leagueSecret) — unlike the
 * generic openSeason() helper (tests/Pest.php), which mints its own random
 * league key and would sign genesis with a key the real server never has:
 * every League Attestation the server itself signs later must reference
 * (`prev`) or share a season with this same genesis.
 *
 * @param  array<string, mixed>  $attributes
 */
function integrationOpenSeason(array $attributes = []): Season
{
    $league = new TestSigner(Stack::instance()->leagueSecret);

    // Idempotent across test FILES sharing one Stack singleton in a single
    // `composer test:integration` run (never `--parallel`, so this genuinely
    // is one process for the whole suite): a second call from a different
    // test would otherwise re-insert the same `slug` (SeasonFactory's own
    // default, "pre-season") and hit seasons.slug's unique constraint. Every
    // caller uses the SAME Stack::$leagueSecret, so reusing the season this
    // key already opened is correct, not a workaround.
    $existing = Season::query()->where('league_pubkey', $league->pubkey)->first();

    if ($existing !== null) {
        return $existing;
    }

    $season = Season::factory()->make(['league_pubkey' => $league->pubkey, ...$attributes]);
    $genesis = NostrEvent::fromSigned(SignedEvent::fromInput($league->sign(
        SeasonChains::GENESIS,
        [['season', $season->slug], ['alt', 'Season Genesis']],
        $season->genesis_message,
        $season->genesis_at->getTimestamp(),
    )));
    $season->genesis_event_id = $genesis->id;
    $season->save();

    return $season;
}

/**
 * Trusted (rank 100, above season minimum) for the live season, the way
 * tests/Browser/OpponentRatedTest.php seeds it directly rather than running
 * the real trust job: a fake trust key's kind-0/30382 rows are enough for
 * App\Support\SeasonChain\AnchoredTrustFacts, which only reads TrustRank.
 *
 * @param  list<string>  $pubkeys
 */
function integrationTrust(array $pubkeys): void
{
    $season = Seasons::live() ?? throw new RuntimeException('integrationTrust(): open a season first.');
    $trust = new TestSigner;
    $anchors = NostrEvent::fromSigned(SignedEvent::fromInput($trust->sign(30000, [['d', 'esports/integration/anchors'], ['alt', 'anchors']])));
    $run = TrustRun::query()->create([
        'season_id' => $season->id, 'trust_pubkey' => $trust->pubkey, 'anchor_list_nostr_event_id' => $anchors->id,
        'anchors' => 0, 'lists' => 0, 'ranked' => count($pubkeys), 'published' => count($pubkeys), 'computed_at' => now(),
    ]);

    foreach ($pubkeys as $pubkey) {
        $assertion = NostrEvent::fromSigned(SignedEvent::fromInput($trust->sign(30382, [['d', $pubkey], ['p', $pubkey], ['rank', '100'], ['alt', 'rank']])));
        TrustRank::query()->updateOrCreate(['pubkey' => $pubkey], [
            'rank' => 100, 'raw' => 1.0, 'anchor_list_event_id' => $anchors->event_id,
            'trust_run_id' => $run->id, 'nostr_event_id' => $assertion->id, 'event_id' => $assertion->event_id,
        ]);
    }
}

/** Both users list each other as opponents (NIP "Trust gate" condition 1), signed by each. */
function integrationMutualList(User $a, TestSigner $signerA, User $b, TestSigner $signerB): void
{
    $opponents = app(Opponents::class);
    $opponents->add($a, $b, $signerA->signTemplates($opponents->prepareAdd($a, $b)));
    $opponents->add($b, $a, $signerB->signTemplates($opponents->prepareAdd($b, $a)));
}
