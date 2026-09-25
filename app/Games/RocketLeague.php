<?php

namespace App\Games;

use App\Games\Contracts\Game;

/**
 * Rocket League: 1v1, 2v2, 3v3 lineups, best of 3 or 5, team goals per game.
 *
 * The result mirrors the `score` tags of a Result Report (NIP kind 2152):
 * games numbered from 1, a winner per game, goals both known or both unknown
 * ("goals unknown" is the per-game escape hatch), and the series ends in the
 * game in which one side reaches ceil(bo / 2) wins. No draws.
 */
final class RocketLeague implements Game
{
    public const SIDES = ['challenger', 'challenged'];

    /** Optional per-game flags (`score` tag, position 5+). */
    public const FLAGS = ['ot'];

    public function slug(): string
    {
        return 'rocket-league';
    }

    public function name(): string
    {
        return 'Rocket League';
    }

    public function modes(): array
    {
        $modes = [];

        foreach ([1, 2, 3] as $size) {
            $slug = "{$size}v{$size}";
            $modes[$slug] = new GameMode($slug, $slug, $size, [3, 5], [], 'lineup', false);
        }

        return $modes;
    }

    public function mode(string $slug): ?GameMode
    {
        return $this->modes()[$slug] ?? null;
    }

    public function resultSchema(GameMode $mode): array
    {
        return [
            'bo' => 'integer, one of '.implode(', ', $mode->bestOf),
            'games' => 'list, one entry per game played, in order',
            'games.*.winner' => 'challenger | challenged',
            'games.*.challenger' => 'team goals of the challenger, or null when unknown',
            'games.*.challenged' => 'team goals of the challenged, or null when unknown',
            'games.*.flags' => 'optional list: '.implode(', ', self::FLAGS),
        ];
    }

    public function validateResult(GameMode $mode, array $result): array
    {
        $bo = $result['bo'] ?? null;

        if (! is_int($bo) || ! $mode->allowsBestOf($bo)) {
            return ['bo_not_allowed'];
        }

        $games = $result['games'] ?? null;

        if (! is_array($games) || $games === [] || ! array_is_list($games)) {
            return ['no_games'];
        }

        $needed = intdiv($bo, 2) + 1;
        $wins = ['challenger' => 0, 'challenged' => 0];
        $errors = [];

        foreach ($games as $index => $game) {
            $number = $index + 1;

            if (max($wins) >= $needed) {
                $errors[] = "game_{$number}_after_series_end";

                break;
            }

            if (! is_array($game) || ! in_array($game['winner'] ?? null, self::SIDES, true)) {
                $errors[] = "game_{$number}_winner";

                continue;
            }

            $winner = $game['winner'];
            $loser = $winner === 'challenger' ? 'challenged' : 'challenger';
            $winnerGoals = $game[$winner] ?? null;
            $loserGoals = $game[$loser] ?? null;

            if ($winnerGoals !== null || $loserGoals !== null) {
                if (! is_int($winnerGoals) || ! is_int($loserGoals) || $winnerGoals < 0 || $loserGoals < 0 || $winnerGoals <= $loserGoals) {
                    $errors[] = "game_{$number}_goals";
                }
            }

            $flags = $game['flags'] ?? [];

            if (! is_array($flags) || array_diff($flags, self::FLAGS) !== []) {
                $errors[] = "game_{$number}_flag";
            }

            $wins[$winner]++;
        }

        if ($errors === [] && max($wins) < $needed) {
            $errors[] = 'series_not_finished';
        }

        return $errors;
    }

    public function assets(): GameAssets
    {
        return new GameAssets('rocket-league', 'var(--color-rl)', 'var(--color-rl-deep)', 'RL');
    }
}
