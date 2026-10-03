<?php

use App\Support\TwentyOne\Stream\BoardScene;
use App\Support\TwentyOne\Stream\RotationPlanner;
use App\Support\TwentyOne\Stream\TournamentLiveSlides;

/*
 * The champion moment on the stream (user, 2026-10-03): right after a
 * tournament is decided its champion slide (tx6, the big champion look)
 * takes the stream alone for `champion_moment_seconds`, as a running
 * tournament takes it; then the tournament joins the rotation as before.
 */

/**
 * Every slot a planner starts over `$seconds` without games: "t scene @id".
 *
 * @param  Closure(float): list<array<string, mixed>>  $liveAt
 * @return list<string>
 */
function championRotation(float $seconds, Closure $liveAt, float $championSeconds = 120): array
{
    $planner = new RotationPlanner(45, 60, 20, 12, 3, 3, 30, 15, championSeconds: $championSeconds);
    $log = [];
    $last = null;

    for ($t = 0.0; $t < $seconds; $t += 0.25) {
        $slot = $planner->at($t, [], [], BoardScene::OFF, $liveAt($t));
        $key = $slot['kind'].' '.$slot['scene'].' '.$slot['tournamentId'].' '.$slot['until'];

        if ($key !== $last) {
            $log[] = sprintf('%g %s @%s', $t, $slot['scene'] ?? $slot['kind'], $slot['tournamentId'] ?? '-');
        }

        $last = $key;
    }

    return $log;
}

/** Tournament 5 runs until 35 s, then is decided: its entry carries the moment while the stream daemon says so. */
function championLive(float $t): array
{
    return [['id' => 5, 'phase' => $t < 35 ? 'running' : 'finished', 'fomo' => false, 'moment' => $t >= 35]];
}

test('a decided tournament\'s champion slide takes the stream at once and alone for the configured time, then the rotation goes on as before', function () {
    $log = championRotation(400, championLive(...));

    expect(array_slice($log, 0, 4))->toBe(['0 tv1 @5', '22.5 tv2 @5', '35 tx6 @5', '155 loop @-'])
        // Never again, although the entry still carries the moment: the tournament is one of the rotation now.
        ->and(array_values(array_filter($log, fn (string $line): bool => str_contains($line, 'tx6'))))->toBe(['35 tx6 @5'])
        ->and($log)->toContain('185 ta6 @5');
});

test('the hold follows champion_moment_seconds', function () {
    $log = championRotation(120, championLive(...), championSeconds: 60);

    expect(array_slice($log, 0, 4))->toBe(['0 tv1 @5', '22.5 tv2 @5', '35 tx6 @5', '95 loop @-']);
});

test('the champion moment cuts into another running tournament, which takes the stream again after it', function () {
    $log = championRotation(260, fn (float $t): array => [
        ['id' => 7, 'phase' => 'running', 'fomo' => false],
        ['id' => 5, 'phase' => $t < 100 ? 'running' : 'finished', 'fomo' => false, 'moment' => $t >= 100],
    ]);

    expect($log)->toContain('100 tx6 @5')
        ->and(collect($log)->first(fn (string $line): bool => (float) $line >= 220 - 0.01 && (float) $line < 221))->toBe('220 tv1 @7')
        ->and(collect($log)->filter(fn (string $line): bool => (float) $line > 100 && (float) $line < 220)->all())->toBe([]);
});

test('a finished frame carries the moment while its finish is younger than the hold', function () {
    $frame = fn (int $finishedMs): array => ['id' => 5, 'phase' => 'finished', 'finishedMs' => $finishedMs];
    $now = 1_000_000_000;

    expect(TournamentLiveSlides::entries([$frame($now - 30_000)], [], $now, 120)[0]['moment'])->toBeTrue()
        ->and(TournamentLiveSlides::entries([$frame($now - 121_000)], [], $now, 120)[0]['moment'])->toBeFalse()
        // Without the clock (an old caller) no moment.
        ->and(TournamentLiveSlides::entries([$frame($now - 30_000)], [])[0]['moment'])->toBeFalse()
        ->and(TournamentLiveSlides::entries([['id' => 6, 'phase' => 'running', 'finishedMs' => null]], [], $now, 120)[0]['moment'])->toBeFalse();
});

test('the control: a finished tournament without the moment never gets the champion moment slide', function () {
    $log = championRotation(200, fn (): array => [['id' => 8, 'phase' => 'finished', 'fomo' => false]]);

    expect(implode(' ', $log))->not->toContain('tx6')->toContain('ta6 @8');
});
