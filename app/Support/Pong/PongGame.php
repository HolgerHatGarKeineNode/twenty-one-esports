<?php

namespace App\Support\Pong;

/**
 * A game of Proof of Pong as a row of rallies (plan "Proof of Pong", P1), mirrored by resources/js/pong/rules.js
 * (createGame): the score, the rally's number and its event, and the winner. Each goal adds the rally's points
 * (two in a Halving) to the scorer at once; the game ends at the first goal that wins it, even in the middle of a
 * Pizza Day rally.
 *
 * bots() plays a whole game between two bot levels, the way the page plays one when both paddles are bots (the
 * browser test's autoplay): the same rallies, ticks and goals, so its result is the page's.
 */
final class PongGame
{
    /** @var array{int, int} */
    public array $score = [0, 0];

    /** The number of the rally in play or next, from 1. */
    public int $rally = 1;

    public ?int $winner = null;

    public function __construct(public readonly int $seed, public readonly PongRules $rules = new PongRules) {}

    /** The event of the current rally, or null. */
    public function event(): ?string
    {
        return $this->rules->eventOf($this->seed, $this->rally);
    }

    /**
     * The current rally, served.
     *
     * @param  array{int, int}  $speeds  each side's paddle speed
     */
    public function serve(array $speeds): PongRally
    {
        return new PongRally(PongRules::rallySeed($this->seed, $this->rally), $this->event(), $speeds);
    }

    /**
     * A goal of the current rally counts: `$points` for side `$scorer`; true when it won the game.
     */
    public function goal(int $scorer, int $points): bool
    {
        if ($this->winner !== null) {
            return true;
        }

        $this->score = $scorer === 0 ? [$this->score[0] + $points, $this->score[1]] : [$this->score[0], $this->score[1] + $points];
        $this->winner = $this->rules->winner($this->score);

        return $this->winner !== null;
    }

    /** The current rally is over; the next one comes. */
    public function next(): void
    {
        $this->rally++;
    }

    /**
     * A whole game between bot level `$levels[0]` (side 0) and `$levels[1]` (side 1).
     *
     * @param  array{int, int}  $levels
     * @return array{score: array{int, int}, winner: int|null, rallies: int, ticks: int, events: list<array{int, string}>, goals: list<array{int, int, int, int}>}
     */
    public static function bots(int $seed, array $levels, PongRules $rules = new PongRules, int $maxRallies = 500): array
    {
        $game = new self($seed, $rules);
        $ticks = 0;
        $events = [];
        $goals = [];
        $over = false;

        while (! $over && $game->rally <= $maxRallies) {
            $rally = $game->serve([PongBot::speed($levels[0]), PongBot::speed($levels[1])]);
            $seedOfRally = PongRules::rallySeed($seed, $game->rally);
            $bots = [new PongBot($levels[0], 0, $seedOfRally), new PongBot($levels[1], 1, $seedOfRally)];

            if ($rally->event !== null) {
                $events[] = [$game->rally, $rally->event];
            }

            $counted = 0;

            while (! $rally->over && ! $over) {
                $rally->step([$bots[0]->target($rally), $bots[1]->target($rally)]);

                for (; $counted < count($rally->goals); $counted++) {
                    [$tick, $scorer, $ball] = $rally->goals[$counted];
                    $goals[] = [$game->rally, $tick, $scorer, $ball];

                    if ($game->goal($scorer, $rally->points)) {
                        $over = true;
                        break;
                    }
                }
            }

            $ticks += $rally->tick;

            if (! $over) {
                $game->next();
            }
        }

        return ['score' => $game->score, 'winner' => $game->winner, 'rallies' => $game->rally, 'ticks' => $ticks, 'events' => $events, 'goals' => $goals];
    }
}
