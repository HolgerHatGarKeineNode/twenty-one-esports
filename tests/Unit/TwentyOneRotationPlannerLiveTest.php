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

test('a running tournament: its live bracket every round, then still standing and how it runs in turn, then the next one to sign up for', function () {
    // Rounds without games: loop at 0, tournament rounds at 30 and 123 (3 slides and 4 teasers each), loop at 216.
    $log = liveRotation(30 + 93 + 93 + 30 + 60, fn () => [['id' => 5, 'phase' => 'running', 'fomo' => true]]);

    expect($log)->toBe([
        '30 ta4 @5', '45 ta5 @5', '60 ta7 @5',
        '123 tb4 @5', '138 tb3 @5', '153 tb7 @5',
        '246 tc4 @5', '261 tc5 @5', '276 tc7 @5',
    ]);
});

test('without an open tournament to point to, the call to sign up is left out', function () {
    $log = liveRotation(30 + 60, fn () => [['id' => 5, 'phase' => 'running', 'fomo' => false]]);

    expect($log)->toBe(['30 ta4 @5', '45 ta5 @5']);
});

test('a finished tournament shows its champion, then the final bracket and its pride in turn; a drawing one how it runs', function () {
    $finished = liveRotation(30 + 93 + 60, fn () => [['id' => 8, 'phase' => 'finished', 'fomo' => true]]);
    $drawing = liveRotation(30 + 40, fn () => [['id' => 3, 'phase' => 'drawing', 'fomo' => true]]);

    expect($finished)->toBe(['30 ta6 @8', '45 ta4 @8', '60 ta7 @8', '123 tb6 @8', '138 tb5 @8', '153 tb7 @8'])
        ->and($drawing)->toBe(['30 ta3 @3', '45 ta7 @3']);
});

test('tournaments past sign-up and upcoming ones take turns, one per round, the live ones first', function () {
    $log = liveRotation(30 + 93 + 78 + 30 + 60, fn () => [['id' => 5, 'phase' => 'running', 'fomo' => false]], fn () => [9]);

    expect($log)->toBe([
        '30 ta4 @5', '45 ta5 @5',
        '108 tb1 @9', '123 tb2 @9',
        '216 tc4 @5', '231 tc3 @5',
    ]);
});

test('a tournament that finishes while its live bracket is on ends the slide at once; its next round shows the champion', function () {
    $log = liveRotation(30 + 93 + 60, fn (float $t): array => [['id' => 5, 'phase' => $t < 35 ? 'running' : 'finished', 'fomo' => false]]);

    // The bracket from 30 s ends at 35 instead of 45, the rest of that round's running slides are skipped.
    expect($log)->toBe(['30 ta4 @5', '83 tb6 @5', '98 tb5 @5']);
});
