<?php

namespace App\Support\Pong;

use App\Support\Hyper\HyperRng;

/**
 * One rally of Proof of Pong (plan "Proof of Pong", P1), from the serve to the last goal, mirrored by
 * resources/js/pong/physics.js (createRally/stepRally). Built from the rally's seed and its event; step() moves it
 * one tick with each side's paddle target, so the rally depends on nothing but those targets.
 *
 * A tick: both paddles move towards their target (at most their speed), then every ball moves (PongPhysics::step(),
 * which is PongPhysics::move() plus the obstacles and the queue of the P7 events),
 * then a ball whose front edge reached a paddle's face in this tick either hits (its centre within the paddle's half
 * length plus its radius) or goes on to the goal line, where it scores for the other side.
 *
 * A hit mirrors the ball off the face, makes it faster and sends it at an angle that grows with the distance from
 * the paddle's centre. The log (`events`) has a line per serve, hit, goal and a rally that hit the tick cap:
 * ['serve', tick, ball, x, y, vx, vy], ['hit', tick, side, ball, y, vy], ['goal', tick, scorer, ball],
 * ['void', tick].
 */
final class PongRally
{
    public int $tick = 0;

    /** @var list<array{0: int, 1: int, 2: int, 3: int, 4: int, 5?: int}> [x, y, vx, vy, r], a sixth element while it waits in the Arbeitsamt's queue */
    public array $balls = [];

    /** @var array<int, bool> per ball, in serve order */
    public array $alive = [];

    /** @var array<int, int> hits per ball, in serve order */
    public array $hits = [];

    /** @var array{int, int} hits per side (Proof of Work grows a paddle with them) */
    public array $sideHits = [0, 0];

    /** @var array{int, int} paddle centres, y */
    public array $paddles = [PongPhysics::HEIGHT >> 1, PongPhysics::HEIGHT >> 1];

    /** @var list<array<int, int|string>> */
    public array $events = [];

    /** @var list<array{int, int, int}> [tick, scorer, ball] */
    public array $goals = [];

    public bool $over = false;

    public readonly int $half;

    public readonly int $points;

    public readonly int $base;

    public readonly int $speedup;

    public readonly int $max;

    /**
     * @param  array{int, int}  $speeds  each side's paddle speed in units per tick
     */
    public function __construct(public readonly int $seed, public readonly ?string $event, public readonly array $speeds)
    {
        $brrr = $event === PongRules::BRRR;
        $this->base = $brrr ? intdiv(PongPhysics::BASE_SPEED * 3, 2) : PongPhysics::BASE_SPEED;
        $this->speedup = $brrr ? intdiv(PongPhysics::SPEEDUP * 3, 2) : PongPhysics::SPEEDUP;
        $this->max = $brrr ? intdiv(PongPhysics::MAX_SPEED * 3, 2) : PongPhysics::MAX_SPEED;
        $this->half = PongPhysics::halfOf($event, 0);
        $this->points = $event === PongRules::HALVING ? 2 : 1;
        $radius = $event === PongRules::HALVING ? intdiv(PongPhysics::BALL_RADIUS, 2) : PongPhysics::BALL_RADIUS;

        // The serve: a side, a height and an angle per ball. Pizza Day serves its second ball to the other side.
        $rng = HyperRng::seeded($seed);
        $towards = $rng->below(2);

        foreach ($event === PongRules::PIZZA ? [$towards, 1 - $towards] : [$towards] as $index => $side) {
            $y = intdiv(PongPhysics::HEIGHT, 2) + ($rng->below(5) - 2) * 6000;
            $vy = PongPhysics::floorDiv(($rng->below(9) - 4) * $this->base, 8);
            $vx = $side === 0 ? -$this->base : $this->base;
            $this->balls[] = [intdiv(PongPhysics::WIDTH, 2), $y, $vx, $vy, $radius];
            $this->alive[] = true;
            $this->hits[] = 0;
            $this->events[] = ['serve', 0, $index, intdiv(PongPhysics::WIDTH, 2), $y, $vx, $vy];
        }
    }

    /**
     * One tick: paddles towards their targets, then the balls.
     *
     * @param  array{int, int}  $targets  each side's wanted paddle centre
     */
    public function step(array $targets): void
    {
        if ($this->over) {
            return;
        }

        $this->tick++;

        foreach ([0, 1] as $side) {
            $half = $this->halfOf($side);
            $delta = max(-$this->speeds[$side], min($this->speeds[$side], $targets[$side] - $this->paddles[$side]));
            $this->paddles[$side] = max($half, min(PongPhysics::HEIGHT - $half, $this->paddles[$side] + $delta));
        }

        foreach ($this->balls as $index => $ball) {
            if (! $this->alive[$index]) {
                continue;
            }

            $this->balls[$index] = $this->advance($index, $ball);
        }

        if (! in_array(true, $this->alive, true)) {
            $this->over = true;
        } elseif ($this->tick >= PongPhysics::RALLY_TICK_CAP) {
            $this->over = true;
            $this->events[] = ['void', $this->tick];
        }
    }

    /**
     * Side `$side`'s paddle half length now (only Proof of Work changes it during a rally).
     */
    public function halfOf(int $side): int
    {
        return PongPhysics::halfOf($this->event, $this->sideHits[$side]);
    }

    /**
     * @param  array{0: int, 1: int, 2: int, 3: int, 4: int, 5?: int}  $ball
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5?: int}
     */
    private function advance(int $index, array $ball): array
    {
        $side = $ball[2] < 0 ? 0 : 1;
        $before = PongPhysics::approaches($ball, $side);
        $ball = PongPhysics::step($ball, $this->tick, $this->event, $this->seed);
        [$x, $y] = $ball;
        $half = $this->halfOf($side);

        if ($before && PongPhysics::crossed($ball, $side) && PongPhysics::meets($ball, $this->paddles[$side], $half)) {
            $this->hits[$index]++;
            $this->sideHits[$side]++;
            $ball = PongPhysics::bounce($ball, $side, $this->paddles[$side], $half, $this->speedAfter($index));
            $this->events[] = ['hit', $this->tick, $side, $index, $y, $ball[3]];

            return $ball;
        }

        if ($x <= 0 || $x >= PongPhysics::WIDTH) {
            $scorer = $x <= 0 ? 1 : 0;
            $this->alive[$index] = false;
            $this->goals[] = [$this->tick, $scorer, $index];
            $this->events[] = ['goal', $this->tick, $scorer, $index];
        }

        return $ball;
    }

    /**
     * Ball `$index`'s speed along x after the hit it just made.
     */
    public function speedAfter(int $index): int
    {
        return PongPhysics::speedAfter($this->hits[$index], $this->base, $this->speedup, $this->max);
    }

    /**
     * The rally's state, as resources/js/pong/physics.js writes it (golden fixtures, the page's test seam).
     *
     * @return array{tick: int, balls: list<array{0: int, 1: int, 2: int, 3: int, 4: int, 5?: int}>, alive: array<int, bool>, paddles: array{int, int}, events: list<array<int, int|string>>, over: bool}
     */
    public function toArray(): array
    {
        return ['tick' => $this->tick, 'balls' => $this->balls, 'alive' => $this->alive, 'paddles' => $this->paddles, 'events' => $this->events, 'over' => $this->over];
    }
}
