<?php

use App\Games\Blockfill;
use App\Games\GameRegistry;
use App\Models\Admin;
use App\Models\Clan;
use App\Models\InviteLink;
use App\Models\Lineup;
use App\Models\StackerRun;
use App\Models\User;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\Verifier;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BlockfillOn;
use Tests\Support\BlockliOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\FakeStackerVerifier;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
| The league and account pages of the revamp (plan "Refactor und Design-Revamp", P7; canvas pages "Liga" and
| "Spieler & Konto": Mempool, MempoolPhone, Season, SeasonPhone, Clans, ClanShow, ClanCreate, ClanManage, Profile,
| ProfilePhone, PlayerPublic, Settings*, ActionDock, ActionDockPhone) in a league that looks like the canvas: an admin
| with a clan, daily games on both sides, finished blitz games, a won series, Blockfill runs and Blockli games.
| At 390 and 1440, and squeezed from 390 to 1920: the console and every response stay quiet, nothing runs past the
| page or out of its box. DESIGN_SHOTS=1 also saves full-page screenshots for the side-by-side with the boards.
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
});

/**
 * The canvas league of the P7 pages; returns the admin (Satoshi of meHubPlayer) and his clan.
 *
 * @return array{me: User, clan: Clan}
 */
function leagueRevampWorld(): array
{
    BlockfillOn::play();
    BlockliOn::play();
    app()->forgetInstance(GameRegistry::class);
    leagueWeeksApproved(Blockfill::SLUG);
    app(BlockfillWeeks::class)->open();

    $world = meHubPlayer();
    Admin::query()->create(['pubkey' => $world['me']->pubkey]);

    $players = [];
    foreach (['TickTrickSat', 'Stefan', 'Erdöpfökasbrot', 'floatYaBoat', 'markusturm', 'Nidan21', 'DerCaddy', 'Lotte'] as $name) {
        $players[$name] = User::factory()->create(['name' => $name]);
    }
    foreach ([[11_800, 'TickTrickSat', 70], [11_200, 'Stefan', 100], [12_100, 'TickTrickSat', 40], [10_700, 'Stefan', 130]] as [$ticks, $name, $minutes]) {
        $run = StackerRun::factory()->verified($ticks)->create(['user_id' => $players[$name]->id]);
        $run->forceFill(['created_at' => now()->subMinutes($minutes)])->save();
    }
    foreach ([['markusturm', 'Nidan21', 3], ['DerCaddy', 'Lotte', 19]] as [$white, $black, $ply]) {
        mempoolBoard('blockli', ['mode' => 'correspondence', 'white_id' => $players[$white]->id, 'black_id' => $players[$black]->id, 'ply' => $ply,
            'initial_ms' => 86_400_000, 'increment_ms' => 0, 'white_ms' => 86_400_000, 'black_ms' => 86_400_000]);
    }

    // A second clan with members, so the clan list has a ranking.
    $hamburg = Clan::factory()->create(['owner_id' => $players['markusturm']->id, 'name' => 'Einundzwanzig Hamburg', 'clantag' => 'HH21']);
    Lineup::factory()->mode('3v3')->ready()->create(['clan_id' => $hamburg->id]);

    return ['me' => $world['me']->refresh(), 'clan' => $world['clan']->refresh()];
}

/** Opens `$path` at `$width` (German), logged in or as a guest, with the console collector in place. */
function leagueRevampOpen(?User $user, string $path, int $width, int $height = 900): mixed
{
    $page = visit($user === null ? '/robots.txt' : BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, $height);
    $page->goto(ComputeUrl::from(route('locale.switch', 'de')));
    $page->goto(ComputeUrl::from($path));
    $page->waitForLoadState('networkidle');

    return $page;
}

/** Nothing runs past the viewport, no button or link spills its own box (the squeeze detector of the canvas). */
const LEAGUE_REVAMP_SQUEEZE = <<<'JS'
() => {
    const out = [];
    const width = document.documentElement.clientWidth;
    if (document.documentElement.scrollWidth > width) out.push('page ' + document.documentElement.scrollWidth + ' > ' + width);
    // A box inside a sideways scroller (chain, chip track, tab row) may leave the window: the scroller itself may not.
    const inScroller = (el) => {
        for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
            if (['auto', 'scroll', 'hidden', 'clip'].includes(getComputedStyle(p).overflowX)) return true;
        }
        return false;
    };
    for (const el of document.querySelectorAll('main *, header *, footer *')) {
        if (!el.checkVisibility() || el.closest('[aria-hidden=true], .sr-only, #game-hub, #account-menu, #more-sheet, canvas') || inScroller(el)) continue;
        const r = el.getBoundingClientRect();
        if (r.width === 0) continue;
        if (r.right > width + 1 || r.left < -1) out.push((el.dataset.test || el.tagName) + ' x ' + Math.round(r.left) + '..' + Math.round(r.right));
        if (['A', 'BUTTON'].includes(el.tagName) && el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).overflow === 'visible') out.push('spill ' + (el.dataset.test || el.textContent.trim().slice(0, 30)));
    }
    return [...new Set(out)].slice(0, 20);
}
JS;

function leagueRevampQuiet(mixed $page, string $label): void
{
    if (getenv('LEAGUE_SOFT')) {
        fwrite(STDERR, '['.$label.'] '.json_encode([$page->evaluate('() => window.__errors'), $page->evaluate(BrowserConsole::BAD_RESPONSES), $page->evaluate(LEAGUE_REVAMP_SQUEEZE)]).PHP_EOL);

        return;
    }

    expect($page->evaluate('() => window.__errors'))->toBe([], $label.': console')
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], $label.': responses')
        ->and($page->evaluate(LEAGUE_REVAMP_SQUEEZE))->toBe([], $label.': squeeze');
}

function leagueRevampShot(mixed $page, string $name): void
{
    if (getenv('DESIGN_SHOTS')) {
        $page->screenshot(true, $name);
    }
}

dataset('league pages', [
    'mempool' => ['/matches', 'mempool'],
    'season' => ['/mining', 'season'],
    'clans' => ['/clans', 'clans'],
    'clan' => ['clan', 'clan-show'],
    'clan create' => ['/clans/create', 'clan-create'],
    'clan manage' => ['clan-manage', 'clan-manage'],
    'profile' => ['profile', 'player'],
    'me' => ['/me', 'me'],
    'correspondence' => ['/me/correspondence', 'correspondence'],
    'settings account' => ['/settings/account', 'settings-account'],
    'settings gaming' => ['/settings/gaming', 'settings-gaming'],
    'settings notifications' => ['/settings/notifications', 'settings-notifications'],
    'settings chess' => ['/settings/chess', 'settings-chess'],
    'settings opponents' => ['/settings/opponents', 'settings-opponents'],
    'settings badges' => ['/settings/badges', 'settings-badges'],
    'settings nip05' => ['/settings/nostr-address', 'settings-nip05'],
]);

test('a league or account page stays quiet and inside the page for an admin from 390 to 1920, and after a round trip', function (string $path, string $name) {
    ['me' => $me, 'clan' => $clan] = leagueRevampWorld();
    $path = match ($path) {
        'clan' => route('clans.show', $clan, false),
        'clan-manage' => route('clans.manage', $clan, false),
        'profile' => route('players.show', $me->npub, false),
        default => $path,
    };

    // 1440 and 390 for the side-by-side with the boards; 768, 1024 and 1920 for the squeeze between them.
    foreach ([1440, 390, 768, 1024, 1920] as $width) {
        $page = leagueRevampOpen($me, $path, $width);
        if (in_array($width, [1440, 390], true)) {
            leagueRevampShot($page, "p7-{$name}-{$width}");
        }
        leagueRevampQuiet($page, "{$name} {$width}");

        $page->evaluate('() => window.Livewire?.all().forEach((c) => c.$wire.$refresh())');
        $page->waitForLoadState('networkidle');
        leagueRevampQuiet($page, "{$name} {$width} after refresh");
    }
})->with('league pages');

test('the action dock opens from the header as a panel under it and from the tab bar as a sheet, beside the floating dock', function () {
    ['me' => $me] = leagueRevampWorld();

    foreach ([1440 => 'nav-actions', 390 => 'tab-actions'] as $width => $entry) {
        $page = leagueRevampOpen($me, '/clans', $width);
        $closed = $page->evaluate('() => ({
            floating: [...document.querySelectorAll("[data-test=match-dock-root] *")].filter((el) => el.checkVisibility() && getComputedStyle(el).position === "fixed").map((el) => el.dataset.test || el.tagName),
            open: Number(document.querySelector("[data-test=match-dock-root]").dataset.open),
        })');
        $page->locator('[data-test='.$entry.']')->click();
        $page->evaluate('() => new Promise((r) => setTimeout(r, 350))');
        leagueRevampShot($page, "p7-dock-{$width}");
        $opened = $page->evaluate('() => {
            const sheet = document.querySelector("[data-test=dock-sheet]");
            const box = sheet.getBoundingClientRect();
            const cards = [...sheet.querySelectorAll("[data-test=dock-card]")].filter((el) => el.checkVisibility());
            const cut = cards.flatMap((card) => [...card.querySelectorAll("a, b, span")]).filter((el) => el.checkVisibility() && el.getBoundingClientRect().right > box.right + 1).length;
            return { visible: sheet.checkVisibility(), top: Math.round(box.top), right: Math.round(box.right), width: Math.round(box.width), cards: cards.length, actions: cards.filter((c) => c.querySelector("[data-test=dock-card-action]")?.getBoundingClientRect().height >= 32).length, cut, focus: document.activeElement?.getAttribute("aria-label") };
        }');
        fwrite(STDERR, "[dock {$width}] ".json_encode([$closed, $opened]).PHP_EOL);

        // The floating dock is back on every page but a running game's (user, 2026-10-14): the bar at the bottom right
        // from 1024 px, the bar above the tab bar below; nothing else of the dock floats while it is closed.
        expect($closed['floating'])->toBe([$width >= 1024 ? 'match-dock' : 'dock-mobile-bar'], "{$width}: only the floating dock's bar floats while it is closed")
            ->and($closed['open'])->toBeGreaterThan(0)
            ->and($opened['visible'])->toBeTrue()
            ->and($opened['cards'])->toBe($closed['open'])
            ->and($opened['actions'])->toBe($opened['cards'])
            ->and($opened['cut'])->toBe(0)
            ->and($opened['right'])->toBe($width);
        if ($width === 1440) {
            // ActionDock.dc.html: under the 56 px header on the right, 460 px wide.
            expect($opened['top'])->toBe(56)->and($opened['width'])->toBe(460);
        }

        // Escape closes it and gives the focus back to the entry that opened it.
        $page->locator('[data-test=dock-sheet] .dk-x')->press('Escape');
        $page->evaluate('() => new Promise((r) => setTimeout(r, 100))');
        expect($page->evaluate('() => document.querySelector("[data-test=dock-sheet]").checkVisibility()'))->toBeFalse()
            ->and($page->evaluate('() => document.activeElement?.dataset.test'))->toBe($entry);
        leagueRevampQuiet($page, "dock {$width}");
    }
});

test('T12: on a 375 x 667 phone nothing floats over the ways to play of a game page, the chat bar sits under them', function () {
    // P4 found the floating match dock and the chat handle over the ways to play (rule R3). The dock no longer floats
    // (ActionDock.dc.html): every control of the primary block in the first screen is hit where it is drawn.
    ['me' => $me] = leagueRevampWorld();

    foreach (['/games/rocket-league', '/chess'] as $path) {
        $page = leagueRevampOpen($me, $path, 375, 667);
        // Since plan "Canvas and live alignment", P4 (GamePhone.dc.html) the page's play bar owns the floor above the
        // tab bar, like the tab bar itself: the first screen ends at its top, it is no floating layer, and a way to play
        // under it is one scroll away and takes its tap once scrolled to (the user accepted this for /chess, 2026-11-09).
        $probe = $page->evaluate('async () => {
            const own = "[data-test=tab-bar], [data-test=game-play-bar]";
            const floorOf = () => Math.min(innerHeight, ...[...document.querySelectorAll(own)].filter((el) => el.checkVisibility()).map((el) => el.getBoundingClientRect().top));
            const floor = floorOf();
            const lead = document.querySelector(".gp-lead");
            const label = (el) => el.dataset.test || el.innerText.trim().slice(0, 24);
            const hits = (el) => { const r = el.getBoundingClientRect(); const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return !!hit && (hit === el || el.contains(hit)); };
            const all = [...lead.querySelectorAll("a[href], button")].filter((el) => el.checkVisibility() && el.getBoundingClientRect().height > 0);
            const controls = all.filter((el) => { const r = el.getBoundingClientRect(); return r.top >= 0 && r.bottom <= floor; });
            const covered = controls.filter((el) => !hits(el)).map(label);
            const below = all.filter((el) => !controls.includes(el));
            const unreachable = [];
            for (const el of below) {
                el.scrollIntoView({ block: "center" });
                await new Promise((r) => setTimeout(r, 120));
                const r = el.getBoundingClientRect();
                if (r.bottom > floorOf() || !hits(el)) unreachable.push(label(el));
            }
            scrollTo(0, 0);
            const floating = [...document.querySelectorAll("body *")].filter((el) => el.checkVisibility() && getComputedStyle(el).position === "fixed" && el.getBoundingClientRect().height > 0)
                .filter((el) => !el.closest("header, [data-test=tab-bar], [data-test=game-play-bar], [data-test=toast-stack], #more-sheet, [aria-hidden=true]"))
                .map((el) => el.dataset.test || el.tagName + "." + el.className.toString().split(" ")[0]);
            const bar = document.querySelector("[data-test=game-play-bar]");
            const tab = document.querySelector("[data-test=tab-bar]");
            return { floor: Math.round(floor), controls: controls.length, below: below.map(label), covered, unreachable, floating,
                barOnTabBar: !!bar && Math.abs(bar.getBoundingClientRect().bottom - tab.getBoundingClientRect().top) <= 1 };
        }');
        fwrite(STDERR, "[T12 {$path}] ".json_encode($probe).PHP_EOL);
        leagueRevampShot($page, 'p7-t12-'.trim(str_replace('/', '-', $path), '-'));

        expect($probe['controls'])->toBeGreaterThan(0, $path)
            ->and($probe['covered'])->toBe([], $path)
            ->and($probe['unreachable'])->toBe([], $path)
            ->and($probe['floating'])->toBe([], $path)
            ->and($probe['barOnTabBar'])->toBeTrue($path);
        leagueRevampQuiet($page, "T12 {$path}");
    }
});

test('on a board game page at 1440 x 900 with open matches nothing covers the chat composer and its send button', function () {
    // PR #5 measured the old floating dock bar over the send button; the dock now opens from the header only.
    ['me' => $me] = leagueRevampWorld();
    $game = mempoolBoard('blockli', ['mode' => 'correspondence', 'white_id' => $me->id, 'black_id' => User::factory()->create(['name' => 'Nidan21'])->id, 'ply' => 2,
        'initial_ms' => 86_400_000, 'increment_ms' => 0, 'white_ms' => 86_400_000, 'black_ms' => 86_400_000]);

    $page = leagueRevampOpen($me, route('board.show', $game, false), 1440, 900);
    // The composer shows once the chat is live (it needs a relay): set the chat's own state, then measure the form's place.
    $page->evaluate('() => { const form = [...document.querySelectorAll("form")].find((f) => f.querySelector("[data-test=chat-send]")); window.Alpine.$data(form).status = "live"; return new Promise((r) => requestAnimationFrame(() => r(true))); }');
    $probe = $page->evaluate('() => {
        const open = Number(document.querySelector("[data-test=match-dock-root]")?.dataset.open ?? 0);
        const hits = ["[data-test=chat-send]", "[data-test=chat-input]"].map((sel) => {
            const el = [...document.querySelectorAll(sel)].find((e) => e.checkVisibility());
            if (!el) return sel + " missing";
            el.scrollIntoView({ block: "center" });
            const r = el.getBoundingClientRect();
            const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
            return hit && (hit === el || el.contains(hit)) ? "ok" : sel + " covered by " + (hit?.closest("[data-test]")?.dataset.test ?? hit?.tagName);
        });
        return { open, hits };
    }');
    fwrite(STDERR, '[board chat] '.json_encode($probe).PHP_EOL);

    expect($probe['open'])->toBeGreaterThan(0)->and($probe['hits'])->toBe(['ok', 'ok']);
    leagueRevampQuiet($page, 'board chat 1440');
});

/** The board, the tab bar and the focus state of a phone's game page, after the bar's 200 ms slide and the board's refit. */
const LEAGUE_FOCUS_PROBE = <<<'JS'
() => new Promise((resolve) => setTimeout(() => requestAnimationFrame(() => requestAnimationFrame(() => {
    const board = document.querySelector('[data-focus-board]').getBoundingClientRect();
    const bar = document.querySelector('[data-test=tab-bar]').getBoundingClientRect();
    const edge = document.querySelector('[data-test=focus-edge]');
    // Whatever floats at the bottom (the dock's tab on Blockli's bar, the bar itself) against the board.
    const floats = [...document.querySelectorAll('[data-test=dock-bar-tab], [data-test=block-input], [data-test=dock-row-tab]')].filter((el) => el.checkVisibility()).map((el) => el.getBoundingClientRect());
    resolve({
        covered: floats.filter((r) => r.top < board.bottom - 1 && r.bottom > board.top && r.left < board.right && r.right > board.left).length,
        focus: document.documentElement.dataset.focus ?? null,
        board: [Math.round(board.top), Math.round(board.width), Math.round(board.height)],
        barTop: Math.round(bar.top),
        edge: edge.checkVisibility(),
        scrollY: Math.round(scrollY),
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    });
})), 600))
JS;

test('focus mode: on a phone game page the tab bar slides away on a board tap and comes back on a scroll up or a tap on the edge, nowhere else', function () {
    ['me' => $me] = leagueRevampWorld();
    $game = mempoolBoard('blockli', ['mode' => 'correspondence', 'white_id' => $me->id, 'black_id' => User::factory()->create(['name' => 'Nidan21'])->id, 'ply' => 2,
        'initial_ms' => 86_400_000, 'increment_ms' => 0, 'white_ms' => 86_400_000, 'black_ms' => 86_400_000]);
    $tap = '() => document.querySelector("[data-focus-board]").dispatchEvent(new PointerEvent("pointerdown", { bubbles: true }))';
    $rows = [];

    foreach ([[360, 740], [390, 664]] as [$width, $height]) {
        $label = "{$width}x{$height}";
        $page = leagueRevampOpen($me, route('board.show', $game, false), $width, $height);
        $shown = $page->evaluate(LEAGUE_FOCUS_PROBE);
        $page->evaluate($tap);
        $hidden = $page->evaluate(LEAGUE_FOCUS_PROBE);
        leagueRevampShot($page, "p7-focus-hidden-{$width}");
        // Back on a tap on the bottom edge.
        $page->locator('[data-test=focus-edge]')->click();
        $back = $page->evaluate(LEAGUE_FOCUS_PROBE);
        // Away on a scroll down, back on a scroll up.
        $page->evaluate('() => { document.body.style.minHeight = (innerHeight * 2) + "px"; window.scrollTo(0, 120); }');
        $down = $page->evaluate(LEAGUE_FOCUS_PROBE);
        $page->evaluate('() => window.scrollTo(0, 40)');
        $up = $page->evaluate(LEAGUE_FOCUS_PROBE);
        $rows[$label] = compact('shown', 'hidden', 'back', 'down', 'up');
        fwrite(STDERR, '[focus '.$label.'] '.json_encode($rows[$label]).PHP_EOL);

        // Nothing that floats at the bottom lies over the board (PR #5's guarantee). One exception, measured: at 390 x 664
        // with the dock's tab on Blockli's bar the board stands at its 200 px floor (boardGame.js fitBoard) and the tab
        // still reaches its lowest row; PR #5 moves that tab into the chat bar's row, under the bar. Focus mode clears it.
        $floor = fn (array $state): bool => $state['board'][1] <= 200;
        expect($hidden['covered'])->toBe(0, $label.': nothing over the board in focus mode')
            ->and($shown['covered'] === 0 || $floor($shown))->toBeTrue($label.': nothing over the board')
            ->and($back['covered'] === 0 || $floor($back))->toBeTrue($label.': nothing over the board, back')
            ->and($shown['focus'])->toBe('shown', $label)
            ->and($shown['edge'])->toBeFalse($label)
            ->and($hidden['focus'])->toBe('hidden', $label)
            // The bar is off the screen, the edge strip is there to bring it back.
            ->and($hidden['barTop'])->toBeGreaterThanOrEqual($height, $label)
            ->and($hidden['edge'])->toBeTrue($label)
            // The board takes the height the bar gave up and stays where it was: no shift of its top edge.
            // Taller by about the bar's 64 px, unless the board meets a limit first: its 200 px floor with the dock's tab on
            // the bar, or the column's width (PR #5 put Blockli's controls over the board: at 360 px it fills the width).
            ->and($hidden['board'][2] - $shown['board'][2])->toBeGreaterThanOrEqual($floor($shown) || $hidden['board'][1] >= $width - 33 ? 1 : 48, $label.': board taller')
            ->and($hidden['board'][0])->toBe($shown['board'][0], $label.': board top unchanged')
            ->and($hidden['overflow'])->toBeLessThanOrEqual(0, $label)
            ->and($back['focus'])->toBe('shown', $label)
            ->and($back['barTop'])->toBe($height - 64, $label)
            ->and($back['board'])->toBe($shown['board'], $label.': board back to its size')
            ->and($down['focus'])->toBe('hidden', $label)
            ->and($up['focus'])->toBe('shown', $label);
        leagueRevampQuiet($page, "focus {$label}");
    }

    // A page that is no game page never hides the bar: a tap and a scroll down leave it.
    $page = leagueRevampOpen($me, '/clans', 390, 664);
    $page->evaluate('() => { document.querySelector("main").dispatchEvent(new PointerEvent("pointerdown", { bubbles: true })); window.scrollTo(0, 300); }');
    $other = $page->evaluate('() => new Promise((r) => setTimeout(() => r([document.documentElement.classList.contains("is-focus"), Math.round(document.querySelector("[data-test=tab-bar]").getBoundingClientRect().top)]), 350))');
    $rows['clans'] = $other;
    fwrite(STDERR, '[focus mode] '.json_encode($rows).PHP_EOL);

    expect($other)->toBe([false, 664 - 64]);
});

test('the Mempool chain loads older blocks at its left end and keeps the cube it showed in place', function () {
    ['me' => $me] = leagueRevampWorld();

    // 1024 px: the chain of this league is wider than its window, so there is room to keep the cube in place.
    $page = leagueRevampOpen($me, '/matches', 1024);
    $before = $page->evaluate('() => {
        const chain = document.querySelector("[data-test=mempool-chain]");
        const vp = chain.querySelector(".rv-chain-vp");
        vp.scrollLeft = 0;
        const first = vp.querySelector("[data-test=chain-cube]");
        return { more: chain.dataset.more, cubes: vp.querySelectorAll("[data-test=chain-cube]").length, href: first.getAttribute("href"), x: Math.round(first.getBoundingClientRect().left) };
    }');
    // A wheel up at the left end (the chain moves sideways on a vertical wheel) asks for older blocks.
    $page->evaluate('() => document.querySelector("[data-test=mempool-chain] .rv-chain-vp").dispatchEvent(new WheelEvent("wheel", { deltaY: -120, bubbles: true, cancelable: true }))');
    $page->waitForLoadState('networkidle');
    $page->evaluate('() => new Promise((r) => setTimeout(() => requestAnimationFrame(() => requestAnimationFrame(r)), 300))');
    $after = $page->evaluate('(href) => {
        const vp = document.querySelector("[data-test=mempool-chain] .rv-chain-vp");
        const same = [...vp.querySelectorAll("[data-test=chain-cube]")].find((c) => c.getAttribute("href") === href);
        return { cubes: vp.querySelectorAll("[data-test=chain-cube]").length, x: same ? Math.round(same.getBoundingClientRect().left) : null, scroll: Math.round(vp.scrollLeft) };
    }', $before['href']);
    fwrite(STDERR, '[chain history] '.json_encode([$before, $after]).PHP_EOL);

    expect($before['more'])->toBe('1')
        ->and($after['cubes'])->toBeGreaterThan($before['cubes'])
        ->and($after['scroll'])->toBeGreaterThan(0)
        ->and(abs($after['x'] - $before['x']))->toBeLessThanOrEqual(2);
    leagueRevampQuiet($page, 'chain history');
});

test('positive control: the collector of this file sees a thrown error and a 500 answer, and the squeeze detector a spill', function () {
    ['me' => $me] = leagueRevampWorld();
    $page = leagueRevampOpen($me, '/clans', 390);

    $page->evaluate('() => { setTimeout(() => { throw new Error("league-probe"); }); return fetch("/__test/server-error").then((r) => r.text()).then(() => true); }');
    BrowserWait::until($page, '() => (window.__errors ?? []).some((e) => e.includes("league-probe"))', 5_000);
    // A button wider than its box and a box past the window: both are reported.
    $page->evaluate('() => { const main = document.querySelector("main"); const b = document.createElement("button"); b.style.cssText = "width:40px;overflow:visible;white-space:nowrap"; b.textContent = "a label far wider than forty px"; b.dataset.test = "probe-spill"; main.append(b); const d = document.createElement("div"); d.style.cssText = "width:900px;height:4px"; d.dataset.test = "probe-wide"; main.append(d); return true; }');

    expect($page->evaluate('() => window.__errors'))->not->toBe([])
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->not->toBe([])
        ->and(implode(' | ', $page->evaluate(LEAGUE_REVAMP_SQUEEZE)))->toContain('spill probe-spill')->toContain('probe-wide');
});

test('the join link on clan management picks with the board dropdowns, whole labels at 390 and 1440, and a link is made with the pick', function () {
    ['me' => $me, 'clan' => $clan] = leagueRevampWorld();
    $rows = [];

    foreach ([390, 1440] as $width) {
        $page = leagueRevampOpen($me, route('clans.manage', $clan, false), $width);
        $measure = '() => ["join-link-uses", "join-link-hours", "create-join-link"].map((t) => { const el = document.querySelector("[data-test=" + t + "]"); el.scrollIntoView({ block: "center" }); const r = el.getBoundingClientRect(); const label = el.querySelector("span") ?? el; return { t, h: Math.round(r.height), left: Math.round(r.left), right: Math.round(r.right), cut: label.scrollWidth > label.clientWidth + 1, text: el.innerText.trim() }; })';
        $before = $page->evaluate($measure);
        // Open the first dropdown, pick "One player", and make the link with it.
        $page->locator('[data-test=join-link-uses]')->click();
        $page->locator('[role=listbox] [role=option]:has-text("'.__('One player').'")')->click();
        $after = $page->evaluate($measure);
        leagueRevampQuiet($page, "join link {$width}");
        $page->locator('[data-test=create-join-link]')->click();
        // The new link opens on its own page (/i/<code>).
        BrowserWait::until($page, '() => location.pathname.startsWith("/i/")', 10_000);
        $rows[$width] = compact('before', 'after');

        foreach ($before as $control) {
            expect($control['h'])->toBeGreaterThanOrEqual(44, "{$width}: {$control['t']}")
                ->and($control['cut'])->toBeFalse("{$width}: {$control['t']} label whole")
                ->and($control['left'])->toBeGreaterThanOrEqual(0)
                ->and($control['right'])->toBeLessThanOrEqual($width);
        }
        expect($after[0]['text'])->toBe(__('One player'));
    }
    fwrite(STDERR, '[join link] '.json_encode($rows, JSON_UNESCAPED_UNICODE).PHP_EOL);

    $link = InviteLink::query()->latest('id')->first();
    expect($link)->not->toBeNull()->and($link->max_uses)->toBe(1);
});

test('the lineup seats on clan management are the board dropdowns, whole and inside the window at 390 and 1440', function () {
    ['me' => $me, 'clan' => $clan] = leagueRevampWorld();
    $rows = [];

    foreach ([390, 1440] as $width) {
        $page = leagueRevampOpen($me, route('clans.manage', $clan, false), $width);
        $page->locator('[data-test=edit-lineup-2v2]')->first()->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test^=pick-] .v") !== null', 10_000);
        $first = $page->evaluate('() => document.querySelector("[data-test^=pick-]").dataset.test');
        $page->locator('[data-test='.$first.']')->click();
        BrowserWait::until($page, '() => [...document.querySelectorAll("[data-test='.$first.']")].some((b) => b.checkVisibility() && b.parentElement.querySelector("[role=listbox]")?.checkVisibility())', 5_000);
        $m = $page->evaluate('() => {
            const seats = [...document.querySelectorAll("[data-test^=pick-]")].filter((el) => el.tagName === "BUTTON" && el.checkVisibility());
            const list = seats[0]?.parentElement.querySelector("[role=listbox]");
            const r = (el) => { const b = el.getBoundingClientRect(); return [Math.round(b.left), Math.round(b.top), Math.round(b.right), Math.round(b.bottom)]; };
            return {
                native: document.querySelectorAll("main select").length,
                seats: seats.map((el) => ({ box: r(el), cut: el.querySelector(".v").scrollWidth > el.querySelector(".v").clientWidth + 1 })),
                list: list && list.checkVisibility() ? r(list) : null,
                options: list ? [...list.querySelectorAll("[role=option]")].map((o) => o.innerText.trim()) : [],
            };
        }');
        $rows[$width] = $m;
        fwrite(STDERR, '[lineup seats '.$width.'] '.$first.' '.json_encode($m).PHP_EOL);

        expect($m['native'])->toBe(0, "{$width}: no native select left on the page")
            ->and($m['seats'])->not->toBe([])
            ->and(collect($m['seats'])->every(fn (array $seat): bool => ! $seat['cut'] && $seat['box'][3] - $seat['box'][1] >= 44 && $seat['box'][0] >= 0 && $seat['box'][2] <= $width))->toBeTrue("{$width}: seats")
            ->and($m['list'])->not->toBeNull()
            ->and($m['list'][0])->toBeGreaterThanOrEqual(0)
            ->and($m['list'][2])->toBeLessThanOrEqual($width)
            ->and(count($m['options']))->toBe(4);
        leagueRevampQuiet($page, "lineup seats {$width}");
    }
    fwrite(STDERR, '[lineup seats] '.json_encode($rows, JSON_UNESCAPED_UNICODE).PHP_EOL);
});
