<?php

use App\Support\TwentyOne\Stream\BoardScene;
use App\Support\TwentyOne\Stream\RotationPlanner;

/*
 * The tournaments past sign-up in the rotation (TournamentLiveSlides): their
 * phase's slides in the round's look, the call to sign up for the next one,
 * and fair turns with the upcoming tournaments.
 */

/**
 * The tournament slots a planner starts over `$seconds` without games: "t scene @id".
 *
 * @param  Closure(float): list<array{id: int, phase: string, fomo: bool}>  $liveAt
 * @param  (Closure(float): list<int>)|null  $upcomingAt
 * @return list<string>
 */
function liveRotation(float $seconds, Closure $liveAt, ?Closure $upcomingAt = null): array
{
    $planner = new RotationPlanner(45, 60, 20, 12, 3, 3, 30, 15);
    $log = [];
    $last = null;

    for ($t = 0.0; $t < $seconds; $t += 0.25) {
        $slot = $planner->at($t, [], $upcomingAt === null ? [] : $upcomingAt($t), BoardScene::OFF, $liveAt($t));
        $key = $slot['scene'].' '.$slot['tournamentId'].' '.$slot['until'];

        if ($key !== $last && $slot['kind'] === RotationPlanner::TOURNAMENT) {
            $log[] = sprintf('%g %s @%d', $t, $slot['scene'], $slot['tournamentId']);
        }

        $last = $key;
    }

    return $log;
}

test('a running tournament holds the stream: live bracket, still standing, live bracket, how it runs, nothing else in between, no loop', function () {
    // 90 s a hold in four slides, hold after hold: only the tournament while it runs (2026-10-03).
    $log = liveRotation(306, fn () => [['id' => 5, 'phase' => 'running', 'fomo' => true]]);

    expect($log)->toBe([
        '0 ta4 @5', '22.5 ta5 @5', '45 ta4 @5', '67.5 ta3 @5',
        '90 tb4 @5', '112.5 tb5 @5', '135 tb4 @5', '157.5 tb3 @5',
        '180 tc4 @5', '202.5 tc5 @5', '225 tc4 @5', '247.5 tc3 @5',
        '270 ta4 @5', '292.5 ta5 @5',
    ]);
});

test('the call to sign up for the next one never cuts into a running tournament\'s hold', function () {
    $log = liveRotation(90, fn () => [['id' => 5, 'phase' => 'running', 'fomo' => true]]);

    expect($log)->toBe(['0 ta4 @5', '22.5 ta5 @5', '45 ta4 @5', '67.5 ta3 @5']);
});

test('a finished tournament shows its champion, then the final bracket and its pride in turn; a drawing one how it runs', function () {
    $finished = liveRotation(30 + 93 + 60, fn () => [['id' => 8, 'phase' => 'finished', 'fomo' => true]]);
    $drawing = liveRotation(30 + 40, fn () => [['id' => 3, 'phase' => 'drawing', 'fomo' => true]]);

    expect($finished)->toBe(['30 ta6 @8', '45 ta4 @8', '60 ta7 @8', '123 tb6 @8', '138 tb5 @8', '153 tb7 @8'])
        ->and($drawing)->toBe(['30 ta3 @3', '45 ta7 @3']);
});

test('while a tournament runs, the upcoming ones wait', function () {
    $log = liveRotation(306, fn () => [['id' => 5, 'phase' => 'running', 'fomo' => false]], fn () => [9]);

    expect($log)->toBe([
        '0 ta4 @5', '22.5 ta5 @5', '45 ta4 @5', '67.5 ta3 @5',
        '90 tb4 @5', '112.5 tb5 @5', '135 tb4 @5', '157.5 tb3 @5',
        '180 tc4 @5', '202.5 tc5 @5', '225 tc4 @5', '247.5 tc3 @5',
        '270 ta4 @5', '292.5 ta5 @5',
    ]);
});

test('a tournament that finishes while its live bracket is on ends the slide at once; its next round shows the champion', function () {
    $log = liveRotation(185, fn (float $t): array => [['id' => 5, 'phase' => $t < 35 ? 'running' : 'finished', 'fomo' => false]]);

    // Still standing from 22.5 s ends at 35, the rest of the hold is skipped: the loop, then the champion.
    expect($log)->toBe(['0 ta4 @5', '22.5 ta5 @5', '65 tb6 @5', '80 tb4 @5', '143 tc6 @5', '158 tc5 @5']);
});

test('a running tournament takes the stream within one planner step, two running ones alternate with the hold', function () {
    $planner = new RotationPlanner(45, 60, 20, 12, 3, 3, 30, 15, runningSeconds: 90);
    $game = [['id' => 7, 'blitz' => true]];

    // A chess match is on show; the tournament starts at 10 s and takes the very next step.
    expect($planner->at(0, $game)['kind'])->toBe(RotationPlanner::MATCH);
    $slot = $planner->at(10, $game, [], BoardScene::OFF, [['id' => 5, 'phase' => 'running', 'fomo' => false]]);
    expect($slot)->toMatchArray(['kind' => RotationPlanner::TOURNAMENT, 'scene' => 'tb4', 'tournamentId' => 5])
        ->and($planner->runningTournament())->toBe(5);

    $two = [['id' => 5, 'phase' => 'running', 'fomo' => false], ['id' => 6, 'phase' => 'running', 'fomo' => false]];
    $shown = [];
    for ($t = 10.25; $t < 10 + 3 * 90 + 12; $t += 0.25) {
        $slot = $planner->at($t, [], [], BoardScene::OFF, $two);
        $label = $slot['kind'] === RotationPlanner::TOURNAMENT ? '@'.$slot['tournamentId'].' '.$slot['until'] : $slot['kind'];
        if (end($shown) !== $label) {
            $shown[] = $label;
        }
    }

    // 5 holds 90 s in four slides, then 6 for 90 s, then 5 again, nothing in between: never the same twice in a row.
    expect($shown)->toBe(['@5 32.5', '@5 55', '@5 77.5', '@5 100', '@6 122.5', '@6 145', '@6 167.5', '@6 190', '@5 212.5', '@5 235', '@5 257.5', '@5 280', '@6 302.5']);
});

test('a casual cup between its live matches does not take the stream: it is one slot among the others, and takes over once a match is live', function () {
    $quiet = liveRotation(306, fn () => [['id' => 7, 'phase' => 'running', 'fomo' => false, 'takeover' => false]]);
    $live = liveRotation(306, fn () => [['id' => 7, 'phase' => 'running', 'fomo' => false, 'takeover' => true]]);

    // Quiet: far fewer tournament slides than a takeover's back-to-back holds, so the rest of the rotation shows between them.
    expect(count($quiet))->toBeLessThan(count($live))
        ->and($live)->toHaveCount(14);
});
