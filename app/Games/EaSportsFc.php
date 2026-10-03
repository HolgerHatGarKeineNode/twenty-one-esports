<?php

namespace App\Games;

use App\Games\Contracts\PlayedOnOwnCopy;

/**
 * EA Sports FC, one registry entry per yearly edition (FC 26, FC 27): two
 * editions do not play each other, so each has its own ladders.
 *
 * Played as a series like Rocket League: best of 1 or 3, goals per game.
 * 1v1 is a player ladder (as RL 1v1, NIP rev. 7.1); 2v2 is a clan lineup
 * (the game's co-op online friendlies). A game level after full time goes to
 * extra time and penalties in the game itself, so every game has a winner:
 * a game won on penalties is reported with its winner and the goals left
 * unknown (NIP `score`: points unknown). No per-game flags.
 */
abstract class EaSportsFc extends SeriesGame implements PlayedOnOwnCopy
{
    abstract protected function edition(): int;

    /**
     * The widths of the cover files (GameCover), smallest first.
     *
     * @return list<int>
     */
    protected function coverWidths(): array
    {
        return [480, 1280];
    }

    public function slug(): string
    {
        return 'ea-sports-fc-'.$this->edition();
    }

    public function name(): string
    {
        return 'EA Sports FC '.$this->edition();
    }

    public function modes(): array
    {
        return [
            '1v1' => new GameMode('1v1', '1v1', 1, [1, 3], [], 'player', false),
            '2v2' => new GameMode('2v2', '2v2', 2, [1, 3], [], 'lineup', false),
        ];
    }

    public function flags(): array
    {
        return [];
    }

    /** Sold on every platform, never free (the Steam pages list a price). */
    public function isFreeToPlay(): bool
    {
        return false;
    }

    public function assets(): GameAssets
    {
        return new GameAssets('soccer', 'var(--color-fc)', 'var(--color-fc-deep)', 'FC'.$this->edition(), new GameCover($this->slug(), $this->coverWidths()));
    }
}
