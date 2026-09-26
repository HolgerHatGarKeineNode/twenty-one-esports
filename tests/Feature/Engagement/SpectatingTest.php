<?php

use App\Models\ChessGame;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use Livewire\Livewire;

/*
 * Spectating (P10): a guest finds every live game on /games and reads a live
 * game move by move; the game page and its public watch channel existed
 * before (P5a), the list and the guest's state read are the gap closed here.
 */

test('a guest sees every live game on the live games page, and no finished one', function () {
    $blitz = ChessGame::factory()->create();
    $daily = ChessGame::factory()->daily()->create();
    $finished = ChessGame::factory()->finished()->create();

    $this->get(route('games.index'))->assertOk()
        ->assertSee(route('games.show', $blitz), false)
        ->assertSee(route('games.show', $daily), false)
        ->assertDontSee(route('games.show', $finished), false)
        ->assertSee($blitz->white->displayName());

    $this->get(route('chess.lobby'))->assertOk()->assertSee('data-test="all-live-games"', false);
});

test('a guest reads a live game\'s state move by move but cannot act in it', function () {
    $game = ChessGame::factory()->create();
    app(ChessGameService::class)->move($game, $game->white, 'e2e4');

    $page = Livewire::test('pages::games.show', ['game' => $game->refresh()])->assertOk()->call('fetchState')->assertOk();
    $state = $page->instance()->fetchState();

    expect($state['ply'])->toBe(1)
        ->and(array_column($state['moves'], 'uci'))->toBe(['e2e4'])
        ->and($page->instance()->move('e7e5', 1)['ok'])->toBeFalse()
        ->and($page->instance()->recordTemplate())->toBeNull()
        ->and($game->refresh()->ply)->toBe(1);
});

test('the live games page is linked from the games menu and the mobile menu', function () {
    $this->actingAs(User::factory()->create())->get(route('home'))->assertOk()
        ->assertSee('data-test="games-menu-live"', false)
        ->assertSee('data-test="mobile-live-games"', false);
});
