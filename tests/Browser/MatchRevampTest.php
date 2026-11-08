<?php

use App\Enums\TournamentFormat;
use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\ChessMove;
use App\Models\SeriesMatch;
use App\Models\TournamentMatch;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Tournaments\TournamentMatchMaker;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Pest\Browser\Support\ComputeUrl;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\Support\BlockliOn;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\NineMensMorrisOn;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| Match view (T2) and match room (T3/T13), plan "Refactor und Design-Revamp", P5
|--------------------------------------------------------------------------
|
| The boards MatchBoards(+Phone), MatchRoom-*, TournamentMatchRoom, MatchShow,
| Correspondence and BoardCorrespondence against the real pages, as an admin
| in German, at 390 and 1440 px: a Rocket League cup match with the league's
| lobby (lobby step, then the result step with the scoreboard), a clan series
| with its captain's lobby, a finished series' public page, a daily chess
| game, a Blockli and a nine men's morris game, and both correspondence
| lists. Console and every response stay quiet on load and after a Livewire
| roundtrip, nothing runs past the page, and a positive control proves the
| collector and the squeeze detector listen. DESIGN_SHOTS=<dir> saves the
| full-page screenshots for the side-by-side with the boards.
|
*/

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
    Queue::fake();
    config(['session.driver' => 'database', 'esports.league.nsec' => (new TestSigner)->secret]);
    app()->rebinding('request', function ($app): void {
        $app['session']->forgetDrivers();
        $app->forgetInstance('session.store');
        $app->forgetInstance('auth.driver');
        $app['auth']->forgetGuards();
        $app['livewire']->flushState();
    });
});

/** Nothing runs past the viewport or out of its own box (the squeeze detector of the canvas sweep, as in HomeRevampTest). */
const MATCH_REVAMP_SQUEEZE = <<<'JS'
() => {
    const out = [];
    const width = document.documentElement.clientWidth;
    if (document.documentElement.scrollWidth > width) out.push('page ' + document.documentElement.scrollWidth + ' > ' + width);
    for (const el of document.querySelectorAll('main *')) {
        if (!el.checkVisibility() || el.closest('[aria-hidden=true], .sr-only, .overflow-x-auto, [data-test=move-history]')) continue;
        const r = el.getBoundingClientRect();
        if (r.width === 0) continue;
        if (r.right > width + 1 || r.left < -1) out.push((el.dataset.test || el.tagName) + ' x ' + Math.round(r.left) + '..' + Math.round(r.right));
        // A 44 px hit area drawn by an absolute ::after (copy-npub) is no spill.
        if (['A', 'BUTTON'].includes(el.tagName) && el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).overflow === 'visible' && getComputedStyle(el, '::after').position !== 'absolute') out.push('spill ' + (el.dataset.test || el.textContent.trim().slice(0, 30)));
    }
    return [...new Set(out)].slice(0, 20);
}
JS;

function matchRevampPage(User $user, string $path, int $width): mixed
{
    $page = visit(BrowserLogin::url($user))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    // The room polls and pushes: a full resource-timing buffer (250 by default) would hide a late 4xx from BAD_RESPONSES.
    $page->context()->addInitScript('performance.setResourceTimingBufferSize(5000)');
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from(route('locale.switch', 'de')));
    $page->goto(ComputeUrl::from($path));
    $page->waitForLoadState('networkidle');

    return $page;
}

function matchRevampQuiet(mixed $page, string $label): void
{
    expect($page->evaluate('() => window.__errors'))->toBe([], $label.': console')
        ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([], $label.': responses')
        ->and($page->evaluate(MATCH_REVAMP_SQUEEZE))->toBe([], $label.': squeeze');
}

function matchRevampShot(mixed $page, string $name, bool $full = true): void
{
    $dir = getenv('DESIGN_SHOTS');

    if (! is_string($dir) || $dir === '' || $dir === '1') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot($full, $name);
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

function matchRevampAdmin(string $name = 'Erdöpfökasbrot'): User
{
    $admin = User::factory()->create(['name' => $name]);
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

/** A Rocket League cup match with the league's lobby, both checked in; the admin plays as the higher seed (host). */
function matchRevampCupRoom(User $admin): SeriesMatch
{
    $cup = runningCup(4, TournamentFormat::DoubleElimination, ['grandFinal' => 'single', 'bestOf' => 3, 'finalBestOf' => 3], 'rocket-league', '1v1');
    $cup->forceFill(['name' => 'RL Casual Cup EU #2'])->save();
    cupTick();
    $match = TournamentMatch::query()->where('tournament_id', $cup->id)->where('status', 'ready')->where('bracket', '!=', 'bye')
        ->with(['slots.participant', 'round'])->orderBy('id')->firstOrFail();
    $series = SeriesMatch::query()->where('tournament_match_id', $match->id)->first()
        ?? app(TournamentMatchMaker::class)->createCupSeries($cup, $match, $match->slots[0]->participant, $match->slots[1]->participant);
    $series->refresh();
    $hostSide = $series->host_side;
    $hostParticipant = $match->slots[$hostSide === 'challenger' ? 0 : 1]->participant;
    $guestParticipant = $match->slots[$hostSide === 'challenger' ? 1 : 0]->participant;
    // The admin takes the host seat; the guest gets a readable name.
    $hostParticipant->forceFill(['user_id' => $admin->id, 'members' => [$admin->id], 'name' => $admin->displayName()])->save();
    User::query()->whereKey($guestParticipant->user_id)->update(['name' => 'Nidan21']);
    $guestParticipant->forceFill(['name' => 'Nidan21'])->save();
    $sides = $series->sides;
    $sides[$hostSide] = [$admin->id];
    $series->forceFill([
        'sides' => $sides, "{$hostSide}_name" => $admin->displayName(), SeriesMatch::otherSide($hostSide).'_name' => 'Nidan21',
        'ready_at_challenger' => now()->subMinutes(2), 'ready_at_challenged' => now()->subMinutes(2), 'start_at' => now()->subMinutes(3),
    ])->save();

    return $series->refresh();
}

test('the match room, the match page, the match views and the correspondence lists stay quiet and inside the page, at 390 and 1440', function () {
    BlockliOn::play();
    NineMensMorrisOn::play();
    $admin = matchRevampAdmin();
    $cupRoom = matchRevampCupRoom($admin);

    // A finished clan-free series for the public page, the admin as a director of nothing: a plain spectator view.
    $done = SeriesMatch::factory()->accepted()->create(['game' => 'rocket-league', 'mode' => '1v1', 'challenger_name' => 'npub134tv37t…', 'challenged_name' => 'El Presidento Ben']);
    $done->forceFill(['live_games' => array_map(fn (array $g): array => ['challenger' => $g[0], 'challenged' => $g[1], 'winner' => $g[0] > $g[1] ? 'challenger' : 'challenged'], [[4, 2], [2, 3], [3, 4]])])->save();

    // Chess daily #156 with four moves each; Blockli and nine men's morris live.
    $chess = ChessGame::factory()->daily()->create(['white_id' => $admin->id, 'black_id' => User::factory()->create(['name' => 'floatYaBoat'])->id, 'ply' => 8]);
    foreach (['d4', 'd5', 'Bf4', 'e6', 'Nf3', 'c5', 'e3', 'Nc6'] as $index => $san) {
        ChessMove::query()->create(['chess_game_id' => $chess->id, 'ply' => $index + 1, 'uci' => 'a1a1', 'san' => $san, 'fen' => ChessGame::START_FEN, 'spent_ms' => 1000, 'clock_ms' => 86_400_000, 'created_at' => now()->subMinutes(80 - $index * 10)]);
    }
    $blockli = BlockliOn::setUp(app(BoardGameService::class)->start('blockli', $admin, User::factory()->create(['name' => 'markusturm'])), 'e7 e3 10 10 w - 0');
    $mill = app(BoardGameService::class)->start('nine-mens-morris', $admin, User::factory()->create(['name' => 'DerCaddy']));

    $pages = [
        'room-lobby' => route('matches.room', $cupRoom, false),
        'match-show' => route('matches.show', $done->number, false),
        'chess' => route('games.show', $chess, false),
        'blockli' => route('board.show', $blockli, false),
        'mill' => route('board.show', $mill, false),
        'correspondence' => route('me.correspondence', [], false),
        'board-correspondence' => route('board.correspondence', 'nine-mens-morris', false),
    ];

    foreach ([1440, 390] as $width) {
        foreach ($pages as $name => $path) {
            $page = matchRevampPage($admin, $path, $width);
            matchRevampShot($page, "{$name}-{$width}");
            matchRevampQuiet($page, "{$name} {$width}");

            // A roundtrip of every Livewire component on the page keeps it quiet too.
            $page->evaluate('() => window.Livewire?.all().forEach((c) => c.$wire.$refresh())');
            $page->waitForLoadState('networkidle');
            matchRevampQuiet($page, "{$name} {$width} after refresh");
        }

        // The room's lobby step: the league card under the stepper, name and password in plain text, never hidden.
        $room = matchRevampPage($admin, $pages['room-lobby'], $width);
        $card = $room->evaluate('() => { const c = document.querySelector("[data-test=room-lobby-pin]"); const s = document.querySelector("[data-test=room-stepper]"); return { league: c?.dataset.league, below: c && s ? c.getBoundingClientRect().top >= s.getBoundingClientRect().bottom : false, password: document.querySelector("[data-test=room-lobby-password]")?.innerText, copy: [...c.querySelectorAll("button")].filter((b) => b.checkVisibility()).map((b) => Math.round(b.getBoundingClientRect().height)) }; }');
        expect($card['league'])->toBe('1')
            ->and($card['below'])->toBeTrue()
            ->and($card['password'])->toBe($cupRoom->lobby_password)
            ->and(min($card['copy']))->toBeGreaterThanOrEqual(44);

        // The host opens the lobby, the guest is in: the result step and its scoreboard.
        $cupRoom->forceFill(['lobby_shared_at' => now()->subMinute(), 'joined_at' => now()->subMinute()])->save();
        $room = matchRevampPage($admin, $pages['room-lobby'], $width);
        // Clicked from script: on a phone the fixed action dock (P7) can sit over the row the page scrolled to.
        $room->evaluate('() => document.querySelector("[data-test=goals-0-c-up]").click()');
        BrowserWait::until($room, '() => document.querySelector("[data-test=goals-0-c]")?.value === "1"', 10_000);
        $room->evaluate('() => document.querySelector("[data-test=goals-0-d-down]").click()');
        BrowserWait::until($room, '() => document.querySelector("[data-test=series-score]")?.innerText.trim() !== "VS"', 10_000);
        $steppers = $room->evaluate('() => [...document.querySelectorAll(".rv-step")].filter((b) => b.checkVisibility()).map((b) => [Math.round(b.getBoundingClientRect().width), Math.round(b.getBoundingClientRect().height)])');
        expect(collect($steppers)->flatten()->min())->toBeGreaterThanOrEqual(48)
            ->and($room->evaluate('() => document.querySelector("[data-test=scoreboard-side-challenger]")?.dataset.lead ?? document.querySelector("[data-test=scoreboard-side-challenged]")?.dataset.lead'))->not->toBeNull()
            ->and($room->evaluate('() => document.querySelector("[data-test=report-summary]")?.innerText ?? ""'))->toContain('1 : 0');
        $room->waitForLoadState('networkidle');
        // At 390 the full-page capture of this state timed out (15 s) twice: the first screen is taken there.
        matchRevampShot($room, "room-result-{$width}", $width > 390);
        matchRevampQuiet($room, "room result {$width}");
        $cupRoom->forceFill(['lobby_shared_at' => null, 'joined_at' => null, 'live_games' => null])->save();
    }
});

test('the match pages fit every width from 390 to 1920 without a squeeze', function () {
    $admin = matchRevampAdmin();
    $cupRoom = matchRevampCupRoom($admin);
    $seen = [];

    foreach ([390, 768, 1024, 1280, 1440, 1920] as $width) {
        foreach (['room' => route('matches.room', $cupRoom, false), 'show' => route('matches.show', $cupRoom->number, false)] as $label => $path) {
            $seen["{$label} {$width}"] = matchRevampPage($admin, $path, $width)->evaluate(MATCH_REVAMP_SQUEEZE);
        }
    }

    fwrite(STDERR, "\n[match squeeze] ".json_encode($seen));
    expect(array_filter($seen))->toBe([]);
});

test('positive control: the collector and the squeeze detector of this file see a throw, a 404 and a box past the edge', function () {
    $admin = matchRevampAdmin();
    $page = matchRevampPage($admin, route('matches.room', matchRevampCupRoom($admin), false), 390);
    $page->evaluate('() => { setTimeout(() => { throw new Error("match positive control"); }); fetch("/__match-missing").then((r) => r.text()); const d = document.createElement("div"); d.style.cssText = "width:600px;height:10px"; d.dataset.test = "match-wide"; document.querySelector("main").append(d); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("match positive control"))', 5_000);
    // The 404 reaches both channels: the collector (fetch) and, once its body is read, the resource timing (BAD_RESPONSES).
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("404") && e.includes("__match-missing"))', 5_000);
    BrowserWait::until($page, '() => performance.getEntries().some((e) => e.name.endsWith("/__match-missing") && e.responseStatus === 404)', 5_000);

    expect($page->evaluate(MATCH_REVAMP_SQUEEZE))->toContain('page 600 > 390')
        ->and(fn () => matchRevampQuiet($page, 'positive control'))->toThrow(ExpectationFailedException::class);
});
