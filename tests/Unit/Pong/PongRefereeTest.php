<?php

use App\Support\Pong\PongBot;
use App\Support\Pong\PongGame;
use App\Support\Pong\PongPhysics;
use App\Support\Pong\PongRally;
use App\Support\Pong\PongReferee;
use App\Support\Pong\PongRules;

/*
| The live referee of Proof of Pong (plan "Proof of Pong", P2): honest reports of both defenders give exactly the
| game PongGame::bots() plays (so two pages and the server agree), and a fake hit, a report by the attacker, a
| duplicate or a report out of step can bend nothing.
*/

/**
 * A whole game under the referee: each rally played by two bots on PongRally (as the two pages do), and every
 * contact reported by its defender, the hit with the paddle of that tick.
 *
 * @param  array{int, int}  $levels
 * @return array{referee: PongReferee, reports: int}
 */
function pongRefereeGame(int $seed, array $levels, int $maxRallies = 500): array
{
    $rules = new PongRules;
    $referee = PongReferee::start($seed, $rules, 0);
    $reports = 0;

    while ($referee->winner === null && $referee->rally <= $maxRallies) {
        $number = $referee->rally;
        $rallySeed = PongRules::rallySeed($seed, $number);
        $rally = new PongRally($rallySeed, $rules->eventOf($seed, $number), [PongBot::speed($levels[0]), PongBot::speed($levels[1])]);
        $bots = [new PongBot($levels[0], 0, $rallySeed), new PongBot($levels[1], 1, $rallySeed)];

        while (! $rally->over && $referee->rally === $number && $referee->winner === null) {
            // Which balls run at a face before this tick.
            $before = array_map(fn (array $ball): ?int => PongPhysics::approaches($ball, $ball[2] < 0 ? 0 : 1) ? ($ball[2] < 0 ? 0 : 1) : null, $rally->balls);
            $logged = count($rally->events);
            $rally->step([$bots[0]->target($rally), $bots[1]->target($rally)]);
            $hits = [];

            foreach (array_slice($rally->events, $logged) as $event) {
                if ($event[0] === 'hit') {
                    $hits[$event[3]] = $event[2];
                }
            }

            foreach ($rally->balls as $index => $ball) {
                $side = $before[$index];

                if ($side === null || $referee->rally !== $number || $referee->winner !== null) {
                    continue;
                }

                if (isset($hits[$index])) {
                    expect($referee->report($side, $index, $rally->tick, 'hit', $rally->paddles[$side]))->toBe('hit');
                    $reports++;
                } elseif (PongPhysics::crossed($ball, $side)) {
                    expect($referee->report($side, $index, $rally->tick, 'goal'))->toBe('miss');
                    $reports++;
                }

                $referee->nextIfOver(0);
            }
        }

        // A rally past the tick cap ends without a contact to report.
        $referee->nextIfOver(0);
    }

    return ['referee' => $referee, 'reports' => $reports];
}

/** The referee in rally 1 with ball 0 a tick before its first contact. */
function pongFirstContact(int $seed = 7): array
{
    $referee = PongReferee::start($seed, new PongRules, 0);
    $contact = $referee->contact(0);

    return [$referee, $contact];
}

it('agrees with PongGame::bots() for whole games when both defenders report honestly', function (int $seed, int $left, int $right) {
    $expected = PongGame::bots($seed, [$left, $right]);
    ['referee' => $referee, 'reports' => $reports] = pongRefereeGame($seed, [$left, $right]);

    expect($referee->score)->toBe($expected['score'])
        ->and($referee->winner)->toBe($expected['winner'])
        ->and($referee->rally)->toBe($expected['rallies'])
        ->and($reports)->toBeGreaterThan(40);
})->with([
    'seed 2, levels 3 and 2' => [2, 3, 2],
    'seed 7, levels 2 and 3' => [7, 2, 3],
    'seed 11, levels 4 and 4 (long rallies)' => [11, 4, 4],
    'seed 21, levels 1 and 4' => [21, 1, 4],
]);

it('takes an honest hit and sends the ball back as PongRally does', function () {
    [$referee, $contact] = pongFirstContact();
    $side = $contact['side'];
    $y = $contact['ball'][1];

    expect($referee->report($side, 0, $contact['tick'], 'hit', max(PongPhysics::PADDLE_HALF, min(PongPhysics::HEIGHT - PongPhysics::PADDLE_HALF, $y))))->toBe('hit')
        ->and($referee->balls[0]['hits'])->toBe(1)
        ->and($referee->balls[0]['t'])->toBe($contact['tick'])
        ->and($referee->score)->toBe([0, 0])
        ->and($referee->contact(0)['side'])->toBe(1 - $side);
});

it('scores a hit for the attacker when the paddle could not have got there (speed)', function () {
    [$referee, $contact] = pongFirstContact();
    $side = $contact['side'];
    $y = max(PongPhysics::PADDLE_HALF, min(PongPhysics::HEIGHT - PongPhysics::PADDLE_HALF, $contact['ball'][1]));
    // The side's last accepted position five ticks before the contact (as after a Pizza Day ball's hit), one unit
    // farther from the claim than five ticks at top speed allow; the claim itself meets the ball.
    $jump = PongPhysics::PLAYER_SPEED * 5 + 1;
    $last = $y > PongPhysics::HEIGHT >> 1 ? $y - $jump : $y + $jump;
    $referee->paddles[$side] = [$last, $contact['tick'] - 5];

    expect(PongPhysics::meets($contact['ball'], $y, PongPhysics::PADDLE_HALF))->toBeTrue()
        ->and($referee->report($side, 0, $contact['tick'], 'hit', $y))->toBe('miss')
        ->and($referee->balls[0]['state'])->toBe(PongReferee::GOAL)
        ->and($referee->score[1 - $side])->toBe(1)
        ->and($referee->score[$side])->toBe(0)
        ->and(collect($referee->takeLog())->last())->toBe(['goal', 1, $contact['tick'], $side, 0, $y, 'speed']);

    // One unit nearer, the same claim is a hit.
    [$referee, $contact] = pongFirstContact();
    $referee->paddles[$side] = [$y > PongPhysics::HEIGHT >> 1 ? $last + 1 : $last - 1, $contact['tick'] - 5];
    expect($referee->report($side, 0, $contact['tick'], 'hit', $y))->toBe('hit');
});

it('scores a hit for the attacker when the paddle does not meet the ball (geometry), and one off the field (range)', function () {
    [$referee, $contact] = pongFirstContact();
    $side = $contact['side'];
    $ballY = $contact['ball'][1];
    // Reachable, on the field, but more than half a paddle plus the ball's radius away from the ball.
    $miss = $ballY > PongPhysics::HEIGHT >> 1 ? $ballY - PongPhysics::PADDLE_HALF - PongPhysics::BALL_RADIUS - 1 : $ballY + PongPhysics::PADDLE_HALF + PongPhysics::BALL_RADIUS + 1;

    expect($referee->report($side, 0, $contact['tick'], 'hit', $miss))->toBe('miss')
        ->and($referee->score[1 - $side])->toBe(1)
        ->and(collect($referee->takeLog())->last())->toBe(['goal', 1, $contact['tick'], $side, 0, $miss, 'geometry']);

    [$referee, $contact] = pongFirstContact();
    expect($referee->report($contact['side'], 0, $contact['tick'], 'hit', 10))->toBe('miss')
        ->and(collect($referee->takeLog())->last()[6])->toBe('range');
});

it('changes nothing when the attacker reports, so nobody can score a goal for themselves', function () {
    [$referee, $contact] = pongFirstContact();
    $attacker = 1 - $contact['side'];
    $before = $referee->toArray();

    expect($referee->report($attacker, 0, $contact['tick'], 'goal'))->toBe('not_defender')
        ->and($referee->report($attacker, 0, $contact['tick'], 'hit', $contact['ball'][1]))->toBe('not_defender')
        ->and($referee->toArray())->toBe($before);
});

it('answers a duplicate report as it was decided, and ignores a report out of step', function () {
    [$referee, $contact] = pongFirstContact();
    $side = $contact['side'];
    $y = max(PongPhysics::PADDLE_HALF, min(PongPhysics::HEIGHT - PongPhysics::PADDLE_HALF, $contact['ball'][1]));

    expect($referee->report($side, 0, $contact['tick'] + 3, 'hit', $y))->toBe('desync')
        ->and($referee->balls[0]['hits'])->toBe(0);

    expect($referee->report($side, 0, $contact['tick'], 'hit', $y))->toBe('hit');
    $after = $referee->toArray();

    // The same hit again, and a late "goal" for the same contact: decided, nothing changes.
    expect($referee->report($side, 0, $contact['tick'], 'hit', $y))->toBe('duplicate')
        ->and($referee->report($side, 0, $contact['tick'], 'goal'))->toBe('duplicate')
        ->and($referee->toArray())->toBe($after);
});

it('decides a contact nobody reported as a miss once the grace is over, and never while paused', function () {
    [$referee, $contact] = pongFirstContact();
    $side = $contact['side'];
    $due = $referee->timeOf($contact['tick']) + PongReferee::GRACE_MS;

    expect($referee->due($due))->toBe(0);

    $referee->pause($due);
    expect($referee->due($due + 60_000))->toBe(0);

    // Back after a minute: the clock goes on from where it stopped, so the contact is due a minute later.
    $servedAt = $referee->servedAt;
    $referee->resume($due + 60_000);
    expect($referee->servedAt)->toBe($servedAt + 60_000)
        ->and($referee->due($due + 60_000))->toBe(0)
        ->and($referee->due($due + 60_001))->toBe(1)
        ->and($referee->score[1 - $side])->toBe(1)
        ->and(collect($referee->takeLog())->last()[6])->toBe('timeout');
});
