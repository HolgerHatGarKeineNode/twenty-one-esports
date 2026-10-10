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
function pongRefereeGame(int $seed, array $levels, int $maxRallies = 500, PongRules $rules = new PongRules): array
{
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

/**
 * A referee whose rally 1 is meme event `$event` (every rally an event), the first seed from `$from` where it is, and
 * optionally where `$accept` holds for it.
 */
function pongEventReferee(string $event, int $from = 1, ?Closure $accept = null): PongReferee
{
    $rules = new PongRules(eventBlock: 1);

    for ($seed = $from; $seed < $from + 5000; $seed++) {
        if ($rules->eventOf($seed, 1) === $event) {
            $referee = PongReferee::start($seed, $rules, 0);

            if ($accept === null || $accept($referee)) {
                return $referee;
            }
        }
    }

    throw new RuntimeException("No seed with {$event} in rally 1.");
}

/** A paddle centre on the field for side `$side` in the referee's rally (Proof of Work grows the half). */
function pongOnField(PongReferee $referee, int $side, int $y): int
{
    $half = $referee->halfOf($side);

    return max($half, min(PongPhysics::HEIGHT - $half, $y));
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

it('agrees with PongGame::bots() when every rally is a meme event, all nine of them included', function (int $seed, int $left, int $right) {
    $rules = new PongRules(eventBlock: 1);
    $expected = PongGame::bots($seed, [$left, $right], $rules);
    ['referee' => $referee] = pongRefereeGame($seed, [$left, $right], 500, $rules);

    expect(array_values(array_unique(array_column($expected['events'], 1))))->toEqualCanonicalizing(PongRules::EVENTS)
        ->and($referee->score)->toBe($expected['score'])
        ->and($referee->winner)->toBe($expected['winner'])
        ->and($referee->rally)->toBe($expected['rallies']);
})->with([
    'seed 3, levels 2 and 3' => [3, 2, 3],
    'seed 21, levels 4 and 4' => [21, 4, 4],
]);

it('takes an honest hit and scores a fake one (paddle away from the ball) for the attacker in every P7 event', function (string $event) {
    foreach ([[1, true], [1, false]] as [$from, $honest]) {
        $referee = pongEventReferee($event, $from);
        $contact = $referee->contact(0);
        $side = $contact['side'];
        $y = $contact['ball'][1];
        $half = $referee->halfOf($side);
        $claim = $honest ? pongOnField($referee, $side, $y) : ($y > PongPhysics::HEIGHT >> 1 ? $y - $half - $contact['ball'][4] - 1 : $y + $half + $contact['ball'][4] + 1);

        if ($honest) {
            expect($referee->report($side, 0, $contact['tick'], 'hit', $claim))->toBe('hit', $event)
                ->and($referee->score)->toBe([0, 0]);
        } else {
            expect($referee->report($side, 0, $contact['tick'], 'hit', $claim))->toBe('miss', $event)
                ->and($referee->score[1 - $side])->toBe(1)
                ->and(collect($referee->takeLog())->last()[6])->toBe('geometry');
        }
    }
})->with([PongRules::TAX, PongRules::CONTROLS, PongRules::FEW, PongRules::POW, PongRules::ARBEITSAMT]);

it('makes the side that played the ball defend it when the tax block or the border wall sends it back', function (string $event) {
    // The serve starts inside the obstacle's band and is never stopped; a returned ball can be. A rally where the
    // first return is sent back by the obstacle to the side that hit it.
    $hitFirst = function (PongReferee $referee): bool {
        $contact = $referee->contact(0);
        $referee->report($contact['side'], 0, $contact['tick'], 'hit', pongOnField($referee, $contact['side'], $contact['ball'][1]));

        return $referee->balls[0]['state'] === PongReferee::LIVE && $referee->contact(0)['side'] === $contact['side'];
    };
    $referee = pongEventReferee($event, 1, $hitFirst);
    $hitter = $referee->balls[0]['b'][2] > 0 ? 0 : 1;
    $contact = $referee->contact(0);
    $before = $referee->toArray();

    // The side the return ran at has nothing to report; the hitter, whom the obstacle sends it back to, does.
    expect($contact['side'])->toBe($hitter)
        ->and($referee->report(1 - $hitter, 0, $contact['tick'], 'goal'))->toBe('not_defender')
        ->and($referee->toArray())->toBe($before)
        // Without the event the same return reaches the other face.
        ->and(PongPhysics::predict($before['balls'][0]['b'], 1 - $hitter))->not->toBeNull()
        ->and($referee->report($hitter, 0, $contact['tick'], 'goal'))->toBe('miss')
        ->and($referee->score[1 - $hitter])->toBe(1);
})->with([PongRules::TAX, PongRules::CONTROLS]);

it('judges two contacts of one side reported out of tick order without a negative speed budget', function () {
    // A stationary defender: the later contact (tick 100) accepted first, then the earlier one (tick 98).
    [$referee, $contact] = pongFirstContact();
    $side = $contact['side'];
    $middle = PongPhysics::HEIGHT >> 1;
    $referee->paddles[$side] = [$middle, $contact['tick'] + 2];

    expect($referee->refusal($side, $middle, ['side' => $side, 'tick' => $contact['tick'], 'ball' => [PongPhysics::faceX($side), $middle, 1000, 0, PongPhysics::BALL_RADIUS]]))->toBeNull();

    // Still bounded on both sides: a jump the paddle cannot make in two ticks to the later sample is refused.
    $far = $middle + PongPhysics::PLAYER_SPEED * 2 + 1;
    expect($referee->refusal($side, $far, ['side' => $side, 'tick' => $contact['tick'], 'ball' => [PongPhysics::faceX($side), $far, 1000, 0, PongPhysics::BALL_RADIUS]]))->toBe('speed');
});

it('accepts two balls at one face reported in ball order, the later contact first, in a Pizza Day and a P7 rally', function (string $event) {
    // Two balls at the same side's face a few ticks apart; the page reports them in ball order, not tick order.
    $rules = new PongRules(eventBlock: 1);
    $seed = 1;

    while ($rules->eventOf($seed, 1) !== $event) {
        $seed++;
    }

    $referee = PongReferee::start($seed, $rules, 0);
    $side = 0;
    $middle = PongPhysics::HEIGHT >> 1;
    $face = PongPhysics::faceX($side);
    $r = $referee->balls[0]['b'][4];
    // Ball 0 crosses the face in 3 ticks, ball 1 in 1 tick, both at the paddle's centre (seeded far from the centre
    // line, so no obstacle or queue is near them).
    $referee->balls = [
        ['b' => [$face + $r + 2500, $middle, -1000, 0, $r], 't' => 10, 'hits' => 0, 'state' => PongReferee::LIVE, 'goal' => null],
        ['b' => [$face + $r + 500, $middle, -1000, 0, $r], 't' => 10, 'hits' => 0, 'state' => PongReferee::LIVE, 'goal' => null],
    ];
    $late = $referee->contact(0);
    $early = $referee->contact(1);

    expect($late['tick'])->toBeGreaterThan($early['tick'])
        ->and($referee->report($side, 0, $late['tick'], 'hit', $middle))->toBe('hit')
        ->and($referee->report($side, 1, $early['tick'], 'hit', $middle))->toBe('hit')
        ->and($referee->score)->toBe([0, 0]);
})->with([PongRules::PIZZA, PongRules::TAX, PongRules::CONTROLS, PongRules::ARBEITSAMT, PongRules::POW]);

it('judges a Proof of Work hit with the paddle the side has grown by its accepted hits', function () {
    $referee = pongEventReferee(PongRules::POW);
    $contact = $referee->contact(0);
    $side = $contact['side'];
    $ball = $contact['ball'];
    // Beyond a plain paddle's reach, within the paddle after two hits.
    $offset = PongPhysics::PADDLE_HALF + $ball[4] + 1000;
    $claim = $ball[1] > PongPhysics::HEIGHT >> 1 ? $ball[1] - $offset : $ball[1] + $offset;
    $referee->paddles[$side] = [$claim, 0];

    expect($referee->refusal($side, $claim, $contact))->toBe('geometry');

    $referee->sideHits[$side] = 2;
    expect($referee->halfOf($side))->toBe(PongPhysics::PADDLE_HALF + 2 * PongPhysics::POW_GROW)
        ->and($referee->halfOf(1 - $side))->toBe(PongPhysics::PADDLE_HALF)
        ->and($referee->toArray()['halves'][$side])->toBe(PongPhysics::PADDLE_HALF + 2 * PongPhysics::POW_GROW)
        ->and($referee->report($side, 0, $contact['tick'], 'hit', $claim))->toBe('hit')
        ->and($referee->sideHits[$side])->toBe(3);

    // A new rally starts with plain paddles again.
    $referee = pongEventReferee(PongRules::POW);
    expect($referee->sideHits)->toBe([0, 0]);
});

it('keeps an Arbeitsamt ball a second in the queue: the next contact comes QUEUE_TICKS later, and a stored state without hits per side loads', function () {
    $referee = pongEventReferee(PongRules::ARBEITSAMT);
    $contact = $referee->contact(0);
    $side = $contact['side'];

    expect($referee->report($side, 0, $contact['tick'], 'hit', pongOnField($referee, $side, $contact['ball'][1])))->toBe('hit');

    $returned = $referee->balls[0]['b'];
    $next = $referee->contact(0);
    [$plainTicks, $plainY] = PongPhysics::predict($returned, 1 - $side);

    expect($next['tick'] - $contact['tick'])->toBe($plainTicks + PongPhysics::QUEUE_TICKS)
        ->and($next['ball'][1])->toBe($plainY)
        ->and($next['side'])->toBe(1 - $side);

    // The grace runs from the contact's real tick, queue included.
    expect($referee->due($referee->timeOf($next['tick']) + PongReferee::GRACE_MS))->toBe(0);

    $state = $referee->toArray();
    unset($state['sideHits']);
    expect(PongReferee::fromArray($referee->seed, $referee->rules, $state)->sideHits)->toBe([0, 0]);
});

test('a referee restored from a state served under other rules judges the rally by its stored event, not the current draw', function () {
    // Review 2026-10-10: a match running across a deploy that changed the event order mixed a plain stored rally with
    // the new draw's points and paddle length (a plain rally judged as a Halving for double points).
    $rules = new PongRules;
    $seed = collect(range(1, 500))->first(fn (int $seed): bool => $rules->eventOf($seed, 1) === 'halving');
    $state = PongReferee::start($seed, $rules, 0)->toArray();

    expect($state['points'])->toBe(2);

    $state['event'] = null;
    $restored = PongReferee::fromArray($seed, $rules, $state)->toArray();

    expect($restored['event'])->toBeNull()
        ->and($restored['points'])->toBe(1)
        ->and($restored['half'])->toBe(PongPhysics::PADDLE_HALF);
});
