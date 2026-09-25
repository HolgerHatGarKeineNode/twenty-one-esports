<?php

use App\Games\Chess;
use App\Games\RocketLeague;

/**
 * @param  list<array{0: string, 1?: int|null, 2?: int|null}>  $games  winner, challenger goals, challenged goals
 * @return array{bo: int, games: list<array<string, mixed>>}
 */
function rlSeries(int $bo, array $games): array
{
    return ['bo' => $bo, 'games' => array_map(fn (array $game): array => [
        'winner' => $game[0],
        'challenger' => $game[1] ?? null,
        'challenged' => $game[2] ?? null,
    ], $games)];
}

test('a finished Rocket League series is valid, with goals or with "goals unknown"', function () {
    $game = new RocketLeague;
    $threes = $game->mode('3v3');

    expect($game->validateResult($threes, rlSeries(5, [
        ['challenger', 3, 1], ['challenged', 0, 2], ['challenger'], ['challenger', 4, 3],
    ])))->toBe([]);
});

test('a Rocket League result breaking a series rule is refused', function (array $result, string $error) {
    $game = new RocketLeague;

    expect($game->validateResult($game->mode('2v2'), $result))->toContain($error);
})->with([
    'best of 4' => [rlSeries(4, [['challenger', 1, 0], ['challenger', 1, 0]]), 'bo_not_allowed'],
    'winner with fewer goals' => [rlSeries(3, [['challenger', 1, 2], ['challenger', 3, 0]]), 'game_1_goals'],
    'only one side has goals' => [rlSeries(3, [['challenger', 2, null], ['challenger', 3, 0]]), 'game_1_goals'],
    'a draw' => [rlSeries(3, [['draw', 1, 1], ['challenger', 1, 0], ['challenger', 1, 0]]), 'game_1_winner'],
    'series not decided' => [rlSeries(5, [['challenger', 1, 0], ['challenged', 0, 1]]), 'series_not_finished'],
    'game after the decider' => [rlSeries(3, [['challenger', 1, 0], ['challenger', 1, 0], ['challenged', 0, 1]]), 'game_3_after_series_end'],
    'unknown flag' => [['bo' => 3, 'games' => [
        ['winner' => 'challenger', 'challenger' => 1, 'challenged' => 0, 'flags' => ['penalties']],
        ['winner' => 'challenger', 'challenger' => 1, 'challenged' => 0],
    ]], 'game_1_flag'],
]);

test('chess accepts a solo result and a 2 or 3 board team match, and nothing else', function () {
    $game = new Chess;
    $blitz = $game->mode('blitz');

    expect($game->validateResult($blitz, ['format' => 'solo', 'result' => '1/2-1/2']))->toBe([])
        ->and($game->validateResult($blitz, ['format' => 'team', 'boards' => ['1-0', '1/2-1/2', '0-1']]))->toBe([])
        ->and($game->validateResult($blitz, ['format' => 'solo', 'result' => '2-0']))->toBe(['result'])
        ->and($game->validateResult($blitz, ['format' => 'team', 'boards' => ['1-0']]))->toBe(['boards_not_allowed'])
        ->and($game->validateResult($blitz, ['format' => 'team', 'boards' => ['1-0', '*']]))->toBe(['board_2_result']);
});
