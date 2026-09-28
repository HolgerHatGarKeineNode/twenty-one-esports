<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\RatingChange;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\SeriesService;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentControl;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Pest\Browser\Playwright\Page;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TrustedFacts;

pest()->group('browser');

/*
|--------------------------------------------------------------------------
| The tournament control in the browser (P18, slice 4)
|--------------------------------------------------------------------------
|
| An admin corrects a round-1 result of a running players-mode knockout on
| the edit page's control section, at 1440 and at 375 px: the section shows
| the new side in the final at once, and the tournament page's bracket
| shows the corrected winner in the final. No horizontal overflow, a clean
| console and no response at 400 or above (BrowserConsole); a thrown error
| and a broken image at the end prove the collector sees what it has to.
|
| CONTROL_SHOTS=<dir> writes the English screenshots there.
|
*/

const CONTROL_STATE = <<<'JS'
    () => ({
        notice: document.querySelector('[data-test=control-notice]')?.textContent.trim() ?? null,
        error: document.querySelector('[data-test=control-error]')?.textContent.trim() ?? null,
        log: document.querySelector('[data-test=moderation-log]')?.textContent.replace(/\s+/g, ' ').trim() ?? '',
        lang: document.documentElement.lang,
        overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        errors: window.__errors,
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

function controlShot(Page $page, string $name): void
{
    $dir = getenv('CONTROL_SHOTS');

    if (! is_string($dir) || $dir === '') {
        return;
    }

    File::ensureDirectoryExists($dir);
    $page->screenshot(true, $name);
    // Pest clears tests/Browser/Screenshots on every run: move the file out at once.
    File::move(base_path('tests/Browser/Screenshots/'.$name.'.png'), $dir.'/'.$name.'.png');
}

/**
 * @return array<string, mixed>
 */
function controlState(Page $page): array
{
    return [...$page->evaluate(CONTROL_STATE), 'bad' => $page->evaluate(BrowserConsole::BAD_RESPONSES)];
}

/** `$slot` wins the match's series 2 : 0 (casual: nothing to sign). */
function controlWin(TournamentMatch $match, int $slot): void
{
    $match->load('slots.participant');
    $series = SeriesMatch::query()->where('tournament_match_id', $match->id)->sole();
    $winner = User::query()->findOrFail($match->slots[$slot]->participant->user_id);
    $loser = User::query()->findOrFail($match->slots[1 - $slot]->participant->user_id);
    $service = app(SeriesService::class);
    $challenger = $series->captainSideOf($winner) === 'challenger';

    foreach (range(0, intdiv($series->best_of, 2)) as $index) {
        $service->saveLiveGame($series, $winner, $index, $challenger ? 3 : 1, $challenger ? 1 : 3, null);
    }

    $service->report($series, $winner, []);
    $service->respond($series->refresh(), $loser, 'confirmed', '', []);
}

/** The text of the final's box on the tournament page. */
function finalBox(Page $page, string $key): string
{
    return (string) $page->evaluate('(key) => document.querySelector(`[data-test=match-box][data-key="${key}"]`)?.textContent.replace(/\s+/g, " ").trim() ?? ""', $key);
}

test('an admin corrects a result on the control section and the bracket follows, at 1440 and 375 px', function () {
    $admin = User::factory()->create(['name' => 'satsjaeger']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $tournament = Tournament::factory()->create([
        'name' => 'Rocket Duel Night', 'game' => 'rocket-league', 'mode' => '1v1', 'format' => TournamentFormat::SingleElimination, 'capacity' => 4,
        'options' => FormatOptions::fromArray(['thirdPlace' => false], GameProfile::for('rocket-league', '1v1'))->toArray(),
        'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running, 'slug' => 'rocket-duel-night',
        'on_site' => true, 'stations' => 4, 'created_by_id' => organizer()->id,
    ]);

    foreach (['Alice Sats', 'Bob Blocks', 'Carol Coins', 'Dave Hodl'] as $index => $name) {
        $player = User::factory()->create(['name' => $name]);
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'name' => $name, 'rating' => 1200 - $index, 'members' => [$player->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($tournament);
    $matches = TournamentMatch::query()->where('tournament_id', $tournament->id)->with(['round', 'slots.participant'])->orderBy('id')->get();
    $first = $matches->filter(fn (TournamentMatch $match): bool => $match->round->number === 1)->values();
    $final = $matches->first(fn (TournamentMatch $match): bool => $match->round->number === 2);

    // Both semi-finals won by slot 0; the final is paired.
    controlWin($first[0], 0);
    controlWin($first[1], 0);

    $url = route('admin.tournaments.edit', $tournament);
    $show = route('tournaments.show', $tournament);
    $page = visit(BrowserLogin::url($admin))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $measured = [];

    foreach ([[1440, 900, 0], [375, 812, 1]] as [$width, $height, $semi]) {
        $match = $first[$semi]->fresh(['slots.participant']);
        $old = $match->slots[0]->participant->name;
        $new = $match->slots[1]->participant->name;

        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from($url));
        BrowserWait::until($page, '() => document.querySelector("[data-test=control]") !== null', 10_000);
        $before = controlState($page);

        expect($before['lang'])->toBe('en')
            ->and($before['overflow'])->toBeLessThanOrEqual(0)
            ->and($before['errors'])->toBe([])
            ->and($before['bad'])->toBe([]);

        // Correct the semi-final: the other side wins, with a typed reason.
        $page->locator("[data-test=control-edit-{$match->key}]")->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=control-result-form]") !== null', 10_000);
        $page->locator('[data-test=control-series-winner]')->selectOption('1');
        $page->locator('[data-test=control-result-reason]')->fill('The report named the wrong winner');
        controlShot($page, "tournament-control-form-{$width}");
        $page->locator('[data-test=control-result-confirm]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=control-notice]") !== null || document.querySelector("[data-test=control-error]") !== null', 10_000);
        BrowserWait::until($page, "() => document.querySelector('[data-test=control-match][data-key=\"{$final->key}\"]')?.textContent.includes(".json_encode($new).')', 10_000);
        // The page hears `tournament-controlled` and refreshes its moderation log in its own roundtrip.
        BrowserWait::until($page, '() => (document.querySelector("[data-test=moderation-log]")?.textContent.match(/set a result/g) ?? []).length === '.($semi + 1), 10_000);
        $after = controlState($page);
        controlShot($page, "tournament-control-after-{$width}");
        $row = (string) $page->evaluate("() => document.querySelector('[data-test=control-match][data-key=\"{$final->key}\"]').textContent.replace(/\\s+/g, ' ')");

        expect($after['error'])->toBeNull()
            ->and($after['notice'])->toBe('The result is set; the bracket moved on.')
            ->and($row)->toContain($new)
            ->and($row)->not->toContain($old)
            ->and($after['log'])->toContain('set a result')
            ->and($after['log'])->toContain('The report named the wrong winner')
            ->and($after['overflow'])->toBeLessThanOrEqual(0)
            ->and($after['errors'])->toBe([])
            ->and($after['bad'])->toBe([]);

        // The tournament page's bracket shows the corrected winner in the final.
        $page->goto(ComputeUrl::from($show));
        BrowserWait::until($page, "() => document.querySelector('[data-test=match-box][data-key=\"{$final->key}\"]') !== null", 10_000);
        $box = finalBox($page, $final->key);
        $public = controlState($page);
        controlShot($page, "tournament-control-bracket-{$width}");
        $measured[$width] = ['edit' => $after['overflow'], 'bracket' => $public['overflow']];

        expect($box)->toContain($new)
            ->and($box)->not->toContain($old)
            ->and($public['overflow'])->toBeLessThanOrEqual(0)
            ->and($public['errors'])->toBe([])
            ->and($public['bad'])->toBe([]);
    }

    // Positive control: a thrown error and a broken image on this very page are caught.
    $page->evaluate('() => { setTimeout(() => { throw new Error("positive control"); }); const img = new Image(); img.src = "/__missing-positive-control.png"; document.body.append(img); }');
    BrowserWait::until($page, '() => window.__errors.length >= 2', 10_000);
    $control = controlState($page);

    expect(implode("\n", $control['errors']))->toContain('positive control')
        ->and(implode("\n", $control['errors']))->toContain('__missing-positive-control.png');

    fwrite(STDERR, "\n[tournament-control] ".json_encode($measured)."\n");
});

/*
| The Elo of a corrected rated result: the form states what saving does to
| the Elo before the save, at 1440 and 375 px; the log keeps it. At 1440 the
| rated game's loser is made the winner, at 375 it goes back.
*/

test('correcting a rated chess result states its Elo effect before the save and logs it, at 1440 and 375 px', function () {
    openSeason();
    app()->bind(TrustFacts::class, TrustedFacts::class);
    $admin = User::factory()->create(['name' => 'satsjaeger']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $tournament = runningChess(TournamentFormat::SingleElimination, 2, TournamentResultsMode::Players, clans: true);
    $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')->with('slots.participant')->sole();
    $game = ChessGame::query()->where('tournament_match_id', $match->id)->sole();
    app(ChessGameService::class)->resign($game, $game->black);
    $whiteSlot = in_array($game->white_id, $match->slots[0]->participant->memberIds(), true) ? 0 : 1;
    $page = visit(BrowserLogin::url($admin))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $measured = [];

    expect($game->refresh()->rated)->toBeTrue();

    // 1440: Black made the winner; 375: White again. Each time the form names the effect first.
    foreach ([[1440, 900, $whiteSlot === 0 ? '0-1' : '1-0', 1], [375, 812, $whiteSlot === 0 ? '1-0' : '0-1', 2]] as [$width, $height, $result, $logged]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('admin.tournaments.edit', $tournament)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=control]") !== null', 10_000);
        $page->locator("[data-test=control-edit-{$match->key}]")->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=control-result-form]") !== null', 10_000);
        $page->locator('[data-test=control-chess-result]')->selectOption($result);
        BrowserWait::until($page, '() => document.querySelector("[data-test=control-elo-effect]") !== null', 10_000);
        $effect = $page->evaluate('() => { const el = document.querySelector("[data-test=control-elo-effect]"); const box = el.getBoundingClientRect(); const form = el.closest("form").getBoundingClientRect(); return { text: el.textContent.trim(), inside: box.left >= form.left && box.right <= form.right, width: Math.round(box.width), height: Math.round(box.height) }; }');
        $page->locator('[data-test=control-result-reason]')->fill('The scoresheet was signed the other way round');
        controlShot($page, "tournament-control-elo-{$width}");
        $expected = TournamentControl::describeElo(app(TournamentControl::class)->eloPreview($tournament, $match->id, ['result' => $result]));
        $page->locator('[data-test=control-result-confirm]')->click();
        BrowserWait::until($page, '() => (document.querySelector("[data-test=moderation-log]")?.textContent.match(/Elo:/g) ?? []).length === '.$logged, 10_000);
        $after = controlState($page);
        $measured[$width] = ['effect' => $effect, 'overflow' => $after['overflow']];

        expect($effect['text'])->toBe('Elo: '.$expected)
            ->and($effect['text'])->toMatch('/^Elo: reverts [+−]\d+\/[+−]\d+, applies [+−]\d+\/[+−]\d+$/u')
            ->and($effect['inside'])->toBeTrue()
            ->and($effect['height'])->toBeGreaterThan(0)
            ->and($after['error'])->toBeNull()
            ->and($after['overflow'])->toBeLessThanOrEqual(0)
            ->and($after['errors'])->toBe([])
            ->and($after['bad'])->toBe([]);
    }

    // Back where it started: the Elo is White's win again.
    expect(RatingChange::query()->where('source', RatingChange::CHESS)->where('source_id', $game->id)->orderBy('id')->get()->map(fn (RatingChange $change): float => $change->score)->all())->toBe([1.0, 0.0]);

    fwrite(STDERR, "\n[tournament-control-elo] ".json_encode($measured, JSON_UNESCAPED_UNICODE)."\n");
});

/*
| "Who blocks what" (P18, slice 5): the panel at 1440 and 375 px, its
| countdown ticking, one click reminds; the player's countdown on the
| tournament page counts to the server's moment on a device whose clock
| runs ten minutes fast.
*/

const WAITS_STATE = <<<'JS'
    () => {
        const panel = document.querySelector('[data-test=waits]');
        const box = panel?.getBoundingClientRect();
        return {
            panel: box ? { left: Math.round(box.left), right: Math.round(box.right), width: Math.round(box.width), height: Math.round(box.height) } : null,
            states: [...document.querySelectorAll('[data-test=wait]')].map((row) => row.dataset.state),
            clock: document.querySelector('[data-test=waits] [data-test=auto-decision-clock]')?.textContent.trim() ?? null,
            notice: document.querySelector('[data-test=waits-notice]')?.textContent.trim() ?? null,
            error: document.querySelector('[data-test=waits-error]')?.textContent.trim() ?? null,
            log: document.querySelector('[data-test=moderation-log]')?.textContent.replace(/\s+/g, ' ').trim() ?? '',
            viewport: document.documentElement.clientWidth,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            lang: document.documentElement.lang,
            errors: window.__errors,
        };
    }
    JS;

/** mm:ss or h:mm:ss as seconds. */
function waitsSeconds(?string $clock): int
{
    $parts = array_map(intval(...), explode(':', (string) $clock));

    return array_reduce($parts, fn (int $carry, int $part): int => $carry * 60 + $part, 0);
}

test('who blocks what: the panel counts down and reminds at 1440 and 375 px, and the player counts on the server clock', function () {
    $admin = User::factory()->create(['name' => 'satsjaeger']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $tournament = Tournament::factory()->create([
        'name' => 'Rocket Duel Night', 'game' => 'rocket-league', 'mode' => '1v1', 'format' => TournamentFormat::SingleElimination, 'capacity' => 4,
        'options' => FormatOptions::fromArray(['thirdPlace' => false], GameProfile::for('rocket-league', '1v1'))->toArray(),
        'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running, 'slug' => 'rocket-duel-night',
        'on_site' => true, 'stations' => 4, 'created_by_id' => organizer()->id,
    ]);

    foreach (['Alice Sats', 'Bob Blocks', 'Carol Coins', 'Dave Hodl'] as $index => $name) {
        $player = User::factory()->create(['name' => $name]);
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'name' => $name, 'rating' => 1200 - $index, 'members' => [$player->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($tournament);
    $series = SeriesMatch::query()->whereIn('tournament_match_id', TournamentMatch::query()->where('tournament_id', $tournament->id)->pluck('id'))->orderBy('id')->get();

    // The first series is reported: its answer is due in 30 minutes, before the other's report (2 h).
    $reporter = User::query()->findOrFail($series[0]->rosterSide('challenger')[0]);
    $answerer = User::query()->findOrFail($series[0]->rosterSide('challenged')[0]);
    $service = app(SeriesService::class);

    foreach (range(0, intdiv($series[0]->best_of, 2)) as $index) {
        $service->saveLiveGame($series[0], $reporter, $index, 3, 1, null);
    }

    $service->report($series[0]->refresh(), $reporter, []);
    $waited = [$answerer, User::query()->findOrFail($series[1]->rosterSide('challenger')[0])];

    $page = visit(BrowserLogin::url($admin))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $measured = [];

    foreach ([[1440, 900, 0], [375, 812, 1]] as [$width, $height, $turn]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('admin.tournaments.edit', $tournament)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=waits] [data-test=auto-decision-clock]") !== null', 10_000);
        $before = $page->evaluate(WAITS_STATE);
        usleep(2_100_000);
        $later = $page->evaluate(WAITS_STATE);

        expect($before['lang'])->toBe('en')
            ->and($before['states'])->toBe(['response', 'report'])
            ->and(waitsSeconds($before['clock']))->toBeGreaterThan(29 * 60)->toBeLessThanOrEqual(30 * 60)
            ->and(waitsSeconds($later['clock']))->toBeLessThan(waitsSeconds($before['clock']))
            ->and($before['panel']['left'])->toBeGreaterThanOrEqual(0)
            ->and($before['panel']['right'])->toBeLessThanOrEqual($before['viewport'])
            ->and($before['overflow'])->toBeLessThanOrEqual(0)
            ->and($later['errors'])->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);

        controlShot($page, "tournament-waits-{$width}");

        // One click reminds the waited-on player; the log names it.
        $page->locator("[data-test=wait-remind-{$waited[$turn]->id}]")->first()->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=waits-notice]") !== null || document.querySelector("[data-test=waits-error]") !== null', 10_000);
        BrowserWait::until($page, '() => (document.querySelector("[data-test=moderation-log]")?.textContent.match(/reminded a player/g) ?? []).length === '.($turn + 1), 10_000);
        $after = $page->evaluate(WAITS_STATE);
        $measured[$width] = ['panel' => $before['panel'], 'overflow' => $after['overflow'], 'clock' => [$before['clock'], $later['clock']]];

        expect($after['notice'])->toBe('Reminder sent.')
            ->and($after['error'])->toBeNull()
            ->and($after['log'])->toContain($waited[$turn]->name)
            ->and($after['overflow'])->toBeLessThanOrEqual(0)
            ->and($after['errors'])->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }

    expect($answerer->notifications()->count())->toBeGreaterThanOrEqual(1);

    // The waited-on player, on a device ten minutes fast: the countdown still shows the server's ~30 minutes.
    $page->goto(ComputeUrl::from(BrowserLogin::url($answerer)));
    $page->context()->addInitScript('(() => { const now = Date.now.bind(Date); Date.now = () => now() + 600000; })()');

    foreach ([[1440, 900], [375, 812]] as [$width, $height]) {
        $page->setViewportSize($width, $height);
        $page->goto(ComputeUrl::from(route('tournaments.show', $tournament)));
        BrowserWait::until($page, '() => document.querySelector("[data-test=my-wait] [data-test=auto-decision-clock]") !== null', 10_000);
        usleep(1_200_000);
        $player = $page->evaluate('() => ({ clock: document.querySelector("[data-test=my-wait] [data-test=auto-decision-clock]").textContent.trim(), skewed: Date.now() - new Date().getTime(), overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth, errors: window.__errors })');
        controlShot($page, "tournament-my-wait-{$width}");
        $measured["player-{$width}"] = $player;

        expect($player['skewed'])->toBeGreaterThan(590_000)
            ->and(waitsSeconds($player['clock']))->toBeGreaterThan(25 * 60)->toBeLessThanOrEqual(30 * 60)
            ->and($player['overflow'])->toBeLessThanOrEqual(0)
            ->and($player['errors'])->toBe([])
            ->and($page->evaluate(BrowserConsole::BAD_RESPONSES))->toBe([]);
    }

    fwrite(STDERR, "\n[tournament-waits] ".json_encode($measured)."\n");
});
