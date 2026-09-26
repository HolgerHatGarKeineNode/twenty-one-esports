<?php

use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\SeasonChain\Candidate;
use App\Support\SeasonChain\Resolution;
use App\Support\SeasonChain\SeasonChains;
use App\Support\Series\SeriesService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The season chain in the browser (P7c)
|--------------------------------------------------------------------------
|
| /mining and AdminSeason before Block 0 and in a live season: no console
| error, no uncaught error, no >= 400 on the page or any fetch/XHR, no
| horizontal overflow at 375 and 1440 px. Block 0 is released through the
| real signing path (a stubbed window.nostr), and a rule change is saved
| through a Livewire roundtrip. Series changes reach the other captain's
| match dock over Reverb without a reload (needs scripts/test-browser.sh).
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
});

const CHAIN_COLLECTOR = <<<'JS'
    window.__errors = [];
    const push = (entry) => window.__errors.push(entry);
    for (const level of ['error', 'warn']) {
        const original = console[level];
        console[level] = function (...args) { push('console.' + level + ': ' + args.map(String).join(' ')); original.apply(console, args); };
    }
    window.addEventListener('error', (e) => push('error: ' + e.message));
    window.addEventListener('unhandledrejection', (e) => push('unhandledrejection: ' + String(e.reason)));
    const originalFetch = window.fetch;
    window.fetch = (...args) => originalFetch(...args).then((r) => { if (r.status >= 400) push(r.status + ' ' + r.url); return r; });
    const originalSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send = function (...args) {
        this.addEventListener('loadend', () => { if (this.status >= 400) push(this.status + ' ' + this.responseURL); });
        return originalSend.apply(this, args);
    };
    JS;

const CHAIN_READ = <<<'JS'
    () => {
        const nav = performance.getEntriesByType('navigation')[0] || {};
        return {
            errors: window.__errors || [],
            status: nav.responseStatus ?? null,
            scrollWidth: document.documentElement.scrollWidth,
            clientWidth: document.documentElement.clientWidth,
        };
    }
    JS;

function chainPage(User $user, string $to, ?string $initScript = null): Page
{
    $page = visit(route('testing.login', ['user' => $user, 'to' => $to]))->page();
    $page->context()->addInitScript(CHAIN_COLLECTOR);

    if ($initScript !== null) {
        $page->context()->addInitScript($initScript);
    }

    $page->goto(ComputeUrl::from($to));

    return $page;
}

/**
 * Load each URL at 375 and 1440 px and return every problem found.
 *
 * @param  list<string>  $urls
 * @return list<string>
 */
function chainProblems(Page $page, array $urls): array
{
    $problems = [];

    foreach ($urls as $url) {
        foreach ([[375, 800], [1440, 900]] as [$width, $height]) {
            $page->setViewportSize($width, $height);
            $page->goto(ComputeUrl::from($url));
            $data = $page->evaluate(CHAIN_READ);

            if (($data['status'] ?? 200) >= 400) {
                $problems[] = "{$url} at {$width}px: navigation {$data['status']}";
            }

            foreach ($data['errors'] as $error) {
                $problems[] = "{$url} at {$width}px: {$error}";
            }

            if ($data['scrollWidth'] > $data['clientWidth']) {
                $problems[] = "{$url} at {$width}px: horizontal overflow {$data['scrollWidth']} > {$data['clientWidth']}";
            }
        }
    }

    return $problems;
}

/** Block `height` won by `winner`, as SeasonChains stores it. */
function chainBlock(int $seasonId, int $height, User $winner, User $loser, CarbonImmutable $at, int $opponent, ?string $reason = null): void
{
    $candidate = new Candidate('#'.(400 + $height), 'series:'.$height, 'rocket-league', 'rocket-league/1v1', $at, Resolution::Confirmed, null,
        [$winner->pubkey], [$loser->pubkey], 'lineup:1', ['lineup:1', 'lineup:'.$opponent], [$winner->pubkey, $loser->pubkey], true,
        [$winner->pubkey => 100, $loser->pubkey => 100], [], []);

    SeasonAttestation::query()->create([
        'season_id' => $seasonId, 'source' => 'series', 'source_id' => $height, 'label' => $candidate->label, 'game' => 'rocket-league', 'mode' => '1v1',
        'ladder_address' => 'rocket-league/1v1', 'attested_at' => $at, 'candidate' => $candidate->toArray(),
        'height' => $reason === null ? $height : null, 'rule' => $reason === null ? null : 4, 'reason' => $reason, 'era' => 1,
        'reward_per_player' => 20_000, 'reward' => $reason === null ? 20_000 : 0, 'event_id' => hash('sha256', 'block-'.$height),
    ]);
}

test('/mining and AdminSeason stay clean before Block 0, through the release, and in the live season', function () {
    $board = User::factory()->create(['name' => 'satsjaeger']);
    TestSigner::forBrowser($board);
    config(['esports.board' => [NostrKeys::hexToNpub($board->refresh()->pubkey)], 'esports.league.nsec' => (new TestSigner)->secret, 'esports.trust.nsec' => (new TestSigner)->secret]);

    $admin = chainPage($board, route('admin.season'), TestSigner::browserStub($board));

    expect(chainProblems($admin, [route('mining'), route('admin.season')]))->toBe([]);

    // Block 0 through the real signing path: retype the supply, sign the label.
    $admin->setViewportSize(1440, 900);
    $admin->goto(ComputeUrl::from(route('admin.season')));
    $admin->locator('#genesis-message')->fill('Pre-Season: every fair win is a block');
    $admin->locator('[data-test=retype-supply]')->fill('2100000');
    $admin->locator('[data-test=release-button]')->click();
    BrowserWait::until($admin, '() => document.querySelector("[data-test=season-notice]") !== null', 10_000);

    expect($admin->evaluate('() => document.querySelector("[data-test=admin-season]").dataset.state'))->toBe('live')
        ->and($admin->evaluate('() => window.__errors'))->toBe([]);

    // A live chain with blocks, a rejected win and a rule change through the page.
    $season = Season::query()->sole();
    $players = User::factory()->count(3)->create();
    chainBlock($season->id, 1, $players[0], $players[1], CarbonImmutable::now()->addSeconds(5), 2);
    chainBlock($season->id, 2, $players[2], $players[1], CarbonImmutable::now()->addSeconds(5), 3);
    // The same pairing as block 1 on the same UTC day: rule 4.
    chainBlock($season->id, 3, $players[0], $players[1], CarbonImmutable::now()->addSeconds(5), 2, 'pairing-daily-limit');
    $this->travel(1)->minutes();

    $admin->goto(ComputeUrl::from(route('admin.season')));
    $admin->locator('[data-test=change-reason]')->fill('More reward for Rocket League.');
    $admin->locator('input[wire\:model="weights.rocket-league/1v1"]')->fill('1.5');
    $admin->locator('[data-test=save-change]')->click();
    BrowserWait::until($admin, '() => document.querySelector("[data-test=season-log]")?.innerText.includes("More reward for Rocket League.")', 10_000);

    expect($admin->evaluate('() => window.__errors'))->toBe([])
        ->and(chainProblems($admin, [route('mining'), route('admin.season')]))->toBe([])
        ->and(app(SeasonChains::class)->chain($season->refresh())->season->changes())->toHaveCount(1);
});

test('a series change reaches the other captain\'s match dock over Reverb, without a reload', function () {
    $match = SeriesMatch::factory()->accepted()->create();
    $match->load('challengerLineup.clan', 'challengedLineup.clan');
    $captainA = $match->challengerLineup->clan->owner;
    $captainB = $match->challengedLineup->clan->owner;

    $page = chainPage($captainB, route('clans.index'));
    BrowserWait::until($page, '() => window.Echo?.connector?.pusher?.connection?.state === "connected"'
        .' && window.Echo.connector.pusher.channel("private-App.Models.User.'.$captainB->id.'")?.subscribed === true'
        .' && document.querySelector("[data-test=match-dock-root]")?.innerText.includes("0 : 0")', 10_000);

    app(SeriesService::class)->saveLiveGame($match, $captainA, 0, 3, 1, null);

    // The dock polls only every 120 s with a websocket: this is the broadcast.
    BrowserWait::until($page, '() => document.querySelector("[data-test=match-dock-root]")?.innerText.includes("0 : 1")', 8_000);

    expect($page->evaluate('() => window.__errors'))->toBe([]);
});
