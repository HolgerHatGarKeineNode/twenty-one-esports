<?php

namespace App\Games;

use App\Games\Contracts\Game;

/**
 * A game played as a best-of series between two sides, with goals per game
 * (Rocket League, EA Sports FC). Everything the series flow needs (challenge,
 * match room, report, dispute, tournament series) reads the mode's `bestOf`
 * and this validator; a new series game only names itself, its modes and
 * its per-game flags.
 *
 * The result mirrors the `score` tags of a Result Report (NIP kind 2152):
 * games numbered from 1, a winner per game, goals both known or both unknown
 * ("goals unknown" is the per-game escape hatch), and the series ends in the
 * game in which one side reaches ceil(bo / 2) wins. No draws.
 */
abstract class SeriesGame implements Game
{
    public const SIDES = ['challenger', 'challenged'];

    /**
     * Optional per-game flags of this game (`score` tag, position 5+).
     *
     * @return list<string>
     */
    abstract public function flags(): array;

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
            'games.*.flags' => 'optional list: '.implode(', ', $this->flags()),
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

            if (! is_array($flags) || array_diff($flags, $this->flags()) !== []) {
                $errors[] = "game_{$number}_flag";
            }

            $wins[$winner]++;
        }

        if ($errors === [] && max($wins) < $needed) {
            $errors[] = 'series_not_finished';
        }

        return $errors;
    }
}
