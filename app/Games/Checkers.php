<?php

namespace App\Games;

use App\Support\Board\BoardRules;
use App\Support\Board\CheckersRules;

/**
 * Checkers ("Dame" on German pages) by German rules on 8 x 8, blitz 5+3,
 * played on the board game core (plan "Mühle und Dame", P4). Registered
 * through `config('esports.board_games.games.checkers')`; the rules are
 * {@see CheckersRules}.
 */
final class Checkers extends BoardGame
{
    public const RESULTS = ['1-0', '0-1', '1/2-1/2'];

    public function rules(): BoardRules
    {
        return new CheckersRules;
    }

    public function slug(): string
    {
        return 'checkers';
    }

    public function name(): string
    {
        return 'Checkers';
    }

    public function modes(): array
    {
        return ['blitz' => new GameMode('blitz', 'Blitz 5+3', 1, [], [], 'player', true, '300+3')];
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
        return new GameAssets('grid', 'var(--color-edge)', 'var(--color-line)', 'Checkers');
    }
}
