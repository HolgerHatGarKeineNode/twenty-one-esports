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
    $log = rotation(planner(), 3 * 132, fn () => [['id' => 7, 'blitz' => true]]);

    // Every round: sats to win (d1, e4 in turn), casual cups, a pride moment (e1, e2, e3 in turn), then three from the pool.
    expect($log)->toBe([
        '0 match a1 #7', '60 teaser d1', '72 teaser d2', '84 teaser e1', '96 teaser a3', '108 teaser a4', '120 teaser a5',
        '132 match b1 #7', '192 teaser e4', '204 teaser d2', '216 teaser e2', '228 teaser b3', '240 teaser b4', '252 teaser b5',
        '264 match c1 #7', '324 teaser d1', '336 teaser d2', '348 teaser e3', '360 teaser c3', '372 teaser c4', '384 teaser c5',
    ]);
});

test('three games: the match takes them in turn, the gallery follows in the same look, daily games count as live', function () {
    $games = [['id' => 1, 'blitz' => true], ['id' => 2, 'blitz' => false], ['id' => 3, 'blitz' => false]];

    $log = rotation(planner(), 152 + 137 + 137 + 1, fn () => $games);

    expect($log)->toBe([
        '0 match a1 #1', '60 gallery a2', '80 teaser d1', '92 teaser d2', '104 teaser e1', '116 teaser a3', '128 teaser a4', '140 teaser a5',
        // A daily game's match is 45 s.
        '152 match b1 #2', '197 gallery b2', '217 teaser e4', '229 teaser d2', '241 teaser e2', '253 teaser b3', '265 teaser b4', '277 teaser b5',
        '289 match c1 #3', '334 gallery c2', '354 teaser d1', '366 teaser d2', '378 teaser e3', '390 teaser c3', '402 teaser c4', '414 teaser c5',
        '426 match a1 #1',
    ]);
});

test('without games: the loop every third round, the first one included, teasers never repeat early', function () {
    $log = rotation(planner(30), 30 + 72 + 72 + 30 + 72 + 1, fn () => []);

    expect($log)->toBe([
        '0 loop -', '30 teaser d1', '42 teaser d2', '54 teaser e1', '66 teaser a3', '78 teaser a4', '90 teaser a5',
        '102 teaser e4', '114 teaser d2', '126 teaser e2', '138 teaser b3', '150 teaser b4', '162 teaser b5',
        '174 loop -', '204 teaser d1', '216 teaser d2', '228 teaser e3', '240 teaser c3', '252 teaser c4', '264 teaser c5',
        '276 teaser e4',
    ]);
});

test('a new game ends the loop at once and waits for the end of a teaser', function () {
    // Game from 10 s (during the loop) to 40 s, then again from 115 s (during a teaser of a round without games).
    $game = fn (float $t): array => ($t >= 10 && $t < 40) || $t >= 115 ? [['id' => 5, 'blitz' => false]] : [];

    $log = rotation(planner(30), 140, $game);

    expect($log)->toBe([
        '0 loop -', '10 match a1 #5',
        // The game is gone at 40: the match ends, the round's teasers follow.
        '40 teaser d1', '52 teaser d2', '64 teaser e1', '76 teaser a3', '88 teaser a4', '100 teaser a5',
        // No game at 112: a round without games; the game from 115 on takes over when this teaser ends.
        '112 teaser e4', '124 match b1 #5',
    ]);
});

test('a gallery that has fewer than two games left ends early', function () {
    $games = fn (float $t): array => $t < 70 ? [['id' => 1, 'blitz' => true], ['id' => 2, 'blitz' => true]] : [['id' => 1, 'blitz' => true]];

    $log = rotation(planner(), 90, $games);

    expect(array_slice($log, 0, 3))->toBe(['0 match a1 #1', '60 gallery a2', '70 teaser d1']);
});

test('an upcoming tournament comes in every round with games: its hero, bracket and how it runs after match and gallery, in the round\'s look', function () {
    $games = [['id' => 1, 'blitz' => true], ['id' => 2, 'blitz' => true]];

    $log = rotation(planner(), 197 + 197 + 1, fn () => $games, fn () => [9]);

    expect($log)->toBe([
        '0 match a1 #1', '60 gallery a2', '80 tournament ta1 @9', '95 tournament ta2 @9', '110 tournament ta3 @9',
        '125 teaser d1', '137 teaser d2', '149 teaser e1', '161 teaser a3', '173 teaser a4', '185 teaser a5',
        '197 match b1 #2', '257 gallery b2', '277 tournament tb1 @9', '292 tournament tb2 @9', '307 tournament tb3 @9',
        '322 teaser e4', '334 teaser d2', '346 teaser e2', '358 teaser b3', '370 teaser b4', '382 teaser b5',
        '394 match c1 #1',
    ]);
});

test('without games a tournament round is hero, bracket, how it runs, pots, cups and one teaser, and the loop keeps every third round', function () {
    $log = rotation(planner(30), 30 + 93 + 93 + 30 + 93 + 1, fn () => [], fn () => [9]);

    expect($log)->toBe([
        '0 loop -',
        '30 tournament ta1 @9', '45 tournament ta2 @9', '60 tournament ta3 @9', '75 teaser d1', '87 teaser d2', '99 teaser e1', '111 teaser a3',
        '123 tournament tb1 @9', '138 tournament tb2 @9', '153 tournament tb3 @9', '168 teaser e4', '180 teaser d2', '192 teaser e2', '204 teaser a4',
        '216 loop -',
        '246 tournament tc1 @9', '261 tournament tc2 @9', '276 tournament tc3 @9', '291 teaser d1', '303 teaser d2', '315 teaser e3', '327 teaser a5',
        '339 tournament ta1 @9',
    ]);
});

test('two tournaments take turns, one per round, soonest sign-up close first', function () {
    $log = rotation(planner(30), 30 + 93 + 93 + 30 + 93 + 1, fn () => [], fn () => [4, 9]);

    expect(array_values(array_filter($log, fn (string $line): bool => str_contains($line, 'tournament'))))->toBe([
        '30 tournament ta1 @4', '45 tournament ta2 @4', '60 tournament ta3 @4',
        '123 tournament tb1 @9', '138 tournament tb2 @9', '153 tournament tb3 @9',
        '246 tournament tc1 @4', '261 tournament tc2 @4', '276 tournament tc3 @4',
        '339 tournament ta1 @9',
    ]);
});

test('a tournament that closes ends its slide at once and its bracket is skipped; without one the round has three teasers again', function () {
    // Open until 40 s: the hero from 30 s ends at 40 instead of 45, the bracket is skipped.
    $log = rotation(planner(30), 30 + 10 + 48 + 72 + 1, fn () => [], fn (float $t): array => $t < 40 ? [9] : []);

    expect($log)->toBe([
        '0 loop -', '30 tournament ta1 @9', '40 teaser d1', '52 teaser d2', '64 teaser e1', '76 teaser a3',
        '88 teaser e4', '100 teaser d2', '112 teaser e2', '124 teaser a4', '136 teaser a5', '148 teaser b3', '160 loop -',
    ]);
});
