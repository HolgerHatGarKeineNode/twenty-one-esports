<?php

use App\Games\Contracts\Game;
use App\Games\GameRegistry;

/** A stand-in game with nothing but a slug: the order only reads the slug. */
function orderGame(string $slug): Game
{
    $game = Mockery::mock(Game::class);
    $game->allows('slug')->andReturn($slug);

    return $game;
}

test('games show chess, Rocket League and Blockfill first, Nine Men\'s Morris and Checkers last, the rest as registered', function () {
    $registered = array_map(orderGame(...), ['chess', 'rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26', 'age-of-empires-2', 'nine-mens-morris', 'checkers', 'blockfill', 'new-game']);

    $ordered = GameRegistry::ordered($registered, (array) config('esports.game_order.first'), (array) config('esports.game_order.last'));

    expect(array_map(fn (Game $game): string => $game->slug(), $ordered))->toBe([
        'chess', 'rocket-league', 'blockfill', 'ea-sports-fc-27', 'ea-sports-fc-26', 'age-of-empires-2', 'new-game', 'nine-mens-morris', 'checkers',
    ]);
});

test('a slug in the order whose game is switched off leaves no gap', function () {
    $ordered = GameRegistry::ordered(array_map(orderGame(...), ['chess', 'rocket-league']), ['chess', 'blockfill', 'rocket-league'], ['checkers']);

    expect(array_map(fn (Game $game): string => $game->slug(), $ordered))->toBe(['chess', 'rocket-league']);
});
