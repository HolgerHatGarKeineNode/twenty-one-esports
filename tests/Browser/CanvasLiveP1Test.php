<?php

use App\Enums\InviteStatus;
use App\Enums\PayoutStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Games\Blockfill;
use App\Games\GameRegistry;
use App\Models\Admin;
use App\Models\Clan;
use App\Models\ClanInvite;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\TournamentPayout;
use App\Models\User;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\Verifier;
use App\Support\Tournaments\TournamentChampion;
use App\Support\TwentyOne\LiveStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Pest\Browser\Support\ComputeUrl;
use Pest\Browser\Support\Screenshot;
use Tests\Support\BlockfillOn;
use Tests\Support\BlockliOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\FakeStackerVerifier;
use Tests\Support\HyperOn;
use Tests\Support\PongOn;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
| Plan "Canvas and live alignment", P1 (2026-11-07): the defects the canvas comparison found in the live product,
| measured as an admin or the player the board shows, in German, at 390 x 844 and 1440 x 900.
|
| Floating layers (B1, G2): the match dock, its cup bar and the "Watch live" tab stand on a reserved floor band at
| the bottom edge. Every floating layer lies inside the band, the band is opaque, and no interactive element of the
| page is hit-tested onto a floating layer at the top, the middle or the end of the page: what lies in the band's
| strip is below the visible area, as under the phone's tab bar, and the end of the page clears the band.
|
| P1_SHOTS=<dir> saves first-screen screenshots for the before/after comparison.
*/

beforeEach(function () {
    config(['session.driver' => 'database', 'esports.league.nsec' => (new TestSigner)->secret]);
    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
    $this->app->instance(Verifier::class, new FakeStackerVerifier);

    // On air: the public playlist moved just now (LiveStatus), so the "Watch live" tab shows on every shell page.
    $this->hls = sys_get_temp_dir().'/esports-p1-live-'.getmypid().'-'.bin2hex(random_bytes(3));
    File::ensureDirectoryExists($this->hls);
    config(['twentyone.stream.hls_dir' => $this->hls, 'twentyone.stream.public_url' => '/__test/live/stream.m3u8']);
    file_put_contents($this->hls.'/stream.m3u8', "#EXTM3U\n");
    Cache::forget(LiveStatus::CACHE_KEY);
});

afterEach(function () {
    File::deleteDirectory($this->hls);
});

/** Opens `$path` in German at `$width`, logged in or as a guest, with the console collector in place. */
function p1Open(?User $user, string $path, int $width): mixed
{
    $page = visit($user === null ? '/robots.txt' : BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript('performance.setResourceTimingBufferSize(5000)');
    $page->setViewportSize($width, $width < 640 ? 844 : 900);
    $page->goto(ComputeUrl::from(route('locale.switch', 'de')));
    touch(test()->hls.'/stream.m3u8');
    Cache::forget(LiveStatus::CACHE_KEY);
    $page->goto(ComputeUrl::from($path));
    $page->waitForLoadState('networkidle');
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Alpine !== undefined && document.fonts.status === "loaded"', 10_000);
    // The live tab re-measures once a second (resources/js/livePlayer.js): let it settle.
    $page->evaluate('() => new Promise((r) => setTimeout(r, 1200))');

    return $page;
}

function p1Shot(mixed $page, string $name): void
{
    $dir = getenv('P1_SHOTS');
    if (! is_string($dir) || $dir === '') {
        return;
    }
    File::ensureDirectoryExists($dir);
    $page->screenshot(false, 'p1-'.$name);
    File::move(Screenshot::path('p1-'.$name), $dir.'/'.$name.'.png');
}

/** Console (collector and runner) and responses of 400 or more: empty is quiet. */
function p1Loud(mixed $page): array
{
    return array_merge(
        $page->evaluate('() => window.__errors ?? ["collector missing"]'),
        array_map(fn ($e): string => 'runner '.(is_array($e) ? ($e['message'] ?? json_encode($e)) : (string) $e), $page->javaScriptErrors()),
        $page->evaluate(BrowserConsole::BAD_RESPONSES),
    );
}

/**
 * The floating layers and what they cover, at the top, the middle and the end of the page. A layer is the match
 * dock (desktop bar, phone bar, cup bar: `data-live-floor` except the tab bar) or the live tab. `outside` lists
 * layers that stand outside the floor band; `covered` lists interactive elements of the page whose centre or
 * lower edge is hit-tested onto a layer; `endUnder` lists elements still under the band at the end of the page.
 */
const P1_FLOATING = <<<'JS'
async () => {
    const layers = () => [...document.querySelectorAll('[data-live-floor]:not(nav), [data-test=live-player] > section')]
        .filter((el) => el.checkVisibility() && getComputedStyle(el).position === 'fixed' && el.getBoundingClientRect().height > 0);
    const isLayer = (el) => !!el && layers().some((l) => l.contains(el));
    const band = document.querySelector('[data-test=floor-band]');
    const bandBox = band && band.checkVisibility() ? band.getBoundingClientRect() : null;
    const label = (el) => (el.dataset.test || el.getAttribute('aria-label') || el.textContent || el.tagName).trim().replace(/\s+/g, ' ').slice(0, 40);
    const targets = () => [...document.querySelectorAll('main a, main button, main input, main textarea, main summary, main [role=button], main [data-square]')]
        .filter((el) => el.checkVisibility() && !isLayer(el));
    const covered = new Set();
    const outside = new Set();
    const pass = () => {
        for (const l of layers()) {
            const r = l.getBoundingClientRect();
            if (!bandBox || r.top < bandBox.top - 0.5 || r.bottom > bandBox.bottom + 0.5) outside.add(label(l) + ' ' + Math.round(r.top) + '..' + Math.round(r.bottom) + (bandBox ? ' band ' + Math.round(bandBox.top) : ' no band'));
        }
        for (const el of targets()) {
            const r = el.getBoundingClientRect();
            if (r.bottom <= 0 || r.top >= innerHeight || r.width === 0) continue;
            for (const [x, y] of [[r.left + r.width / 2, r.top + r.height / 2], [r.left + r.width / 2, r.bottom - 2]]) {
                // A point in the band's strip is below the visible area (the band is opaque): only what shows can be covered.
                if (y <= 0 || y >= innerHeight || (bandBox && y >= band.getBoundingClientRect().top)) continue;
                const hit = document.elementFromPoint(x, y);
                if (isLayer(hit)) covered.add(label(el) + ' @' + Math.round(scrollY));
            }
        }
    };
    const max = document.documentElement.scrollHeight - innerHeight;
    for (const y of [0, Math.round(max / 2), max]) {
        window.scrollTo(0, y);
        await new Promise((r) => setTimeout(r, 1300));
        pass();
    }
    const endUnder = bandBox ? targets().filter((el) => { const r = el.getBoundingClientRect(); return r.bottom > band.getBoundingClientRect().top + 1 && r.top < innerHeight; }).map(label) : [];
    window.scrollTo(0, 0);
    await new Promise((r) => setTimeout(r, 300));
    const bg = band ? getComputedStyle(band).backgroundColor : null;
    return { layers: layers().map(label), band: bandBox ? [Math.round(bandBox.top), Math.round(bandBox.height)] : null, opaque: !!bg && !bg.includes('0)') && bg !== 'transparent', outside: [...outside], covered: [...covered], endUnder };
}
JS;

/** A logged-in player with open matches (the dock), a clan invite to answer, and Blockfill and Blockli on. */
function p1Player(): array
{
    BlockfillOn::play();
    BlockliOn::play();
    app()->forgetInstance(GameRegistry::class);
    leagueWeeksApproved(Blockfill::SLUG);
    app(BlockfillWeeks::class)->open();
    $world = meHubPlayer();
    $me = $world['me'];
    $me->forceFill(['name' => 'Bitcoinmaximalistausdemerzgebirge'])->save();
    Admin::query()->create(['pubkey' => $me->pubkey]);
    $owner = User::factory()->create(['name' => 'Mx12art']);
    $clan = Clan::factory()->create(['owner_id' => $owner->id, 'name' => 'Bitcoin Metropole Kempten Allgäu Nord', 'clantag' => 'BMK']);
    $invite = ClanInvite::query()->create(['clan_id' => $clan->id, 'inviter_id' => $owner->id, 'invitee_id' => $me->id, 'status' => InviteStatus::Pending]);

    return [$me->refresh(), $invite, $world];
}

test('P1 floating layers: the dock and the live tab stand on the floor band and cover nothing of the page, at 390 and 1440', function () {
    [$me, $invite] = p1Player();
    $pages = [
        'invite' => route('invites.show', $invite, false),
        'board-correspondence' => route('board.correspondence', 'blockli', false),
        'blockfill' => '/blockfill',
        'settings-gaming' => '/settings/gaming',
        'settings-chess' => '/settings/chess',
        'me' => '/me',
        'home' => '/',
    ];
    $problems = [];
    foreach ([1440, 390] as $width) {
        foreach ($pages as $name => $path) {
            $page = p1Open($me, $path, $width);
            p1Shot($page, "player-{$name}-{$width}");
            $m = $page->evaluate(P1_FLOATING);
            $loud = p1Loud($page);
            if ($m['outside'] !== [] || $m['covered'] !== [] || $m['endUnder'] !== [] || ($m['band'] !== null && ! $m['opaque']) || $loud !== []) {
                $problems["{$name} {$width}"] = $m + ['loud' => $loud];
            }
        }
    }
    expect($problems)->toBe([]);
});

test('P1 floating layers: the live tab covers nothing for a guest on the start, game, tournament and match pages, at 390 and 1440', function () {
    [$admin, $featured, $finished] = p1TournamentLeague();
    $done = SeriesMatch::factory()->accepted()->create(['game' => 'rocket-league', 'mode' => '1v1', 'challenger_name' => 'Bitcoinmaximalistausdemerzgebirge', 'challenged_name' => 'El Presidento Ben']);
    $pages = [
        'home' => '/',
        'hyper' => '/hyperbitcoinization',
        'scores-blockfill' => '/scores/blockfill',
        'tournament' => route('tournaments.show', $finished, false),
        'match' => route('matches.show', $done->number, false),
    ];
    $problems = [];
    foreach ([1440, 390] as $width) {
        foreach ($pages as $name => $path) {
            $page = p1Open(null, $path, $width);
            p1Shot($page, "guest-{$name}-{$width}");
            $m = $page->evaluate(P1_FLOATING);
            $loud = p1Loud($page);
            if ($m['outside'] !== [] || $m['covered'] !== [] || $m['endUnder'] !== [] || ($m['band'] !== null && ! $m['opaque']) || $loud !== []) {
                $problems["{$name} {$width}"] = $m + ['loud' => $loud];
            }
        }
    }
    expect($problems)->toBe([]);
});

/**
 * A finished organizer tournament with a pot and its payouts (long names, the payout status column of
 * TournamentDetailPhone), Blockfill and Hyperbitcoinization on; returns the admin and the tournaments.
 */
function p1TournamentLeague(): array
{
    BlockfillOn::play();
    HyperOn::play();
    app()->forgetInstance(GameRegistry::class);
    leagueWeeksApproved(Blockfill::SLUG);
    app(BlockfillWeeks::class)->open();
    $admin = User::factory()->create(['name' => 'Erdöpfökasbrot']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $featured = openTournament(['name' => 'Blockli Cup #1: Das erste Blockli-Turnier der Weltmeisterschaft']);

    $finished = runningChess(TournamentFormat::SingleElimination, 6, TournamentResultsMode::Director, ['thirdPlace' => true]);
    foreach (['Bitcoinmaximalistausdemerzgebirge', 'El Presidento Ben', 'Lux', 'MissMarple', 'Satoshi Stacker', 'Industrie_KPI'] as $index => $name) {
        TournamentParticipant::query()->where('tournament_id', $finished->id)->where('name', 'Player '.($index + 1))->update(['name' => $name]);
    }
    playOutAsDirector($finished);
    $finished->forceFill(['name' => '21,000 Sats, Zero Ball Control', 'published_at' => now()->subDays(8), 'created_by_id' => organizer()->id, 'starts_at' => now()->subDays(6),
        'pool_opened_at' => now()->subDays(8), 'pool_closed_at' => now()->subDays(5), 'pot_source' => Tournament::POT_WALLET, 'pot_balance_sats' => 23_310, 'prize_target_sats' => 23_310, 'payouts_approved_at' => now()->subDays(5)])->save();
    $champion = app(TournamentChampion::class)->of($finished->refresh());
    foreach ([[1, 11_655, PayoutStatus::Paid], [2, 6_993, PayoutStatus::Pending]] as [$place, $sats, $status]) {
        $entry = $place === 1 ? $champion : TournamentParticipant::query()->where('tournament_id', $finished->id)->whereKeyNot($champion->id)->first();
        TournamentPayout::query()->create(['tournament_id' => $finished->id, 'user_id' => $entry->user_id, 'participant_id' => $entry->id, 'pubkey' => User::query()->find($entry->user_id)->pubkey,
            'name' => $entry->name, 'place' => $place, 'amount_sats' => $sats, 'idempotency_key' => 'p1-'.$place, 'status' => $status]);
    }

    return [$admin->refresh(), $featured->refresh(), $finished->refresh()];
}

test('P1 main navigation: each page marks the item the board marks (G4), at 1440', function () {
    [$admin, $featured, $finished] = p1TournamentLeague();
    PongOn::play();
    app()->forgetInstance(GameRegistry::class);
    $done = SeriesMatch::factory()->accepted()->create(['game' => 'rocket-league', 'mode' => '1v1']);
    // Header.dc.html and the page boards: GameScore, BlockfillReplays, LadderHyper, LadderPong -> Spiele; GamesIndex,
    // MatchShow -> Mempool; LadderPage, LadderStrongest -> Season; TournamentDetail -> Turniere.
    $expected = [
        '/scores/blockfill' => 'games-menu',
        '/blockfill/replays' => 'games-menu',
        '/hyperbitcoinization/ladder' => 'games-menu',
        '/proof-of-pong/ladder' => 'games-menu',
        '/games' => 'nav-mempool',
        route('matches.show', $done->number, false) => 'nav-mempool',
        '/ladder/chess/blitz' => 'nav-mining',
        '/ladder/strongest' => 'nav-mining',
        '/mining' => 'nav-mining',
        route('tournaments.show', $finished, false) => 'nav-tournaments',
    ];
    $seen = [];
    foreach ($expected as $path => $item) {
        $page = p1Open($admin, $path, 1440);
        $seen[$path] = $page->evaluate('() => [...document.querySelectorAll("[data-test=main-nav] [aria-current]")].map((el) => el.dataset.test)');
        expect(p1Loud($page))->toBe([], $path);
    }
    expect($seen)->toBe(array_map(fn (string $item): array => [$item], $expected));
});
