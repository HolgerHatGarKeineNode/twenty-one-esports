<?php

namespace App\Games;

/**
 * Age of Empires II: Definitive Edition, played as a series like Rocket
 * League: best of 1 or 3. 1v1 is a player ladder (as RL 1v1, NIP rev. 7.1);
 * 2v2 and 3v3 are clan lineups. No 4v4 (plan "AoE2 und Trackmania").
 *
 * A game has a winner and no score: AoE2 knows no goals, so every game is
 * reported with its winner only (NIP `score`: points unknown) and a score
 * is refused (hasGoals()). No per-game flags. A player hosts the lobby in
 * the game itself (password, spectators allowed); checking the result
 * against the game's match history comes with a later phase.
 */
final class AgeOfEmpires2 extends SeriesGame
{
    public function slug(): string
    {
        return 'age-of-empires-2';
    }

    public function name(): string
    {
        return 'Age of Empires II: Definitive Edition';
    }

    public function modes(): array
    {
        $modes = [];

        foreach ([1, 2, 3] as $size) {
            $slug = "{$size}v{$size}";
            $modes[$slug] = new GameMode($slug, $slug, $size, [1, 3], [], $size === 1 ? 'player' : 'lineup', false);
        }

        return $modes;
    }

    public function flags(): array
    {
        return [];
    }

    public function hasGoals(): bool
    {
        return false;
    }

    public function assets(): GameAssets
    {
        // The supplied key art (1102 × 620) at 800 × 450: its marble ground does not fit the 80 kB per-file cap any larger.
        return new GameAssets('castle', 'var(--color-aoe)', 'var(--color-aoe-deep)', 'AoE2', new GameCover('age-of-empires-2', [480, 800]));
    }
}
