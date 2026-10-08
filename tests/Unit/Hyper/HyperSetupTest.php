<?php

use App\Support\Hyper\HyperGame;
use App\Support\Hyper\HyperMap;

/*
| Setting a game up as the page does (newGame, dealFair): a value-balanced deal with equal territories,
| banks and mines per seat, the rest neutral, no currency space complete, 14 start plebs plus the seat
| compensation of tools/hbsim/balance.json, and in the duel a neutral third side.
*/

/**
 * @return array<string, array{territories: int, banks: int, mines: int, units: int}>
 */
function hyperHoldings(HyperGame $game): array
{
    $holdings = [];

    foreach ($game->toArray()['territories'] as $id => $territory) {
        $t = array_search($id, HyperMap::IDS, true);
        $key = $territory['owner'] === null ? 'neutral' : (string) $territory['owner'];
        $row = $holdings[$key] ?? ['territories' => 0, 'banks' => 0, 'mines' => 0, 'units' => 0];
        $holdings[$key] = ['territories' => $row['territories'] + 1, 'banks' => $row['banks'] + (HyperMap::BANK[$t] ? 1 : 0), 'mines' => $row['mines'] + (HyperMap::MINE[$t] ? 1 : 0), 'units' => $row['units'] + $territory['pleb']];
    }

    return $holdings;
}

/**
 * @return list<array{faction: string}>
 */
function hyperSeats(int $players): array
{
    return array_map(fn (string $faction): array => ['faction' => $faction], array_slice(array_keys(HyperGame::FACTIONS), 0, $players));
}

it('deals four seats the same territories, banks and mines, with the seat compensation for later seats', function (): void {
    $step = HyperGame::start(hyperSeats(4), 0, 7);
    $holdings = hyperHoldings($step->game);

    foreach (['0', '1', '2', '3'] as $seat) {
        expect($holdings[$seat])->toMatchArray(['territories' => 12, 'banks' => 2, 'mines' => 1]);
    }

    // 14 plebs per side on top of one per territory; later seats get round(4.5 × seat) more.
    expect(array_column([$holdings['0'], $holdings['1'], $holdings['2'], $holdings['3']], 'units'))->toBe([26, 31, 35, 40])
        ->and($holdings['neutral']['territories'])->toBe(5)
        ->and(hyperTerritory($step->game, 'zuerich'))->toMatchArray(['owner' => null, 'pleb' => 2])
        ->and(array_map(fn (int $zone): ?int => $step->game->zoneOwner($zone), array_keys(HyperMap::ZONE_KEYS)))->each->toBeNull()
        ->and(array_column($step->events, 'type'))->toBe(['game_started', 'turn_started', 'loot_gained'])
        ->and($step->game->phase())->toBe('buy')
        ->and($step->game->currentSeat())->toBe(0);
});

it('adds a neutral third side to the duel, holding as much as each player', function (): void {
    $holdings = hyperHoldings(HyperGame::start(hyperSeats(2), 0, 7)->game);

    // The neutral side's 16 territories carry its 14 start plebs; Zuerich and the undealt rest hold 2 each.
    expect($holdings['0'])->toMatchArray(['territories' => 16, 'units' => 30])
        ->and($holdings['1'])->toMatchArray(['territories' => 16, 'units' => 40])
        ->and($holdings['neutral'])->toMatchArray(['territories' => 21, 'units' => 16 + 14 + 2 * 5]);
});

it('deals the same game for the same seed and another for another seed', function (): void {
    $deal = fn (int $seed): array => HyperGame::start(hyperSeats(5), 20, $seed)->game->toArray();

    expect($deal(99))->toBe($deal(99))
        ->and($deal(99)['territories'])->not->toBe($deal(100)['territories']);
});

it('refuses a setup the page does not offer', function (array $seats, int $limit, int $seed): void {
    expect(fn () => HyperGame::start($seats, $limit, $seed))->toThrow(InvalidArgumentException::class);
})->with([
    'one seat' => [[['faction' => 'fed']], 0, 1],
    'seven seats' => [[...hyperSeats(6), ['faction' => 'fed']], 0, 1],
    'a faction twice' => [[['faction' => 'fed'], ['faction' => 'fed']], 0, 1],
    'an unknown faction' => [[['faction' => 'fed'], ['faction' => 'snb']], 0, 1],
    'a round limit of 15' => [hyperSeats(3), 15, 1],
    'a negative seed' => [hyperSeats(3), 0, -1],
]);
