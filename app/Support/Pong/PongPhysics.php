<?php

namespace App\Support\Pong;

/**
 * Proof of Pong's field and ball (plan "Proof of Pong", P1): a fixed step of 1/60 s in whole units, so the server
 * and the browser (resources/js/pong/physics.js) compute the same ball, tick for tick, with no float in between.
 *
 * The field is WIDTH x HEIGHT units; x runs from side 0's goal line (0) to side 1's (WIDTH), y from the top wall (0)
 * to the bottom wall (HEIGHT). A ball is [x, y, vx, vy, r]: centre, velocity in units per tick, radius. A move adds
 * the velocity and mirrors the ball off a wall it crossed; nothing else changes it between two paddle hits, so the
 * path from a hit to the next goal line is known from the ball alone (predict()).
 *
 * Every product stays below 2^31 and every division is floorDiv(), so PHP's 64-bit integers and JavaScript's doubles
 * give the same numbers.
 */
final class PongPhysics
{
    public const int TICKS_PER_SECOND = 60;

    public const int WIDTH = 160000;

    public const int HEIGHT = 90000;

    /** A paddle's face, measured from its own goal line. */
    public const int PADDLE_X = 4000;

    /** Half a paddle's length (a paddle covers a fifth of the field's height). */
    public const int PADDLE_HALF = 9000;

    /** How thick a paddle is drawn behind its face; the physics only knows the face. */
    public const int PADDLE_DEPTH = 1600;

    public const int BALL_RADIUS = 1200;

    /** A serve's speed in units per tick along x: 2.5 s from paddle to paddle. */
    public const int BASE_SPEED = 1000;

    /** Every hit of a ball makes it this much faster, up to MAX_SPEED. */
    public const int SPEEDUP = 60;

    public const int MAX_SPEED = 2600;

    /** The steepest return: vy is at most 4/5 of vx, at the paddle's very edge. */
    public const int ANGLE_NUM = 4;

    public const int ANGLE_DEN = 5;

    /** How fast a player's paddle follows the mouse, finger or keys, in units per tick. */
    public const int PLAYER_SPEED = 2400;

    /** A rally longer than two minutes ends without a point (only two bots that never miss get there). */
    public const int RALLY_TICK_CAP = 7200;

    /**
     * Floor division, as JavaScript's Math.floor(a / b) gives it (PHP's intdiv() rounds towards zero).
     */
    public static function floorDiv(int $a, int $b): int
    {
        $quotient = intdiv($a, $b);

        return ($a % $b !== 0 && (($a < 0) !== ($b < 0))) ? $quotient - 1 : $quotient;
    }

    /**
     * The ball one tick on: the velocity added, mirrored off the top or bottom wall it crossed.
     *
     * @param  array{int, int, int, int, int}  $ball  [x, y, vx, vy, r]
     * @return array{int, int, int, int, int}
     */
    public static function move(array $ball): array
    {
        [$x, $y, $vx, $vy, $r] = $ball;
        $x += $vx;
        $y += $vy;

        if ($y - $r < 0) {
            $y = 2 * $r - $y;
            $vy = -$vy;
        } elseif ($y + $r > self::HEIGHT) {
            $y = 2 * (self::HEIGHT - $r) - $y;
            $vy = -$vy;
        }

        return [$x, $y, $vx, $vy, $r];
    }

    /**
     * The x of side `$side`'s paddle face.
     */
    public static function faceX(int $side): int
    {
        return $side === 0 ? self::PADDLE_X : self::WIDTH - self::PADDLE_X;
    }

    /**
     * Whether the ball runs towards side `$side` and has not yet passed its paddle's face.
     *
     * @param  array{int, int, int, int, int}  $ball
     */
    public static function approaches(array $ball, int $side): bool
    {
        [$x, , $vx, , $r] = $ball;

        return $side === 0 ? $vx < 0 && $x - $r > self::PADDLE_X : $vx > 0 && $x + $r < self::WIDTH - self::PADDLE_X;
    }

    /**
     * Where and when the ball reaches side `$side`'s paddle face if nothing touches it: the number of ticks and the
     * ball's y in that tick (the tick in which a hit is decided). Null when it does not run towards that side.
     *
     * @param  array{int, int, int, int, int}  $ball
     * @return array{int, int}|null [ticks, y]
     */
    public static function predict(array $ball, int $side): ?array
    {
        if (! self::approaches($ball, $side)) {
            return null;
        }

        for ($ticks = 1; $ticks <= self::RALLY_TICK_CAP; $ticks++) {
            $ball = self::move($ball);

            if (self::crossed($ball, $side)) {
                return [$ticks, $ball[1]];
            }
        }

        return null;
    }

    /**
     * Whether the ball's front edge is at or past side `$side`'s paddle face.
     *
     * @param  array{int, int, int, int, int}  $ball
     */
    public static function crossed(array $ball, int $side): bool
    {
        return $side === 0 ? $ball[0] - $ball[4] <= self::PADDLE_X : self::WIDTH - self::PADDLE_X <= $ball[0] + $ball[4];
    }

    /**
     * Whether a ball that crossed side `$side`'s face in this tick meets that side's paddle (centre `$paddle`, half
     * length `$half`): its centre within the half length plus its radius.
     *
     * @param  array{int, int, int, int, int}  $ball  the ball after the tick's move
     */
    public static function meets(array $ball, int $paddle, int $half): bool
    {
        return abs($ball[1] - $paddle) <= $half + $ball[4];
    }

    /**
     * A ball that met side `$side`'s paddle (meets()), sent back: mirrored off the face (its front edge ends as far
     * in front of it as it went past it), at `$speed` along x and at an angle that grows with the distance from the
     * paddle's centre. PongRally and the live referee (PongReferee) both hit with it.
     *
     * @param  array{int, int, int, int, int}  $ball  the ball after the tick's move
     * @return array{int, int, int, int, int}
     */
    public static function bounce(array $ball, int $side, int $paddle, int $half, int $speed): array
    {
        [$x, $y, , , $r] = $ball;
        $reach = $half + $r;
        $face = self::faceX($side);
        $x = $side === 0 ? 2 * ($face + $r) - $x : 2 * ($face - $r) - $x;
        $vy = self::floorDiv(($y - $paddle) * $speed * self::ANGLE_NUM, $reach * self::ANGLE_DEN);

        return [$x, $y, $side === 0 ? $speed : -$speed, $vy, $r];
    }

    /**
     * A ball's speed along x after its `$hits`-th hit, from a rally's base speed.
     */
    public static function speedAfter(int $hits, int $base, int $speedup, int $max): int
    {
        return min($base + $hits * $speedup, $max);
    }
}
