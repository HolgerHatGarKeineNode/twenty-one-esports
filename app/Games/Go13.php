<?php

namespace App\Games;

/**
 * Go on 13 x 13 (DerCaddy, 2026-10-27): about 150 plies a game, too many for blitz 5+3, so rapid 10+5 (about
 * 10 + 75 x 5 s, some 16 minutes a side at most) and one move a day.
 */
final class Go13 extends Go
{
    public const SLUG = 'go-13';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function size(): int
    {
        return 13;
    }

    public function modes(): array
    {
        return [
            'rapid' => new GameMode('rapid', 'Rapid 10+5', 1, [], [], 'player', true, '600+5'),
            'correspondence' => new GameMode('correspondence', 'Correspondence', 1, [], [], 'player', true, '1/86400'),
        ];
    }
}
