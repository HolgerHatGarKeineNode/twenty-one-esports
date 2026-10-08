<?php

use App\Support\Hyper\HyperBot;
use App\Support\Hyper\HyperGame;

/*
| The state the server stores between actions: through JSON and back it plays on exactly as before,
| an action never touches the state it was applied to, and broken data is refused.
*/

it('plays on identically after a round trip through JSON', function (): void {
    $seats = [['faction' => 'fed'], ['faction' => 'goldbug'], ['faction' => 'nocoiner'], ['faction' => 'ezb']];
    $game = HyperGame::start($seats, 0, 314)->game;

    for ($turn = 0; $turn < 9; $turn++) {
        $game = HyperBot::playTurn($game)->game;
    }

    $restored = HyperGame::fromArray(json_decode(json_encode($game->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));

    expect($restored->toArray())->toEqual($game->toArray())
        ->and(HyperBot::playGame($restored)->game->toArray())->toBe(HyperBot::playGame($game)->game->toArray());
});

it('leaves the game an action was applied to as it was', function (): void {
    $game = hyperPosition(['phase' => 'attack', 'territories' => ['ny' => [0, 30], 'irland' => [1, 1]], 'seats' => [1 => ['hand' => ['dip']]]]);
    $before = $game->toArray();

    $game->apply(0, ['type' => 'attack', 'from' => 'ny', 'to' => 'irland', 'mode' => 'blitz']);
    hyperRefusal(fn () => $game->apply(0, ['type' => 'fortify', 'from' => 'ny', 'to' => 'irland', 'count' => 1]));

    expect($game->toArray())->toBe($before);
});

it('refuses data that is no game state', function (array $change): void {
    $state = [...hyperPosition()->toArray(), ...$change];

    expect(fn () => HyperGame::fromArray($state))->toThrow(InvalidArgumentException::class);
})->with([
    'another version' => [['version' => 2]],
    'an unknown phase' => [['phase' => 'loot']],
    'a seat out of range' => [['seat' => 3]],
    'a broken RNG' => [['rng' => [1, 2]]],
    'a card that does not exist' => [['deck' => ['hodl']]],
    'a missing territory' => [['territories' => ['ny' => ['owner' => 0, 'pleb' => 1, 'maxi' => 0, 'asic' => 0, 'shield' => null]]]],
    'a single seat' => [['seats' => [['faction' => 'fed']]]],
]);

it('tells the page what the seat to move can do', function (): void {
    $buy = hyperPosition(['territories' => ['ny' => [0, 3], 'texas' => [0, 1], 'irland' => [1, 1]], 'seats' => [0 => ['fiat' => 1.0, 'sats' => 2.0, 'hand' => ['nokeys', 'dip']]]]);
    $attack = hyperPosition(['phase' => 'attack', 'territories' => ['ny' => [0, 3], 'texas' => [0, 1], 'irland' => [1, 1]]]);

    expect($buy->legal())->toMatchArray([
        'phase' => 'buy',
        'deploy' => ['texas', 'ny'],
        'affordable' => ['pleb' => true, 'maxi' => true, 'asic' => false],
        'cards' => ['nokeys' => ['irland'], 'dip' => null],
        'can_undo' => false,
        'can_end_phase' => true,
        'attack' => [],
    ])
        ->and($attack->legal()['attack'])->toBe(['ny' => ['kanada', 'mexiko', 'irland']])
        ->and($attack->legal()['deploy'])->toBe([]);
});
