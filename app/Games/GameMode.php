<?php

namespace App\Games;

/**
 * One rated mode of a game, with the fields the NIP "Game registry" table needs.
 */
final readonly class GameMode
{
    /**
     * @param  int  $teamSize  players per side in one game (chess: 1, RL 3v3: 3)
     * @param  list<int>  $bestOf  allowed series lengths; empty = a single game
     * @param  list<int>  $boards  allowed board counts of a team match; empty = no team match
     * @param  'lineup'|'player'  $rates  what the ladder of this mode rates (`rates` tag)
     * @param  string|null  $timeControl  PGN TimeControl (`time_control` tag), null if the game has none
     */
    public function __construct(
        public string $slug,
        public string $name,
        public int $teamSize,
        public array $bestOf,
        public array $boards,
        public string $rates,
        public bool $allowsDraws,
        public ?string $timeControl = null,
    ) {}

    /**
     * Fewest `captain` + `player` entries a lineup of this mode lists (NIP rule 8).
     * A chess lineup exists to field team matches, so it needs the smallest board count.
     */
    public function lineupMinimum(): int
    {
        return $this->boards === [] ? $this->teamSize : min($this->boards);
    }

    public function allowsBestOf(int $bestOf): bool
    {
        return in_array($bestOf, $this->bestOf, true);
    }

    public function allowsBoards(int $boards): bool
    {
        return in_array($boards, $this->boards, true);
    }
}
