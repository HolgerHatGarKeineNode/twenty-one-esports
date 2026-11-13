<?php

namespace App\Games;

/**
 * Go on 19 x 19, the full board (DerCaddy, 2026-10-27): about 250 plies a game, so the live mode is a long rapid,
 * 15+10 (15 minutes and about 125 moves of 10 s, some 36 minutes a side at most), and one move a day.
 */
final class Go19 extends Go
{
    public const SLUG = 'go-19';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function size(): int
    {
        return 19;
    }

    public function modes(): array
    {
        return [
            'rapid' => new GameMode('rapid', 'Rapid 15+10', 1, [], [], 'player', true, '900+10'),
            'correspondence' => new GameMode('correspondence', 'Correspondence', 1, [], [], 'player', true, '1/86400'),
        ];
    }
}
