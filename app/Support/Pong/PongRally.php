<?php

namespace App\Support\Pong;

use App\Support\Hyper\HyperRng;

/**
 * One rally of Proof of Pong (plan "Proof of Pong", P1), from the serve to the last goal, mirrored by
 * resources/js/pong/physics.js (createRally/stepRally). Built from the rally's seed and its event; step() moves it
 * one tick with each side's paddle target, so the rally depends on nothing but those targets.
 *
 * A tick: both paddles move towards their target (at most their speed), then every ball moves (PongPhysics::move()),
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

    /** @var list<array{int, int, int, int, int}> [x, y, vx, vy, r] */
    public array $balls = [];

    /** @var array<int, bool> per ball, in serve order */
    public array $alive = [];

    /** @var array<int, int> hits per ball, in serve order */
    public array $hits = [];

    /** @var array{int, int} paddle centres, y */
    public array $paddles = [PongPhysics::HEIGHT >> 1, PongPhysics::HEIGHT >> 1];

    /** @var list<array<int, int|string>> */
    public array $events = [];

    /** @var list<array{int, int, int}> [tick, scorer, ball] */
    public array $goals = [];

    public bool $over = false;

    public readonly int $half;

    public readonly int $points;

    private readonly int $base;

    private readonly int $speedup;

    private readonly int $max;

    /**
     * @param  array{int, int}  $speeds  each side's paddle speed in units per tick
     */
    public function __construct(public readonly int $seed, public readonly ?string $event, public readonly array $speeds)
    {
        $brrr = $event === PongRules::BRRR;
        $this->base = $brrr ? intdiv(PongPhysics::BASE_SPEED * 3, 2) : PongPhysics::BASE_SPEED;
        $this->speedup = $brrr ? intdiv(PongPhysics::SPEEDUP * 3, 2) : PongPhysics::SPEEDUP;
        $this->max = $brrr ? intdiv(PongPhysics::MAX_SPEED * 3, 2) : PongPhysics::MAX_SPEED;
        $this->half = $event === PongRules::DIFFICULTY ? intdiv(PongPhysics::PADDLE_HALF * 2, 3) : PongPhysics::PADDLE_HALF;
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
            $delta = max(-$this->speeds[$side], min($this->speeds[$side], $targets[$side] - $this->paddles[$side]));
            $this->paddles[$side] = max($this->half, min(PongPhysics::HEIGHT - $this->half, $this->paddles[$side] + $delta));
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
     * @param  array{int, int, int, int, int}  $ball
     * @return array{int, int, int, int, int}
     */
    private function advance(int $index, array $ball): array
    {
        $side = $ball[2] < 0 ? 0 : 1;
        $before = PongPhysics::approaches($ball, $side);
        $ball = PongPhysics::move($ball);
        [$x, $y, , , $r] = $ball;

        if ($before && PongPhysics::crossed($ball, $side)) {
            $reach = $this->half + $r;
            $offset = $y - $this->paddles[$side];

            if (abs($offset) <= $reach) {
                $this->hits[$index]++;
                $speed = PongPhysics::speedAfter($this->hits[$index], $this->base, $this->speedup, $this->max);
                $face = PongPhysics::faceX($side);
                // Mirrored off the face: the ball's front edge ends as far in front of it as it went past it.
                $x = $side === 0 ? 2 * ($face + $r) - $x : 2 * ($face - $r) - $x;
                $vy = PongPhysics::floorDiv($offset * $speed * PongPhysics::ANGLE_NUM, $reach * PongPhysics::ANGLE_DEN);
                $this->events[] = ['hit', $this->tick, $side, $index, $y, $vy];

                return [$x, $y, $side === 0 ? $speed : -$speed, $vy, $r];
            }
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
     * The rally's state, as resources/js/pong/physics.js writes it (golden fixtures, the page's test seam).
     *
     * @return array{tick: int, balls: list<array{int, int, int, int, int}>, alive: array<int, bool>, paddles: array{int, int}, events: list<array<int, int|string>>, over: bool}
     */
    public function toArray(): array
    {
        return ['tick' => $this->tick, 'balls' => $this->balls, 'alive' => $this->alive, 'paddles' => $this->paddles, 'events' => $this->events, 'over' => $this->over];
    }
}
