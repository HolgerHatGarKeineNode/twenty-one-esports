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
    $log = rotation(planner(), 3 * 96, fn () => [['id' => 7, 'blitz' => true]]);

    expect($log)->toBe([
        '0 match a1 #7', '60 teaser a3', '72 teaser a4', '84 teaser a5',
        '96 match b1 #7', '156 teaser b3', '168 teaser b4', '180 teaser b5',
        '192 match c1 #7', '252 teaser c3', '264 teaser c4', '276 teaser c5',
    ]);
});

test('three games: the match takes them in turn, the gallery follows in the same look, daily games count as live', function () {
    $games = [['id' => 1, 'blitz' => true], ['id' => 2, 'blitz' => false], ['id' => 3, 'blitz' => false]];

    $log = rotation(planner(), 116 + 101 + 101 + 1, fn () => $games);

    expect($log)->toBe([
        '0 match a1 #1', '60 gallery a2', '80 teaser a3', '92 teaser a4', '104 teaser a5',
        // A daily game's match is 45 s.
        '116 match b1 #2', '161 gallery b2', '181 teaser b3', '193 teaser b4', '205 teaser b5',
        '217 match c1 #3', '262 gallery c2', '282 teaser c3', '294 teaser c4', '306 teaser c5',
        '318 match a1 #1',
    ]);
});

test('without games: the loop every third round, the first one included, teasers never repeat early', function () {
    $log = rotation(planner(30), 30 + 36 + 36 + 30 + 36 + 1, fn () => []);

    expect($log)->toBe([
        '0 loop -', '30 teaser a3', '42 teaser a4', '54 teaser a5', '66 teaser b3', '78 teaser b4', '90 teaser b5',
        '102 loop -', '132 teaser c3', '144 teaser c4', '156 teaser c5', '168 teaser d1',
    ]);
});

test('a new game ends the loop at once and waits for the end of a teaser', function () {
    // Game from 10 s (during the loop) to 40 s, then again from 80 s (during a teaser of a round without games).
    $game = fn (float $t): array => ($t >= 10 && $t < 40) || $t >= 80 ? [['id' => 5, 'blitz' => false]] : [];

    $log = rotation(planner(30), 100, $game);

    expect($log)->toBe([
        '0 loop -', '10 match a1 #5',
        // The game is gone at 40: the match ends, the round's teasers follow.
        '40 teaser a3', '52 teaser a4', '64 teaser a5',
        // No game at 76: a round without games; the game from 80 on takes over when this teaser ends.
        '76 teaser b3', '88 match b1 #5',
    ]);
});

test('a gallery that has fewer than two games left ends early', function () {
    $games = fn (float $t): array => $t < 70 ? [['id' => 1, 'blitz' => true], ['id' => 2, 'blitz' => true]] : [['id' => 1, 'blitz' => true]];

    $log = rotation(planner(), 90, $games);

    expect(array_slice($log, 0, 3))->toBe(['0 match a1 #1', '60 gallery a2', '70 teaser a3']);
});

test('an upcoming tournament comes in every round with games: its hero and bracket after match and gallery, in the round\'s look', function () {
    $games = [['id' => 1, 'blitz' => true], ['id' => 2, 'blitz' => true]];

    $log = rotation(planner(), 146 + 146 + 1, fn () => $games, fn () => [9]);

    expect($log)->toBe([
        '0 match a1 #1', '60 gallery a2', '80 tournament ta1 @9', '95 tournament ta2 @9', '110 teaser a3', '122 teaser a4', '134 teaser a5',
        '146 match b1 #2', '206 gallery b2', '226 tournament tb1 @9', '241 tournament tb2 @9', '256 teaser b3', '268 teaser b4', '280 teaser b5',
        '292 match c1 #1',
    ]);
});

test('without games a tournament round is hero, bracket and one teaser, and the loop keeps every third round', function () {
    $log = rotation(planner(30), 30 + 42 + 42 + 30 + 42 + 1, fn () => [], fn () => [9]);

    expect($log)->toBe([
        '0 loop -',
        '30 tournament ta1 @9', '45 tournament ta2 @9', '60 teaser a3',
        '72 tournament tb1 @9', '87 tournament tb2 @9', '102 teaser a4',
        '114 loop -',
        '144 tournament tc1 @9', '159 tournament tc2 @9', '174 teaser a5',
        '186 tournament ta1 @9',
    ]);
});

test('two tournaments take turns, one per round, soonest sign-up close first', function () {
    $log = rotation(planner(30), 30 + 42 + 42 + 30 + 42 + 1, fn () => [], fn () => [4, 9]);

    expect(array_values(array_filter($log, fn (string $line): bool => str_contains($line, 'tournament'))))->toBe([
        '30 tournament ta1 @4', '45 tournament ta2 @4',
        '72 tournament tb1 @9', '87 tournament tb2 @9',
        '144 tournament tc1 @4', '159 tournament tc2 @4',
        '186 tournament ta1 @9',
    ]);
});

test('a tournament that closes ends its slide at once and its bracket is skipped; without one the round has three teasers again', function () {
    // Open until 40 s: the hero from 30 s ends at 40 instead of 45, the bracket is skipped.
    $log = rotation(planner(30), 30 + 10 + 12 + 36 + 1, fn () => [], fn (float $t): array => $t < 40 ? [9] : []);

    expect($log)->toBe([
        '0 loop -', '30 tournament ta1 @9', '40 teaser a3',
        '52 teaser a4', '64 teaser a5', '76 teaser b3', '88 loop -',
    ]);
});
