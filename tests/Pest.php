<?php

use App\Models\NostrEvent;
use App\Models\Season;
use App\Models\SeasonSettingChange;
use App\Support\Nostr\SignedEvent;
use App\Support\Rating\RatingSettings;
use App\Support\SeasonChain\ChainDraft;
use App\Support\SeasonChain\LadderEvents;
use App\Support\SeasonChain\LeagueKey;
use App\Support\SeasonChain\SeasonChains;
use App\Support\Voice\ChatFactCheck;
use App\Support\Voice\StockCoherenceJudge;
use App\Support\Voice\VoiceFactCheck;
use App\Support\Voice\WebLookupAgent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Sleep;
use Pest\Evals\Drivers\LaravelAiJudge;
use Tests\Integration\Support\Stack;
use Tests\Support\BrowserAssets;
use Tests\Support\BrowserBodylessFraming;
use Tests\Support\TestSigner;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->tia()->defaultBranch('master');

// Before anything can ask for the temp directory: see the header of PrivateTempDir.php.
require_once __DIR__.'/Support/PrivateTempDir.php';
// Before anything can autoload swentel\nostr\Sign\Sign: see the header of FastSign.php.
require_once __DIR__.'/Support/FastSign.php';
// Per-test timings, only when TEST_PROFILE_FILE is set: see scripts/test-profile.php.
require_once __DIR__.'/Support/TestProfile.php';
require_once __DIR__.'/Support/tournaments.php';
require_once __DIR__.'/Support/casual.php';
require_once __DIR__.'/Support/casual_cups.php';
require_once __DIR__.'/Support/wallet.php';
require_once __DIR__.'/Support/twentyone_stream.php';
require_once __DIR__.'/Support/season_settlement.php';
require_once __DIR__.'/Support/shares.php';
require_once __DIR__.'/Support/navigation.php';
require_once __DIR__.'/Support/shell.php';
require_once __DIR__.'/Support/me.php';
require_once __DIR__.'/Support/move_history.php';
require_once __DIR__.'/Support/mempool.php';
require_once __DIR__.'/Support/scores.php';
require_once __DIR__.'/Support/tmnf.php';
require_once __DIR__.'/Support/league_weeks.php';
require_once __DIR__.'/Support/hyper.php';
require_once __DIR__.'/Support/node.php';
require_once __DIR__.'/Support/blockli.php';
require_once __DIR__.'/Support/caster.php';
require_once __DIR__.'/Integration/Support/helpers.php';

// No test of the default suite may reach the real network: an unfaked
// Http call (App\Support\Membership asked the live Verein API at
// verein.einundzwanzig.space on every page that shows a member badge) costs a
// DNS lookup plus a TLS handshake, makes the result depend on the outside
// world, and was measured at ~7 % of one test's wall time. A stray request now
// throws; the app code that guards its calls treats that like an unreachable
// host, exactly as before, only instantly.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        Http::preventStrayRequests();
        // A sleep on a frozen clock (travelTo, setTestNow) moves nothing but the wall clock of the test: the daily
        // caps of ProfileVarietyTest waited a second per published tournament (TournamentPublisher::publishCalendar()
        // sleeps until the next second), caster:chat 55 s in one test. Skipped when Carbon has a test now; on the real
        // clock a sleep still sleeps, because there it is what lets the next signature be dated later. A test that
        // asserts on its sleeps fakes Sleep itself, which replaces this.
        Sleep::fake();
        Sleep::whenFakingSleep(function (CarbonInterval $duration): void {
            if (! Carbon::hasTestNow()) {
                usleep((int) $duration->totalMicroseconds);
            }
        });
        // The league voice's fact check is a model call: cleared by default, the voice tests fake their own answers.
        VoiceFactCheck::fake(fn (): array => ['claims' => []]);
        ChatFactCheck::fake(fn (): array => ['claims' => []]);
        // A chat question's web lookup is a model call too: nothing to look up by default (ChatContextTest fakes findings).
        WebLookupAgent::fake(fn (): string => WebLookupAgent::NOTHING);
        // The day stock's coherence judge is a model call: every score passes by default (StockCoherenceTest fakes weak ones).
        StockCoherenceJudge::fake(fn (): array => ['premise' => 5, 'through_line' => 5, 'payoff' => 5, 'fit' => 5, 'liveliness' => 5, 'sense' => 5, 'nonsense' => [], 'invented' => [], 'hint' => '']);
        // Quiet hours follow the wall clock: off by default so a run after one at night tests the day schedule (QuietHoursTest switches them on).
        config(['tv.commentary.spool.quiet_hours.from' => '']);
        // The stream command refreshes the cover picture at config('twentyone.stream.cover.path'); with the fake renderer
        // (tests/Support/twentyone_stream.php) it wrote a 4-byte "PNG" into the checkout's real storage/app/stream/cover.png,
        // where /stream/cover.png then served it to whatever read it next (measured 2026-10-04: TwentyOneStreamTest, Part2, Part3).
        config(['twentyone.stream.cover.path' => storage_path('framework/testing/stream-cover/'.getmypid().'/cover.png')]);
    })
    ->group('feature')
    ->in('Feature');

pest()->group('unit')->in('Unit');

pest()->extend(TestCase::class)->group('nostr')->in('Nostr');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->group('relay')
    ->in('Relay');

// The Playwright client's own request timeout defaults to 5s (every click(),
// fill(), goto() and evaluate() round-trip shares it), which is also the
// plugin's default per-element wait. Under host CPU contention (parallel
// agent sessions on a shared box) that single fixed budget is what actually
// throws "Playwright 5s timeout" from inside a locator action, not from any
// wait this suite wrote — raising it here is the documented fix, not a
// per-test wait-for-N-seconds. tests/Support/BrowserWait.php still narrows
// this back down around its own polling calls so a raised default here does
// not silently swallow its shorter, condition-specific budgets.
pest()->browser()->timeout(15_000);

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // Browser tests run their pages through this same in-process app
    // (Pest\Browser\Drivers\LaravelHttpServer), reading public/build/*
    // directly (scripts/test-browser.sh runs `npm run build` first). If a
    // developer's `composer dev` happens to be running elsewhere and its
    // public/hot file exists, @vite() would otherwise point every browser
    // test's page at the (unrelated, maybe-not-running-here) Vite dev
    // server instead of the built manifest — the page loads with a
    // "failed to connect to websocket" console error and stale/missing
    // assets. Point at a path that never exists instead of touching or
    // deleting the developer's real public/hot.
    //
    // The built assets come through /__test/assets/ (Tests\Support\BrowserAssets),
    // which lets the browser cache them within a context.
    //
    // A 204 leaves the in-process server with a Content-Length of 0 (Tests\Support\BrowserBodylessFraming):
    // framed as chunked it ends in a late "0\r\n\r\n" that breaks the page's next fetch on the same socket.
    //
    // The session cookie is a browser session cookie here (no Expires). Laravel stamps Expires with its own
    // (frozen or travelled) clock, Chromium judges it against the real one: a test that travelTo()s a day that
    // is more than session.lifetime (120 min) behind the wall clock got a cookie that was already expired,
    // so the login, the chosen locale and the CSRF session vanished on the next page (measured 2026-10-09 on
    // b80beef0: the tests that travel to 2026-10-07 turned red once the real date left that day). The
    // server-side session lifetime still runs on the app's clock; tests/Browser/BrowserLoginClockTest.php holds it.
    ->beforeEach(function (): void {
        // The in-process app server never reaches the real network either (same rule as the default suite above).
        Http::preventStrayRequests();
        // The league voice's fact check is a model call: cleared by default, the voice tests fake their own answers.
        VoiceFactCheck::fake(fn (): array => ['claims' => []]);
        ChatFactCheck::fake(fn (): array => ['claims' => []]);
        // A chat question's web lookup is a model call too: nothing to look up by default (ChatContextTest fakes findings).
        WebLookupAgent::fake(fn (): string => WebLookupAgent::NOTHING);
        // The day stock's coherence judge is a model call: every score passes by default (StockCoherenceTest fakes weak ones).
        StockCoherenceJudge::fake(fn (): array => ['premise' => 5, 'through_line' => 5, 'payoff' => 5, 'fit' => 5, 'liveliness' => 5, 'sense' => 5, 'nonsense' => [], 'invented' => [], 'hint' => '']);
        // Quiet hours follow the wall clock: off by default so a run after one at night tests the day schedule (QuietHoursTest switches them on).
        config(['tv.commentary.spool.quiet_hours.from' => '']);
        config(['session.expire_on_close' => true]);
        Vite::useHotFile(storage_path('framework/testing/vite-hot-disabled-for-browser-tests'));
        BrowserAssets::use();
        app(HttpKernel::class)->pushMiddleware(BrowserBodylessFraming::class);
        // Returning players, not bare Google sign-ins: no welcome banner above the page under measurement (see UserFactory::$withProfile).
        UserFactory::$withProfile = true;
    })
    ->afterEach(function (): void {
        UserFactory::$withProfile = false;
    })
    ->group('browser')
    ->in('Browser');

/*
| P15: a separate, expensive suite against the real stack (app server,
| Reverb, queue, a local `nak serve` relay) — never `--parallel`/TIA/
| pre-commit, started only by `composer test:integration`. No
| RefreshDatabase: Tests\Integration\Support\Stack owns one real SQLite
| file per run (fresh on every boot()) that the app server, the queue
| worker and this test process all share; attach() points this process's
| own DB/cache connections at it before each test.
*/
pest()->extend(TestCase::class)
    ->beforeEach(fn () => Stack::instance()->boot()->attach())
    ->group('integration')
    ->in('Integration');

/*
| League voice evals (plan "KI-Stimme der Liga"): real Claude calls that cost
| money, skipped unless run with `--evals` (`vendor/bin/pest tests/Evals
| --evals`), never in the gate. The judge runs on Anthropic, not the plugin's
| OpenAI default. Real network: no Http::preventStrayRequests() here.
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->group('evals')
    ->in('Evals');

pest()->evals()->judgeUsing(new LaravelAiJudge(provider: 'anthropic', model: 'claude-haiku-5-5'));

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A live chain season (Block 0 an hour ago) signed by a throwaway league key,
 * which becomes `esports.league.nsec`, so rated play is open and every rated
 * result is attested. The genesis is a real signed 2156, so block 1 can name
 * it. The real release path is tested in tests/Feature/SeasonChain.
 *
 * Block 0 publishes the first version of every ladder, signed at the genesis
 * time, and a ladder opens only with it (Ladders::address(), plan "Schach
 * Rapid und Clan", P1). `$ladders = false` leaves them out: a season released
 * before a game or mode joined, whose ladder opens later (publishLadders()).
 *
 * @param  array<string, mixed>  $attributes
 */
function openSeason(array $attributes = [], bool $ladders = true): Season
{
    $league = new TestSigner;
    config(['esports.league.nsec' => $league->secret]);

    $season = Season::factory()->make(['league_pubkey' => $league->pubkey, ...$attributes]);
    $genesis = NostrEvent::fromSigned(SignedEvent::fromInput($league->sign(SeasonChains::GENESIS, [['season', $season->slug], ['alt', 'Season Genesis']], $season->genesis_message, $season->genesis_at->getTimestamp())));
    $season->genesis_event_id = $genesis->id;
    $season->save();

    if ($ladders) {
        $now = Carbon::getTestNow();
        CarbonImmutable::setTestNow(CarbonImmutable::instance($season->genesis_at));
        Carbon::setTestNow(CarbonImmutable::instance($season->genesis_at));

        try {
            publishLadders($season);
        } finally {
            CarbonImmutable::setTestNow($now);
            Carbon::setTestNow($now);
        }
    }

    return $season;
}

/**
 * openSeason() attributes for a season released before these games and
 * modes mined: the chain defaults without their weights. Chess rapid and
 * the board games mine from Block 0 by default (user, 2026-10-11); a live
 * season whose genesis lacks them takes them in by a parameter change.
 *
 * @return array{parameters: array<string, mixed>}
 */
function seasonGenesisWithout(string ...$weightKeys): array
{
    $chain = ChainDraft::defaults();

    return ['parameters' => [
        'weights' => array_diff_key($chain['weights'], array_flip($weightKeys)),
        'groups' => $chain['groups'],
        'shares' => $chain['shares'],
        'daily' => $chain['daily'],
        'pairlimit' => $chain['pairlimit'],
        'subtree' => $chain['subtree'],
        'moves' => $chain['moves'],
    ]];
}

/**
 * The first version of every ladder of the season, as Block 0 publishes
 * them (a throwaway trust key): a board game's ladder opens only with it
 * (Ladders::address(), plan "Mühle und Dame", P6).
 */
function publishLadders(Season $season): void
{
    app(LadderEvents::class)->publish($season, LeagueKey::required(), (new TestSigner)->pubkey);
}

/**
 * A chain draft (ChainDraft, P43) as the board saved it on the admin season
 * page: the defaults with $chain over them. The admin form path is tested in
 * tests/Feature/SeasonChain/ChainDraftTest.php.
 *
 * @param  array<string, mixed>  $chain
 */
function saveChainDraft(array $chain = []): SeasonSettingChange
{
    return SeasonSettingChange::query()->create([
        'changed_by_pubkey' => str_repeat('b', 64),
        'values' => [...RatingSettings::draft(), 'chain' => [...ChainDraft::defaults(), ...$chain]],
        'changes' => [],
    ]);
}

/** The Pre-Season's planned Block 0 in the saved chain draft; null = "date coming soon". */
function planBlock0(CarbonInterface|string|null $at): void
{
    saveChainDraft([...(ChainDraft::stored() ?? []), 'block0_at' => $at === null ? null : CarbonImmutable::parse($at)->getTimestamp()]);
}
