<?php

use App\Support\Hyper\HyperBot;
use App\Support\Hyper\HyperGame;

/*
| The page's bot, playing through the same actions a human sends: whole games end with a winner and
| the loot per seat, a bot takes a seat over mid-game, and one bot turn follows the page's order.
*/

it('plays whole games from 2 to 6 seats to a winner through the public actions', function (int $players): void {
    $seats = array_map(fn (string $faction): array => ['faction' => $faction, 'bot' => true], array_slice(array_keys(HyperGame::FACTIONS), 0, $players));

    $step = HyperBot::playGame(HyperGame::start($seats, 20, 2026)->game);
    $won = hyperEvents($step->events, 'game_won');

    expect($step->game->isOver())->toBeTrue()
        ->and($won)->toHaveCount(1)
        ->and($won[0]['seat'])->toBe($step->game->winner())
        ->and($step->game->round())->toBeLessThanOrEqual(20)
        ->and($won[0]['loot'])->toBe($step->game->loot())
        ->and(array_sum($step->game->loot()))->toBeGreaterThan(0.0);
})->with([2, 3, 4, 5, 6]);

it('takes over a seat in the middle of a game', function (): void {
    $game = HyperGame::start([['faction' => 'bitcoiner'], ['faction' => 'fed', 'bot' => true]], 0, 5)->game;

    $game = $game->withBot(0);
    $step = HyperBot::playTurn($game);

    expect($game->isBot(0))->toBeTrue()
        ->and($step->game->currentSeat())->toBe(1)
        ->and(array_column($step->events, 'type'))->toContain('phase_changed', 'turn_ended');
});

it('plays one turn as the page does: cards, everything onto the biggest front stack in the goal space, attacks, one fortify move', function (): void {
    $game = hyperPosition([
        'territories' => ['ny' => [0, 2], 'texas' => [0, 6], 'kanada' => [0, 1], 'westk' => [0, 1], 'alaska' => [0, 1], 'mexiko' => [1, 1]],
        'seats' => [0 => ['fiat' => 3.0, 'sats' => 3.0, 'free_plebs' => 2, 'hand' => ['dip']]],
    ]);

    $events = HyperBot::playTurn($game)->events;
    $types = array_column($events, 'type');
    $placed = hyperEvents($events, 'placed');

    expect($types[0])->toBe('card_played')
        ->and(array_column($placed, 'territory'))->each->toBe('texas')
        ->and(array_column($placed, 'source'))->toBe(['free', 'sats', 'fiat', 'fiat', 'fiat', 'fiat', 'fiat', 'fiat'])
        ->and($types)->toContain('dice_rolled', 'turn_ended');
});
