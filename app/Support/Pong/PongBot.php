<?php

namespace App\Support\Pong;

use App\Support\Hyper\HyperRng;
use InvalidArgumentException;

/**
 * Proof of Pong's bot (plan "Proof of Pong", P1), mirrored by resources/js/pong/bot.js: four levels from the
 * Nocoiner uncle to Madame Brrr Lagarde, the end boss. A level differs in three things:
 *
 * - reaction: how many ticks after a ball turned towards it the bot starts to move;
 * - speed: how fast its paddle moves, in units per tick;
 * - error: how far off its aim is at most, drawn once per approaching ball; beyond the paddle's reach it misses.
 *
 * It reads the ball's exact path (PongPhysics::predict()), aims at that point plus its error and returns to the
 * middle while no ball comes. Its draws come from its own RNG per rally and side, so a bot plays a rally the same
 * way on the server and in the browser.
 */
final class PongBot
{
    /**
     * The levels, 1 to 4; names are the cast's (plan, "Bot-Leiter").
     *
     * @var array<int, array{name: string, reaction: int, speed: int, error: int}>
     */
    public const array LEVELS = [
        1 => ['name' => 'Nocoiner Uncle', 'reaction' => 20, 'speed' => 520, 'error' => 20000],
        2 => ['name' => 'Shitcoiner', 'reaction' => 14, 'speed' => 780, 'error' => 15500],
        3 => ['name' => 'Goldbug', 'reaction' => 9, 'speed' => 1050, 'error' => 12500],
        4 => ['name' => 'Madame Brrr Lagarde', 'reaction' => 5, 'speed' => 1400, 'error' => 11000],
    ];

    private const int SALT = 0xB07B07;

    private HyperRng $rng;

    /** The ball it follows (index * 1000 + its hits) and what it worked out for it. */
    private ?int $key = null;

    private int $aim = 0;

    private int $ready = 0;

    /** @var array<int, array{int, int}|null> per followed ball: [tick it reaches the face, y there] */
    private array $paths = [];

    public function __construct(public readonly int $level, public readonly int $side, int $rallySeed)
    {
        if (! isset(self::LEVELS[$level])) {
            throw new InvalidArgumentException("No bot level [{$level}].");
        }

        $this->rng = HyperRng::seeded(($rallySeed ^ (self::SALT + $side)) & 0xFFFFFFFF);
    }

    public static function speed(int $level): int
    {
        return self::LEVELS[$level]['speed'] ?? throw new InvalidArgumentException("No bot level [{$level}].");
    }

    /**
     * Where the bot wants its paddle in this tick, before the rally steps.
     */
    public function target(PongRally $rally): int
    {
        $level = self::LEVELS[$this->level];
        $next = null;

        // The ball that reaches its face first. A ball's path only changes at a hit, so each is worked out once.
        foreach ($rally->balls as $index => $ball) {
            if (! $rally->alive[$index] || ! PongPhysics::approaches($ball, $this->side)) {
                continue;
            }

            $key = $index * 1000 + $rally->hits[$index];

            if (! array_key_exists($key, $this->paths)) {
                $path = PongPhysics::predict($ball, $this->side);
                $this->paths[$key] = $path === null ? null : [$rally->tick + $path[0], $path[1]];
            }

            $path = $this->paths[$key];

            if ($path !== null && ($next === null || $path[0] < $next[1])) {
                $next = [$key, $path[0], $path[1]];
            }
        }

        if ($next === null) {
            $this->key = null;

            return PongPhysics::HEIGHT >> 1;
        }

        [$key, , $y] = $next;

        if ($key !== $this->key) {
            $this->key = $key;
            $this->aim = $y + $this->rng->below(2 * $level['error'] + 1) - $level['error'];
            $this->ready = $rally->tick + $level['reaction'];
        }

        return $rally->tick < $this->ready ? $rally->paddles[$this->side] : $this->aim;
    }
}
