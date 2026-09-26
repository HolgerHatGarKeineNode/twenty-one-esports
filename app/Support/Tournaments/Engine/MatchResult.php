<?php

namespace App\Support\Tournaments\Engine;

/**
 * The result of one bracket match, per slot: `games` won inside the match (a
 * Rocket League series, the two games of a 2-game chess match; 1 or 0 for a
 * single game, ½ each for a draw), `points` scored (goals), and for a heat or
 * a leaderboard the places (1 = best). `winner` is the winning slot, null for
 * a draw.
 */
final readonly class MatchResult
{
    /**
     * @param  list<float>  $games
     * @param  list<float>  $points
     * @param  list<int>|null  $ranks
     */
    public function __construct(
        public ?int $winner,
        public array $games = [],
        public array $points = [],
        public ?array $ranks = null,
    ) {}

    /**
     * @param  list<float>  $games
     * @param  list<float>  $points
     */
    public static function win(int $slot, array $games = [], array $points = []): self
    {
        return new self($slot, $games === [] ? ($slot === 0 ? [1.0, 0.0] : [0.0, 1.0]) : $games, $points);
    }

    /**
     * @param  list<float>  $games
     * @param  list<float>  $points
     */
    public static function draw(array $games = [0.5, 0.5], array $points = []): self
    {
        return new self(null, $games, $points);
    }

    /**
     * Places of a heat or a leaderboard, per slot.
     *
     * @param  non-empty-list<int>  $ranks
     */
    public static function ranked(array $ranks): self
    {
        $best = array_search(min($ranks), $ranks, true);

        return new self(is_int($best) ? $best : null, [], [], $ranks);
    }

    public function isDraw(): bool
    {
        return $this->winner === null;
    }

    public function loser(): ?int
    {
        return $this->winner === null ? null : 1 - $this->winner;
    }
}
