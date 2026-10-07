<?php

namespace App\Games;

use App\Support\Board\BoardRules;
use App\Support\Board\NineMensMorrisRules;

/**
 * Nine men's morris ("Mühle"), correspondence only (one move a day, P8;
 * the user dropped blitz on 2026-10-07: fast modes only make sense for chess), played move by move on the board game core (plan "Mühle und
 * Dame", P3). The rules are NineMensMorrisRules.
 * The slug is `nine-mens-morris`, never `mill` (BoardGame::RESERVED_SLUGS).
 */
final class NineMensMorris extends BoardGame
{
    public const SLUG = 'nine-mens-morris';

    public const RESULTS = ['1-0', '0-1', '1/2-1/2'];

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return "Nine Men's Morris";
    }

    public function modes(): array
    {
        return [
            // Correspondence (P8): one move per day, as daily chess; the rules end every game (no endless draws).
            'correspondence' => new GameMode('correspondence', 'Correspondence', 1, [], [], 'player', true, '1/86400'),
        ];
    }

    public function resultSchema(GameMode $mode): array
    {
        return ['result' => 'one of '.implode(', ', self::RESULTS)];
    }

    public function validateResult(GameMode $mode, array $result): array
    {
        return in_array($result['result'] ?? null, self::RESULTS, true) ? [] : ['result'];
    }

    public function assets(): GameAssets
    {
        return new GameAssets('morris', 'var(--color-edge)', 'var(--color-line)', 'Morris', new GameCover('nine-mens-morris'));
    }

    public function rules(): BoardRules
    {
        return new NineMensMorrisRules;
    }
}
