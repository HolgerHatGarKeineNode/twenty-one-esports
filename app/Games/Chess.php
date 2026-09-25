<?php

namespace App\Games;

use App\Games\Contracts\Game;

/**
 * Chess: blitz 5+3 and daily (one move per day), rated per player, plus clan
 * team matches over 2 or 3 boards whose boards are rated solo games
 * (NIP "Game registry"; there is no team Elo).
 *
 * The result of one game is its PGN result. A solo game reports one result,
 * a team match one result per board. Moves themselves are checked by the
 * chess server in P5, not here.
 */
final class Chess implements Game
{
    public const RESULTS = ['1-0', '0-1', '1/2-1/2'];

    public function slug(): string
    {
        return 'chess';
    }

    public function name(): string
    {
        return 'Chess';
    }

    public function modes(): array
    {
        return [
            'blitz' => new GameMode('blitz', 'Blitz 5+3', 1, [], [2, 3], 'player', true, '300+3'),
            'correspondence' => new GameMode('correspondence', 'Daily', 1, [], [2, 3], 'player', true, '1/86400'),
        ];
    }

    public function mode(string $slug): ?GameMode
    {
        return $this->modes()[$slug] ?? null;
    }

    public function resultSchema(GameMode $mode): array
    {
        return [
            'format' => 'solo | team',
            'result' => 'solo: PGN result, one of '.implode(', ', self::RESULTS),
            'boards' => 'team: list of PGN results, board 1 first, '.implode(' or ', $mode->boards).' boards',
        ];
    }

    public function validateResult(GameMode $mode, array $result): array
    {
        return match ($result['format'] ?? null) {
            'solo' => in_array($result['result'] ?? null, self::RESULTS, true) ? [] : ['result'],
            'team' => $this->validateTeamMatch($mode, $result['boards'] ?? null),
            default => ['format'],
        };
    }

    /**
     * @return list<string>
     */
    private function validateTeamMatch(GameMode $mode, mixed $boards): array
    {
        if (! is_array($boards) || ! array_is_list($boards) || ! $mode->allowsBoards(count($boards))) {
            return ['boards_not_allowed'];
        }

        $errors = [];

        foreach ($boards as $index => $board) {
            if (! in_array($board, self::RESULTS, true)) {
                $errors[] = 'board_'.($index + 1).'_result';
            }
        }

        return $errors;
    }

    public function assets(): GameAssets
    {
        return new GameAssets('chess', 'var(--color-chess)', 'var(--color-chess-deep)', 'Chess');
    }
}
