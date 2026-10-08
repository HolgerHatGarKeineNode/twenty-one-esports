<?php

use App\Support\Hyper\HyperGame;

/*
| The ten event cards (playCard, cardTargetOk), each with the effect the page gives it, played from
| the hand in any phase of one's own turn; a card with a target needs a fitting one.
*/

/**
 * Seat 0 (ny, texas) against seat 1 (irland, london) and seat 2 (mexiko), holding the card.
 *
 * @param  array<string, mixed>  $edit
 */
function hyperCardGame(string $card, array $edit = []): HyperGame
{
    return hyperPosition([
        'territories' => ['ny' => [0, 5], 'texas' => [0, 1], 'irland' => [1, 3], 'london' => [1, 4], 'mexiko' => [2, 1], ...($edit['territories'] ?? [])],
        'seats' => [0 => ['hand' => [$card], 'fiat' => 2.0, 'sats' => 10.0], 1 => ['sats' => 20.0], 2 => ['sats' => 30.0], ...($edit['seats'] ?? [])],
    ]);
}

/**
 * @return array{game: HyperGame, effect: array<string, mixed>, events: list<array<string, mixed>>}
 */
function hyperPlay(string $card, ?string $target = null, array $edit = []): array
{
    $step = hyperCardGame($card, $edit)->apply(0, ['type' => 'play_card', 'card' => $card, 'target' => $target]);

    return ['game' => $step->game, 'effect' => hyperEvents($step->events, 'card_played')[0]['effect'], 'events' => $step->events];
}

it('Notenpresse: 8 free plebs now, inflation next round', function (): void {
    ['game' => $game] = hyperPlay('brrrr');

    expect($game->seatAt(0)['free'])->toBe(8)
        ->and($game->seatAt(0)['hand'])->toBe([])
        ->and($game->toArray()['inflation_next'])->toBeTrue();
});

it('51%-Attacke: takes a neighbour of at most 3 units without dice, from the strongest own neighbour', function (): void {
    ['game' => $game, 'effect' => $effect, 'events' => $events] = hyperPlay('attack51', 'irland');

    expect($effect)->toBe(['from' => 'ny'])
        ->and(hyperTerritory($game, 'irland'))->toMatchArray(['owner' => 0, 'pleb' => 1])
        ->and(hyperTerritory($game, 'ny')['pleb'])->toBe(4)
        ->and(hyperEvents($events, 'territory_conquered'))->toHaveCount(1);
});

it('51%-Attacke: a lone neighbour takes it without leaving home, and a loser without land is out', function (): void {
    $step = hyperPosition([
        'territories' => ['ny' => [0, 1], 'texas' => [0, 1], 'irland' => [1, 3], 'mexiko' => [2, 1]],
        'seats' => [0 => ['hand' => ['attack51']], 2 => ['hand' => ['pizza']]],
    ])->apply(0, ['type' => 'play_card', 'card' => 'attack51', 'target' => 'mexiko']);
    ['game' => $game, 'events' => $events] = ['game' => $step->game, 'events' => $step->events];

    expect(hyperTerritory($game, 'mexiko'))->toMatchArray(['owner' => 0, 'pleb' => 1])
        ->and(hyperTerritory($game, 'texas')['pleb'])->toBe(1)
        ->and($game->seatAt(0)['conquered'])->toBe(1)
        ->and($game->seatAt(2)['out'])->toBeTrue()
        ->and($game->seatAt(0)['hand'])->toBe(['pizza'])
        ->and(hyperEvents($events, 'player_eliminated'))->toBe([['type' => 'player_eliminated', 'seat' => 2, 'by' => 0, 'cards' => 1]]);
});

it('Lost Keys: the richest rival loses 30 % of his sats', function (): void {
    ['game' => $game, 'effect' => $effect] = hyperPlay('keys');

    expect($effect)->toBe(['victim' => 2, 'sats_lost' => 9.0])
        ->and($game->seatAt(2)['sats'])->toBe(21.0)
        ->and($game->seatAt(1)['sats'])->toBe(20.0);
});

it('Diamond Hands: +1 defence on an own territory until the next own turn', function (): void {
    ['game' => $game] = hyperPlay('diamond', 'texas');
    $texas = HyperGame::territoryIndex('texas');

    expect($game->defenseBonus($texas, false))->toBe(1)
        ->and($game->apply(0, ['type' => 'end_turn'])->game->apply(1, ['type' => 'end_turn'])->game->apply(2, ['type' => 'end_turn'])->game->defenseBonus($texas, false))->toBe(0);
});

it('El-Salvador-Move: the sats go 25 % up or down on a coin toss', function (): void {
    ['game' => $game, 'effect' => $effect] = hyperPlay('salvador');

    expect($game->seatAt(0)['sats'])->toBe($effect['up'] ? 12.5 : 7.5);
});

it('Exit Scam: an enemy central bank loses 2 units, one always stays', function (): void {
    expect(hyperTerritory(hyperPlay('scam', 'london')['game'], 'london')['pleb'])->toBe(2)
        ->and(hyperTerritory(hyperPlay('scam', 'london', ['territories' => ['london' => [1, 2]]])['game'], 'london')['pleb'])->toBe(1);
});

it('Pizza-Tag: 10 % of the sats for 3 free plebs', function (): void {
    ['game' => $game] = hyperPlay('pizza');

    expect($game->seatAt(0))->toMatchArray(['sats' => 9.0, 'free' => 3]);
});

it('Lagardes Glaskugel: +3 fiat and a look at the next card', function (): void {
    ['game' => $game, 'effect' => $effect] = hyperPlay('lagarde');

    expect($game->seatAt(0)['fiat'])->toBe(5.0)
        ->and($effect['next_card'])->toBe($game->toArray()['deck'][0]);
});

it('Buy the Dip: plebs at half price this turn', function (): void {
    expect(hyperPlay('dip')['game']->plebCost(0))->toBe(0.5);
});

it('Not Your Keys: an enemy territory with exactly 1 unit turns neutral, and a seat without land is out', function (): void {
    ['game' => $game, 'events' => $events] = hyperPlay('nokeys', 'mexiko');

    expect(hyperTerritory($game, 'mexiko')['owner'])->toBeNull()
        ->and($game->seatAt(2)['out'])->toBeTrue()
        ->and(hyperEvents($events, 'player_eliminated')[0])->toMatchArray(['seat' => 2, 'by' => 0, 'cards' => 0]);
});

it('refuses a card that is not in hand, a target that does not fit and a target for a card without one', function (string $card, ?string $target, string $reason): void {
    $game = hyperCardGame('attack51');

    expect(hyperRefusal(fn () => $game->apply(0, ['type' => 'play_card', 'card' => $card, 'target' => $target])))->toBe($reason);
})->with([
    'not in hand' => ['scam', 'london', 'card_not_in_hand'],
    'no such card' => ['hodl', null, 'unknown_card'],
    'more than 3 units' => ['attack51', 'london', 'bad_target'],
    'no target' => ['attack51', null, 'unknown_territory'],
]);

it('refuses targets that do not fit the other targeted cards', function (string $card, string $target): void {
    expect(hyperRefusal(fn () => hyperCardGame($card)->apply(0, ['type' => 'play_card', 'card' => $card, 'target' => $target])))->toBe('bad_target');
})->with([
    'Diamond Hands on a foreign territory' => ['diamond', 'irland'],
    'Exit Scam on a territory without a bank' => ['scam', 'irland'],
    'Exit Scam on a neutral bank' => ['scam', 'frankfurt'],
    'Not Your Keys on 3 units' => ['nokeys', 'irland'],
    'Lost Keys with a target' => ['keys', 'irland'],
]);
