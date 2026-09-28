<?php

use App\Support\TwentyOne\Stream\RotationPlanner;

/**
 * Run a planner in quarter seconds, as the supervisor polls, and list every
 * slot it starts: "t kind scene [#game] [@tournament]".
 *
 * @param  Closure(float): list<array{id: int, blitz: bool}>  $gamesAt
 * @param  (Closure(float): list<int>)|null  $tournamentsAt  the upcoming tournaments' ids
 * @return list<string>
 */
function rotation(RotationPlanner $planner, float $seconds, Closure $gamesAt, ?Closure $tournamentsAt = null): array
{
    $log = [];
    $last = null;

    for ($t = 0.0; $t < $seconds; $t += 0.25) {
        $slot = $planner->at($t, $gamesAt($t), $tournamentsAt === null ? [] : $tournamentsAt($t));
        $key = $slot['kind'].' '.$slot['scene'].' '.$slot['gameId'].' '.$slot['tournamentId'].' '.$slot['until'];

        if ($key !== $last) {
            $log[] = trim(sprintf('%g %s %s %s', $t, $slot['kind'], $slot['scene'] ?? '-', $slot['gameId'] === null ? ($slot['tournamentId'] === null ? '' : '@'.$slot['tournamentId']) : '#'.$slot['gameId']));
            $last = $key;
        }
    }

    return $log;
}

function planner(float $loopSeconds = 30): RotationPlanner
{
    return new RotationPlanner(45, 60, 20, 12, 3, 3, $loopSeconds, 15);
}

test('one blitz game: a 60 s match per round, looks A B C, no gallery, teasers in turn', function () {
    $log = rotation(planner(), 3 * 120, fn () => [['id' => 7, 'blitz' => true]]);

    // Every round: prize pots and casual cups (EVERY_ROUND), then three from the pool.
    expect($log)->toBe([
        '0 match a1 #7', '60 teaser d1', '72 teaser d2', '84 teaser a3', '96 teaser a4', '108 teaser a5',
        '120 match b1 #7', '180 teaser d1', '192 teaser d2', '204 teaser b3', '216 teaser b4', '228 teaser b5',
        '240 match c1 #7', '300 teaser d1', '312 teaser d2', '324 teaser c3', '336 teaser c4', '348 teaser c5',
    ]);
});

test('three games: the match takes them in turn, the gallery follows in the same look, daily games count as live', function () {
    $games = [['id' => 1, 'blitz' => true], ['id' => 2, 'blitz' => false], ['id' => 3, 'blitz' => false]];

    $log = rotation(planner(), 140 + 125 + 125 + 1, fn () => $games);

    expect($log)->toBe([
        '0 match a1 #1', '60 gallery a2', '80 teaser d1', '92 teaser d2', '104 teaser a3', '116 teaser a4', '128 teaser a5',
        // A daily game's match is 45 s.
        '140 match b1 #2', '185 gallery b2', '205 teaser d1', '217 teaser d2', '229 teaser b3', '241 teaser b4', '253 teaser b5',
        '265 match c1 #3', '310 gallery c2', '330 teaser d1', '342 teaser d2', '354 teaser c3', '366 teaser c4', '378 teaser c5',
        '390 match a1 #1',
    ]);
});

test('without games: the loop every third round, the first one included, teasers never repeat early', function () {
    $log = rotation(planner(30), 30 + 60 + 60 + 30 + 60 + 1, fn () => []);

    expect($log)->toBe([
        '0 loop -', '30 teaser d1', '42 teaser d2', '54 teaser a3', '66 teaser a4', '78 teaser a5',
        '90 teaser d1', '102 teaser d2', '114 teaser b3', '126 teaser b4', '138 teaser b5',
        '150 loop -', '180 teaser d1', '192 teaser d2', '204 teaser c3', '216 teaser c4', '228 teaser c5',
        '240 teaser d1',
    ]);
});

test('a new game ends the loop at once and waits for the end of a teaser', function () {
    // Game from 10 s (during the loop) to 40 s, then again from 110 s (during a teaser of a round without games).
    $game = fn (float $t): array => ($t >= 10 && $t < 40) || $t >= 110 ? [['id' => 5, 'blitz' => false]] : [];

    $log = rotation(planner(30), 125, $game);

    expect($log)->toBe([
        '0 loop -', '10 match a1 #5',
        // The game is gone at 40: the match ends, the round's teasers follow.
        '40 teaser d1', '52 teaser d2', '64 teaser a3', '76 teaser a4', '88 teaser a5',
        // No game at 100: a round without games; the game from 110 on takes over when this teaser ends.
        '100 teaser d1', '112 match b1 #5',
    ]);
});

test('a gallery that has fewer than two games left ends early', function () {
    $games = fn (float $t): array => $t < 70 ? [['id' => 1, 'blitz' => true], ['id' => 2, 'blitz' => true]] : [['id' => 1, 'blitz' => true]];

    $log = rotation(planner(), 90, $games);

    expect(array_slice($log, 0, 3))->toBe(['0 match a1 #1', '60 gallery a2', '70 teaser d1']);
});

test('an upcoming tournament comes in every round with games: its hero and bracket after match and gallery, in the round\'s look', function () {
    $games = [['id' => 1, 'blitz' => true], ['id' => 2, 'blitz' => true]];

    $log = rotation(planner(), 170 + 170 + 1, fn () => $games, fn () => [9]);

    expect($log)->toBe([
        '0 match a1 #1', '60 gallery a2', '80 tournament ta1 @9', '95 tournament ta2 @9',
        '110 teaser d1', '122 teaser d2', '134 teaser a3', '146 teaser a4', '158 teaser a5',
        '170 match b1 #2', '230 gallery b2', '250 tournament tb1 @9', '265 tournament tb2 @9',
        '280 teaser d1', '292 teaser d2', '304 teaser b3', '316 teaser b4', '328 teaser b5',
        '340 match c1 #1',
    ]);
});

test('without games a tournament round is hero, bracket, pots, cups and one teaser, and the loop keeps every third round', function () {
    $log = rotation(planner(30), 30 + 66 + 66 + 30 + 66 + 1, fn () => [], fn () => [9]);

    expect($log)->toBe([
        '0 loop -',
        '30 tournament ta1 @9', '45 tournament ta2 @9', '60 teaser d1', '72 teaser d2', '84 teaser a3',
        '96 tournament tb1 @9', '111 tournament tb2 @9', '126 teaser d1', '138 teaser d2', '150 teaser a4',
        '162 loop -',
        '192 tournament tc1 @9', '207 tournament tc2 @9', '222 teaser d1', '234 teaser d2', '246 teaser a5',
        '258 tournament ta1 @9',
    ]);
});

test('two tournaments take turns, one per round, soonest sign-up close first', function () {
    $log = rotation(planner(30), 30 + 66 + 66 + 30 + 66 + 1, fn () => [], fn () => [4, 9]);

    expect(array_values(array_filter($log, fn (string $line): bool => str_contains($line, 'tournament'))))->toBe([
        '30 tournament ta1 @4', '45 tournament ta2 @4',
        '96 tournament tb1 @9', '111 tournament tb2 @9',
        '192 tournament tc1 @4', '207 tournament tc2 @4',
        '258 tournament ta1 @9',
    ]);
});

test('a tournament that closes ends its slide at once and its bracket is skipped; without one the round has three teasers again', function () {
    // Open until 40 s: the hero from 30 s ends at 40 instead of 45, the bracket is skipped.
    $log = rotation(planner(30), 30 + 10 + 36 + 60 + 1, fn () => [], fn (float $t): array => $t < 40 ? [9] : []);

    expect($log)->toBe([
        '0 loop -', '30 tournament ta1 @9', '40 teaser d1', '52 teaser d2', '64 teaser a3',
        '76 teaser d1', '88 teaser d2', '100 teaser a4', '112 teaser a5', '124 teaser b3', '136 loop -',
    ]);
});
