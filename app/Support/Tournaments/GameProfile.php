<?php

namespace App\Support\Tournaments;

use InvalidArgumentException;

/**
 * Planning values of one game and mode for the tournament estimator
 * (TOURNAMENT-FORMATS.md, section 2; `TF_GAMES` of the artboard script).
 * Every value is an assumption until measured on real tournaments; the
 * organizer can change game, setup and break per tournament ("Change times").
 *
 * Times are in the profile's unit: minutes, or days for daily chess.
 */
final readonly class GameProfile
{
    /**
     * @param  'blitz'|'daily'|'rl1'|'rl2'|'rl3'  $key
     * @param  'min'|'day'  $unit
     * @param  list<int>  $bestOfOptions
     * @param  'game'|'series'  $what
     */
    public function __construct(
        public string $key,
        public string $game,
        public string $mode,
        public string $unit,
        public float $gameLength,
        public float $setup,
        public float $break,
        public int $bestOf,
        public int $finalBestOf,
        public array $bestOfOptions,
        public bool $allAtOnce,
        public string $what,
    ) {}

    /**
     * The league defaults of a registered game and mode.
     */
    public static function for(string $game, string $mode): self
    {
        return match ("{$game}/{$mode}") {
            'chess/blitz' => new self('blitz', $game, $mode, 'min', 14, 0, 3, 1, 1, [1, 2], false, 'game'),
            'chess/correspondence' => new self('daily', $game, $mode, 'day', 30, 0, 1, 1, 1, [1, 2], true, 'game'),
            'rocket-league/1v1' => new self('rl1', $game, $mode, 'min', 8, 5, 5, 3, 5, [1, 3, 5, 7], false, 'series'),
            'rocket-league/2v2' => new self('rl2', $game, $mode, 'min', 8, 5, 5, 3, 5, [1, 3, 5, 7], false, 'series'),
            'rocket-league/3v3' => new self('rl3', $game, $mode, 'min', 8, 5, 5, 3, 5, [1, 3, 5, 7], false, 'series'),
            default => throw new InvalidArgumentException("No tournament profile for [{$game}/{$mode}]."),
        };
    }

    /**
     * The same profile with the organizer's own times; null keeps the default.
     */
    public function withTimes(?float $gameLength = null, ?float $setup = null, ?float $break = null): self
    {
        return new self($this->key, $this->game, $this->mode, $this->unit,
            $gameLength ?? $this->gameLength, $setup ?? $this->setup, $break ?? $this->break,
            $this->bestOf, $this->finalBestOf, $this->bestOfOptions, $this->allAtOnce, $this->what);
    }

    public function isChess(): bool
    {
        return $this->game === 'chess';
    }

    public function isRocketLeague(): bool
    {
        return $this->game === 'rocket-league';
    }

    public function isDaily(): bool
    {
        return $this->unit === 'day';
    }

    /**
     * RL 2v2 and 3v3 enter teams, everything else single players.
     */
    public function entersTeams(): bool
    {
        return $this->key === 'rl2' || $this->key === 'rl3';
    }

    /**
     * Time one match needs, worst case: a round waits for its slowest match.
     * Daily chess plays both games of a 2-game match at the same time.
     */
    public function slot(int $bestOf): float
    {
        if ($this->allAtOnce) {
            return $this->gameLength;
        }

        return $this->setup + $bestOf * $this->gameLength;
    }
}
