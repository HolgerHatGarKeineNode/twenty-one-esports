<?php

use App\Support\Hyper\HyperStep;

/*
| Around a turn (beginTurn, endTurn, the move dialog, checkWin): income from territories, banks,
| mines and complete currency spaces, fiat decay or interest, the card after a conquest, one fortify
| move (two in South America), the round limit and its ranking.
*/

/**
 * Ends seat 0's turn from a hand-made position and returns seat 1's start of turn.
 *
 * @param  array<string, mixed>  $edit
 */
function hyperNextTurn(array $edit): HyperStep
{
    return hyperPosition([...$edit, 'phase' => 'fortify'])->apply(0, ['type' => 'end_phase']);
}

it('pays fiat for every 3 territories (at least 3) and 2 per bank, sats for mines and sats interest', function (): void {
    $land = ['kolumbien', 'paraguay', 'argentinien', 'island', 'westeuropa', 'skandi', 'osteuropa', 'mittelmeer'];
    $step = hyperNextTurn(['territories' => [...array_fill_keys($land, [1, 1]), 'london' => [1, 1]], 'seats' => [1 => ['sats' => 10.0, 'fiat' => 1.0]]]);

    // 9 territories -> 3, one bank -> +2; two mines -> 1 sats, plus 3 % on the 10 sats held.
    expect(hyperEvents($step->events, 'turn_started')[0])->toMatchArray(['seat' => 1, 'fiat' => 5.0, 'sats' => 1.3, 'free_plebs' => 0])
        ->and($step->game->seatAt(1))->toMatchArray(['fiat' => 6.0, 'sats' => 11.3, 'loot' => 1.3]);
});

it('adds the currency space bonuses to the income: Dollar +25 %, South America +2 fiat, Afro-Union 2 free plebs', function (): void {
    $dollar = hyperNextTurn(['territories' => hyperZone('dollar', 1)])->game->seatAt(1);
    $sa = hyperNextTurn(['territories' => hyperZone('sa', 1)])->game->seatAt(1);
    $afro = hyperNextTurn(['territories' => hyperZone('afro', 1)])->game->seatAt(1);

    // Dollar: (3 + 2 for New York) × 1.25 = 6.25 -> 6, and 2 sats for the space plus 0.5 for Texas.
    expect($dollar)->toMatchArray(['fiat' => 6.0, 'sats' => 2.5])
        ->and($sa)->toMatchArray(['fiat' => 3.0 + 2 + 2, 'sats' => 1.5])
        ->and($afro)->toMatchArray(['fiat' => 4.0 + 2, 'free' => 2, 'sats' => 3.5]);
});

it('lets 15 % of the fiat rot at the end of the turn, pays 10 % interest in London and keeps it in Japan', function (): void {
    $plain = hyperPosition(['phase' => 'fortify', 'seats' => [0 => ['fiat' => 10.0]]])->apply(0, ['type' => 'end_phase']);
    $pound = hyperPosition(['phase' => 'fortify', 'territories' => hyperZone('pound', 0), 'seats' => [0 => ['fiat' => 10.0]]])->apply(0, ['type' => 'end_phase']);
    $yen = hyperPosition(['phase' => 'fortify', 'territories' => hyperZone('yen', 0), 'seats' => [0 => ['fiat' => 10.0]]])->apply(0, ['type' => 'end_phase']);

    expect($plain->game->seatAt(0)['fiat'])->toBe(8.5)
        ->and(hyperEvents($plain->events, 'fiat_decayed')[0]['amount'])->toBe(1.5)
        ->and($pound->game->seatAt(0)['fiat'])->toBe(11.0)
        ->and($yen->game->seatAt(0)['fiat'])->toBe(10.0);
});

it('draws a card after a turn with a conquest, unless five are in hand', function (): void {
    $step = hyperPosition(['phase' => 'fortify', 'seats' => [0 => ['conquered' => 1, 'hand' => ['dip']]]])->apply(0, ['type' => 'end_phase']);
    $full = hyperPosition(['phase' => 'fortify', 'seats' => [0 => ['conquered' => 1, 'hand' => ['dip', 'dip', 'keys', 'keys', 'pizza']]]])->apply(0, ['type' => 'end_phase']);
    $drawn = hyperEvents($step->events, 'card_drawn')[0]['card'];

    expect($step->game->seatAt(0)['hand'])->toBe(['dip', $drawn])
        ->and($full->game->seatAt(0)['hand'])->toHaveCount(5)
        ->and(hyperEvents($full->events, 'card_drawn'))->toBe([]);
});

it('allows one fortify move between neighbours, two when holding South America', function (): void {
    $once = hyperPosition(['phase' => 'fortify', 'territories' => ['ny' => [0, 5], 'texas' => [0, 1], 'kanada' => [0, 1]]])
        ->apply(0, ['type' => 'fortify', 'from' => 'ny', 'to' => 'texas', 'count' => 4]);
    $twice = hyperPosition(['phase' => 'fortify', 'territories' => [...hyperZone('sa', 0), 'brasilia' => [0, 5]]])
        ->apply(0, ['type' => 'fortify', 'from' => 'brasilia', 'to' => 'paraguay', 'count' => 1])->game
        ->apply(0, ['type' => 'fortify', 'from' => 'brasilia', 'to' => 'argentinien', 'count' => 1]);

    expect(hyperTerritory($once->game, 'texas')['pleb'])->toBe(5)
        ->and(hyperRefusal(fn () => $once->game->apply(0, ['type' => 'fortify', 'from' => 'texas', 'to' => 'ny', 'count' => 1])))->toBe('already_fortified')
        ->and(hyperTerritory($twice->game, 'brasilia')['pleb'])->toBe(3)
        ->and(hyperRefusal(fn () => $twice->game->apply(0, ['type' => 'fortify', 'from' => 'brasilia', 'to' => 'paraguay', 'count' => 1])))->toBe('already_fortified');
});

it('refuses fortify moves that leave nothing behind or skip a border', function (array $action, string $reason): void {
    $game = hyperPosition(['phase' => 'fortify', 'territories' => ['ny' => [0, 5], 'texas' => [0, 1], 'london' => [0, 1], 'irland' => [1, 1]]]);

    expect(hyperRefusal(fn () => $game->apply(0, ['type' => 'fortify', ...$action])))->toBe($reason);
})->with([
    'all units' => [['from' => 'ny', 'to' => 'texas', 'count' => 5], 'bad_count'],
    'no units' => [['from' => 'ny', 'to' => 'texas', 'count' => 0], 'bad_count'],
    'not a neighbour' => [['from' => 'ny', 'to' => 'london', 'count' => 1], 'not_adjacent'],
    'into foreign land' => [['from' => 'ny', 'to' => 'irland', 'count' => 1], 'not_your_territory'],
]);

it('ends the turn from any phase for the turn timer, keeping what was placed', function (): void {
    $game = hyperPosition(['territories' => ['ny' => [0, 1], 'texas' => [1, 1]], 'seats' => [0 => ['free_plebs' => 4]]]);

    $step = $game->apply(0, ['type' => 'deploy', 'territory' => 'ny', 'qty' => 2])->game->apply(0, ['type' => 'end_turn']);

    expect($step->game->currentSeat())->toBe(1)
        ->and($step->game->phase())->toBe('buy')
        ->and(hyperTerritory($step->game, 'ny')['pleb'])->toBe(3)
        ->and($step->game->seatAt(0)['free'])->toBe(2)
        ->and(array_column($step->events, 'type'))->toBe(['turn_ended', 'turn_started', 'loot_gained']);
});

it('ranks at the round limit by central banks, then territories, then units', function (): void {
    $game = hyperPosition([
        'limit' => 12, 'round' => 12, 'seat' => 2, 'phase' => 'fortify',
        'territories' => ['ny' => [0, 1], 'london' => [1, 1], 'irland' => [1, 1], 'frankfurt' => [2, 9]],
    ]);

    $step = $game->apply(2, ['type' => 'end_phase']);

    expect($step->game->isOver())->toBeTrue()
        ->and($step->game->wonByLimit())->toBeTrue()
        ->and($step->game->winner())->toBe(1)
        ->and(hyperEvents($step->events, 'game_won')[0])->toMatchArray(['seat' => 1, 'by_limit' => true, 'round' => 12, 'standings' => [1, 2, 0]])
        ->and(hyperRefusal(fn () => $step->game->apply(0, ['type' => 'end_phase'])))->toBe('game_over');
});

it('plays on past the limit round only when the limit is not reached yet', function (): void {
    $step = hyperPosition(['limit' => 12, 'round' => 11, 'seat' => 2, 'phase' => 'fortify', 'territories' => ['ny' => [0, 1], 'london' => [1, 1], 'frankfurt' => [2, 1]]])
        ->apply(2, ['type' => 'end_phase']);

    expect($step->game->isOver())->toBeFalse()
        ->and($step->game->round())->toBe(12)
        ->and(hyperEvents($step->events, 'round_started')[0])->toBe(['type' => 'round_started', 'round' => 12, 'inflation' => false]);
});
