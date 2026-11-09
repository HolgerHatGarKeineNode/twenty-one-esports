<?php

use App\Enums\StackerRunStatus;
use App\Games\Blockfill;
use App\Games\GameRegistry;
use App\Jobs\VerifyStackerRun;
use App\Models\Admin;
use App\Models\Rating;
use App\Models\StackerRun;
use App\Models\User;
use App\Support\Scores\ScoreLeaderboards;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\Verifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Pest\Browser\Support\ComputeUrl;
use Pest\Browser\Support\Screenshot;
use Tests\Support\BlockfillOn;
use Tests\Support\BlockliOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\FakeStackerVerifier;
use Tests\Support\HyperOn;
use Tests\Support\NineMensMorrisOn;
use Tests\Support\PongOn;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Game pages against their boards (plan "Canvas and live alignment", P4)
|--------------------------------------------------------------------------
|
| GameScore, GameSeries and GamePhone, measured as an admin in German:
| - 1440: no grid on a game page leaves more than a third of its width empty
|   ("Blöcke der Woche" sat in one half of a two-column grid, comparison GameScore).
| - 390: the page's one "Spielen" action stands in a bar on the floor above the tab bar
|   (GamePhone "Chat + Spielen: Rapid suchen"): bar bottom == floor top, it covers nothing of
|   the page at the top, middle and end, the page ends above it; the game bar's tabs are not
|   cut by a fixed button and the hero line is not cut with an ellipsis.
| - The RL ladder is the T5 table (GameSeries board), not a row of podium cards.
| Console and responses stay quiet on first load and after `$wire.$refresh()`.
| P4_SHOTS=<dir> saves a first-screen screenshot per page and width.
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
    $this->app->instance(Verifier::class, new FakeStackerVerifier);
});

/** Every game page that renders through x-game.page or x-ladder.page. */
function p4Pages(): array
{
    return [
        'chess' => '/chess',
        'rocket-league' => '/games/rocket-league',
        'nine-mens-morris' => '/games/nine-mens-morris',
        'blockli' => '/games/blockli',
        'hyper' => '/hyperbitcoinization',
        'pong' => '/proof-of-pong',
        'scores-blockfill' => '/scores/blockfill',
        'ladder-strongest' => '/ladder/strongest',
        'ladder-rl' => '/ladder/rocket-league/1v1',
        'hyper-ladder' => '/hyperbitcoinization/ladder',
        'pong-ladder' => '/proof-of-pong/ladder',
    ];
}

/** The game pages with a primary "Spielen" action (the slot of x-game.subnav). */
function p4PlayPages(): array
{
    return array_intersect_key(p4Pages(), array_flip(['chess', 'rocket-league', 'nine-mens-morris', 'blockli', 'hyper', 'pong', 'scores-blockfill']));
}

/**
 * The league: every game on, an admin with the longest name the profile allows, a Blockfill week finished with 19
 * players (the points ladder of GameScore has 19 rows) and the running week, five casual RL 1v1 ratings.
 */
function p4League(): User
{
    BlockfillOn::play();
    BlockliOn::play();
    HyperOn::play();
    PongOn::play();
    NineMensMorrisOn::play();
    app()->forgetInstance(GameRegistry::class);
    leagueWeeksApproved(Blockfill::SLUG);

    $names = ['Stefan', 'B13', 'Wels', 'TickTrickSat', '0xFCKBKA', 'npub1gc8f8zrxyz', 'el-z', 'El Presidento Ben', 'UWE', 'Auryn_Wolfback',
        'MissMarple', 'zettizettler', 'NostrLlama', 'xXxecutor', 'Marmite', 'npub130j8jxeabc', 'whoami', 'LightningInTheAlps', 'PlebInstitute'];
    $users = array_map(fn (string $name): User => User::factory()->create(['name' => $name]), $names);

    // Two weeks ago the first ten, last week the other nine (and the tenth): each week pays its top ten points per
    // place after its review time, so the ladder has 19 players with points.
    $now = CarbonImmutable::now();
    foreach ([2 => array_slice($users, 0, 10), 1 => array_slice($users, 9)] as $weeksAgo => $players) {
        test()->travelTo($now->subWeeks($weeksAgo));
        app(BlockfillWeeks::class)->open();
        foreach ($players as $index => $user) {
            $run = StackerRun::factory()->for($user)->create(['status' => StackerRunStatus::Verifying, 'issued_at' => now(), 'started_at' => now(), 'submitted_at' => now(),
                'ticks' => 3000 + $index * 37, 'state_hash' => '00000000', 'replay' => 'AAAA']);
            VerifyStackerRun::dispatchSync($run->id);
        }
        test()->travelTo($now->subWeeks($weeksAgo)->addDays(9));
        app(ScoreLeaderboards::class)->tick();
    }
    test()->travelBack();
    app(BlockfillWeeks::class)->open();

    foreach (array_slice($users, 0, 5) as $index => $user) {
        Rating::query()->create(['pool' => Rating::CASUAL, 'season' => '', 'game' => 'rocket-league', 'mode' => '1v1', 'subject' => 'user:'.$user->id, 'user_id' => $user->id,
            'rating' => 1020 - $index * 11, 'results' => 3, 'wins' => 2, 'draws' => 0, 'losses' => 1]);
    }

    // Faked only now: the runs above join their week and snapshot through queued jobs.
    Queue::fake();
    $admin = User::factory()->create(['name' => 'Ada Admin Who Watches Every Replay Here']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

function p4Open(User $user, string $path, int $width): mixed
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript('performance.setResourceTimingBufferSize(5000)');
    $page->setViewportSize($width, $width < 640 ? 844 : 900);
    $page->goto(ComputeUrl::from(route('locale.switch', 'de')));
    $page->goto(ComputeUrl::from($path));
    $page->waitForLoadState('networkidle');
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Alpine !== undefined && document.fonts.status === "loaded"', 10_000);
    $page->evaluate('() => new Promise((r) => setTimeout(r, 400))');

    return $page;
}

function p4Shot(mixed $page, string $name): void
{
    $dir = getenv('P4_SHOTS');
    if (! is_string($dir) || $dir === '') {
        return;
    }
    File::ensureDirectoryExists($dir);
    $page->screenshot(getenv('P4_FULL') === '1', 'p4-'.$name);
    File::move(Screenshot::path('p4-'.$name), $dir.'/'.$name.'.png');
}

/** Console (collector and runner) and responses of 400 or more: empty is quiet. */
function p4Loud(mixed $page): array
{
    return array_merge(
        $page->evaluate('() => window.__errors ?? ["collector missing"]'),
        array_map(fn ($e): string => 'runner '.(is_array($e) ? ($e['message'] ?? json_encode($e)) : (string) $e), $page->javaScriptErrors()),
        $page->evaluate(BrowserConsole::BAD_RESPONSES),
    );
}

/** `$wire.$refresh()` on every Livewire component of the page, then settle. */
function p4Refresh(mixed $page): void
{
    $page->evaluate('async () => { for (const c of window.Livewire.all()) { await c.$wire.$refresh(); } await new Promise((r) => setTimeout(r, 600)); }');
}

/**
 * Grids of the game page (the page grid and every grid with two or more columns inside it, wider than 400 px) whose
 * visible children cover less than two thirds of the grid's width: [label, grid width, empty px].
 */
const P4_EMPTY_COLUMNS = <<<'JS'
() => {
    const out = [];
    const root = document.querySelector('.gp');
    if (!root) return ['no .gp'];
    for (const grid of [root, ...root.querySelectorAll('*')]) {
        const cs = getComputedStyle(grid);
        if (!grid.checkVisibility() || cs.display !== 'grid') continue;
        const tracks = cs.gridTemplateColumns.split(' ').filter(Boolean).length;
        const box = grid.getBoundingClientRect();
        if (tracks < 2 || box.width < 400) continue;
        const spans = [...grid.children].filter((c) => c.checkVisibility() && c.getBoundingClientRect().width > 0)
            .map((c) => { const r = c.getBoundingClientRect(); return [Math.max(r.left, box.left), Math.min(r.right, box.right)]; })
            .sort((a, b) => a[0] - b[0]);
        let covered = 0, end = box.left;
        for (const [l, r] of spans) { if (r > end) { covered += r - Math.max(l, end); end = r; } }
        const empty = box.width - covered;
        if (empty > box.width / 3) out.push([(grid.dataset.test || grid.id || grid.className).toString().slice(0, 60), Math.round(box.width), Math.round(empty)]);
    }
    return out;
}
JS;

/**
 * The phone's play bar: where it stands against the floor (a probe at bottom: var(--floor-top)), what of the page it
 * covers at the end of the page (elementFromPoint at the centre of every interactive element of main), and whether
 * the page's last interactive element ends above it there.
 */
const P4_PLAY_BAR = <<<'JS'
async () => {
    const bar = document.querySelector('[data-test=game-play-bar]');
    if (!bar || !bar.checkVisibility()) return { bar: null };
    const probe = document.createElement('div');
    probe.style.cssText = 'position:fixed;left:0;width:1px;height:0;bottom:var(--floor-top)';
    document.body.append(probe);
    const floorTop = probe.getBoundingClientRect().top;
    probe.remove();
    const label = (el) => (el.dataset.test || el.getAttribute('aria-label') || el.textContent || el.tagName).trim().replace(/\s+/g, ' ').slice(0, 40);
    const targets = () => [...document.querySelectorAll('main a, main button, main input, main summary, main [role=button]')].filter((el) => el.checkVisibility() && !bar.contains(el));
    const covered = new Set();
    // What lies under an opaque bar mid-page is scrolled past, as under the tab bar; at the end of the page nothing may.
    window.scrollTo(0, document.documentElement.scrollHeight);
    await new Promise((r) => setTimeout(r, 400));
    for (const el of targets()) {
        const r = el.getBoundingClientRect();
        if (r.width === 0 || r.bottom <= 0 || r.top >= innerHeight) continue;
        const cy = r.top + r.height / 2;
        if (cy >= innerHeight) continue;
        if (bar.contains(document.elementFromPoint(r.left + r.width / 2, cy))) covered.add(label(el));
    }
    const last = targets().filter((el) => el.getBoundingClientRect().width > 0).map((el) => el.getBoundingClientRect().bottom).reduce((a, b) => Math.max(a, b), 0);
    const r = bar.getBoundingClientRect();
    const buttons = [...bar.querySelectorAll('a, button')].filter((b) => b.checkVisibility()).map((b) => {
        const box = b.getBoundingClientRect();
        return { text: b.textContent.trim().replace(/\s+/g, ' '), h: Math.round(box.height), takesClick: b.contains(document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2)) };
    });
    window.scrollTo(0, 0);
    return { bar: [Math.round(r.top), Math.round(r.bottom)], floorTop: Math.round(floorTop), position: getComputedStyle(bar).position, covered: [...covered], lastBottom: Math.round(last), buttons };
}
JS;

/** The game bar's tabs at a phone width: cut ones (partly outside the scroll box) and whether a fixed action shows. */
const P4_TABS = <<<'JS'
() => {
    const box = document.querySelector('[data-test=game-subnav] .gs-tabs');
    if (!box) return { tabs: null };
    const b = box.getBoundingClientRect();
    const tabs = [...box.querySelectorAll('.gs-tab')].map((t) => { const r = t.getBoundingClientRect(); return { text: t.textContent.trim(), cut: r.left < b.left - 1 || r.right > b.right + 1, current: t.hasAttribute('aria-current') }; });
    const act = document.querySelector('[data-test=game-subnav] .gs-act');
    return { tabs, act: !!act && act.checkVisibility() && act.getBoundingClientRect().width > 0 };
}
JS;

/** The hero's data line: cut with an ellipsis (line clamp, or content taller than its box). */
const P4_LEDE = <<<'JS'
() => {
    const lede = document.querySelector('[data-test=game-lede]');
    if (!lede) return null;
    const cs = getComputedStyle(lede);
    return { clamp: cs.webkitLineClamp, cut: lede.scrollHeight > lede.clientHeight + 1, width: Math.round(lede.getBoundingClientRect().width) };
}
JS;

test('P4 GameScore and every game page at 1440: no grid leaves more than a third of its width empty', function () {
    $admin = p4League();
    $problems = [];
    foreach (p4Pages() as $name => $path) {
        $page = p4Open($admin, $path, 1440);
        p4Shot($page, "{$name}-1440");
        $empty = $page->evaluate(P4_EMPTY_COLUMNS);
        // The widest empty share of any grid on the page, for the report (the limit is a third).
        $widest = $page->evaluate(str_replace('if (empty > box.width / 3) out.push(', 'out.push(', P4_EMPTY_COLUMNS));
        $share = collect(is_array($widest) ? $widest : [])->filter(fn ($row): bool => is_array($row))->map(fn (array $row): float => round($row[2] / $row[1], 3))->max();
        fwrite(STDERR, "\n[p4] {$name} 1440: widest empty share ".json_encode($share)."\n");
        [$scroll, $client] = $page->evaluate(BrowserConsole::WIDTHS);
        $loud = p4Loud($page);
        p4Refresh($page);
        $loud = array_merge($loud, p4Loud($page));
        if ($empty !== [] || $scroll > $client || $loud !== []) {
            $problems[$name] = ['empty' => $empty, 'overflow' => $scroll - $client, 'loud' => $loud];
        }
    }
    expect($problems)->toBe([]);
});

test('P4 GameScore: "Blöcke der Woche" spans the main column in two columns of places, 1 to 10 left', function () {
    $admin = p4League();
    $page = p4Open($admin, '/scores/blockfill', 1440);
    $m = $page->evaluate(<<<'JS'
        () => {
            const card = document.querySelector('[data-test=score-points-40-blocks]');
            const main = document.querySelector('.gp-rest');
            const rows = [...card.querySelectorAll('[data-test=score-points-row]')].map((r) => r.getBoundingClientRect());
            return { card: Math.round(card.getBoundingClientRect().width), main: Math.round(main.getBoundingClientRect().width), rows: rows.length,
                lefts: [...new Set(rows.map((r) => Math.round(r.left)))].length, eleventhBesideFirst: rows.length > 10 && Math.abs(rows[10].top - rows[0].top) < 2 };
        }
        JS);
    expect($m['rows'])->toBe(19)
        ->and($m['card'])->toBe($m['main'])
        ->and($m['lefts'])->toBe(2)
        ->and($m['eleventhBesideFirst'])->toBeTrue();
});

test('P4 GamePhone at 390: the "Spielen" action stands in a bar on the floor, covers nothing and the page ends above it; tabs and hero line are whole', function () {
    $admin = p4League();
    $problems = [];
    $seen = [];
    foreach (p4PlayPages() as $name => $path) {
        $page = p4Open($admin, $path, 390);
        p4Shot($page, "{$name}-390");
        $bar = $page->evaluate(P4_PLAY_BAR);
        fwrite(STDERR, "\n[p4] {$name} 390: ".json_encode(array_intersect_key($bar, array_flip(['bar', 'floorTop', 'lastBottom', 'covered'])))."\n");
        $tabs = $page->evaluate(P4_TABS);
        $lede = $page->evaluate(P4_LEDE);
        $squeezed = $page->evaluate(BrowserConsole::SQUEEZED, ['main']);
        [$scroll, $client] = $page->evaluate(BrowserConsole::WIDTHS);
        $seen[$name] = $bar['buttons'] ?? null;
        $issues = [];
        if ($bar['bar'] === null) {
            $issues[] = 'no play bar';
        } else {
            if (abs($bar['bar'][1] - $bar['floorTop']) > 1) {
                $issues[] = "bar bottom {$bar['bar'][1]} != floor top {$bar['floorTop']}";
            }
            if ($bar['position'] !== 'fixed') {
                $issues[] = 'bar not fixed';
            }
            if ($bar['covered'] !== []) {
                $issues[] = 'covers '.implode(', ', $bar['covered']);
            }
            if ($bar['lastBottom'] > $bar['bar'][0] + 1) {
                $issues[] = "page ends under the bar ({$bar['lastBottom']} > {$bar['bar'][0]})";
            }
            foreach ($bar['buttons'] as $button) {
                if (! $button['takesClick'] || $button['h'] < 44) {
                    $issues[] = 'button '.$button['text'].' h '.$button['h'].($button['takesClick'] ? '' : ' covered');
                }
            }
        }
        if ($tabs['act']) {
            $issues[] = 'fixed action in the game bar';
        }
        foreach ($tabs['tabs'] ?? [] as $tab) {
            if ($tab['cut'] && ($tab['current'] || $tab === $tabs['tabs'][0])) {
                $issues[] = 'tab cut: '.$tab['text'];
            }
        }
        if ($lede !== null && ($lede['cut'] || $lede['clamp'] !== 'none')) {
            $issues[] = 'hero line cut '.json_encode($lede);
        }
        if ($squeezed !== [] || $scroll > $client) {
            $issues[] = 'squeezed '.json_encode($squeezed).' overflow '.($scroll - $client);
        }
        $loud = p4Loud($page);
        p4Refresh($page);
        $loud = array_merge($loud, p4Loud($page));
        if ($loud !== []) {
            $issues[] = 'loud '.json_encode(array_values(array_unique($loud)));
        }
        if ($issues !== []) {
            $problems[$name] = $issues;
        }
    }
    expect($problems)->toBe([])
        // GamePhone: chess shows its chat beside "Spielen: Rapid suchen", every chess tab whole.
        ->and(array_column($seen['chess'] ?? [], 'text'))->toBe(['Chat', 'Spielen: Rapid suchen']);
});

test('P4 GamePhone: the chess tabs are all whole at 390 and the bar\'s Chat opens the chat', function () {
    $admin = p4League();
    $page = p4Open($admin, '/chess', 390);
    $tabs = $page->evaluate(P4_TABS);
    expect(array_filter($tabs['tabs'], fn (array $tab): bool => $tab['cut']))->toBe([]);

    $page->evaluate('() => document.querySelector("[data-test=game-play-bar-chat]").click()');
    // Opened where the chat is configured (its toggle), scrolled into view either way.
    BrowserWait::until($page, '() => { const c = document.querySelector("[data-test=game-chat]"); const r = c.getBoundingClientRect(); return (c.dataset.open === undefined || c.dataset.open === "true") && r.top >= 0 && r.top < innerHeight / 2; }', 5_000);
    $m = $page->evaluate('() => { const r = document.querySelector("[data-test=game-chat]").getBoundingClientRect(); return [Math.round(r.top), innerHeight]; }');
    expect($m[0])->toBeLessThan($m[1]);
});

test('P4 GameSeries: the RL ladder is the T5 table with the casual Elo and the record, not podium cards', function () {
    $admin = p4League();
    foreach ([1440, 390] as $width) {
        $page = p4Open($admin, '/games/rocket-league', $width);
        $m = $page->evaluate(<<<'JS'
            () => {
                const ladder = document.querySelector('[data-test=game-ladder]');
                const table = ladder?.querySelector('table.rv-tb');
                return { table: !!table, rows: table ? table.querySelectorAll('tbody tr').length : 0, heads: table ? [...table.querySelectorAll('th')].filter((th) => th.checkVisibility()).map((th) => th.textContent.trim()) : [],
                    first: table?.querySelector('tbody tr')?.textContent.replace(/\s+/g, ' ').trim() };
            }
            JS);
        expect($m['table'])->toBeTrue("no T5 table at {$width}")
            ->and($m['rows'])->toBe(5)
            ->and($m['first'])->toContain('Stefan')->toContain('1.020');
        if ($width === 1440) {
            expect($m['heads'])->toBe(['#', 'Spieler', 'Casual-Elo', 'Bilanz'])
                ->and($m['first'])->toContain('2 S, 1 N');
        }
    }
});

test('P4 positive control: the console collector and the bar probe see what they should', function () {
    $admin = p4League();
    $page = p4Open($admin, '/chess', 390);
    $page->evaluate('() => { setTimeout(() => { throw new Error("p4 positive control"); }); const i = new Image(); i.src = "/p4-missing.png"; document.body.append(i); }');
    $page->evaluate('() => new Promise((r) => setTimeout(r, 600))');
    $loud = implode(' | ', p4Loud($page));
    expect($loud)->toContain('p4 positive control')->toContain('p4-missing.png');

    // A button pushed under the bar is reported as covered.
    $page->evaluate('() => { const b = document.createElement("button"); b.textContent = "probe-under-bar"; b.style.cssText = "position:fixed;left:200px;bottom:calc(var(--floor-top) + 10px);height:30px;z-index:1"; document.querySelector("main").append(b); }');
    $bar = $page->evaluate(P4_PLAY_BAR);
    expect($bar['covered'] ?? [])->toContain('probe-under-bar');
});
