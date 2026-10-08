<?php

use App\Support\Hyper\HyperGame;

/*
| The attack phase (battleRound, doRoll, conquest, the move dialog): dice pairs highest first with the
| defender winning ties, the defence bonuses, blitz, the move-in after a conquest, a fallen bank, a
| completed currency space, and a seat thrown out with its cards going to the conqueror.
*/

it('rolls up to three dice against two, compares them highest first and takes the losses', function (): void {
    $game = hyperPosition(['phase' => 'attack', 'territories' => ['ny' => [0, 10], 'irland' => [1, 5]]]);

    $step = $game->apply(0, ['type' => 'attack', 'from' => 'ny', 'to' => 'irland', 'mode' => 'roll']);
    $dice = $step->events[0];

    expect($step->events)->toHaveCount(1)
        ->and($dice['type'])->toBe('dice_rolled')
        ->and($dice['attacker'])->toHaveCount(3)
        ->and($dice['defender'])->toHaveCount(2)
        ->and($dice['attacker'])->toBe(array_values(collect($dice['attacker'])->sortDesc()->all()))
        ->and($dice['attacker_losses'] + $dice['defender_losses'])->toBe(2)
        ->and($dice['attacker_losses'])->toBe(count(array_filter([0, 1], fn (int $k): bool => $dice['attacker_total'][$k] <= $dice['defender_total'][$k])))
        ->and([$dice['attacker_units'], $dice['defender_units']])->toBe([10 - $dice['attacker_losses'], 5 - $dice['defender_losses']]);
});

it('adds the defence bonuses to the highest die: bank (not against an ASIC), maxi, vault, Rubel space, Diamond Hands', function (): void {
    $plain = hyperPosition(['territories' => ['irland' => [1, 1]]]);
    $bank = hyperPosition(['territories' => ['london' => [1, 1, 1, 0, 1]]]);
    $rubel = hyperPosition(['territories' => hyperZone('rubel', 1)]);

    expect($plain->defenseBonus(HyperGame::territoryIndex('irland'), false))->toBe(0)
        ->and($bank->defenseBonus(HyperGame::territoryIndex('london'), false))->toBe(3)
        ->and($bank->defenseBonus(HyperGame::territoryIndex('london'), true))->toBe(2)
        ->and($plain->defenseBonus(HyperGame::territoryIndex('zuerich'), false))->toBe(2)
        ->and($rubel->defenseBonus(HyperGame::territoryIndex('sibirien'), false))->toBe(1);
});

it('gives an attacking ASIC +1 on its highest die', function (): void {
    $game = hyperPosition(['phase' => 'attack', 'territories' => ['ny' => [0, 3, 0, 1], 'irland' => [1, 2]]]);

    $dice = $game->apply(0, ['type' => 'attack', 'from' => 'ny', 'to' => 'irland'])->events[0];

    expect($dice['attacker_total'][0])->toBe($dice['attacker'][0] + 1)
        ->and(array_slice($dice['attacker_total'], 1))->toBe(array_slice($dice['attacker'], 1));
});

it('blitzes until the territory falls, moves the dice in and waits for the move-in', function (): void {
    $game = hyperPosition(['phase' => 'attack', 'territories' => ['ny' => [0, 40], 'irland' => [null, 1]]]);

    $step = $game->apply(0, ['type' => 'attack', 'from' => 'ny', 'to' => 'irland', 'mode' => 'blitz']);
    $conquered = hyperEvents($step->events, 'territory_conquered')[0];
    $pending = $step->game->pendingMove();

    expect($conquered)->toMatchArray(['territory' => 'irland', 'previous_owner' => null, 'units' => 3])
        ->and($pending)->toBe(['from' => 'ny', 'to' => 'irland', 'max' => hyperTerritory($step->game, 'ny')['pleb'] - 1])
        ->and(hyperRefusal(fn () => $step->game->apply(0, ['type' => 'end_phase'])))->toBe('move_in_pending')
        ->and(hyperRefusal(fn () => $step->game->apply(0, ['type' => 'move_in', 'count' => $pending['max'] + 1])))->toBe('bad_count');

    $moved = $step->game->apply(0, ['type' => 'move_in', 'count' => $pending['max']]);

    expect(hyperTerritory($moved->game, 'ny')['pleb'])->toBe(1)
        ->and($moved->game->pendingMove())->toBeNull()
        ->and($moved->events[0])->toMatchArray(['type' => 'moved', 'kind' => 'conquest', 'count' => $pending['max']]);
});

it('pays 1 sats as loot for a fallen bank and names a completed currency space', function (): void {
    $game = hyperPosition(['phase' => 'attack', 'territories' => ['tokio' => [null, 1], 'seoul' => [0, 40]]]);

    $step = $game->apply(0, ['type' => 'attack', 'from' => 'seoul', 'to' => 'tokio', 'mode' => 'blitz']);

    expect(hyperEvents($step->events, 'bank_fallen'))->toHaveCount(1)
        ->and(hyperEvents($step->events, 'zone_completed')[0])->toMatchArray(['seat' => 0, 'zone' => 'yen'])
        ->and($step->game->seatAt(0))->toMatchArray(['sats' => 1.0, 'loot' => 1.0, 'conquered' => 1]);
});

it('throws a seat out with its last territory and hands its cards over, seven at most', function (): void {
    $game = hyperPosition(['phase' => 'attack', 'territories' => ['ny' => [0, 40], 'irland' => [1, 1]], 'seats' => [0 => ['hand' => ['dip', 'dip', 'keys', 'keys', 'pizza']], 1 => ['hand' => ['scam', 'lagarde', 'brrrr']]]]);

    $step = $game->apply(0, ['type' => 'attack', 'from' => 'ny', 'to' => 'irland', 'mode' => 'blitz']);

    expect(hyperEvents($step->events, 'player_eliminated')[0])->toMatchArray(['seat' => 1, 'by' => 0, 'cards' => 3])
        ->and($step->game->seatAt(1))->toMatchArray(['out' => true, 'hand' => []])
        ->and($step->game->seatAt(0)['hand'])->toBe(['dip', 'dip', 'keys', 'keys', 'pizza', 'scam', 'lagarde'])
        ->and($step->game->isOver())->toBeFalse();
});

it('ends the game the moment the last rival falls', function (): void {
    $game = hyperPosition(['phase' => 'attack', 'territories' => ['ny' => [0, 40], 'irland' => [1, 1]], 'seats' => [2 => ['out' => true]]]);

    $step = $game->apply(0, ['type' => 'attack', 'from' => 'ny', 'to' => 'irland', 'mode' => 'blitz']);

    expect($step->game->isOver())->toBeTrue()
        ->and($step->game->winner())->toBe(0)
        ->and($step->game->pendingMove())->toBeNull()
        ->and(hyperEvents($step->events, 'game_won')[0])->toMatchArray(['seat' => 0, 'by_limit' => false]);
});

it('refuses attacks the page does not allow', function (array $action, string $reason): void {
    $game = hyperPosition(['phase' => 'attack', 'territories' => ['ny' => [0, 5], 'texas' => [0, 1], 'mexiko' => [1, 2], 'irland' => [1, 2]]]);

    expect(hyperRefusal(fn () => $game->apply(0, ['type' => 'attack', ...$action])))->toBe($reason);
})->with([
    'from a foreign territory' => [['from' => 'mexiko', 'to' => 'texas'], 'not_your_territory'],
    'with a single unit' => [['from' => 'texas', 'to' => 'mexiko'], 'too_few_units'],
    'a territory beyond the border' => [['from' => 'ny', 'to' => 'london'], 'not_adjacent'],
    'an own territory' => [['from' => 'ny', 'to' => 'texas'], 'own_territory'],
    'in an unknown mode' => [['from' => 'ny', 'to' => 'irland', 'mode' => 'yolo'], 'unknown_mode'],
]);
