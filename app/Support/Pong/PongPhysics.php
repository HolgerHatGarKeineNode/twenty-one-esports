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

    /** The centre line, where the P7 events put their obstacles and the Arbeitsamt its queue. */
    public const int CENTRE = 80000;

    /** Steuern sind Raub: the tax office's block, half its width and height, and how fast it patrols (per tick). */
    public const int TAX_HALF_X = 2000;

    public const int TAX_HALF_Y = 10000;

    public const int TAX_SPEED = 450;

    /** Kapitalverkehrskontrolle: the border wall's half thickness, half its gap and how fast the gap wanders. */
    public const int WALL_HALF_X = 1200;

    public const int GAP_HALF = 15000;

    public const int GAP_SPEED = 300;

    /** Arbeitsamt: a ball that crosses the centre line waits this many ticks (one second) in the queue. */
    public const int QUEUE_TICKS = 60;

    /** Proof of Work: each own hit makes the paddle this much longer (half length), up to POW_MAX_HALF. */
    public const int POW_GROW = 1500;

    public const int POW_MAX_HALF = 18000;

    /**
     * The pauses around a rally, in ticks, as resources/js/pong/physics.js (the browser test's golden checks both): on a
     * point, a meme event's announcement (its takeover: 600 ms in, the name and line held long enough to read the
     * longest of them in German and English, 400 ms out; resources/js/pong/takeover.js), the serve's countdown. The
     * referee serves the next rally after them, so they are part of the rules' fingerprint (PongRules::version()).
     */
    public const int POINT_TICKS = 70;

    public const int ANNOUNCE_TICKS = 480;

    public const int SERVE_TICKS = 50;

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
     * @param  array{0: int, 1: int, 2: int, 3: int, 4: int, 5?: int}  $ball  [x, y, vx, vy, r]
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5?: int}
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
     * The ball one tick on in a rally of `$event` (seed `$seed`), arriving at tick `$tick`: move() plus the field of
     * the P7 events, all of them functions of the rally's seed and tick only, so the path between two paddle contacts
     * is still known from the ball and the tick alone.
     *
     * - Steuern sind Raub / Kapitalverkehrskontrolle: a ball whose front edge enters the obstacle's band on the centre
     *   line in this tick is mirrored back off it where it is blocked (the tax block; the wall outside its gap). A
     *   ball inside the band (the serve starts there) is never stopped.
     * - Arbeitsamt: a ball that crosses the centre line in this tick stops there for QUEUE_TICKS (a sixth element
     *   counts the ticks left), then goes on unchanged.
     *
     * @param  array{0: int, 1: int, 2: int, 3: int, 4: int, 5?: int}  $ball  [x, y, vx, vy, r] or, waiting in the queue, [x, y, vx, vy, r, ticks left]
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5?: int}
     */
    public static function step(array $ball, int $tick, ?string $event, int $seed): array
    {
        if ($event === PongRules::ARBEITSAMT) {
            if (isset($ball[5])) {
                $left = $ball[5] - 1;

                return $left > 0 ? [$ball[0], $ball[1], $ball[2], $ball[3], $ball[4], $left] : [$ball[0], $ball[1], $ball[2], $ball[3], $ball[4]];
            }

            $moved = self::move($ball);
            $centre = self::CENTRE;

            if (($ball[0] < $centre && $moved[0] >= $centre) || ($ball[0] > $centre && $moved[0] <= $centre)) {
                return [...$moved, self::QUEUE_TICKS];
            }

            return $moved;
        }

        if ($event !== PongRules::TAX && $event !== PongRules::CONTROLS) {
            return self::move($ball);
        }

        $moved = self::move($ball);
        [$x, $y, $vx, $vy, $r] = $moved;
        $face = $vx > 0 ? self::CENTRE - self::bandHalf($event) : self::CENTRE + self::bandHalf($event);
        $enters = $vx > 0 ? $face > $ball[0] + $r && $x + $r >= $face : $face < $ball[0] - $r && $x - $r <= $face;

        if (! $enters || ! self::blocked($event, $seed, $tick, $y, $r)) {
            return $moved;
        }

        return [$vx > 0 ? 2 * ($face - $r) - $x : 2 * ($face + $r) - $x, $y, -$vx, $vy, $r];
    }

    /** Half the width of an event's obstacle on the centre line. */
    public static function bandHalf(string $event): int
    {
        return $event === PongRules::TAX ? self::TAX_HALF_X : self::WALL_HALF_X;
    }

    /**
     * The centre y of an event's moving part at tick `$tick`: the tax block, or the border wall's gap. It patrols
     * between the walls at a constant speed, from a start drawn from the rally's seed.
     */
    public static function patrol(string $event, int $seed, int $tick): int
    {
        [$half, $speed] = $event === PongRules::TAX ? [self::TAX_HALF_Y, self::TAX_SPEED] : [self::GAP_HALF, self::GAP_SPEED];
        $span = self::HEIGHT - 2 * $half;
        $u = ($tick * $speed + $seed % (2 * $span)) % (2 * $span);

        return $half + ($u <= $span ? $u : 2 * $span - $u);
    }

    /**
     * Whether a ball at height `$y` (radius `$r`) is stopped by the event's obstacle at tick `$tick`: it touches the
     * tax block, or it is not wholly inside the wall's gap.
     */
    public static function blocked(string $event, int $seed, int $tick, int $y, int $r): bool
    {
        $centre = self::patrol($event, $seed, $tick);

        return $event === PongRules::TAX
            ? abs($y - $centre) <= self::TAX_HALF_Y + $r
            : abs($y - $centre) > self::GAP_HALF - $r;
    }

    /**
     * A paddle's half length in a rally of `$event` after its side's `$hits` hits (Proof of Work grows it).
     */
    public static function halfOf(?string $event, int $hits): int
    {
        return match ($event) {
            PongRules::DIFFICULTY => intdiv(self::PADDLE_HALF * 2, 3),
            PongRules::POW => min(self::PADDLE_HALF + $hits * self::POW_GROW, self::POW_MAX_HALF),
            default => self::PADDLE_HALF,
        };
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
     * @param  array{0: int, 1: int, 2: int, 3: int, 4: int, 5?: int}  $ball
     */
    public static function approaches(array $ball, int $side): bool
    {
        [$x, , $vx, , $r] = $ball;

        return $side === 0 ? $vx < 0 && $x - $r > self::PADDLE_X : $vx > 0 && $x + $r < self::WIDTH - self::PADDLE_X;
    }

    /**
     * Where and when the ball reaches side `$side`'s paddle face if nothing touches it: the number of ticks and the
     * ball's y in that tick (the tick in which a hit is decided). Null when it does not run towards that side, or an
     * event's obstacle sends it back before (step(); `$tick` is the tick the ball stands at).
     *
     * @param  array{0: int, 1: int, 2: int, 3: int, 4: int, 5?: int}  $ball
     * @return array{int, int}|null [ticks, y]
     */
    public static function predict(array $ball, int $side, ?string $event = null, int $seed = 0, int $tick = 0): ?array
    {
        if (! self::approaches($ball, $side)) {
            return null;
        }

        for ($ticks = 1; $ticks <= self::RALLY_TICK_CAP; $ticks++) {
            $ball = self::step($ball, $tick + $ticks, $event, $seed);

            if (self::crossed($ball, $side)) {
                return [$ticks, $ball[1]];
            }

            if (! self::approaches($ball, $side)) {
                return null;
            }
        }

        return null;
    }

    /**
     * Whether the ball's front edge is at or past side `$side`'s paddle face.
     *
     * @param  array{0: int, 1: int, 2: int, 3: int, 4: int, 5?: int}  $ball
     */
    public static function crossed(array $ball, int $side): bool
    {
        return $side === 0 ? $ball[0] - $ball[4] <= self::PADDLE_X : self::WIDTH - self::PADDLE_X <= $ball[0] + $ball[4];
    }

    /**
     * Whether a ball that crossed side `$side`'s face in this tick meets that side's paddle (centre `$paddle`, half
     * length `$half`): its centre within the half length plus its radius.
     *
     * @param  array{0: int, 1: int, 2: int, 3: int, 4: int, 5?: int}  $ball  the ball after the tick's move
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
     * @param  array{0: int, 1: int, 2: int, 3: int, 4: int, 5?: int}  $ball  the ball after the tick's move
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5?: int}
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
