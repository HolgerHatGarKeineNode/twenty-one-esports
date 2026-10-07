<?php

/*
| The admin pages with a game selection offer the board games (plan "Mühle
| und Dame", P6) while they are switched on: the weekly events, the
| tournament chooser of the create and edit pages (TournamentGames), with the
| board games' own wording where a format cannot run and in the deadlines.
| The other admin pages select no game: fair play, status and the tournament
| list only show the game a record already has.
*/

use App\Enums\TournamentFormat;
use App\Models\Admin;
use App\Models\User;
use App\Models\WeeklySlot;
use App\Support\Tournaments\Estimator;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentGames;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Support\CheckersGame;
use Tests\Support\NineMensMorrisOn;

function boardPagesAdmin(): User
{
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

test('switched off, no admin page offers a board game; switched on, the weekly events and the tournament chooser do', function () {
    expect(array_keys(Livewire::actingAs(boardPagesAdmin())->test('pages::admin.events')->instance()->ladders))->not->toContain('checkers/blitz')
        ->and(array_column(TournamentGames::grouped(), 'slug'))->not->toContain('checkers');

    NineMensMorrisOn::play();
    CheckersGame::play();

    expect(array_keys(Livewire::actingAs(boardPagesAdmin())->test('pages::admin.events')->instance()->ladders))->toContain('nine-mens-morris/correspondence', 'checkers/correspondence', 'chess/blitz')->not->toContain('nine-mens-morris/blitz')->not->toContain('checkers/blitz')
        ->and(TournamentGames::find('checkers/correspondence'))->toBe(['checkers', 'correspondence'])
        // Correspondence only since 2026-10-07: no blitz tournament of a board game.
        ->and(TournamentGames::find('checkers/blitz'))->toBeNull()
        ->and(array_column(TournamentGames::grouped(), 'slug'))->toContain('nine-mens-morris', 'checkers');

    Livewire::actingAs(boardPagesAdmin())->test('pages::admin.tournament-create')
        ->assertSee('data-test="game-row-nine-mens-morris"', false)
        ->assertSee('data-test="game-row-checkers"', false)
        ->assertSee('Chess and board games: first move within (minutes)');
});

test('a weekly event can be set for a board game', function () {
    CheckersGame::play();

    Livewire::actingAs(boardPagesAdmin())->test('pages::admin.events')
        ->set('title', 'Dame-Abend')
        ->set('ladder', 'checkers/correspondence')
        ->call('add')
        ->assertHasNoErrors();

    expect(WeeklySlot::query()->sole()->only(['game', 'mode']))->toBe(['game' => 'checkers', 'mode' => 'correspondence']);
});

test('a format that cannot run says why in board game words, never as a series', function () {
    CheckersGame::play();
    $estimator = new Estimator;
    $board = GameProfile::for('checkers', 'correspondence');

    expect($estimator->disabledReason(TournamentFormat::FreeForAll, $board, 8))->toBe('Needs 3 or more players in one match. A board game is always one player against one.')
        ->and($estimator->disabledReason(TournamentFormat::Leaderboard, $board, 8))->toBe('Needs a game with a score or time you play alone, like a time trial. Board games are won against an opponent.')
        // Chess and a series keep theirs.
        ->and($estimator->disabledReason(TournamentFormat::FreeForAll, GameProfile::for('chess', 'blitz'), 8))->toBe('Needs 3 or more players in one match. Chess is always one player against one.')
        ->and($estimator->disabledReason(TournamentFormat::FreeForAll, GameProfile::for('rocket-league', '3v3'), 8))->toBe('Needs 3 or more players in one match. A series is always one side against the other.');
});

test('the rules page names the share group of the board games only while a board game is switched on', function () {
    $this->get(route('rules'))->assertOk()
        ->assertSee('Both EA Sports FC editions share one share and one daily limit.')
        ->assertDontSee('and so do the board games');

    CheckersGame::play();
    Cache::flush();

    $this->get(route('rules'))->assertOk()->assertSee('and so do the board games nine men', false);
});
