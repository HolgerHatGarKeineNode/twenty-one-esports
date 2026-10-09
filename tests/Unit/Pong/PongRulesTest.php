<?php

use App\Games\GameMode;
use App\Games\ProofOfPong;
use App\Support\Pong\PongBot;
use App\Support\Pong\PongGame;
use App\Support\Pong\PongPhysics;
use App\Support\Pong\PongRally;
use App\Support\Pong\PongRules;

/*
| Proof of Pong's rules core (plan "Proof of Pong", P1): a game to 21 with two points ahead at 20:20, the meme
| event of every 21st rally (the same for both sides, from the seed), what each event does to its rally, the ball's
| path between two hits, and four bot levels that return measurably differently.
*/

/** A rally played to its end, with a bot on each side. */
function pongRally(int $seed, ?string $event, int $left, int $right): PongRally
{
    $rally = new PongRally($seed, $event, [PongBot::speed($left), PongBot::speed($right)]);
    $bots = [new PongBot($left, 0, $seed), new PongBot($right, 1, $seed)];

    while (! $rally->over) {
        $rally->step([$bots[0]->target($rally), $bots[1]->target($rally)]);
    }

    return $rally;
}

it('ends a game at 21 only with two points ahead, and goes on after 20:20 until one side leads by two', function () {
    $rules = new PongRules;

    expect($rules->winner([21, 19]))->toBe(0)
        ->and($rules->winner([20, 21]))->toBeNull()
        ->and($rules->winner([21, 20]))->toBeNull()
        ->and($rules->winner([22, 20]))->toBe(0)
        ->and($rules->winner([25, 26]))->toBeNull()
        ->and($rules->winner([25, 27]))->toBe(1)
        ->and($rules->winner([0, 21]))->toBe(1);

    // Point by point from 20:20: 21:20, 21:21, 22:21 run on; 23:21 wins.
    $game = new PongGame(1);
    $game->score = [20, 20];

    expect($game->goal(0, 1))->toBeFalse()
        ->and($game->goal(1, 1))->toBeFalse()
        ->and($game->goal(0, 1))->toBeFalse()
        ->and($game->goal(0, 1))->toBeTrue()
        ->and($game->score)->toBe([23, 21])
        ->and($game->winner)->toBe(0)
        // A goal after the end changes nothing.
        ->and($game->goal(1, 1))->toBeTrue()
        ->and($game->score)->toBe([23, 21]);
});

it('takes its targets from the config', function () {
    $rules = PongRules::fromConfig(['points_to_win' => 11, 'win_by' => 2, 'event_every_rallies' => 5]);

    expect($rules->winner([11, 9]))->toBe(0)
        ->and($rules->winner([11, 10]))->toBeNull()
        ->and($rules->eventOf(3, 4))->toBeNull()
        ->and($rules->eventOf(3, 5))->toBeIn(PongRules::EVENTS)
        ->and($rules->toArray())->toBe(['points_to_win' => 11, 'win_by' => 2, 'event_every_rallies' => 5]);
});

it('makes every 21st rally a meme event, all four in each round of four, drawn from the seed', function () {
    $rules = new PongRules;

    foreach ([1, 7, 99, 4294967295] as $seed) {
        $events = array_map(fn (int $k): ?string => $rules->eventOf($seed, 21 * $k), range(1, 8));

        expect(array_slice($events, 0, 4))->toEqualCanonicalizing(PongRules::EVENTS)
            ->and(array_slice($events, 4, 4))->toEqualCanonicalizing(PongRules::EVENTS)
            ->and($rules->eventOf($seed, 20))->toBeNull()
            ->and($rules->eventOf($seed, 22))->toBeNull()
            ->and($rules->eventOf($seed, 0))->toBeNull();
    }

    // Another seed, another order (one of these four differs from seed 1's).
    $orders = array_map(fn (int $seed): array => array_map(fn (int $k): ?string => $rules->eventOf($seed, 21 * $k), range(1, 4)), [1, 2, 3, 4]);
    expect(array_unique(array_map('json_encode', $orders)))->not->toHaveCount(1);
});

it('gives each event its effect on that one rally, the same for both paddles', function () {
    $plain = new PongRally(5, null, [1000, 1000]);
    $halving = new PongRally(5, PongRules::HALVING, [1000, 1000]);
    $brrr = new PongRally(5, PongRules::BRRR, [1000, 1000]);
    $pizza = new PongRally(5, PongRules::PIZZA, [1000, 1000]);
    $difficulty = new PongRally(5, PongRules::DIFFICULTY, [1000, 1000]);

    expect($plain->balls)->toHaveCount(1)
        ->and($plain->balls[0][4])->toBe(PongPhysics::BALL_RADIUS)
        ->and($plain->points)->toBe(1)
        ->and($halving->balls[0][4])->toBe(PongPhysics::BALL_RADIUS / 2)
        ->and($halving->points)->toBe(2)
        ->and(abs($brrr->balls[0][2]))->toBe(PongPhysics::BASE_SPEED * 3 / 2)
        ->and(abs($plain->balls[0][2]))->toBe(PongPhysics::BASE_SPEED)
        // Pizza Day: two balls, one served to each side.
        ->and($pizza->balls)->toHaveCount(2)
        ->and($pizza->balls[0][2] * $pizza->balls[1][2])->toBeLessThan(0)
        ->and($difficulty->half)->toBe(PongPhysics::PADDLE_HALF * 2 / 3)
        ->and($plain->half)->toBe(PongPhysics::PADDLE_HALF);

    // A Halving goal counts twice; a Pizza Day rally ends when both balls are out.
    $game = new PongGame(3);
    $rally = pongRally(9, PongRules::PIZZA, 1, 1);
    expect(array_count_values(array_column($rally->events, 0))['goal'])->toBe(2)
        ->and($game->goal(1, (new PongRally(1, PongRules::HALVING, [1, 1]))->points))->toBeFalse()
        ->and($game->score)->toBe([0, 2]);
});

it('knows the ball\'s path from a hit to the paddle face: the predicted tick and height are the ones the rally reaches', function () {
    foreach ([11, 12, 13, 14, 15] as $seed) {
        $rally = new PongRally($seed, null, [PongPhysics::PLAYER_SPEED, PongPhysics::PLAYER_SPEED]);
        $ball = $rally->balls[0];
        $side = $ball[2] < 0 ? 0 : 1;
        [$ticks, $y] = PongPhysics::predict($ball, $side);
        // Paddles parked out of the way at the far end of the wall the ball does not reach.
        $park = $y < PongPhysics::HEIGHT / 2 ? PongPhysics::HEIGHT : 0;

        for ($tick = 0; $tick < $ticks; $tick++) {
            $rally->step([$park, $park]);
        }

        expect($rally->balls[0][1])->toBe($y)
            ->and(PongPhysics::crossed($rally->balls[0], $side))->toBeTrue()
            ->and(array_column($rally->events, 0))->toBe(['serve']);
    }
});

it('scores a ball that passes a paddle for the other side, and sends a hit ball back faster and steeper off the paddle edge', function () {
    // The serve runs at a paddle parked far away: a goal for the other side.
    $rally = new PongRally(11, null, [PongPhysics::PLAYER_SPEED, PongPhysics::PLAYER_SPEED]);
    $side = $rally->balls[0][2] < 0 ? 0 : 1;
    [, $y] = PongPhysics::predict($rally->balls[0], $side);
    $away = $y < PongPhysics::HEIGHT / 2 ? PongPhysics::HEIGHT : 0;

    while (! $rally->over) {
        $rally->step([$away, $away]);
    }

    expect($rally->goals)->toHaveCount(1)
        ->and($rally->goals[0][1])->toBe(1 - $side);

    // The same serve met at the paddle's centre, then at its edge: the edge sends it steeper.
    $vy = function (int $offset) use ($y): array {
        $rally = new PongRally(11, null, [PongPhysics::PLAYER_SPEED, PongPhysics::PLAYER_SPEED]);

        while (array_column($rally->events, 0) === ['serve']) {
            $rally->step([$y - $offset, $y - $offset]);
        }

        $hit = $rally->events[1];

        return [$hit[0], $hit[2], abs($rally->balls[0][2]), abs($hit[5])];
    };
    [$kind, $by, $speed, $flat] = $vy(0);
    [, , , $steep] = $vy(PongPhysics::PADDLE_HALF);

    expect($kind)->toBe('hit')
        ->and($by)->toBe($side)
        ->and($speed)->toBe(PongPhysics::BASE_SPEED + PongPhysics::SPEEDUP)
        ->and($steep)->toBeGreaterThan($flat + 500);
});

it('has four bot levels whose return rate rises from the Nocoiner uncle to Madame Brrr Lagarde', function () {
    $rates = [];

    foreach ([1, 2, 3, 4] as $level) {
        $returns = 0;
        $misses = 0;

        // 300 rallies against the same opponent (level 3 on side 0), the same seeds for every level.
        foreach (range(1, 300) as $number) {
            $rally = pongRally(PongRules::rallySeed(777, $number), null, 3, $level);
            $returns += count(array_filter($rally->events, fn (array $event): bool => $event[0] === 'hit' && $event[2] === 1));
            $misses += count(array_filter($rally->events, fn (array $event): bool => $event[0] === 'goal' && $event[2] === 0));
        }

        $rates[$level] = round($returns / ($returns + $misses), 3);
    }

    fwrite(STDERR, 'Pong bot return rates: '.json_encode($rates).PHP_EOL);

    expect($rates[1])->toBeLessThan($rates[2] - 0.05)
        ->and($rates[2])->toBeLessThan($rates[3] - 0.05)
        ->and($rates[3])->toBeLessThan($rates[4] - 0.05)
        ->and($rates[4])->toBeGreaterThan(0.9)
        ->and($rates[1])->toBeLessThan(0.7)
        // And a whole game: the end boss beats the Nocoiner uncle; the same seed plays the same game.
        ->and(PongGame::bots(42, [1, 4])['winner'])->toBe(1)
        ->and(PongGame::bots(42, [1, 4]))->toBe(PongGame::bots(42, [1, 4]))
        ->and(array_keys(PongBot::LEVELS))->toBe([1, 2, 3, 4])
        ->and(PongBot::LEVELS[4]['name'])->toBe('Madame Brrr Lagarde');
});

it('accepts a result only as a score a game can end with', function () {
    $pong = new ProofOfPong;
    $live = $pong->mode('live');

    expect($live)->toBeInstanceOf(GameMode::class)
        ->and($pong->mode('correspondence'))->toBeNull();

    foreach ([[21, 0], [19, 21], [23, 21], [22, 19], [24, 21], [30, 28]] as $score) {
        expect($pong->validateResult($live, ['score' => $score]))->toBe([], json_encode($score));
    }

    foreach ([[21, 20], [20, 18], [25, 20], [-1, 21], [21], ['21', 0], null] as $score) {
        expect($pong->validateResult($live, ['score' => $score]))->toBe(['score'], json_encode($score));
    }
});
