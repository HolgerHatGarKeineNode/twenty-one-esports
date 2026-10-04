<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Series\SeriesService;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The match room reads top to bottom in the player's order (2026-10-04)
|--------------------------------------------------------------------------
|
| User, after a live tournament: "Ergebnis melden ist auf einmal ganz wo
| anders zu finden, als die Ergebnisse, die man einträgt." The room is one
| thread: who vs who and the next deadline, get into the game (lobby,
| check-in), play and enter results with the report action right under the
| sheet, problems, then details. Each step carries `data-flow="<n>"`.
|
| Every name is 40 characters without a space. Measured in German (the
| longer copy) at 390 and 1440 px: the steps sit in their order, the report
| action sits right under the sheet, no horizontal page scroll, no text box
| wider than its own box, no name squeezed to zero width. The console stays
| empty on load and after a roundtrip (a goal entered), every roundtrip
| answers 2xx, and a thrown error proves the collector listens.
|
| ROOM_FLOW_ALL=1 adds the remaining states (before the start, a disputed
| result, a reported no-show, a spectator); ROOM_SHOTS=<dir> writes English
| full-page screenshots there.
|
*/

const ROOM_FLOW_LONG = 'Bitcoinmaximalistausdemerzgebirge2140xyz';

const ROOM_FLOW_PROBE = <<<'JS'
    () => {
        const root = document.querySelector('[data-test=match-room]');
        // Text that runs out of its box: every text node's line boxes against the element's own box (the nearest
        // block for inline text). Measured on the text, not scrollWidth: a 44 px hit area (::after) is no overflow.
        const overflowing = [];
        const range = document.createRange();
        for (const el of root.querySelectorAll('*')) {
            if (! el.checkVisibility()) continue;
            const nodes = [...el.childNodes].filter((n) => n.nodeType === 3 && n.textContent.trim() !== '');
            if (nodes.length === 0) continue;
            let box = el;
            while (box.parentElement && getComputedStyle(box).display === 'inline') box = box.parentElement;
            if (['hidden', 'clip', 'auto', 'scroll'].includes(getComputedStyle(box).overflowX)) continue;
            const edge = box.getBoundingClientRect();
            for (const node of nodes) {
                range.selectNodeContents(node);
                if ([...range.getClientRects()].some((r) => r.width > 0 && (r.right > edge.right + 0.5 || r.left < edge.left - 0.5))) {
                    overflowing.push(el.tagName + ' ' + node.textContent.trim().slice(0, 50));
                    break;
                }
            }
        }
        // Every element that shows a long name itself (a text node of its own), visible or squeezed to nothing.
        const names = [...root.querySelectorAll('*')].filter((el) => [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.includes('Bitcoinmaximalist')))
            .filter((el) => el.checkVisibility() && el.closest('.sr-only') === null)
            .map((el) => Math.round(el.getBoundingClientRect().width));
        const flow = [...root.querySelectorAll('[data-flow]')].filter((el) => el.checkVisibility()).map((el) => ({ step: el.dataset.flow, hook: [el, ...el.querySelectorAll('[data-test]')].map((h) => h.dataset.test).filter(Boolean).join(' '), top: Math.round(el.getBoundingClientRect().top + scrollY), left: Math.round(el.getBoundingClientRect().left) }));
        const sheet = document.querySelector('[data-test=games]');
        const report = document.querySelector('[data-test=report-block]');
        return {
            lang: document.documentElement.lang,
            scroll: [document.scrollingElement.scrollWidth, innerWidth],
            // What sticks out of the window, for the failure message.
            wide: [...root.querySelectorAll('*')].filter((el) => el.checkVisibility() && el.getBoundingClientRect().right > innerWidth + 0.5).slice(-4).map((el) => el.tagName + ' in ' + (el.closest('[data-test]')?.dataset.test ?? '') + ': ' + el.textContent.trim().slice(0, 40)),
            overflowing,
            squeezed: names.filter((w) => w < 1).length,
            names: names.length,
            flow,
            // The report block's top edge against the sheet's bottom edge: right under it, nothing between.
            gap: sheet && report ? Math.round(report.getBoundingClientRect().top - sheet.getBoundingClientRect().bottom) : null,
        };
    }
    JS;

const ROOM_FLOW_ROUNDTRIPS = <<<'JS'
    window.__roundtrips = [];
    const roomFetch = window.fetch;
    window.fetch = (...args) => roomFetch(...args).then((r) => { if (String(r.url).includes('/livewire')) window.__roundtrips.push(r.status); return r; });
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

/** A running players-mode Rocket League 1v1 knockout of two, its one series started 5 minutes ago. */
function roomFlowSeries(): SeriesMatch
{
    $profile = GameProfile::for('rocket-league', '1v1');
    $tournament = Tournament::factory()->create([
        'name' => 'Rocket Duel Night Weltmeisterschaftsqualifikation2140', 'game' => 'rocket-league', 'mode' => '1v1',
        'format' => TournamentFormat::SingleElimination, 'capacity' => 2,
        'options' => FormatOptions::fromArray(['thirdPlace' => false], $profile)->toArray(),
        'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
        'slug' => 'room-flow-'.fake()->unique()->numberBetween(1, 1_000_000), 'created_by_id' => organizer()->id,
    ]);

    foreach ([1, 2] as $index) {
        $name = ROOM_FLOW_LONG.(1000 + $index);
        $player = User::factory()->create(['name' => $name, 'locale' => 'de']);
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'name' => $name, 'rating' => 1200 - $index, 'members' => [$player->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($tournament);

    $series = SeriesMatch::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->sole();
    $series->forceFill(['number' => 4711 + SeriesMatch::query()->where('number', '>=', 4711)->count(), 'start_at' => now()->subMinutes(5), 'lobby_name' => 'e21-'.strtolower(ROOM_FLOW_LONG), 'lobby_password' => 'hunter2-secret', 'lobby_region' => 'EU'])->save();

    return $series->refresh();
}

function roomFlowPlayer(SeriesMatch $series, string $side): User
{
    return User::query()->findOrFail($series->sides[$side][0]);
}

/** Both checked in, three games on the sheet (3 : 1, 1 : 2, 2 : 0 for the challenger, a best of 5). */
function roomFlowFilled(SeriesMatch $series): SeriesMatch
{
    $series->forceFill(['ready_at_challenger' => now()->subMinutes(4), 'ready_at_challenged' => now()->subMinutes(3)])->save();
    $writer = roomFlowPlayer($series, 'challenger');

    foreach ([[3, 1], [1, 2], [2, 0]] as $game => [$c, $d]) {
        app(SeriesService::class)->saveLiveGame($series->refresh(), $writer, $game, $c, $d, null);
    }

    return $series->refresh();
}

/** A fourth game for the challenger (3 : 1 in games) and their report: the other side has to answer. */
function roomFlowReported(SeriesMatch $series): SeriesMatch
{
    $writer = roomFlowPlayer($series, 'challenger');
    app(SeriesService::class)->saveLiveGame($series->refresh(), $writer, 3, 2, 1, null);
    app(SeriesService::class)->report($series->refresh(), $writer, []);

    return $series->refresh();
}

function roomFlowPage(User $user, int $width): Page
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->context()->addInitScript(ROOM_FLOW_ROUNDTRIPS);
    $page->setViewportSize($width, 900);

    return $page;
}

/** @return array<string, mixed> */
function roomFlowOpen(Page $page, SeriesMatch $series, string $lang = 'de'): array
{
    // `?lang=` sets the session's language (SetLocale), which wins over the user's stored one.
    $page->goto(ComputeUrl::from(route('matches.room', ['match' => $series, 'lang' => $lang])));
    BrowserWait::until($page, '() => document.readyState === "complete" && window.Livewire !== undefined && document.querySelector("[data-test=match-room]") !== null', 10_000);

    return $page->evaluate(ROOM_FLOW_PROBE);
}

function roomFlowShot(Page $page, User $user, SeriesMatch $series, string $name): void
{
    $dir = getenv('ROOM_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    roomFlowOpen($page, $series, 'en');
    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
    roomFlowOpen($page, $series);
}

/**
 * Every data-test hook inside the steps, in document order.
 *
 * @param  array<string, mixed>  $probe
 * @return list<string>
 */
function roomFlowHooks(array $probe): array
{
    return array_values(array_filter(explode(' ', implode(' ', array_column($probe['flow'], 'hook')))));
}

/** @param array<string, mixed> $probe */
function roomFlowExpect(array $probe, string $label): void
{
    $steps = array_map(fn (array $f): int => (int) $f['step'], $probe['flow']);
    $sorted = $steps;
    sort($sorted);

    expect($probe['lang'])->toBe('de', $label)
        ->and($probe['scroll'][0])->toBeLessThanOrEqual($probe['scroll'][1], $label.' '.json_encode($probe['wide']))
        ->and($probe['overflowing'])->toBe([], $label)
        ->and($probe['squeezed'])->toBe(0, $label)
        // The steps come in their order in the document and down the page (a step may hold several blocks).
        ->and($steps)->toBe($sorted, $label)
        ->and(array_column($probe['flow'], 'top'))->toBe(collect($probe['flow'])->pluck('top')->sort()->values()->all(), $label);
}

test('the match room runs who, lobby, play and report, problems, details in that order, with 40-character names at 390 and 1440 px', function () {
    $series = roomFlowSeries();
    $filled = roomFlowFilled($series);
    $a = roomFlowPlayer($series, 'challenger');
    $reported = roomFlowReported(roomFlowFilled(roomFlowSeries()));
    $b = roomFlowPlayer($reported, 'challenged');

    // A casual 1v1 under way: the same order around its steps.
    [$casual, $host, $guest] = casualStarted('rocket-league');
    $host->forceFill(['name' => ROOM_FLOW_LONG.'host', 'locale' => 'de'])->save();
    $guest->forceFill(['name' => ROOM_FLOW_LONG.'gast', 'locale' => 'de'])->save();

    $measured = [];

    foreach ([390, 1440] as $width) {
        $page = roomFlowPage($a, $width);

        // Playing, both in, three games: the report action sits right under the sheet.
        $probe = roomFlowOpen($page, $filled);
        roomFlowExpect($probe, "playing @{$width}");
        expect($probe['gap'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(24)
            ->and(roomFlowHooks($probe))->toContain('room-lobby-pin')->toContain('lobby-checkin')->toContain('games')->toContain('report-block')->toContain('open-submit');
        $measured["playing-{$width}"] = $probe;
        roomFlowShot($page, $a, $filled, "room-after-playing-en-{$width}");

        // A roundtrip: a fourth game's goals. The console stays empty, every answer is 2xx.
        if ($width === 390) {
            $page->locator('[data-test=goals-3-c]')->fill('1');
            $page->locator('[data-test=goals-3-c]')->press('Tab');
            $page->locator('[data-test=goals-3-d]')->fill('0');
            $page->locator('[data-test=goals-3-d]')->press('Tab');
            BrowserWait::until($page, '() => window.__roundtrips.length > 0 && ! document.querySelector("[wire\\\\:loading]")', 10_000);
            BrowserWait::until($page, '() => document.querySelector("[data-test=series-score]")?.innerText.trim() === "3 : 1"', 10_000);
            // Back to three games for the 1440 pass.
            app(SeriesService::class)->saveLiveGame($filled->refresh(), $a, 3, null, null, null);
            expect($page->evaluate('() => window.__roundtrips.every((s) => s >= 200 && s < 300)'))->toBeTrue();
        }

        expect($page->evaluate('() => window.__errors'))->toBe([], "console @{$width}")
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

        // The other side, with the result reported: accept or dispute right under the sheet.
        $other = roomFlowPage($b, $width);
        $probe = roomFlowOpen($other, $reported);
        roomFlowExpect($probe, "to confirm @{$width}");
        expect(roomFlowHooks($probe))->toContain('check-result')->toContain('accept-result');
        $measured["confirm-{$width}"] = $probe;
        roomFlowShot($other, $b, $reported, "room-after-confirm-en-{$width}");

        $casualPage = roomFlowPage($guest, $width);
        $probe = roomFlowOpen($casualPage, $casual);
        roomFlowExpect($probe, "casual @{$width}");
        $measured["casual-{$width}"] = $probe;
        roomFlowShot($casualPage, $guest, $casual, "room-after-casual-en-{$width}");

        expect($other->evaluate('() => window.__errors'))->toBe([])
            ->and($casualPage->evaluate('() => window.__errors'))->toBe([]);
    }

    // Positive control: a thrown error lands in the collector.
    $page->evaluate('() => { setTimeout(() => { throw new Error("room-flow-control"); }); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("room-flow-control"))', 3_000);
    // And the probe sees what it guards against: a name squeezed to 0 px that spills out of its box.
    $page->evaluate('() => { const name = document.querySelector("[data-test=checkin-challenger] span"); name.style.cssText = "display: block; width: 0; white-space: nowrap"; }');
    $control = $page->evaluate(ROOM_FLOW_PROBE);
    expect($control['squeezed'])->toBeGreaterThan(0)->and($control['overflowing'])->not->toBe([]);

    fwrite(STDERR, "\n[room-flow] ".json_encode(array_map(fn (array $p): array => ['scroll' => $p['scroll'], 'squeezed' => $p['squeezed'], 'names' => $p['names'], 'gap' => $p['gap'], 'flow' => array_map(fn (array $f): string => $f['step'].'@'.$f['top'], $p['flow'])], $measured))."\n");
});

test('the remaining room states keep the order and the names', function () {
    $states = [];

    $before = roomFlowSeries();
    $before->forceFill(['start_at' => now()->addMinutes(20)])->save();
    $states['before'] = [$before->refresh(), roomFlowPlayer($before, 'challenger')];

    $disputed = roomFlowReported(roomFlowFilled(roomFlowSeries()));
    app(SeriesService::class)->respond($disputed->refresh(), roomFlowPlayer($disputed, 'challenged'), 'disputed', 'Spiel 2 war unseres, der Endbildschirm zeigt 2 : 1.', []);
    $states['disputed'] = [$disputed->refresh(), roomFlowPlayer($disputed, 'challenger')];

    $noshow = roomFlowSeries();
    $noshow->forceFill(['start_at' => now()->subMinutes(40), 'ready_at_challenger' => now()->subMinutes(38), 'noshow_side' => 'challenger', 'noshow_reported_at' => now()->subMinutes(4)])->save();
    $states['noshow'] = [$noshow->refresh(), roomFlowPlayer($noshow, 'challenged')];

    // A lineup series seen by a player who is not the captain.
    $clan = fn (string $tag) => Clan::factory()->create(['clantag' => $tag, 'name' => ROOM_FLOW_LONG.$tag]);
    $challenger = Lineup::factory()->ready()->create(['clan_id' => $clan('WWWW')->id]);
    $challenged = Lineup::factory()->ready()->create(['clan_id' => $clan('MMMM')->id]);
    $lineupSeries = SeriesMatch::factory()->accepted()->create(['challenger_lineup_id' => $challenger->id, 'challenged_lineup_id' => $challenged->id,
        'start_at' => now()->subMinutes(5), 'lobby_name' => 'e21-room', 'lobby_password' => 'pw', 'lobby_region' => 'EU']);
    $member = $challenger->seats()->where('user_id', '!=', $challenger->clan->owner_id)->first()->user;
    $member->forceFill(['name' => ROOM_FLOW_LONG.'mate', 'locale' => 'de'])->save();
    $states['spectator'] = [$lineupSeries->refresh(), $member];

    $measured = [];

    foreach ($states as $state => [$series, $viewer]) {
        foreach ([390, 1440] as $width) {
            $page = roomFlowPage($viewer, $width);
            $probe = roomFlowOpen($page, $series);
            roomFlowExpect($probe, "{$state} @{$width}");
            expect($page->evaluate('() => window.__errors'))->toBe([], "{$state} @{$width}");
            $measured["{$state}-{$width}"] = ['scroll' => $probe['scroll'], 'squeezed' => $probe['squeezed'], 'names' => $probe['names'], 'overflowing' => count($probe['overflowing']), 'flow' => array_map(fn (array $f): string => $f['step'].'@'.$f['top'], $probe['flow'])];
            roomFlowShot($page, $viewer, $series, "room-after-{$state}-en-{$width}");
        }
    }

    fwrite(STDERR, "\n[room-flow-states] ".json_encode($measured)."\n");
})->skip(fn () => getenv('ROOM_FLOW_ALL') !== '1', 'ROOM_FLOW_ALL=1 runs the remaining states');
