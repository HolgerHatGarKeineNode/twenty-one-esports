<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\Tournament;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| The tournament page renders its islands alone on a push (performance plan P4)
|--------------------------------------------------------------------------
|
| While a tournament runs, a push or the fallback poll calls refreshLive():
| "What to do now" and the board (who plays, the bracket) render, the rest of
| the page (hero, facts, how it works, questions) is not sent again. Whatever
| changes the page around them renders the whole page.
|
*/

beforeEach(fn () => config(['esports.league.nsec' => (new TestSigner)->secret]));

/**
 * @return array{0: Tournament, 1: Testable}
 */
function runningShow(): array
{
    $tournament = runningChess(TournamentFormat::SingleElimination, 4);
    $tournament->forceFill(['published_at' => now()->subDay()])->save();

    return [$tournament, Livewire::test('pages::tournaments.show', ['tournament' => $tournament])];
}

test('a push while the tournament runs answers with the two islands only', function () {
    [, $page] = runningShow();

    $page->call('refreshLive')->assertOk();
    $fragments = $page->effects['islandFragments'] ?? [];

    expect($page->effects['html'] ?? null)->toBeNull()
        ->and($fragments)->toHaveCount(2)
        ->and($fragments[0])->toContain('FRAGMENT:type=island|name=now')
        ->and($fragments[1])->toContain('FRAGMENT:type=island|name=board')
        ->and($fragments[1])->toContain('data-test="entries"')
        ->and($fragments[1])->toContain('data-test="bracket"')
        ->and(implode('', $fragments))->not->toContain('data-test="tournament-hero"')
        ->and(implode('', $fragments))->not->toContain('data-test="how-it-works"')
        ->and(implode('', $fragments))->not->toContain('data-test="faq"');
});

test('a push renders the whole page once the tournament has ended', function () {
    [$tournament, $page] = runningShow();

    $tournament->forceFill(['status' => TournamentStatus::Finished])->save();
    $page->call('refreshLive')->assertOk();

    expect($page->effects['islandFragments'] ?? [])->toBe([])
        ->and($page->effects['html'])->toContain('data-test="tournament-hero"');
});

test('a push during sign-up renders the whole page', function () {
    $page = Livewire::test('pages::tournaments.show', ['tournament' => openTournament()]);

    $page->call('refreshLive')->assertOk();

    expect($page->effects['islandFragments'] ?? [])->toBe([])
        ->and($page->effects['html'])->toContain('data-test="tournament-hero"');
});

test('a full refresh still renders the islands with the page', function () {
    [, $page] = runningShow();

    $page->call('$refresh')->assertOk();

    expect($page->effects['html'])->toContain('data-test="tournament-hero"')
        ->and($page->effects['html'])->toContain('data-test="bracket"')
        ->and($page->effects['html'])->toContain('FRAGMENT:type=island|name=board');
});

/*
 * The tournament form (order E, 2026-11-05) stands in four chooser islands (game, the time you have, players and place,
 * format) plus the summary and the deadlines, and on the create page the start vote (PR #26): a change in any of them
 * renders all seven, so a game picked updates the systems and whether the start can be put to a vote, a player count
 * the recommendation, a results mode the deadlines.
 */
test('a chooser change on the tournament form renders all seven of its islands', function (string $method, array $arguments) {
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);
    $form = Livewire::actingAs($admin)->test('pages::admin.tournament-create');

    $form->call($method, ...$arguments)->assertOk();
    preg_match_all('/FRAGMENT:type=island\|name=([\w-]+)/', implode('', $form->effects['islandFragments'] ?? []), $names);

    expect(array_values(array_unique($names[1])))->toEqualCanonicalizing(['chooser-game', 'chooser-window', 'chooser-place', 'chooser', 'summary', 'deadlines', 'start-vote']);
})->with([
    'a game picked' => ['pickGame', ['rapid']],
    'one player more' => ['stepPlayers', [1]],
    'directors enter results' => ['pickResultsMode', ['director']],
]);
