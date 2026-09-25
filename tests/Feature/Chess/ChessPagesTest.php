<?php

use App\Models\ChessGame;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use Livewire\Livewire;

test('every chess page survives a Livewire roundtrip', function (string $page, bool $guest, Closure $params) {
    $user = User::factory()->create();
    $component = $guest ? Livewire::test($page, $params($user)) : Livewire::actingAs($user)->test($page, $params($user));

    $component->assertOk()->call('$refresh')->assertOk();
})->with([
    'lobby, guest' => ['pages::chess.lobby', true, fn () => []],
    'lobby, player' => ['pages::chess.lobby', false, fn () => []],
    'live game, player' => ['pages::games.show', false, fn (User $user) => ['game' => ChessGame::factory()->create(['white_id' => $user->id])]],
    'live game, spectator' => ['pages::games.show', true, fn () => ['game' => ChessGame::factory()->create()]],
    'finished game' => ['pages::games.show', true, fn () => ['game' => ChessGame::factory()->finished()->create()]],
]);

test('a reconnecting client gets back the exact position and both clocks', function () {
    $this->freezeTime();
    $game = ChessGame::factory()->create();
    $service = app(ChessGameService::class);

    foreach (['e2e4', 'c7c5', 'g1f3'] as $i => $uci) {
        $this->travel(7)->seconds();
        $game->refresh();
        $service->move($game, $i % 2 === 0 ? $game->white : $game->black, $uci);
    }

    // Black has been thinking for 5 s when the connection comes back.
    $this->travel(5)->seconds();

    Livewire::actingAs($game->black)->test('pages::games.show', ['game' => $game])
        ->call('fetchState')
        ->assertReturned(fn (array $state) => $state['fen'] === 'rnbqkbnr/pp1ppppp/8/2p5/4P3/5N2/PPPP1PPP/RNBQKB1R b KQkq - 1 2'
            && $state['ply'] === 3
            && $state['turn'] === 'b'
            && array_column($state['moves'], 'san') === ['e4', 'c5', 'Nf3']
            && $state['clock']['w'] === 300_000 - 7_000 + 3_000
            && $state['clock']['b'] === 300_000 - 5_000
            && $state['clock']['running'] === 'b');
});

test('the game page refuses a move from someone who does not play', function () {
    $game = ChessGame::factory()->create();

    Livewire::actingAs(User::factory()->create())->test('pages::games.show', ['game' => $game])
        ->call('move', 'e2e4', 1)
        ->assertReturned(fn (array $response) => $response['ok'] === false && $response['error'] === 'not_a_player' && $response['state']['ply'] === 0);
});
