<?php

use App\Enums\TournamentFormat;
use App\Models\Admin;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Tournaments\FirstPlaceTie;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentControl;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Pest\Browser\Support\ComputeUrl;
use Tests\Support\BrowserConsole;
use Tests\Support\BrowserLogin;
use Tests\Support\BrowserWait;
use Tests\Support\TestSigner;

pest()->group('browser');

/*
| A tie for 1st place (FirstPlaceTie, PR #21) measured in the browser as an admin in German at 390, 1280 and 1920:
| the editor's choice and its help (nothing stored before "Save changes"), the tournament page with the tie-break
| stage open and with a shared 1st place, and the stale tie-break note on the director desk and the control. On every
| page: no sideways scroll, every new element inside the viewport, no text spilling out of its box, the console and
| every response quiet on first load and after one Livewire round trip. A thrown error and a 404 at the end prove the
| collector sees them. FPT_DUMP=<file> writes the measured numbers there.
*/

const FPT_WIDTHS = [390, 1280, 1920];

/** [left, right, height] of the first visible match per selector, and the page's [scrollWidth, clientWidth]. */
const FPT_BOXES = <<<'JS'
    ([sels]) => ({
        page: [document.documentElement.scrollWidth, document.documentElement.clientWidth],
        boxes: Object.fromEntries(sels.map((s) => {
            const el = [...document.querySelectorAll(s)].find((e) => e.checkVisibility()), r = el?.getBoundingClientRect();
            return [s, el ? [Math.round(r.left), Math.round(r.right), Math.round(r.height)] : null];
        })),
    })
    JS;

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

function fptAdmin(): User
{
    $admin = User::factory()->create(['name' => 'Erdöpfökasbrot']);
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

/** A round robin of three where seed i beats seed i+1 (level on everything), synced once. */
function fptCircle(string $rule): Tournament
{
    $tournament = runningChess(TournamentFormat::RoundRobin, 3, options: ['firstPlaceTie' => $rule]);
    $rank = array_flip(TournamentParticipant::query()->where('tournament_id', $tournament->id)->orderBy('seed')->pluck('id')->all());

    foreach (TournamentMatch::query()->where('tournament_id', $tournament->id)->whereNull('result')->with('slots')->get() as $match) {
        $ids = $match->slots->pluck('tournament_participant_id')->all();
        $slot = count($ids) === 2 ? (($rank[$ids[0]] + 1) % 3 === $rank[$ids[1]] ? 0 : 1) : null;
        $slot === null || app(TournamentRunner::class)->store($match, ['winner' => $slot, 'games_won' => $slot === 0 ? [1.0, 0.0] : [0.0, 1.0], 'points' => [], 'forfeit' => false, 'label' => '1–0',
            'by' => 'director', 'user_id' => null, 'name' => 'Director', 'at' => now()->toIso8601String()]);
    }

    app(TournamentRunner::class)->sync($tournament);

    return $tournament->refresh();
}

/** The tie-break knockout is open; an admin then flips a game of the table: place 1 is clear and the knockout is stale. */
function fptStale(User $admin): Tournament
{
    $tournament = fptCircle('playoff');
    $match = TournamentMatch::query()->where('tournament_round_id', TournamentRunner::currentRound($tournament)->id)->where('bracket', '!=', 'bye')->with('slots')->sole();
    app(TournamentControl::class)->setResult($tournament, $admin, $match->id, ['result' => $match->result['winner'] === 0 ? '0-1' : '1-0'], 'The scoresheet said otherwise');

    return $tournament->refresh();
}

function fptPage(User $admin, string $path, int $width): mixed
{
    $page = visit(BrowserLogin::url($admin))->page();
    $page->context()->addInitScript(BrowserConsole::COLLECTOR);
    $page->setViewportSize($width, 900);
    $page->goto(ComputeUrl::from(route('locale.switch', 'de')));
    $page->goto(ComputeUrl::from($path));
    $page->waitForLoadState('networkidle');

    return $page;
}

/**
 * Measure `$sels` (each must be visible and inside the viewport), the page width, the text spill inside `$scope` and the
 * quiet of console and responses; then one Livewire round trip and the quiet again. Returns what is wrong, [] when nothing.
 *
 * @param  list<string>  $sels
 * @return array<string, mixed>
 */
function fptMeasure(mixed $page, string $label, int $width, array $sels, string $scope): array
{
    $loud = fn (): array => [...$page->evaluate('() => window.__errors'), ...$page->evaluate(BrowserConsole::BAD_RESPONSES)];
    $problems = [];

    foreach (['first load', 'after a round trip'] as $step) {
        if ($step === 'after a round trip') {
            $page->evaluate('([sel]) => Livewire.find(document.querySelector(sel).closest("[wire\\\\:id]").getAttribute("wire:id")).$refresh()', [$sels[0]]);
            $page->waitForLoadState('networkidle');
        }
        $m = $page->evaluate(FPT_BOXES, [$sels]);
        $out = array_filter($m['boxes'], fn ($b) => $b === null || $b[0] < 0 || $b[1] > $width || $b[2] <= 0);
        $spill = $page->evaluate(BrowserConsole::SPILLED, [$scope]);

        if ($m['page'][0] > $m['page'][1] || $out !== [] || $spill !== [] || $loud() !== []) {
            $problems["{$label} {$width} {$step}"] = [$m, $out, $spill, $loud()];
        }
    }

    return $problems;
}

function fptText(mixed $page, string $sel): string
{
    return $page->evaluate('([sel]) => document.querySelector(sel)?.innerText ?? ""', [$sel]);
}

test('the editor offers the tie choice with its help, a click changes the help and stores nothing, only the save button does', function () {
    $admin = fptAdmin();
    $tournament = openTournament(['format' => TournamentFormat::RoundRobin, 'capacity' => 8, 'options' => FormatOptions::defaults(GameProfile::for('chess', 'blitz'))->toArray(), 'created_by_id' => $admin->id]);
    $problems = [];

    foreach (FPT_WIDTHS as $width) {
        $page = fptPage($admin, route('admin.tournaments.edit', $tournament, false), $width);
        $sels = ['[data-test=first-place-tie-option]', '[data-test=first-place-tie]', '[data-test=first-place-tie] [role=radio]:nth-child(1)', '[data-test=first-place-tie] [role=radio]:nth-child(2)', '[data-test=first-place-tie-help]'];
        $problems += fptMeasure($page, 'editor', $width, $sels, '[data-test=first-place-tie-option]');

        $help = fn (): string => fptText($page, '[data-test=first-place-tie-help]');
        expect(fptText($page, '[data-test=first-place-tie] [aria-checked=true]'))->toBe('Stichkampf')->and($help())->toStartWith('Stichkampf: Wer nach Punkten');
        $page->locator('[data-test=first-place-tie] [role=radio][aria-checked=false]')->click();
        BrowserWait::until($page, '() => document.querySelector("[data-test=first-place-tie] [aria-checked=true]")?.innerText === "Geteilter 1. Platz"', 10_000);
        $problems += fptMeasure($page, 'editor-shared', $width, $sels, '[data-test=first-place-tie-option]');
        expect($help())->toStartWith('Geteilter 1. Platz: Wer nach Punkten')
            // Nothing is stored by the pick itself.
            ->and(FormatOptions::fromArray($tournament->refresh()->options ?? [], GameProfile::for('chess', 'blitz'))->firstPlaceTie)->toBe('playoff');
    }

    // The save button stores it (the positive control of "nothing stored before").
    $page->locator('[data-test=edit-save]')->click();
    BrowserWait::until($page, '() => document.querySelector("[data-test=edit-notice], [data-test=edit-error]") !== null', 10_000);
    expect(fptText($page, '[data-test=edit-error]'))->toBe('')
        ->and(FormatOptions::fromArray($tournament->refresh()->options ?? [], GameProfile::for('chess', 'blitz'))->firstPlaceTie)->toBe('shared');

    expect($problems)->toBe([]);
});

test('the tournament page shows the tie-break stage and the shared 1st place, the desk and the control the stale tie-break', function () {
    $admin = fptAdmin();
    $playoff = fptCircle('playoff');
    $shared = fptCircle('shared');
    $stale = fptStale($admin);
    $problems = [];

    foreach (FPT_WIDTHS as $width) {
        // Tie-break stage open: the tie note, the stage title and the fact chip.
        $page = fptPage($admin, route('tournaments.show', $playoff, false), $width);
        $problems += fptMeasure($page, 'tie-break', $width, ['[data-test=standings-first-tie]', 'section[aria-label="Stichkämpfe um Platz 1"] h3', '[data-fact=first-tie]'], '[data-test=standings-first-tie], [data-fact=first-tie]');
        expect(fptText($page, '[data-test=standings-first-tie]'))->toContain('Ein Stichkampf entscheidet über Platz 1.')
            ->and(fptText($page, 'section[aria-label="Stichkämpfe um Platz 1"] h3'))->toBe('Stichkämpfe um Platz 1')
            ->and(fptText($page, '[data-fact=first-tie]'))->toContain('Gleichstand um Platz 1')->toContain('ein Stichkampf unter allen');

        // Finished with a shared 1st place: the hero says so and leads to the results, not to a winner to zap.
        $page = fptPage($admin, route('tournaments.show', $shared, false), $width);
        $problems += fptMeasure($page, 'shared', $width, ['[data-test=champion-hero]', '[data-test=to-bracket]', '[data-test=standings-first-tie]', '[data-fact=first-tie]'], '[data-test=champion-hero], [data-fact=first-tie]');
        expect(fptText($page, '[data-test=champion-hero]'))->toContain('Geteilter 1. Platz')
            ->and($page->evaluate('() => document.querySelectorAll("[data-test=to-zap-winner], [data-test=tournament-winner]").length'))->toBe(0)
            ->and(fptText($page, '[data-fact=first-tie]'))->toContain('teilt sich Platz 1 und das Preisgeld der belegten Plätze');

        // A tie-break that no longer matches the table: the note on the desk and on the control.
        expect(FirstPlaceTie::isStale($stale))->toBeTrue();
        $page = fptPage($admin, route('tournaments.director', $stale, false), $width);
        $problems += fptMeasure($page, 'desk-stale', $width, ['[data-test=desk-stale-tie]'], '[data-test=desk-stale-tie]');
        $page = fptPage($admin, route('admin.tournaments.edit', $stale, false), $width);
        $problems += fptMeasure($page, 'control-stale', $width, ['[data-test=control-stale-tie]'], '[data-test=control-stale-tie]');
        expect(fptText($page, '[data-test=control-stale-tie]'))->toContain('Der Stichkampf um Platz 1 passt nicht mehr zur Tabelle');
    }

    // Positive control on the last page, same collector: a thrown error, a fetch 404 (console channel) and a broken image (response channel) are all caught.
    $page->evaluate('() => { setTimeout(() => { throw new Error("fpt control"); }); fetch("/fpt-no-such-asset"); const i = new Image(); i.src = "/fpt-no-such-image.png"; document.body.append(i); }');
    BrowserWait::until($page, '() => window.__errors.some((e) => e.includes("fpt control")) && window.__errors.some((e) => e.startsWith("404 ")) && performance.getEntries().some((e) => e.name.endsWith("fpt-no-such-image.png"))', 5_000);
    expect($page->evaluate(BrowserConsole::BAD_RESPONSES))->toHaveCount(1);

    expect($problems)->toBe([]);
});
