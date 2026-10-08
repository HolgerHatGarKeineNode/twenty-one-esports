<?php

use App\Support\Hyper\HyperBot;
use App\Support\Hyper\HyperGame;

/*
| Team games (plan "Hyperbitcoinization", P4): teammates never attack each other or hit each other with a
| card, troops move across a shared border into a teammate's territory, and a team wins together (the
| only team left, or the most central banks at the round limit).
*/

/**
 * Seats 0 and 2 (team 0: ny, texas; mexiko) against seat 1 (team 1: irland, london).
 *
 * @param  array<string, mixed>  $edit
 */
function hyperTeamGame(array $edit = []): HyperGame
{
    return hyperPosition([
        ...$edit,
        'territories' => ['ny' => [0, 5], 'texas' => [0, 1], 'mexiko' => [2, 1], 'irland' => [1, 3], 'london' => [1, 4], ...($edit['territories'] ?? [])],
        'seats' => array_replace_recursive([0 => ['team' => 0], 1 => ['team' => 1], 2 => ['team' => 0]], $edit['seats'] ?? []),
    ]);
}

it('never offers or allows an attack on a teammate', function (): void {
    $game = hyperTeamGame(['phase' => 'attack']);

    expect($game->legal()['attack']['ny'])->toBe(['kanada', 'irland'])
        ->and(hyperRefusal(fn () => $game->apply(0, ['type' => 'attack', 'from' => 'ny', 'to' => 'mexiko', 'mode' => 'roll'])))->toBe('teammate_territory')
        ->and(hyperRefusal(fn () => $game->apply(0, ['type' => 'attack', 'from' => 'ny', 'to' => 'irland', 'mode' => 'roll'])))->toBeNull();
});

it('lets every seat attack every other seat without teams', function (): void {
    $game = hyperPosition(['phase' => 'attack', 'territories' => ['ny' => [0, 5], 'mexiko' => [2, 1], 'irland' => [1, 3]]]);

    expect($game->legal()['attack']['ny'])->toContain('mexiko', 'irland');
});

it('moves troops across a shared border into a teammate\'s territory, which stays the teammate\'s', function (): void {
    $game = hyperTeamGame(['phase' => 'fortify']);

    expect($game->legal()['fortify']['ny'])->toBe(['texas', 'mexiko']);

    $step = $game->apply(0, ['type' => 'fortify', 'from' => 'ny', 'to' => 'mexiko', 'count' => 3]);

    expect(hyperTerritory($step->game, 'mexiko'))->toMatchArray(['owner' => 2, 'pleb' => 4])
        ->and(hyperTerritory($step->game, 'ny')['pleb'])->toBe(2)
        ->and(hyperEvents($step->events, 'moved')[0])->toMatchArray(['seat' => 0, 'to' => 'mexiko', 'kind' => 'fortify'])
        ->and(hyperRefusal(fn () => $game->apply(0, ['type' => 'fortify', 'from' => 'ny', 'to' => 'irland', 'count' => 1])))->toBe('not_your_territory')
        ->and(hyperRefusal(fn () => $game->apply(0, ['type' => 'fortify', 'from' => 'mexiko', 'to' => 'ny', 'count' => 1])))->toBe('not_your_territory');
});

it('aims no opponent card at a teammate', function (string $card, string $teammate, string $opponent): void {
    $game = hyperTeamGame(['territories' => ['irland' => [1, 1], 'london' => [1, 4], 'brasilia' => [2, 4]], 'seats' => [0 => ['hand' => [$card]]]]);

    expect($game->cardTargetOk($card, HyperGame::territoryIndex($teammate)))->toBeFalse()
        ->and($game->cardTargetOk($card, HyperGame::territoryIndex($opponent)))->toBeTrue()
        ->and(hyperRefusal(fn () => $game->apply(0, ['type' => 'play_card', 'card' => $card, 'target' => $teammate])))->toBe('bad_target');
})->with([
    '51%-Attacke' => ['attack51', 'mexiko', 'irland'],
    'Not your keys' => ['nokeys', 'mexiko', 'irland'],
    'Scam' => ['scam', 'brasilia', 'london'],
]);

it('takes the lost keys from the richest opponent, never from a richer teammate', function (): void {
    $step = hyperTeamGame(['seats' => [0 => ['hand' => ['keys']], 1 => ['sats' => 10.0], 2 => ['sats' => 30.0]]])
        ->apply(0, ['type' => 'play_card', 'card' => 'keys', 'target' => null]);

    expect(hyperEvents($step->events, 'card_played')[0]['effect'])->toBe(['victim' => 1, 'sats_lost' => 3.0])
        ->and($step->game->seatAt(2)['sats'])->toBe(30.0);
});

it('ends a team game when only one team is left, with a teammate still in', function (): void {
    $step = hyperTeamGame(['territories' => ['irland' => [1, 1], 'london' => [null, 1]], 'seats' => [0 => ['hand' => ['nokeys']]]])
        ->apply(0, ['type' => 'play_card', 'card' => 'nokeys', 'target' => 'irland']);

    expect($step->game->isOver())->toBeTrue()
        ->and($step->game->winnerTeam())->toBe(0)
        ->and($step->game->winner())->toBe(0)
        ->and($step->game->seatAt(2)['out'])->toBeFalse()
        ->and(hyperEvents($step->events, 'game_won')[0])->toMatchArray(['seat' => 0, 'team' => 0, 'by_limit' => false]);
});

it('goes on while two teams are left, even with one seat left per team', function (): void {
    $game = hyperTeamGame(['territories' => ['texas' => [null, 1], 'ny' => [null, 1], 'mexiko' => [2, 1]], 'seats' => [0 => ['out' => true]], 'seat' => 2]);

    $step = $game->apply(2, ['type' => 'end_turn']);

    expect($step->game->isOver())->toBeFalse();
});

it('gives the round limit to the team with the most central banks together, not to the strongest seat', function (): void {
    // Seat 1 alone holds the most (one bank, three territories); seats 0 and 2 hold one bank each.
    $game = hyperTeamGame([
        'territories' => ['ny' => [0, 1], 'texas' => [null, 1], 'brasilia' => [2, 1], 'mexiko' => [null, 1], 'london' => [1, 9], 'irland' => [1, 9], 'island' => [1, 9]],
        'limit' => 12, 'round' => 12, 'seat' => 2,
    ]);

    expect($game->standings()[0])->toBe(1);

    $step = $game->apply(2, ['type' => 'end_turn']);

    expect($step->game->isOver())->toBeTrue()
        ->and($step->game->wonByLimit())->toBeTrue()
        ->and($step->game->winnerTeam())->toBe(0)
        ->and($step->game->winner())->toBeIn([0, 2]);
});

it('keeps the teams and the winning team through toArray and fromArray', function (): void {
    $game = hyperTeamGame();
    $again = HyperGame::fromArray(json_decode((string) json_encode($game->toArray()), true));

    expect(array_column($again->toArray()['seats'], 'team'))->toBe([0, 1, 0])
        ->and($again->isTeamGame())->toBeTrue()
        ->and($again->allied(0, 2))->toBeTrue()
        ->and($again->allied(0, 1))->toBeFalse();
});

it('refuses a team game that names a team for some seats only or has one team', function (array $teams): void {
    $seats = array_map(fn (string $faction, ?int $team): array => ['faction' => $faction, 'team' => $team], ['bitcoiner', 'fed', 'ezb', 'goldbug'], $teams);

    expect(fn () => HyperGame::start($seats, 0, 1))->toThrow(InvalidArgumentException::class);
})->with([
    'some seats' => [[0, 1, null, 1]],
    'one team' => [[0, 0, 0, 0]],
]);

it('plays bot team games in which no seat ever attacks, conquers or carded a teammate', function (int $players): void {
    $seats = array_map(fn (string $faction, int $index): array => ['faction' => $faction, 'bot' => true, 'team' => $index % 2], array_slice(array_keys(HyperGame::FACTIONS), 0, $players), range(0, $players - 1));

    foreach ([3, 4, 5] as $seed) {
        $game = HyperGame::start($seats, 0, $seed)->game;

        // Replays each bot turn action by action, so every attack and card is checked against the owner it hits.
        while (! $game->isOver() && $game->round() <= 200) {
            foreach (HyperBot::playTurn($game)->actions as ['action' => $action]) {
                $target = $action['type'] === 'attack' ? $action['to'] : ($action['type'] === 'play_card' && in_array($action['card'], ['attack51', 'scam', 'nokeys'], true) ? $action['target'] : null);

                if ($target !== null) {
                    $owner = $game->toArray()['territories'][$target]['owner'];
                    expect($owner === null || $owner % 2 !== $game->currentSeat() % 2)->toBeTrue("seat {$game->currentSeat()} hit {$target} of seat {$owner}");
                }

                $game = $game->apply($game->currentSeat(), $action)->game;
            }
        }

        expect($game->isOver())->toBeTrue()
            ->and($game->winnerTeam())->toBe($game->winner() % 2);
    }
})->with([4, 6]);
