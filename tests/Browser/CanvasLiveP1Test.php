<?php

use App\Enums\InviteStatus;
use App\Enums\PayoutStatus;
use App\Enums\SeriesStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Games\Blockfill;
use App\Games\GameRegistry;
use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\ClanInvite;
use App\Models\InviteLink;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentOrganizer;
use App\Models\TournamentParticipant;
use App\Models\TournamentPayout;
use App\Models\User;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\Verifier;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentChampion;
use App\Support\Tournaments\TournamentRunner;
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

/**
 * German formats on German pages (G5, B2): text nodes of the page with a number grouped by a space ("2 100"), a
 * 4- to 7-digit number above 999 without a separator that is not a year ("1143"), an ISO date ("2026-11-07"), a
 * German month written before its day ("Okt 5", "Sep 25, 2026") or an English stock phrase.
 */
const P1_FORMATS = <<<'JS'
() => {
    const out = [];
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    const months = 'Jan|Feb|Mär|Apr|Mai|Jun|Jul|Aug|Sep|Sept|Okt|Nov|Dez';
    for (let n = walker.nextNode(); n; n = walker.nextNode()) {
        const el = n.parentElement;
        if (!el || el.closest('script, style, input, textarea, code, kbd, [data-format-free], .sr-only') || !el.checkVisibility()) continue;
        const t = n.textContent;
        const push = (kind, m) => out.push(kind + ': ' + t.trim().replace(/\s+/g, ' ').slice(0, 60));
        if (/\d[    ]\d{3}(?!\d)/.test(t) && !/\d{1,2}[  ]\d{3}\s*(px|ms)/.test(t)) push('space-grouped', t);
        for (const m of t.matchAll(/(?<![\d.,:#\/\-])(\d{4,7})(?![\d.,:\-\/])/g)) {
            const v = Number(m[1]);
            if (v >= 1900 && v <= 2100 && m[1].length === 4) continue;
            if (/^\s*Zeichen/.test(t.slice(m.index + m[1].length))) continue;
            push('ungrouped ' + m[1], t);
        }
        if (/\b\d{4}-\d{2}-\d{2}\b/.test(t)) push('iso', t);
        if (new RegExp('\\b(' + months + ')\\.? \\d{1,2}\\b').test(t)) push('month-first', t);
        if (/games played on|matches played on/i.test(t)) push('english', t);
    }
    return [...new Set(out)].slice(0, 30);
}
JS;

test('P1 German formats: German pages group thousands with a dot, Elo too, and write dates day first; English keeps English', function () {
    [$admin, $featured, $finished] = p1TournamentLeague();
    [$me, $invite, $world] = p1Player();
    $done = SeriesMatch::factory()->accepted()->create(['game' => 'rocket-league', 'mode' => '1v1']);
    $pages = ['/mining', route('tournaments.show', $finished, false), '/ladder/chess/blitz', route('players.show', $me->npub, false), route('clans.show', $world['clan'], false),
        route('matches.show', $done->number, false), '/me', route('admin.tournaments.edit', $featured, false), route('games.show', $world['myMove'], false), '/games/rocket-league',
        // An invite link open for three more days ("Offen bis …", gate M3) and the TV page of the league's next tournament.
        route('invites.link', InviteLink::factory()->create(['inviter_id' => $admin->id, 'expires_at' => now()->addDays(3)]), false),
        route('tournaments.tv', $featured, false)];
    $found = [];
    foreach ($pages as $path) {
        $page = p1Open($me, $path, 1440);
        $bad = $page->evaluate(P1_FORMATS);
        $loud = p1Loud($page);
        if ($bad !== [] || $loud !== []) {
            $found[$path] = ['formats' => $bad, 'loud' => $loud];
        }
    }
    expect($found)->toBe([]);

    // English pages keep English: "2,100" sats per win on /mining, "Nov 7" style dates.
    $page = visit(BrowserLogin::url($me))->page();
    $page->setViewportSize(1440, 900);
    $page->goto(ComputeUrl::from(route('locale.switch', 'en')));
    $page->goto(ComputeUrl::from('/mining'));
    $page->waitForLoadState('networkidle');
    $text = $page->evaluate('() => document.body.innerText');
    expect($text)->toContain('2,100')->not->toContain('2.100')->not->toMatch('/\d[\x{00A0}\x{202F} ]\d{3}\b/u');
});

test('P1 native inputs: the admin week-slot time field shows "08:00 PM" whole at 1440', function () {
    [$admin] = p1TournamentLeague();
    // en-US (the runner's default): the 12-hour clock is the widest a time field shows.
    $page = p1Open($admin, route('admin.events', absolute: false), 1440);
    $fit = $page->evaluate('() => {
        const input = document.querySelector("[data-test=slot-time]");
        const cs = getComputedStyle(input);
        const ctx = document.createElement("canvas").getContext("2d");
        ctx.font = cs.fontWeight + " " + cs.fontSize + " " + cs.fontFamily;
        // The text, the clock button (about 24 px) and the padding: what the field needs to show "08:00 PM" whole.
        const need = ctx.measureText("08:00 PM").width + 24 + parseFloat(cs.paddingLeft) + parseFloat(cs.paddingRight) + parseFloat(cs.borderLeftWidth) * 2;
        return { need: Math.ceil(need), has: Math.floor(input.getBoundingClientRect().width) };
    }');
    expect($fit['has'])->toBeGreaterThanOrEqual($fit['need'], json_encode($fit));
    p1Shot($page, 'admin-events-en-US-1440');

});

/**
 * Cut and broken text inside `scope` (R3, WCAG 1.4.10): a word laid out over two lines ("Hyperbitcoiniz|ation",
 * "21-archive-2|-1"), a cell or label whose text is cut by its own box (overflow hidden or an ellipsis), and a box
 * that runs out of the page. Text inside a sideways scroller counts as cut only when the scroller cannot reach it.
 */
const P1_CUT = <<<'JS'
([scope]) => {
    const out = [];
    const roots = [...document.querySelectorAll(scope)].filter((el) => el.checkVisibility());
    if (roots.length === 0) return ['no ' + scope];
    const width = document.documentElement.clientWidth;
    if (document.documentElement.scrollWidth > width) out.push('page ' + document.documentElement.scrollWidth + ' > ' + width);
    for (const root of roots) {
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        for (let n = walker.nextNode(); n; n = walker.nextNode()) {
            const el = n.parentElement;
            if (!el || !el.checkVisibility() || el.closest('.sr-only, svg, [aria-hidden=true]')) continue;
            // A soft hyphen is a break the text allows; a user's unbroken word of 25 characters or more has to break somewhere.
            const re = /[^\s\u00a0\u2009\u202f\u00ad\-\u2013\u00b7,/]+/g;
            let m;
            while ((m = re.exec(n.textContent))) {
                if (m[0].length < 2 || m[0].length >= 25) continue;
                const range = document.createRange();
                range.setStart(n, m.index);
                range.setEnd(n, m.index + m[0].length);
                // After a soft hyphen the range also holds the hyphen drawn at the end of the line above: not part of the word.
                const rects = [...range.getClientRects()].filter((r) => r.width > 2).slice(n.textContent[m.index - 1] === '\u00ad' ? 1 : 0);
                const tops = new Set(rects.map((r) => Math.round(r.top)));
                if (tops.size > 1) out.push('broken ' + m[0].slice(0, 40));
            }
        }
        // A lobby's name and password stay on one line: a break inside reads as two values.
        for (const el of root.querySelectorAll('[data-test$="-access"] b')) {
            if (new Set([...el.getClientRects()].map((r) => Math.round(r.top))).size > 1 || el.getClientRects().length > 1) out.push('wrapped ' + el.textContent.trim());
        }
        for (const el of [root, ...root.querySelectorAll('*')]) {
            if (!el.checkVisibility() || el.childElementCount > 0 || !el.textContent.trim() || el.closest('.sr-only, [aria-hidden=true]')) continue;
            // Visually hidden on purpose (a responsive sr-only label: 1 px, clipped) is not cut text.
            if (el.getBoundingClientRect().width <= 1) continue;
            const cs = getComputedStyle(el);
            if ((cs.overflowX !== 'visible' || cs.textOverflow === 'ellipsis') && el.scrollWidth > el.clientWidth + 1) out.push('cut ' + el.textContent.trim().slice(0, 40));
            const r = el.getBoundingClientRect();
            let clipped = false;
            for (let a = el.parentElement; a && a !== document.body; a = a.parentElement) {
                const ox = getComputedStyle(a).overflowX;
                // A scroller with edge fades (x-ui.scroll-x) says there is more; a bare overflow-x-auto cuts without a hint.
                if (ox === 'auto' || ox === 'scroll') { const b = a.getBoundingClientRect(); if (!a.classList.contains('rv-xs-vp') && (r.right > b.right + 1 || r.left < b.left - 1)) clipped = true; break; }
                if (ox === 'hidden' || ox === 'clip') { const b = a.getBoundingClientRect(); if (r.right > b.right + 1 || r.left < b.left - 1) clipped = true; break; }
            }
            if (clipped) out.push('clipped ' + el.textContent.trim().slice(0, 40));
        }
    }
    return [...new Set(out)].slice(0, 20);
}
JS;

/** An Age of Empires II lobby tournament run by its director: nine players in two lobbies with their access. */
function p1LobbyTournament(): array
{
    $organizer = User::factory()->create(['name' => 'Bitcoinmaximalistausdemerzgebirge']);
    TournamentOrganizer::query()->create(['pubkey' => $organizer->pubkey]);
    $tournament = Tournament::factory()->create([
        'name' => 'Age of Empires II Diplomacy Night: Wer baut das größte Weltwunder', 'game' => 'age-of-empires-2', 'mode' => '1v1', 'format' => TournamentFormat::FreeForAll,
        'options' => FormatOptions::fromArray([], GameProfile::for('age-of-empires-2', '1v1'))->toArray(),
        'capacity' => 9, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running, 'starts_at' => now(),
        'published_at' => now(), 'slug' => 'p1-lobby-night', 'created_by_id' => $organizer->id,
    ]);
    foreach (['Saladin', 'Joan of Arc', 'Genghis Khan', 'Barbarossa', 'Tamerlane', 'El Cid', 'Attila', 'Bari', 'Gajah Mada'] as $index => $name) {
        $user = User::factory()->create(['name' => $name]);
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => $name, 'rating' => 1500 - 10 * $index, 'members' => [$user->id]]);
    }
    app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($tournament);
    $lobby = TournamentMatch::query()->where('tournament_id', $tournament->id)->orderBy('position')->with('slots.participant')->firstOrFail();

    return [$tournament->refresh(), $organizer, User::query()->findOrFail($lobby->slots[0]->participant->user_id)];
}

test('P1 cut columns and broken words: payouts, match games, the season table, Hyperbitcoinization, the more sheet and lobby access, at 390 and 1440', function () {
    [$admin, $featured, $finished] = p1TournamentLeague();
    [$me] = p1Player();
    [$lobbyTournament, $organizer, $lobbyPlayer] = p1LobbyTournament();
    $done = SeriesMatch::factory()->accepted()->create(['game' => 'rocket-league', 'mode' => '1v1', 'challenger_name' => 'Bitcoinmaximalistausdemerzgebirge', 'challenged_name' => 'El Presidento Ben']);
    $done->forceFill(['live_games' => array_map(fn (array $g): array => ['challenger' => $g[0], 'challenged' => $g[1], 'winner' => $g[0] > $g[1] ? 'challenger' : 'challenged'], [[4, 2], [2, 3], [3, 4]])])->save();
    $cases = [
        ['payouts', null, route('tournaments.show', $finished, false), '[data-test=pool-payouts]', [375, 390, 1440]],
        ['match-games', null, route('matches.show', $done->number, false), '[data-test=flow-game]', [390, 1440]],
        ['mining-pay', $admin, '/mining', '#eras-h, [data-test=mining-eras-scroll]', [390, 1440]],
        ['hyper', null, '/hyperbitcoinization', 'main h1', [390, 1440]],
        ['hyper-ladder', null, '/hyperbitcoinization/ladder', 'main h1', [390, 1440]],
        ['more-sheet', $me, '/me', '#more-sheet ul', [390]],
        ['lobby-director', $organizer, route('tournaments.show', $lobbyTournament, false), '[data-test=lobby-card]', [1440]],
        ['lobby-player', $lobbyPlayer, route('tournaments.show', $lobbyTournament, false), '[data-test=my-lobby], [data-test=lobby-card]', [390, 1440]],
    ];
    $found = [];
    foreach ($cases as [$name, $user, $path, $scope, $widths]) {
        foreach ($widths as $width) {
            $page = p1Open($user, $path, $width);
            if ($name === 'more-sheet') {
                $page->locator('[data-test=tab-more]')->click();
                $page->evaluate('() => new Promise((r) => setTimeout(r, 400))');
            }
            if ($name === 'mining-pay') {
                // The table scrolls inside its card (x-ui.scroll-x): the right fade says there is more, the scroller reaches the end.
                $scroll = $page->evaluate('() => { const v = document.querySelector("[data-test=mining-eras-scroll] .rv-xs-vp"); const more = v.scrollWidth > v.clientWidth + 1; return { more, fade: getComputedStyle(document.querySelector("[data-test=mining-eras-scroll] [data-test=scroll-fade-r]")).opacity, keys: v.tabIndex }; }');
                if ($scroll['more'] && ($scroll['fade'] !== '1' || $scroll['keys'] !== 0)) {
                    $found["{$name} {$width}"][] = 'scroll '.json_encode($scroll);
                }
            }
            $page->evaluate('(s) => { const el = document.querySelector(s); el?.scrollIntoView({ block: "center" }); return new Promise((r) => setTimeout(r, 300)); }', $scope);
            p1Shot($page, "cut-{$name}-{$width}");
            $cut = $page->evaluate(P1_CUT, [$scope]);
            $loud = p1Loud($page);
            if ($cut !== [] || $loud !== []) {
                $found["{$name} {$width}"] = array_merge($found["{$name} {$width}"] ?? [], $cut, $loud);
            }
        }
    }
    expect($found)->toBe([]);
});

test('P1 button hierarchy: the one primary action of each page is the orange button the board draws, at 1440 and 390', function () {
    [$admin, $featured] = p1TournamentLeague();
    $dispute = SeriesMatch::factory()->accepted()->create(['status' => SeriesStatus::Disputed, 'challenger_name' => 'Bitcoinmaximalistausdemerzgebirge', 'challenged_name' => 'El Presidento Ben']);
    $orange = 'rgb(247, 147, 26)';
    // [user, path, the primary (orange fill), the secondaries (no orange fill) in their order after it, a danger button (red edge)]
    $cases = [
        'admins' => [$admin, '/admin/admins', '[data-test=admin-add]', null, null],
        'organizers' => [$admin, '/admin/organizers', '[data-test=organizer-add]', null, null],
        'fair-play' => [$admin, '/admin/fair-play', null, null, '[data-test=fair-play-link]'],
        'dispute' => [$admin, route('admin.disputes.show', $dispute, false), '[data-test=confirm-decision]', '[data-test=ask-new-report]', null],
        'strongest' => [null, '/ladder/strongest', '[data-test=strongest-play]', 'main a[href$="/mining"].btn-s', null],
        'live-chat' => [null, '/live', '[data-test=live-chat-login]', null, null],
    ];
    $found = [];
    foreach ([1440, 390] as $width) {
        foreach ($cases as $name => [$user, $path, $primary, $secondary, $danger]) {
            $page = p1Open($user, $path, $width);
            $m = $page->evaluate('([primary, secondary, danger]) => {
                const pick = (s) => s ? [...document.querySelectorAll(s)].find((el) => el.checkVisibility()) ?? null : null;
                const p = pick(primary), s = pick(secondary), d = pick(danger);
                const bg = (el) => el ? getComputedStyle(el).backgroundColor : null;
                return {
                    primary: primary ? (p ? bg(p) : "missing") : null,
                    secondaryBg: secondary ? (s ? bg(s) : "missing") : null,
                    order: p && s ? (p.compareDocumentPosition(s) & Node.DOCUMENT_POSITION_FOLLOWING ? "primary first" : "secondary first") : null,
                    danger: danger ? (d ? [getComputedStyle(d).borderTopColor, bg(d)] : "missing") : null,
                };
            }', [$primary, $secondary, $danger]);
            $bad = [];
            if ($primary !== null && $m['primary'] !== $orange) {
                $bad[] = 'primary '.$m['primary'];
            }
            if ($secondary !== null && ($m['secondaryBg'] === $orange || $m['secondaryBg'] === 'missing' || $m['order'] !== 'primary first')) {
                $bad[] = 'secondary '.json_encode([$m['secondaryBg'], $m['order']]);
            }
            if ($danger !== null && (! is_array($m['danger']) || $m['danger'][0] !== 'rgb(255, 59, 59)' && $m['danger'][0] !== 'rgb(248, 113, 113)' || $m['danger'][1] === $orange)) {
                $bad[] = 'danger '.json_encode($m['danger']);
            }
            $loud = p1Loud($page);
            if ($bad !== [] || $loud !== []) {
                $found["{$name} {$width}"] = [...$bad, ...$loud];
            }
        }
    }

    // Tournament edit: one save button for the whole page; the prize pot saves with it.
    $page = p1Open($admin, route('admin.tournaments.edit', $featured, false), 1440);
    $saves = $page->evaluate('() => [...document.querySelectorAll("main button")].filter((b) => b.checkVisibility() && /^(Änderungen speichern|Gewinntopf speichern|Speichern)$/.test(b.textContent.trim())).map((b) => b.textContent.trim())');
    expect($found)->toBe([])->and($saves)->toBe(['Änderungen speichern']);
});

test('P1 "Neu hier?" strip at 390: the steps scroll sideways inside the strip under an edge fade, the page does not', function () {
    $page = p1Open(null, '/clans', 390);
    $m = $page->evaluate('() => {
        const list = document.querySelector("[data-test=first-steps-list]");
        const cs = getComputedStyle(list);
        return { scrolls: cs.overflowX === "auto" && list.scrollWidth > list.clientWidth, fade: (cs.maskImage || cs.webkitMaskImage || "").includes("linear-gradient"), page: document.documentElement.scrollWidth <= document.documentElement.clientWidth };
    }');
    expect($m)->toBe(['scrolls' => true, 'fade' => true, 'page' => true])->and(p1Loud($page))->toBe([]);
});

test('P1 positive control: a thrown error and a 404 fetch reach the channels the other P1 tests read', function () {
    $page = p1Open(null, '/', 1440);
    expect(p1Loud($page))->toBe([]);
    $page->evaluate('() => { setTimeout(() => { throw new Error("p1-positive-control"); }, 0); return fetch("/__p1-missing-route-500").then((r) => r.text()).catch(() => null); }');
    $page->evaluate('() => new Promise((r) => setTimeout(r, 300))');
    $loud = implode(' | ', p1Loud($page));
    expect($loud)->toContain('p1-positive-control')->toContain('404');
});

/**
 * Every button of a page's own bottom bar (`[data-save-bar]`, `[data-floor-owner]`) at the top, the middle and the
 * end of the page: its centre and its lower edge hit-test to the button itself, never to the floor band, the live
 * tab, the dock or the tab bar. Unlike P1_FLOATING, points inside the band strip count.
 */
const P1_BARS = <<<'JS'
async () => {
    const wait = (ms) => new Promise((r) => setTimeout(r, ms));
    const label = (el) => !el ? 'nothing' : (el.closest('[data-test]')?.dataset.test || el.className?.toString().slice(0, 40) || el.tagName);
    const out = [];
    let seen = 0;
    const max = Math.max(0, document.scrollingElement.scrollHeight - innerHeight);
    for (const y of [0, Math.round(max / 2), max]) {
        scrollTo(0, y);
        await wait(1300);
        for (const bar of document.querySelectorAll('[data-save-bar], [data-floor-owner]')) {
            if (!bar.checkVisibility()) continue;
            for (const b of bar.querySelectorAll('button, a[href]')) {
                if (!b.checkVisibility()) continue;
                const r = b.getBoundingClientRect();
                // On screen means its centre is in the viewport (a sticky bar left behind at the form's end is off screen, not covered).
                if (r.width === 0 || r.top + r.height / 2 < 0 || r.top + r.height / 2 > innerHeight) continue;
                seen++;
                for (const [px, py] of [[r.left + r.width / 2, r.top + r.height / 2], [r.left + r.width / 2, r.bottom - 3]]) {
                    if (py < 0 || py >= innerHeight) continue;
                    const hit = document.elementFromPoint(px, py);
                    if (!hit || !(b === hit || b.contains(hit))) out.push((b.dataset.test || b.textContent.trim().replace(/\s+/g, ' ').slice(0, 30)) + ' @' + y + ' -> ' + label(hit));
                }
            }
        }
    }
    return seen === 0 ? ['no bar button seen'] : [...new Set(out)];
}
JS;

test('P1 page bottom bars: every save-bar and floor-owner button stays free of the floor band, live tab, dock and tab bar, at 390 and 1440', function () {
    [$admin, $featured] = p1TournamentLeague();
    $signup = Tournament::factory()->signup()->create(['name' => 'P1 Sign-up Cup', 'published_at' => now()]);
    $found = [];
    foreach (['live tab' => false, 'dock and live tab' => true] as $state => $dock) {
        $user = User::factory()->member()->create(['name' => 'Bitcoinmaximalistausdemerzgebirge']);
        Admin::query()->firstOrCreate(['pubkey' => $user->pubkey]);
        if ($dock) {
            ChessGame::factory()->daily()->create(['white_id' => $user->id]);
        }
        // The challenge form had a phone step bar here; since P4 it is a wizard whose actions sit in the step's own card
        // (ChallengeCreate.dc.html, tests/Browser/ChallengeWizardTest.php). In its place the game page's play bar
        // (GamePhone.dc.html, P4), which owns the floor the same way.
        $cases = [
            ['edit', $user, route('admin.tournaments.edit', $featured, false), [1440, 390]],
            ['create', $user, route('admin.tournaments.create', absolute: false), [1440, 390]],
            ['chess-play-bar', $user, route('chess.lobby', absolute: false), [390]],
            ['signup', $user, route('tournaments.show', $signup, false), [390]],
        ];
        foreach ($cases as [$name, $who, $path, $widths]) {
            foreach ($widths as $width) {
                $page = p1Open($who, $path, $width);
                $bad = $page->evaluate(P1_BARS);
                p1Shot($page, "bars-{$name}-".($dock ? 'dock-' : '')."{$width}");
                $loud = p1Loud($page);
                if ($bad !== [] || $loud !== []) {
                    $found["{$name} {$width} {$state}"] = [...$bad, ...$loud];
                }
            }
        }
    }

    // Stacker's touch keys (a coarse pointer, practice running): the floor-owner bar of a playfield page.
    $player = User::factory()->member()->create(['name' => 'Phone Player']);
    ChessGame::factory()->daily()->create(['white_id' => $player->id]);
    $page = visit(BrowserLogin::url($player))->on()->mobile()->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize(390, 844);
    touch($this->hls.'/stream.m3u8');
    $page->goto(ComputeUrl::from(route('stacker.play', [], false)));
    BrowserWait::until($page, '() => window.__stacker !== undefined', 10_000);
    $page->locator('[data-test=start-practice]')->click();
    BrowserWait::until($page, '() => window.__stacker.state().mode === "playing"', 6_000);
    $stacker = $page->evaluate('() => [...document.querySelectorAll("[data-floor-owner] [data-test^=touch-]")].filter((b) => b.checkVisibility()).map((b) => { const r = b.getBoundingClientRect(); const e = document.elementFromPoint(r.left + r.width / 2, r.bottom - 3); return b.contains(e) ? null : b.dataset.test + " -> " + (e?.closest("[data-test]")?.dataset.test ?? e?.className); }).filter(Boolean)');
    if ($stacker !== []) {
        $found['stacker 390'] = $stacker;
    }

    expect($found)->toBe([]);
});
