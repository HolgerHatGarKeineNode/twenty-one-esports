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

test('the browser games lead, Proof of Pong second, then the own-copy games, a game added later after them (R19)', function () {
    $registered = array_map(orderGame(...), ['chess', 'rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26', 'age-of-empires-2', 'nine-mens-morris', 'checkers', 'blockli', 'blockspace', 'go-9', 'go-13', 'go-19', 'blockfill', 'tmnf', 'hyperbitcoinization', 'proof-of-pong', 'new-game']);

    $ordered = GameRegistry::ordered($registered, (array) config('esports.game_order.first'), (array) config('esports.game_order.last'));

    expect(array_map(fn (Game $game): string => $game->slug(), $ordered))->toBe([
        'hyperbitcoinization', 'proof-of-pong', 'chess', 'blockfill', 'blockli', 'blockspace', 'nine-mens-morris', 'checkers',
        // Go (DerCaddy, 2026-10-27): a browser game, its three sizes after Checkers.
        'go-9', 'go-13', 'go-19',
        'rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26', 'age-of-empires-2', 'tmnf', 'new-game',
    ]);
});

test('a slug in the order whose game is switched off leaves no gap', function () {
    $ordered = GameRegistry::ordered(array_map(orderGame(...), ['chess', 'rocket-league']), ['chess', 'blockfill', 'rocket-league'], ['checkers']);

    expect(array_map(fn (Game $game): string => $game->slug(), $ordered))->toBe(['chess', 'rocket-league']);
});
