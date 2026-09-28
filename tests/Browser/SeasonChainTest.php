<?php

use App\Models\Admin;
use App\Models\NostrEvent;
use App\Models\Rating;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\SeasonPayout;
use App\Models\SeasonSettingChange;
use App\Models\SeriesMatch;
use App\Models\TrustExclusion;
use App\Models\TrustReportDismissal;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use App\Support\PreSeason;
use App\Support\Prizes\PoolInvoices;
use App\Support\SeasonChain\Candidate;
use App\Support\SeasonChain\ChainDraft;
use App\Support\SeasonChain\LadderEvents;
use App\Support\SeasonChain\LeagueKey;
use App\Support\SeasonChain\Resolution;
use App\Support\SeasonChain\SeasonChains;
use App\Support\SeasonChain\SeasonPlans;
use App\Support\SeasonChain\TrustJob;
use App\Support\Series\SeriesService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
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
    // The genesis message is part of the chain draft (P43): save it, then release.
    $admin->locator('[data-test=draft-message]')->fill('Pre-Season: every fair win is a block');
    $admin->locator('[data-test=save-draft]')->click();
    BrowserWait::until($admin, '() => document.querySelector("[data-test=release-message]")?.innerText.includes("every fair win")', 10_000);
    $admin->locator('[data-test=retype-supply]')->fill('2100000');
    $admin->locator('[data-test=release-button]')->click();
    // The draft save showed a notice already: wait for the release's own.
    BrowserWait::until($admin, '() => document.querySelector("[data-test=season-notice]")?.innerText.includes("Block 0 is released") || document.querySelector("[data-test=release-error]") !== null', 10_000);

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

test('the reserve card shows the zap QR code inside the card at 375 and 1440 px, never the address, with a quiet console', function () {
    fakeWallet();
    openSeason();
    $page = chainPage(User::factory()->create(), route('mining'));

    expect(chainProblems($page, [route('mining')]))->toBe([]);

    $sizes = [];

    foreach ([[375, 800], [1440, 900]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('mining')));
        $sizes[$width] = $page->evaluate('() => { const card = document.querySelector("[data-test=mining-reserve]"); const c = card.getBoundingClientRect();'
            .' const q = document.querySelector("[data-test=reserve-zap-qr] svg").getBoundingClientRect();'
            .' return {qr: [Math.round(q.left - c.left), Math.round(c.right - q.right), Math.round(q.width), Math.round(q.height)], text: card.innerText}; }');
    }

    fwrite(STDERR, "\n[mining reserve QR] left/right/width/height in the card: ".json_encode(array_map(fn (array $size): array => $size['qr'], $sizes))."\n");

    foreach ($sizes as $size) {
        expect($size['qr'][0])->toBeGreaterThanOrEqual(0)
            ->and($size['qr'][1])->toBeGreaterThanOrEqual(0)
            ->and($size['qr'][2])->toBeGreaterThanOrEqual(90)
            ->and($size['qr'][2])->toBe($size['qr'][3])
            ->and($size['text'])->not->toContain(PoolInvoices::address())
            ->and($size['text'])->not->toContain('LNURL');
    }

    expect($page->evaluate('() => window.__errors'))->toBe([]);
});

const SUPPLY_MEASURE = <<<'JS'
    () => {
        const plot = document.querySelector('[data-test=supply-plot]');
        plot.scrollIntoView({block: 'center'});
        const card = plot.closest('section').getBoundingClientRect();
        const p = plot.getBoundingClientRect();
        plot.dispatchEvent(new PointerEvent('pointermove', {clientX: p.left + p.width * 0.1, clientY: p.top + 20, bubbles: true}));
        return new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => {
            const tip = document.querySelector('[data-test=supply-tooltip]');
            const t = tip ? tip.getBoundingClientRect() : null;
            const summary = document.querySelector('[data-test=supply-table] summary').getBoundingClientRect();
            resolve({
                plot: [Math.round(p.left - card.left), Math.round(card.right - p.right), Math.round(p.width), Math.round(p.height)],
                tip: t ? [Math.round(t.left - p.left), Math.round(p.right - t.right), tip.innerText] : null,
                summaryHeight: Math.round(summary.height),
                legend: document.querySelector('[data-test=supply-chart] ul').innerText,
            });
        })));
    }
    JS;

test('the Season page supply chart fits, reads out on hover and focus at 375 and 1440 px, in German too, with a quiet console', function () {
    $season = openSeason(['genesis_at' => now()->subDays(20)->startOfSecond(), 'ends_at' => now()->subDays(20)->startOfSecond()->addWeeks(24)]);
    $players = User::factory()->count(4)->create();
    foreach (range(1, 6) as $height) {
        chainBlock($season->id, $height, $players[$height % 4], $players[($height + 1) % 4], CarbonImmutable::now()->subDays(19 - 3 * $height), 1 + $height);
    }
    $viewer = User::factory()->create();
    $page = chainPage($viewer, route('mining'));

    expect(chainProblems($page, [route('mining')]))->toBe([]);

    $measured = [];
    foreach ([['en', 1440, 900, 260], ['en', 375, 800, 200], ['de', 375, 800, 200]] as [$locale, $width, $height, $plotHeight]) {
        if ($locale === 'de') {
            $page->goto(ComputeUrl::from(route('locale.switch', 'de')));
        }
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('mining')));
        $data = $page->evaluate(SUPPLY_MEASURE);
        $measured["{$locale}-{$width}"] = $data;

        expect($data['plot'][0])->toBeGreaterThanOrEqual(0)
            ->and($data['plot'][1])->toBeGreaterThanOrEqual(0)
            ->and($data['plot'][3])->toBe($plotHeight)
            ->and($data['tip'])->not->toBeNull()
            ->and($data['tip'][0])->toBeGreaterThanOrEqual(0)
            ->and($data['tip'][1])->toBeGreaterThanOrEqual(0)
            ->and($data['summaryHeight'])->toBeGreaterThanOrEqual(44)
            ->and($data['legend'])->toContain($locale === 'de' ? 'Prognose' : 'Forecast')
            ->and($page->evaluate('() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]'))->toBe([$width, $width]);
    }
    fwrite(STDERR, "\n[season-chart] ".json_encode($measured)."\n");

    // Keyboard: focus on the plot reads out the newest point.
    $page->evaluate('() => { document.activeElement?.blur(); document.querySelector("[data-test=supply-plot]").focus(); }');
    BrowserWait::until($page, '() => document.querySelector("[data-test=supply-tooltip]")?.innerText.includes("Heute")', 3_000);

    // Positive control: the collector sees a thrown error and a 404 on this very page.
    $page->evaluate('() => { setTimeout(() => { throw new Error("season-control"); }); fetch("/season-control-missing"); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("season-control")) && window.__errors.some((e) => e.startsWith("404"))', 5_000);

    // The ended season, as it closed.
    $season->forceFill(['genesis_at' => now()->subDays(30), 'ends_at' => now()->subHour()])->save();
    $page->goto(ComputeUrl::from(route('locale.switch', 'en')));

    expect(chainProblems($page, [route('mining')]))->toBe([])
        ->and($page->evaluate('() => document.querySelector("[data-test=mining]").dataset.state'))->toBe('between');
});

test('the admin trust page stays clean at 375 and 1440 px with reports, and a dismissal round-trips', function () {
    $admin = User::factory()->create(['name' => 'satsjaeger']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $reporter = new TestSigner;

    // An unknown reporter (long npub) against a player with a long name, with a long reason.
    $target = User::factory()->create(['name' => 'Mempool Max, captain of the longest clan name in the league']);
    $report = NostrEvent::fromSigned(SignedEvent::fromInput($reporter->sign(TrustJob::REPORT, [
        ['p', $target->pubkey, 'other'], ['L', TrustJob::LABEL_NAMESPACE], ['l', 'result-fixing', TrustJob::LABEL_NAMESPACE],
    ], str_repeat('Three games in a row lost on move twelve. ', 8), now()->getTimestamp())));
    TrustExclusion::query()->create(['pubkey' => (new TestSigner)->pubkey, 'excluded_by_id' => $admin->id, 'reason' => 'Sold the account.']);

    $page = chainPage($admin, route('admin.trust'));

    expect(chainProblems($page, [route('admin.trust')]))->toBe([]);

    $page->setViewportSize(375, 800);
    $page->goto(ComputeUrl::from(route('admin.trust')));
    $row = $page->evaluate('() => { const r = document.querySelector("[data-test=trust-report]").getBoundingClientRect(); return [Math.round(r.left), Math.round(r.right), Math.round(r.width)]; }');
    fwrite(STDERR, "\n[admin-trust] 375px report row left/right/width: ".json_encode($row)."\n");

    $page->locator('[data-test=trust-dismiss]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=trust-error]") !== null', 8_000);
    $page->locator('[data-test=trust-reason]')->fill('Lost fair and square.');
    $page->locator('[data-test=trust-dismiss]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=trust-report]")?.innerText.includes("Lost fair and square.")', 8_000);

    expect($page->evaluate('() => window.__errors'))->toBe([])
        ->and($row[1])->toBeLessThanOrEqual(375)
        ->and(TrustReportDismissal::query()->sole()->event_id)->toBe($report->event_id);
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

test('AdminSeason P35: rating settings, soft-reset preview and season review stay clean and inside 375 and 1440 px, with round-trips', function () {
    $admin = User::factory()->create(['name' => 'satsjaeger']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    // The rating draft is the board's (P39).
    config(['esports.board' => [NostrKeys::hexToNpub($admin->pubkey)]]);

    // Positive control: an injected throw and a 404 fetch must show up as problems.
    $control = chainPage($admin, route('admin.season'), 'window.addEventListener("load", () => { setTimeout(() => { throw new Error("p35-positive-control"); }, 0); fetch("/p35-positive-control-missing"); });');
    $controlProblems = implode("\n", chainProblems($control, [route('admin.season')]));
    // The fetch answers after the load event that chainProblems() reads at, so wait for it.
    BrowserWait::until($control, '() => (window.__errors || []).some((entry) => entry.startsWith("404 "))', 10_000);

    expect($controlProblems)->toContain('p35-positive-control')
        ->and(implode("\n", $control->evaluate('() => window.__errors')))->toContain('404 ')
        ->and(implode("\n", $control->evaluate('() => window.__errors')))->toContain('p35-positive-control-missing');

    // Before Block 0: a board member edits the draft through a Livewire round-trip.
    $page = chainPage($admin, route('admin.season'));

    expect(chainProblems($page, [route('admin.season')]))->toBe([]);

    $page->setViewportSize(1440, 900);
    $page->goto(ComputeUrl::from(route('admin.season')));
    $page->locator('[data-test=setting-rating-k]')->fill('24');
    $page->locator('[data-test=save-settings]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=settings-log]")?.innerText.includes("32 → 24")', 10_000);

    expect($page->evaluate('() => window.__errors'))->toBe([])
        ->and(SeasonSettingChange::query()->sole()->changes)->toBe(['rating.k' => [32, 24]]);

    // An ended season with 26 rated players: the preview pages, the review lists the champion.
    $season = openSeason(['genesis_at' => now()->subDays(30), 'ends_at' => now()->subHour()]);
    $players = User::factory()->count(26)->sequence(fn ($sequence) => ['name' => 'Player '.str_pad((string) $sequence->index, 2, '0', STR_PAD_LEFT)])->create();

    foreach ($players as $index => $player) {
        Rating::query()->create(['pool' => Rating::RATED, 'season' => $season->slug, 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$player->id,
            'user_id' => $player->id, 'rating' => 1100 - $index, 'results' => 6, 'wins' => 6]);
    }

    expect(chainProblems($page, [route('admin.season')]))->toBe([]);

    $sizes = [];

    foreach ([[375, 800], [1440, 900]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('admin.season')));
        $sizes[$width] = $page->evaluate('() => Object.fromEntries(["season-settings", "season-soft-reset", "season-review", "reset-table"].map((name) => {'
            .' const r = document.querySelector(`[data-test=${name}]`).getBoundingClientRect();'
            .' return [name, [Math.round(r.left), Math.round(r.right), Math.round(r.width), Math.round(r.height)]]; }))');
    }

    fwrite(STDERR, "\n[admin-season P35] left/right/width/height: ".json_encode($sizes)."\n");

    foreach (['season-settings', 'season-soft-reset', 'season-review'] as $section) {
        expect($sizes[375][$section][0])->toBeGreaterThanOrEqual(0)
            ->and($sizes[375][$section][1])->toBeLessThanOrEqual(375)
            ->and($sizes[1440][$section][1])->toBeLessThanOrEqual(1440);
    }

    // Page 2 of the preview and a new factor, both through Livewire.
    $page->locator('[data-test=reset-pages] button[aria-label="Next page"]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=reset-table]")?.innerText.includes("Player 25")', 10_000);
    $page->locator('[data-test=reset-factor]')->fill('0.2');
    BrowserWait::until($page, '() => [...document.querySelectorAll("[data-test=reset-seed]")].some((cell) => cell.innerText === "1015")', 10_000);

    expect($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate('() => document.querySelector("[data-test=season-review]").innerText'))->toContain('Player 00')
        ->and($page->evaluate('() => document.querySelector("[data-test=season-settlement]").innerText'))->toContain('No block was mined in this season');
});

test('AdminSeason P38: the board plans the next season and releases it with the soft reset, clean and inside 375 and 1440 px', function () {
    $board = User::factory()->create(['name' => 'vorstand']);
    TestSigner::forBrowser($board);
    $board->refresh();

    // The Pre-Season ended an hour ago, with its ladders and two rated players on chess blitz.
    $preSeason = openSeason(['genesis_at' => now()->subDays(30), 'ends_at' => now()->subHour()]);
    config(['esports.board' => [NostrKeys::hexToNpub($board->pubkey)], 'esports.trust.nsec' => (new TestSigner)->secret]);
    app(LadderEvents::class)->publish($preSeason, LeagueKey::required(), (string) LeagueKey::trust()?->pubkey());
    [$alice, $bob] = [User::factory()->create(['name' => 'alice']), User::factory()->create(['name' => 'bob'])];
    foreach ([[$alice, 1029], [$bob, 971]] as [$player, $rating]) {
        Rating::query()->create(['pool' => Rating::RATED, 'season' => 'pre-season', 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$player->id,
            'user_id' => $player->id, 'rating' => $rating, 'results' => 4, 'wins' => 2, 'losses' => 2]);
    }
    SeasonAttestation::query()->create([
        'season_id' => $preSeason->id, 'source' => 'chess', 'source_id' => 1, 'label' => '#1', 'game' => 'chess', 'mode' => 'blitz',
        'ladder_address' => '32152:'.$preSeason->league_pubkey.':chess/blitz/pre-season', 'attested_at' => now()->subDays(2), 'event_id' => str_repeat('c', 64),
    ]);

    // Positive control: an injected throw and a 404 fetch must show up as problems.
    $control = chainPage($board, route('admin.season'), 'window.addEventListener("load", () => { setTimeout(() => { throw new Error("p38-positive-control"); }, 0); fetch("/p38-positive-control-missing"); });');
    $controlProblems = implode("\n", chainProblems($control, [route('admin.season')]));
    BrowserWait::until($control, '() => (window.__errors || []).some((entry) => entry.startsWith("404 "))', 10_000);

    expect($controlProblems)->toContain('p38-positive-control')
        ->and(implode("\n", $control->evaluate('() => window.__errors')))->toContain('p38-positive-control-missing');

    $page = chainPage($board, route('admin.season'), TestSigner::browserStub($board));

    expect(chainProblems($page, [route('admin.season')]))->toBe([]);

    $sizes = [];

    foreach ([[375, 800], [1440, 900]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('admin.season')));
        $sizes[$width] = $page->evaluate('() => ["season-planner", "plan-starts-at", "save-plan"].map((name) => {'
            .' const r = document.querySelector(`[data-test=${name}]`).getBoundingClientRect();'
            .' return [name, Math.round(r.left), Math.round(r.right), Math.round(r.width), Math.round(r.height)]; })');
    }

    fwrite(STDERR, "\n[admin-season P38] name/left/right/width/height: ".json_encode($sizes)."\n");

    foreach ([375, 1440] as $width) {
        foreach ($sizes[$width] as [$name, $left, $right, $boxWidth, $boxHeight]) {
            expect($left)->toBeGreaterThanOrEqual(0)
                ->and($right)->toBeLessThanOrEqual($width)
                ->and($boxHeight)->toBeGreaterThan(0);
        }
    }

    // Plan through a Livewire round-trip: Block 0 at the default, the next full hour.
    $page->locator('[data-test=plan-name]')->fill('Winter Season');
    $page->locator('[data-test=plan-weeks]')->fill('10');
    $page->locator('[data-test=plan-factor]')->fill('0.5');
    $page->locator('[data-test=save-plan]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=plan-log]")?.innerText.includes("Winter Season")', 10_000);

    $plan = SeasonPlans::current();
    $zone = PreSeason::timezoneFor($board);

    expect($page->evaluate('() => window.__errors'))->toBe([])
        ->and($plan?->weeks)->toBe(10)
        ->and($plan?->starts_at->getTimestamp())->toBe(CarbonImmutable::now()->addHour()->startOfHour()->getTimestamp())
        ->and($page->evaluate('() => document.querySelector("[data-test=plan-starts-at]").value'))->toBe($plan?->starts_at->setTimezone($zone)->format('Y-m-d\TH:i'))
        ->and($page->evaluate('() => document.querySelector("[data-test=release-refusal]")?.innerText ?? ""'))->toContain('Winter Season is planned for Block 0');

    // From the planned Block 0 on, one board member releases it through the real signing path.
    $this->travel(2)->hours();
    $page->goto(ComputeUrl::from(route('admin.season')));
    $page->locator('[data-test=draft-message]')->fill('Winter Season: every fair win is a block');
    $page->locator('[data-test=save-draft]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=release-message]")?.innerText.includes("Winter Season")', 10_000);
    $page->locator('[data-test=retype-supply]')->fill('2100000');
    $page->locator('[data-test=release-button]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=season-notice]")?.innerText.includes("Winter Season") || document.querySelector("[data-test=release-error]") !== null || [...document.querySelectorAll("[data-test=release-block0] [role=alert]")].some((alert) => alert.innerText.trim() !== "")', 15_000);

    expect($page->evaluate('() => [...document.querySelectorAll("[data-test=release-block0] [role=alert]")].map((alert) => alert.innerText.trim()).filter(Boolean)'))->toBe([]);

    $season = Season::query()->where('slug', 'season-1')->sole();
    $seeds = Rating::query()->where(['pool' => Rating::RATED, 'season' => 'season-1'])->orderByDesc('rating')->pluck('rating')->all();

    expect($page->evaluate('() => document.querySelector("[data-test=admin-season]").dataset.state'))->toBe('live')
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and($season->previous_season_id)->toBe($preSeason->id)
        // 1029: 29 × 0.5 = 14.5 rounds to 15; 971: −14.5 rounds to −15.
        ->and($seeds)->toBe([1015, 985])
        ->and(chainProblems($page, [route('admin.season'), route('mining')]))->toBe([]);
});

test('AdminSeason P43: the chain draft form and "What Block 0 signs" stay clean and inside 375 and 1440 px, and a save round-trips', function () {
    $board = User::factory()->create(['name' => 'vorstand']);
    config(['esports.board' => [NostrKeys::hexToNpub($board->pubkey)]]);

    // Positive control: an injected throw and a 404 fetch must show up as problems on this page.
    $control = chainPage($board, route('admin.season'), 'window.addEventListener("load", () => { setTimeout(() => { throw new Error("p43-positive-control"); }, 0); fetch("/p43-positive-control-missing"); });');
    $controlProblems = implode("\n", chainProblems($control, [route('admin.season')]));
    BrowserWait::until($control, '() => (window.__errors || []).some((entry) => entry.startsWith("404 "))', 10_000);

    expect($controlProblems)->toContain('p43-positive-control')
        ->and(implode("\n", $control->evaluate('() => window.__errors')))->toContain('p43-positive-control-missing');

    $page = chainPage($board, route('admin.season'));

    expect(chainProblems($page, [route('admin.season')]))->toBe([]);

    $sizes = [];

    foreach ([[375, 800], [1440, 900]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('admin.season')));
        $sizes[$width] = $page->evaluate('() => ["season-chain-draft", "draft-table", "draft-supply", "draft-share-ea-sports-fc", "save-draft", "season-preview", "preview-wins", "preview-genesis"].map((name) => {'
            .' const r = document.querySelector(`[data-test=${name}]`).getBoundingClientRect();'
            .' return [name, Math.round(r.left), Math.round(r.right), Math.round(r.width), Math.round(r.height)]; })');
    }

    fwrite(STDERR, "\n[admin-season P43] name/left/right/width/height: ".json_encode($sizes)."\n");

    foreach ([375, 1440] as $width) {
        foreach ($sizes[$width] as [$name, $left, $right, $boxWidth, $boxHeight]) {
            // The table and its inputs scroll inside their own box on a phone; everything else stays inside the viewport.
            if (! in_array($name, ['draft-table', 'draft-share-ea-sports-fc', 'preview-wins'], true)) {
                expect($right)->toBeLessThanOrEqual($width);
            }

            expect($left)->toBeGreaterThanOrEqual(0)->and($boxHeight)->toBeGreaterThan(0);
        }
    }

    // A save through a Livewire round-trip at 375: the log names it, the preview signs the new share.
    $page->setViewportSize(375, 800);
    $page->goto(ComputeUrl::from(route('admin.season')));
    $page->locator('[data-test=draft-share-chess]')->fill('30');
    $page->locator('[data-test=draft-message]')->fill('Block 0: every fair win is a block');
    $page->locator('[data-test=save-draft]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=settings-log]")?.innerText.includes("35 → 30")', 10_000);

    expect($page->evaluate('() => window.__errors'))->toBe([])
        ->and($page->evaluate('() => document.querySelector("[data-test=preview-genesis]").innerText'))->toContain('["share","chess","30"]')
        ->and($page->evaluate('() => [...document.querySelectorAll("[data-test=draft-warning]")].map((el) => el.innerText).join(" | ")'))->toContain('The shares add up to 95 %')
        ->and(ChainDraft::stored()['shares']['chess'] ?? null)->toBe(30)
        ->and(chainProblems($page, [route('admin.season'), route('home'), route('rules')]))->toBe([]);

    // A refused value shows its reason and saves nothing.
    $page->setViewportSize(1440, 900);
    $page->goto(ComputeUrl::from(route('admin.season')));
    $page->locator('[data-test=draft-share-chess]')->fill('50');
    $page->locator('[data-test=save-draft]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=draft-error]") !== null', 10_000);

    expect($page->evaluate('() => document.querySelector("[data-test=draft-error]").innerText'))->toContain('The shares add up to 115 %')
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and(ChainDraft::stored()['shares']['chess'] ?? null)->toBe(30);
});

test('AdminSeason P37: an admin voids a block, approves the settlement and pays per click, clean and inside 375 and 1440 px', function () {
    // The LNURL fake of the players' addresses answers instead of the blanket fake of beforeEach.
    Http::swap(new HttpFactory);
    $wallet = fakeWallet();
    fakeLightningAddresses($wallet);
    $admin = anAdmin();
    $admin->forceFill(['name' => 'satsjaeger', 'locale' => 'en'])->save();
    $alice = settlementPlayer('Alice');
    $bob = settlementPlayer('Bob');
    $bob->forceFill(['lud16' => null])->save();
    $carol = settlementPlayer('Carol');
    $season = settledSeason([[$alice, 1], [$alice, 2], [$bob, 3], [$carol, 4]]);

    // Positive control: an injected throw and a 404 fetch must show up as problems on this page.
    $control = chainPage($admin, route('admin.season'), 'window.addEventListener("load", () => { setTimeout(() => { throw new Error("p37-positive-control"); }, 0); fetch("/p37-positive-control-missing"); });');
    $controlProblems = implode("\n", chainProblems($control, [route('admin.season')]));
    BrowserWait::until($control, '() => (window.__errors || []).some((entry) => entry.startsWith("404 "))', 10_000);

    expect($controlProblems)->toContain('p37-positive-control')
        ->and(implode("\n", $control->evaluate('() => window.__errors')))->toContain('p37-positive-control-missing');

    // wire:confirm asks window.confirm; the admin says yes.
    $page = chainPage($admin, route('admin.season'), 'window.confirm = () => true;');
    $measure = '() => ["season-settlement", "settlement-list", "settlement-payouts", "void-form"].map((name) => { const el = document.querySelector(`[data-test=${name}]`);'
        .' if (!el) return [name, null]; const r = el.getBoundingClientRect(); const box = el.closest(".overflow-x-auto") ?? el;'
        .' return [name, Math.round(r.left), Math.round(r.right), Math.round(r.width), Math.round(r.height), Math.round(box.getBoundingClientRect().right)]; })';
    $sizes = [];

    expect(chainProblems($page, [route('admin.season'), route('mining')]))->toBe([]);

    foreach ([[375, 800], [1440, 900]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('admin.season')));
        $sizes['review'][$width] = $page->evaluate($measure);
    }

    // At 375: void Alice's second block with a public reason, through Livewire.
    $page->setViewportSize(375, 800);
    $page->goto(ComputeUrl::from(route('admin.season')));
    $page->locator('[data-test=void-height]')->fill('2');
    $page->locator('[data-test=void-reason]')->fill('Second win of the same pairing within minutes.');
    $page->locator('[data-test=void-block]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=settlement-voids]")?.innerText.includes("Block 2 void")', 10_000);

    expect($season->blockVoids()->sole()->height)->toBe(2)
        ->and($page->evaluate('() => window.__errors'))->toBe([]);

    // At 1440: approve the list, pay Alice, fail Carol on an empty wallet, then retry after the top-up.
    $page->setViewportSize(1440, 900);
    $page->goto(ComputeUrl::from(route('admin.season')));
    $page->locator('[data-test=approve-settlement]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=settlement-payouts]") !== null', 10_000);

    $row = fn (User $user): string => '[data-test=settlement-payout-row]:has-text("'.$user->name.'")';
    $page->locator($row($alice).' [data-test=pay-one]')->click();
    BrowserWait::until($page, '() => [...document.querySelectorAll("[data-test=settlement-payout-row]")].some((tr) => tr.dataset.status === "paid" && tr.innerText.includes("Alice"))', 15_000);

    $wallet->balanceMsats = 1_000;
    $page->locator($row($carol).' [data-test=pay-one]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=settlement-top-up]") !== null', 15_000);
    $failedText = $page->evaluate('() => document.querySelector("[data-test=settlement-payouts]").innerText');

    $wallet->balanceMsats = 50_000_000_000;
    $page->locator($row($carol).' [data-test=pay-one]')->click();
    BrowserWait::until($page, '() => [...document.querySelectorAll("[data-test=settlement-payout-row]")].some((tr) => tr.dataset.status === "paid" && tr.innerText.includes("Carol"))', 15_000);

    foreach ([[375, 800], [1440, 900]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('admin.season')));
        $sizes['paid'][$width] = $page->evaluate($measure);
    }

    fwrite(STDERR, "\n[admin-season P37] name/left/right/width/height/scroll-box right: ".json_encode($sizes)."\n");

    foreach ($sizes as $state => $byWidth) {
        foreach ($byWidth as $width => $boxes) {
            foreach ($boxes as $box) {
                if ($box[1] === null) {
                    continue;
                }

                // A table may be wider than a phone; it scrolls inside its own box, which stays inside the viewport.
                expect($box[1])->toBeGreaterThanOrEqual(0, "{$state} {$box[0]} at {$width}")
                    ->and($box[5])->toBeLessThanOrEqual($width, "{$state} {$box[0]} at {$width}")
                    ->and($box[4])->toBeGreaterThan(0);
            }
        }
    }

    $bodyText = $page->evaluate('() => document.body.innerText');

    expect($failedText)->toContain('Top up the payout wallet')
        ->and($bodyText)->not->toContain('@wallet.example')
        ->and($bodyText)->toContain('has address')
        ->and(SeasonPayout::query()->where('status', 'paid')->count())->toBe(2)
        ->and(SeasonPayout::query()->where('pubkey', $bob->pubkey)->sole()->reason)->toBe('no_lud16')
        ->and($page->evaluate('() => window.__errors'))->toBe([])
        ->and(chainProblems($page, [route('admin.season'), route('mining')]))->toBe([]);

    // /mining lists the two paid payouts, never an address.
    $page->goto(ComputeUrl::from(route('mining')));
    expect($page->evaluate('() => document.querySelectorAll("[data-test=mining-season-payout]").length'))->toBe(2)
        ->and($page->evaluate('() => document.body.innerText'))->not->toContain('@wallet.example')
        ->and($page->evaluate('() => document.querySelector("[data-test=mining-voids]").innerText'))->toContain('Second win of the same pairing within minutes.');
});
