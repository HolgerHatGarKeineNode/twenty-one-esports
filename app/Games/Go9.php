<?php

namespace App\Games;

/**
 * Go on 9 x 9 (DerCaddy, 2026-10-27): the size for a quick game, so the modes are Blockli's, blitz 5+3, rapid 10+5 and
 * one move a day. A game lasts about 60 plies; its practice page (lessons and bots) plays on this board.
 */
final class Go9 extends Go
{
    public const SLUG = 'go-9';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function size(): int
    {
        return 9;
    }

    public function modes(): array
    {
        return [
            // Each its own rating pool and ladder, as Blockli's (go-9/blitz, go-9/rapid).
            'blitz' => new GameMode('blitz', 'Blitz 5+3', 1, [], [], 'player', true, '300+3'),
            'rapid' => new GameMode('rapid', 'Rapid 10+5', 1, [], [], 'player', true, '600+5'),
            'correspondence' => new GameMode('correspondence', 'Correspondence', 1, [], [], 'player', true, '1/86400'),
        ];
    }
}
