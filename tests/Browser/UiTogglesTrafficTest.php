<?php

use App\Enums\InviteStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Games\NineMensMorris;
use App\Models\Admin;
use App\Models\Clan;
use App\Models\ClanInvite;
use App\Models\Lineup;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Board\BoardQueue;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Playwright\Playwright;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\LivewireTraffic;
use Tests\Support\NineMensMorrisOn;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| UI toggles and searches without a page render (performance plan P7, F9 and F7)
|--------------------------------------------------------------------------
|
| What one interaction costs in Livewire roundtrips and bytes, counted by the
| page itself (Tests\Support\LivewireTraffic: Livewire.interceptRequest on a
| virtual clock, so a 300 ms debounce fires on `advance`, not on the wall):
|
| - clans/manage (owner): open "Edit clan", type a tag (the tag check renders
|   the island `edit-clan` only), Cancel, switch the roster tab and back;
| - the tournament control (admin edit page): open and cancel "Call off",
|   open "Restart round";
| - chess/challenge: type a search (renders the island `players` only), pick
|   a player (renders the page, the summary names the opponent);
| - /clans: type a search (filters in the browser).
|
| A toggle that must outlive a render is checked after one: the edit card
| stays open after an island roundtrip and after a whole render, the former
| tab stays the one shown, the call-off panel stays open.
|
| Every page: console empty (collector and the plugin's list), every
| Livewire answer 2xx, the measured boxes at 1440 and 390 px; a thrown
| error and a failing Livewire call are the positive controls.
|
| The board lobby (F7) runs on the real clock, its own counter: while
| searching, with the socket up it asks when the range widens, without one
| every 4 s. The quick run widens every 6 s and watches 12 s; with
| UI_TOGGLES_LOBBY_MINUTE=1 it keeps the real config (every 30 s) and
| watches 60 s, the number per minute for the report.
|
| UI_TOGGLES_REPORT=<file> writes every number there (JSON).
|
*/

/** Real-clock Livewire request counter for the board lobby (its 1 s ticker must run on the wall clock). */
const TOGGLES_REAL_COUNTER = <<<'JS'
    (() => {
        window.__lw = [];
        document.addEventListener('livewire:init', () => {
            Livewire.interceptRequest(({ request, onSend, onResponse }) => {
                const record = { what: '', status: null, at: Math.round(performance.now()) };
                onSend(() => { record.what = [...request.messages].map((m) => m.component.name + ':' + ([...m.actions].map((a) => a.name).join('+') || 'render')).join(','); });
                onResponse(({ response }) => { record.status = response.status; });
                window.__lw.push(record);
            });
        });
    })();
    JS;

/** Boxes of the given selectors: [x, y, width, height] rounded, null when absent or hidden, plus the overflow. */
const TOGGLES_BOXES = <<<'JS'
    (selectors) => ({
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        height: document.documentElement.scrollHeight,
        boxes: Object.fromEntries(selectors.map((s) => {
            const el = document.querySelector(s);
            const r = el && el.offsetParent !== null ? el.getBoundingClientRect() : null;
            return [s, r ? [Math.round(r.x), Math.round(r.y + scrollY), Math.round(r.width), Math.round(r.height)] : null];
        })),
    })
    JS;

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    Queue::fake();

    config(['session.driver' => 'database']);

    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
});

/** Opens a page logged in as `$viewer` with the console collector and the virtual-clock probe. */
function togglesOpen(User $viewer, string $url, int $width = 1440): array
{
    $webpage = visit(BrowserLogin::url($viewer));
    $page = $webpage->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript(LivewireTraffic::PROBE);
    $page->setViewportSize($width, $width < 800 ? 844 : 900);
    $page->goto(ComputeUrl::from($url));
    BrowserWait::until($page, '() => document.readyState === "complete" && window.__traffic?.hasLivewire() === true', 15_000);
    togglesAdvance($page, 1_000);

    return [$webpage, $page];
}

function togglesAdvance(Page $page, int $ms): void
{
    Playwright::usingTimeout(60_000, fn () => $page->evaluate('async (ms) => { await window.__traffic.advance(ms); }', $ms));
}

/**
 * Runs one interaction and returns what it cost: requests, bytes sent + received, labels, statuses.
 *
 * @return array{requests: int, bytes: int, what: array<string, int>, statuses: list<mixed>}
 */
function togglesCost(Page $page, Closure $interaction, int $settleMs = 1_000): array
{
    $mark = $page->evaluate('() => window.__traffic.mark()');
    $interaction();
    togglesAdvance($page, $settleMs);
    $seen = $page->evaluate('(mark) => window.__traffic.since(mark)', $mark);

    return ['requests' => $seen['requests'], 'bytes' => $seen['sent'] + $seen['received'], 'what' => $seen['labels'], 'statuses' => $seen['statuses']];
}

function togglesVisible(Page $page, string $selector): bool
{
    return (bool) $page->evaluate('(s) => { const el = document.querySelector(s); return !! el && el.offsetParent !== null && getComputedStyle(el).visibility !== "hidden"; }', $selector);
}

/** Every problem the collectors saw on this page: console, failed resources, a Livewire answer that was not 2xx. */
function togglesProblems(string $name, Page $page, array $costs): array
{
    $problems = array_map(fn (string $error): string => "{$name}: {$error}", [...$page->evaluate('() => window.__errors'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)]);

    foreach ($costs as $step => $cost) {
        foreach ($cost['statuses'] as $status) {
            if (! is_int($status) || $status < 200 || $status > 299) {
                $problems[] = "{$name} / {$step}: a Livewire answer with status ".json_encode($status);
            }
        }
    }

    return $problems;
}

/** A running 1v1 knockout of four, its first round undecided: the control section offers "Restart round". */
function togglesRunningKnockout(): Tournament
{
    $tournament = Tournament::factory()->create([
        'name' => 'Toggle Cup', 'game' => 'rocket-league', 'mode' => '1v1', 'format' => TournamentFormat::SingleElimination, 'capacity' => 4,
        'options' => FormatOptions::fromArray(['thirdPlace' => false], GameProfile::for('rocket-league', '1v1'))->toArray(),
        'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running, 'slug' => 'toggle-cup',
        'on_site' => true, 'stations' => 4, 'created_by_id' => organizer()->id,
    ]);

    foreach (['Alice Sats', 'Bob Blocks', 'Carol Coins', 'Dave Hodl'] as $index => $name) {
        $player = User::factory()->create(['name' => $name]);
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'name' => $name, 'rating' => 1200 - $index, 'members' => [$player->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($tournament);

    return $tournament->refresh();
}

test('toggles and searches cost no page render, keep their state through a render, with a clean console and 2xx answers', function () {
    $report = [];
    $problems = [];

    /* ---------- clans/manage ---------- */
    $lineup = Lineup::factory()->mode('3v3')->ready()->create();
    $clan = $lineup->clan;
    $owner = $clan->owner;
    $rival = Lineup::factory()->mode('3v3')->ready()->create();
    ClanInvite::query()->create(['clan_id' => $clan->id, 'inviter_id' => $owner->id, 'invitee_id' => $rival->clan->owner_id, 'status' => InviteStatus::Pending]);
    $manage = route('clans.manage', $clan);
    $manageBoxes = ['#edit-clan', '#invite', '#join-requests', '[role=tablist]'];

    foreach ([1440, 390] as $width) {
        [, $page] = togglesOpen($owner, $manage, $width);
        $report['layout']["clans.manage {$width}"] = $page->evaluate(TOGGLES_BOXES, $manageBoxes);
    }

    [$webpage, $page] = togglesOpen($owner, $manage);
    $costs = [];
    $costs['open edit'] = togglesCost($page, fn () => $page->locator('[data-test=open-edit]')->click());
    $cardAfterOpen = togglesVisible($page, '[data-test=edit-name]');
    $costs['type tag'] = togglesCost($page, fn () => $page->locator('[data-test=edit-clantag]')->fill('ZZ9'));
    $cardAfterIsland = togglesVisible($page, '[data-test=edit-name]');
    $tagHint = (string) $page->evaluate('() => document.getElementById("edit-tag-hint")?.textContent.replace(/\s+/g, " ").trim() ?? ""');
    $costs['whole render'] = togglesCost($page, fn () => $page->evaluate('() => Livewire.all().find((c) => c.name === "pages::clans.manage").$wire.$refresh()'));
    $cardAfterRender = togglesVisible($page, '[data-test=edit-name]');
    $costs['cancel'] = togglesCost($page, fn () => $page->locator('#edit-clan button:has-text("Cancel")')->click());
    $cardAfterCancel = togglesVisible($page, '[data-test=edit-name]');
    $costs['tab former'] = togglesCost($page, fn () => $page->locator('[role=tab]')->nth(2)->click());
    $formerShown = $page->evaluate('() => document.querySelector("[role=tab]:nth-child(3)").getAttribute("aria-selected")') === 'true'
        && ! togglesVisible($page, '[data-test=lineups-rocket-league]');
    $costs['render after tab'] = togglesCost($page, fn () => $page->evaluate('() => Livewire.all().find((c) => c.name === "pages::clans.manage").$wire.$refresh()'));
    $formerAfterRender = $page->evaluate('() => document.querySelector("[role=tab]:nth-child(3)").getAttribute("aria-selected")') === 'true'
        && ! togglesVisible($page, '[data-test=lineups-rocket-league]');
    $costs['tab active'] = togglesCost($page, fn () => $page->locator('[role=tab]')->nth(0)->click());
    $report['clans.manage'] = ['costs' => array_map(fn ($c) => array_diff_key($c, ['statuses' => 1]), $costs), 'state' => [
        'card visible after open' => $cardAfterOpen, 'card visible after island roundtrip' => $cardAfterIsland, 'card visible after whole render' => $cardAfterRender,
        'card hidden after cancel' => ! $cardAfterCancel, 'tag hint' => $tagHint, 'former tab shown' => $formerShown, 'former tab kept through a render' => $formerAfterRender,
    ]];
    $problems = [...$problems, ...togglesProblems('clans.manage', $page, $costs)];
    $webpage->assertNoJavaScriptErrors();

    /* ---------- tournament control ---------- */
    $admin = User::factory()->create(['name' => 'satsjaeger']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $tournament = togglesRunningKnockout();
    $edit = route('admin.tournaments.edit', $tournament);

    foreach ([1440, 390] as $width) {
        [, $page] = togglesOpen($admin, $edit, $width);
        $report['layout']["control {$width}"] = $page->evaluate(TOGGLES_BOXES, ['[data-test=control]', '[data-test=control-abort]', '[data-test=control-rounds]']);
    }

    [$webpage, $page] = togglesOpen($admin, $edit);
    $costs = [];
    $costs['open call-off'] = togglesCost($page, fn () => $page->locator('[data-test=control-abort-start]')->click());
    $abortOpen = togglesVisible($page, '[data-test=control-abort-reason]');
    $costs['render'] = togglesCost($page, fn () => $page->evaluate('() => Livewire.all().find((c) => c.name === "tournament-control").$wire.$refresh()'));
    $abortAfterRender = togglesVisible($page, '[data-test=control-abort-reason]') && ! togglesVisible($page, '[data-test=control-abort-start]');
    $costs['cancel call-off'] = togglesCost($page, fn () => $page->locator('[data-test=control-abort-form] button:has-text("Cancel")')->click());
    $abortClosed = ! togglesVisible($page, '[data-test=control-abort-reason]') && togglesVisible($page, '[data-test=control-abort-start]');
    $costs['open restart'] = togglesCost($page, fn () => $page->locator('[data-test=control-restart-1]')->click());
    $restartOpen = togglesVisible($page, '[data-test=control-restart-reason]');
    $report['control'] = ['costs' => array_map(fn ($c) => array_diff_key($c, ['statuses' => 1]), $costs), 'state' => [
        'call-off open' => $abortOpen, 'call-off kept through a render' => $abortAfterRender, 'call-off closed by cancel' => $abortClosed, 'restart open' => $restartOpen,
    ]];
    $problems = [...$problems, ...togglesProblems('control', $page, $costs)];
    $webpage->assertNoJavaScriptErrors();

    /* ---------- chess/challenge ---------- */
    $challenger = User::factory()->create(['name' => 'Hal Finney']);
    foreach (['Satoshi One', 'Satoshi Two', 'Adam Back', 'Nick Szabo'] as $name) {
        User::factory()->create(['name' => $name]);
    }
    $challenge = route('chess.challenge');

    foreach ([1440, 390] as $width) {
        [, $page] = togglesOpen($challenger, $challenge, $width);
        $report['layout']["chess.challenge {$width}"] = $page->evaluate(TOGGLES_BOXES, ['[data-test=player-search]', '[data-test=pick-player]', '[data-test=send-challenge]']);
    }

    [$webpage, $page] = togglesOpen($challenger, $challenge);
    $costs = [];
    $costs['type search'] = togglesCost($page, fn () => $page->locator('[data-test=player-search]')->fill('Satoshi'));
    $found = (int) $page->evaluate('() => document.querySelectorAll("[data-test=pick-player]").length');
    $costs['pick'] = togglesCost($page, fn () => $page->locator('[data-test=pick-player]')->first()->click());
    $picked = (bool) $page->evaluate('() => ! document.querySelector("[data-test=send-challenge]").disabled && document.querySelector("[data-test=pick-player][aria-checked=true]") !== null');
    $report['chess.challenge'] = ['costs' => array_map(fn ($c) => array_diff_key($c, ['statuses' => 1]), $costs), 'state' => ['players found' => $found, 'summary names the pick' => $picked]];
    $problems = [...$problems, ...togglesProblems('chess.challenge', $page, $costs)];
    $webpage->assertNoJavaScriptErrors();

    /* ---------- /clans ---------- */
    $directory = Clan::query()->orderBy('name')->get();
    $target = $directory->first();
    $clansIndex = route('clans.index');

    foreach ([1440, 390] as $width) {
        [, $page] = togglesOpen($challenger, $clansIndex, $width);
        $report['layout']["clans.index {$width}"] = $page->evaluate(TOGGLES_BOXES, ['#clan-q', '[data-test=clan-grid]']);
    }

    [$webpage, $page] = togglesOpen($challenger, $clansIndex);
    $costs = [];
    $costs['type search'] = togglesCost($page, fn () => $page->locator('#clan-q')->fill($target->clantag));
    $shownCards = (int) $page->evaluate('() => [...document.querySelectorAll("[data-test=clan-card]")].filter((el) => el.offsetParent !== null).length');
    $costs['clear search'] = togglesCost($page, fn () => $page->locator('#clan-q')->fill(''));
    $allCards = (int) $page->evaluate('() => [...document.querySelectorAll("[data-test=clan-card]")].filter((el) => el.offsetParent !== null).length');
    $costs['no match'] = togglesCost($page, fn () => $page->locator('#clan-q')->fill('qqqqqq'));
    $noMatch = togglesVisible($page, '[data-test=clans-no-match]');
    $report['clans.index'] = ['costs' => array_map(fn ($c) => array_diff_key($c, ['statuses' => 1]), $costs), 'state' => [
        'clans' => $directory->count(), 'cards shown for one tag' => $shownCards, 'cards shown with no search' => $allCards, 'no-match line' => $noMatch,
    ]];
    $problems = [...$problems, ...togglesProblems('clans.index', $page, $costs)];
    $webpage->assertNoJavaScriptErrors();

    // Positive controls on the last page: a thrown error, a 404 fetch and a failing Livewire call reach the collectors.
    $page->evaluate('() => { setTimeout(() => { throw new Error("positive control"); }); fetch("/__toggles-missing").catch(() => null); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("positive control")) && window.__errors.some((e) => e.includes("404"))', 10_000);
    $failing = togglesCost($page, fn () => $page->evaluate('() => { Livewire.first().togglesMissingMethod().catch(() => null); }'));

    fwrite(STDERR, "\n[ui-toggles] ".json_encode($report)."\n");

    if (is_string($file = getenv('UI_TOGGLES_REPORT')) && $file !== '') {
        file_put_contents($file, json_encode($report, JSON_PRETTY_PRINT)."\n");
    }

    expect($problems)->toBe([])
        ->and(implode("\n", array_column($page->javaScriptErrors(), 'message')))->toContain('positive control')
        ->and($failing['statuses'])->not->toBe([])
        ->and(array_filter($failing['statuses'], fn ($status): bool => $status >= 200 && $status <= 299))->toBe([]);

    // F9: opening, closing and switching ask nothing; the searches render their island or nothing.
    $manageCosts = $report['clans.manage']['costs'];
    expect($manageCosts['open edit']['requests'])->toBe(0)
        ->and($manageCosts['cancel']['requests'])->toBe(1)
        ->and($manageCosts['tab former']['requests'])->toBe(0)
        ->and($manageCosts['tab active']['requests'])->toBe(0)
        ->and($manageCosts['type tag']['requests'])->toBe(1)
        ->and($manageCosts['type tag']['bytes'])->toBeLessThan(intdiv($manageCosts['whole render']['bytes'], 2))
        ->and($report['clans.manage']['state'])->toMatchArray([
            'card visible after open' => true, 'card visible after island roundtrip' => true, 'card visible after whole render' => true,
            'card hidden after cancel' => true, 'former tab shown' => true, 'former tab kept through a render' => true,
        ])
        ->and($report['clans.manage']['state']['tag hint'])->toContain('ZZ9 is free');

    expect($report['control']['costs']['open call-off']['requests'])->toBe(0)
        ->and($report['control']['costs']['cancel call-off']['requests'])->toBe(0)
        ->and($report['control']['costs']['open restart']['requests'])->toBe(0)
        ->and($report['control']['state'])->toBe(['call-off open' => true, 'call-off kept through a render' => true, 'call-off closed by cancel' => true, 'restart open' => true]);

    expect($report['chess.challenge']['costs']['type search']['requests'])->toBe(1)
        ->and($report['chess.challenge']['costs']['type search']['bytes'])->toBeLessThan(intdiv($report['chess.challenge']['costs']['pick']['bytes'], 2))
        ->and($report['chess.challenge']['state'])->toBe(['players found' => 2, 'summary names the pick' => true]);

    expect($report['clans.index']['costs']['type search']['requests'])->toBe(0)
        ->and($report['clans.index']['costs']['clear search']['requests'])->toBe(0)
        ->and($report['clans.index']['state'])->toMatchArray(['cards shown for one tag' => 1, 'cards shown with no search' => $directory->count(), 'no-match line' => true]);
});

test('a searching board lobby asks when its range widens with the socket up, and every 4 s without one (performance plan P7, F7)', function () {
    NineMensMorrisOn::play();
    $minute = getenv('UI_TOGGLES_LOBBY_MINUTE') === '1';
    $windowMs = $minute ? 60_000 : 12_000;

    if (! $minute) {
        config(['esports.board_games.queue.range.every_seconds' => 6]);
    }

    $player = User::factory()->create(['name' => 'Morris Waiting']);
    app(BoardQueue::class)->join($player, NineMensMorris::SLUG);

    $webpage = visit(BrowserLogin::url($player));
    $page = $webpage->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript(TOGGLES_REAL_COUNTER);
    $page->goto(ComputeUrl::from(route('board.lobby', ['board' => NineMensMorris::SLUG])));
    BrowserWait::until($page, '() => document.readyState === "complete" && typeof window.Livewire !== "undefined"', 15_000);
    BrowserWait::until($page, '() => window.Echo?.connector?.pusher?.connection?.state === "connected"', 15_000);
    BrowserWait::until($page, '() => document.querySelector("[data-test=lobby-searching]") !== null', 10_000);
    // The channel's first subscription asks once (a push sent before it would be lost): part of the load, not the window.
    BrowserWait::until($page, '() => window.__lw.some((r) => r.what.includes("board.lobby:poll") && r.status !== null)', 10_000);

    $count = function (int $ms) use ($page): array {
        $mark = $page->evaluate('() => window.__lw.length');
        // Waited in the page: the in-process server answers the lobby's requests meanwhile.
        Playwright::usingTimeout($ms + 30_000, fn () => $page->evaluate('(ms) => new Promise((resolve) => setTimeout(resolve, ms))', $ms));

        return $page->evaluate('(mark) => window.__lw.slice(mark)', $mark);
    };

    $withSocket = $count($windowMs);
    $page->evaluate('() => window.Echo.connector.pusher.disconnect()');
    BrowserWait::until($page, '() => window.Echo.connector.pusher.connection.state !== "connected"', 5_000);
    $withoutSocket = $count($windowMs);

    $result = ['window ms' => $windowMs, 'with socket' => count($withSocket), 'without socket' => count($withoutSocket),
        'at' => [array_column($withSocket, 'at'), array_column($withoutSocket, 'at')],
        'what' => array_count_values(array_column([...$withSocket, ...$withoutSocket], 'what'))];
    fwrite(STDERR, "\n[ui-toggles board lobby] ".json_encode($result)."\n");

    if (is_string($file = getenv('UI_TOGGLES_REPORT')) && $file !== '') {
        file_put_contents($file.'.lobby.json', json_encode($result, JSON_PRETTY_PRINT)."\n");
    }

    $statuses = array_column([...$withSocket, ...$withoutSocket], 'status');
    expect(array_filter($statuses, fn ($status): bool => ! is_int($status) || $status < 200 || $status > 299))->toBe([])
        ->and($page->evaluate('() => window.__errors'))->toBe([]);

    if ($minute) {
        // Joined just before the page: the widenings at 30 and 60 s.
        expect(count($withSocket))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(2)->and(count($withoutSocket))->toBeGreaterThanOrEqual(14);
    } else {
        // Widening every 6 s up to the widest range at 18 s: one or two widening asks in the 12 s window (page start shifts
        // it by about a second), never none: a lobby that never asks leaves a waiting player unpaired (reviewer 2026-10-05,
        // D1). Without the socket one every 4 s, so at least two in the window.
        expect(count($withSocket))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(2)->and(count($withoutSocket))->toBeGreaterThanOrEqual(2);
    }
});
