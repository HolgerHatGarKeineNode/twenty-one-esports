<?php

/*
| The buy phase (placeOn, undo, the main button): free plebs go first, plebs cost fiat (inflation,
| Euro block, Buy the Dip), every third pleb is free in the Yuan sphere, maxis and ASICs cost sats,
| undo gives back what was paid, and the attack phase waits for the free plebs.
*/

it('places free plebs first, whatever unit is chosen, and holds the phase until they are all set', function (): void {
    $game = hyperPosition(['territories' => ['ny' => [0, 2]], 'seats' => [0 => ['free_plebs' => 3, 'sats' => 9.0]]]);

    $step = $game->apply(0, ['type' => 'deploy', 'territory' => 'ny', 'unit' => 'asic', 'qty' => 2]);

    expect(hyperTerritory($step->game, 'ny'))->toMatchArray(['pleb' => 4, 'asic' => 0])
        ->and($step->game->seatAt(0))->toMatchArray(['free' => 1, 'sats' => 9.0])
        ->and($step->events[0])->toMatchArray(['type' => 'placed', 'source' => 'free', 'count' => 2])
        ->and(hyperRefusal(fn () => $step->game->apply(0, ['type' => 'end_phase'])))->toBe('free_plebs_first');
});

it('prices a pleb at 1 fiat, 1.5 under inflation, 20 % less in the Euro block and half with Buy the Dip', function (): void {
    $euro = hyperZone('euro', 0);

    expect(hyperPosition()->plebCost(0))->toBe(1.0)
        ->and(hyperPosition(['inflation' => true])->plebCost(0))->toBe(1.5)
        ->and(hyperPosition(['territories' => $euro])->plebCost(0))->toBe(0.8)
        ->and(hyperPosition(['territories' => $euro, 'inflation' => true, 'seats' => [0 => ['dip' => true]]])->plebCost(0))->toBe(0.6);
});

it('buys as many plebs as the fiat pays for with "max", and refuses without fiat', function (): void {
    $game = hyperPosition(['territories' => ['ny' => [0, 1]], 'inflation' => true, 'seats' => [0 => ['fiat' => 4.0]]]);

    $step = $game->apply(0, ['type' => 'deploy', 'territory' => 'ny', 'unit' => 'pleb', 'qty' => 'max']);

    expect(hyperTerritory($step->game, 'ny')['pleb'])->toBe(3)
        ->and($step->game->seatAt(0)['fiat'])->toBe(1.0)
        ->and($step->events[0])->toMatchArray(['source' => 'fiat', 'count' => 2, 'cost' => 3.0])
        ->and(hyperRefusal(fn () => $step->game->apply(0, ['type' => 'deploy', 'territory' => 'ny', 'unit' => 'pleb', 'qty' => 1])))->toBe('not_enough_fiat');
});

it('gives every third pleb free in the Yuan sphere, and undo takes the bonus back with its pleb', function (): void {
    $game = hyperPosition(['territories' => hyperZone('yuan', 0), 'seats' => [0 => ['fiat' => 6.0]]]);

    $step = $game->apply(0, ['type' => 'deploy', 'territory' => 'peking', 'unit' => 'pleb', 'qty' => 'max']);
    $undone = $step->game->apply(0, ['type' => 'undo']);

    expect(hyperTerritory($step->game, 'peking')['pleb'])->toBe(1 + 6 + 2)
        ->and($step->events[0])->toMatchArray(['count' => 6, 'bonus' => 2])
        ->and(hyperTerritory($undone->game, 'peking')['pleb'])->toBe(1 + 5 + 1)
        ->and($undone->game->seatAt(0))->toMatchArray(['fiat' => 1.0, 'yuan' => 5]);
});

it('buys up to five maxis or ASICs per click with sats, and undo refunds them', function (): void {
    $game = hyperPosition(['territories' => ['ny' => [0, 1]], 'seats' => [0 => ['sats' => 7.5]]]);

    $step = $game->apply(0, ['type' => 'deploy', 'territory' => 'ny', 'unit' => 'asic', 'qty' => 5]);
    $undone = $step->game->apply(0, ['type' => 'undo']);
    $maxi = $undone->game->apply(0, ['type' => 'deploy', 'territory' => 'ny', 'unit' => 'maxi', 'qty' => 5]);

    expect(hyperTerritory($step->game, 'ny')['asic'])->toBe(2)
        ->and($step->game->seatAt(0)['sats'])->toBe(1.5)
        ->and(hyperRefusal(fn () => $step->game->apply(0, ['type' => 'deploy', 'territory' => 'ny', 'unit' => 'maxi'])))->toBe('not_enough_sats')
        ->and(hyperTerritory($undone->game, 'ny')['asic'])->toBe(1)
        ->and($undone->game->seatAt(0)['sats'])->toBe(4.5)
        ->and(hyperTerritory($maxi->game, 'ny')['maxi'])->toBe(2)
        ->and($maxi->game->seatAt(0)['sats'])->toBe(0.5);
});

it('refuses placements out of turn, out of phase, on foreign ground and in odd amounts', function (int $seat, array $action, string $reason, array $edit): void {
    $game = hyperPosition([...$edit, 'territories' => ['ny' => [0, 1], 'texas' => [1, 1]], 'seats' => [0 => ['fiat' => 5.0]]]);

    expect(hyperRefusal(fn () => $game->apply($seat, $action)))->toBe($reason);
})->with([
    'another seat\'s turn' => [1, ['type' => 'deploy', 'territory' => 'texas'], 'not_your_turn', []],
    'the attack phase' => [0, ['type' => 'deploy', 'territory' => 'ny'], 'wrong_phase', ['phase' => 'attack']],
    'a foreign territory' => [0, ['type' => 'deploy', 'territory' => 'texas'], 'not_your_territory', []],
    'an unknown territory' => [0, ['type' => 'deploy', 'territory' => 'atlantis'], 'unknown_territory', []],
    'zero plebs' => [0, ['type' => 'deploy', 'territory' => 'ny', 'qty' => 0], 'bad_quantity', []],
    'an unknown unit' => [0, ['type' => 'deploy', 'territory' => 'ny', 'unit' => 'whale'], 'unknown_unit', []],
    'nothing to undo' => [0, ['type' => 'undo'], 'nothing_to_undo', []],
    'an unknown action' => [0, ['type' => 'hodl'], 'unknown_action', []],
    'a finished game' => [0, ['type' => 'deploy', 'territory' => 'ny'], 'game_over', ['over' => true, 'winner' => 0]],
]);
