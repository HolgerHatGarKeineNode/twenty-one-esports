<?php

namespace App\Games;

use App\Support\Board\BlockliRules;
use App\Support\Board\BoardRules;

/**
 * Blockli, a race with blocks on 9 x 9 (Quoridor-type rules), blitz 5+3,
 * rapid 10+5 or one move a day, played on the board game core (blitz was
 * dropped on 2026-10-07 and came back with rapid on 2026-10-11, user:
 * "Blockli braucht unbedingt einen Rapid Modus, wie Schach ihn auch hat";
 * "am besten noch Blitz auch anschalten"). Registered through
 * `config('esports.board_games.games.blockli')`, off until
 * ESPORTS_BOARD_GAME_BLOCKLI switches it on; the rules are
 * {@see BlockliRules}.
 *
 * Clan team matches over 2 or 3 boards in rapid only, exactly as chess has
 * them (DerCaddy, 2026-11-06: "Bau mir Rapid und orientiere dich strikt an
 * dem Pendant von Schach!"; NIP rev. 9.23): a Blockli lineup is
 * `<clan>/blockli/rapid`, its boards are rated solo games on the rapid
 * ladder, there is no team Elo. A solo game reports one result, a team
 * match one result per board, as in chess.
 */
final class Blockli extends BoardGame
{
    public const SLUG = 'blockli';

    public const RESULTS = ['1-0', '0-1', '1/2-1/2'];

    public function rules(): BoardRules
    {
        return new BlockliRules;
    }

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Blockli';
    }

    public function modes(): array
    {
        return [
            // As chess has them, in chess's order (user, 2026-10-11): each its own rating pool and ladder (blockli/blitz, blockli/rapid).
            'blitz' => new GameMode('blitz', 'Blitz 5+3', 1, [], [], 'player', true, '300+3'),
            // Rapid also plays clan team matches over 2 or 3 boards, as chess rapid does (NIP rev. 9.23).
            'rapid' => new GameMode('rapid', 'Rapid 10+5', 1, [], [2, 3], 'player', true, '600+5'),
            // Correspondence: one move per day, as for the other board games; the rules end every game.
            'correspondence' => new GameMode('correspondence', 'Correspondence', 1, [], [], 'player', true, '1/86400'),
        ];
    }

    public function resultSchema(GameMode $mode): array
    {
        // As chess (NIP rev. 9.23): a team match reports one result per board, in the modes that have boards.
        if ($mode->boards === []) {
            return ['result' => 'one of '.implode(', ', self::RESULTS)];
        }

        return [
            'format' => 'solo | team',
            'result' => 'solo: one of '.implode(', ', self::RESULTS),
            'boards' => 'team: list of results, board 1 first, '.implode(' or ', $mode->boards).' boards',
        ];
    }

    public function validateResult(GameMode $mode, array $result): array
    {
        if ($mode->boards === []) {
            return in_array($result['result'] ?? null, self::RESULTS, true) ? [] : ['result'];
        }

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

    /**
     * The first move is an advantage (DerCaddy's open point 5; user,
     * 2026-10-07): a tournament pairing plays twice with the colours swapped,
     * and at 1:1 a third game with drawn colours decides, in the tournament's
     * mode (blitz, rapid or correspondence).
     */
    public function playsTwoLegs(): bool
    {
        return true;
    }

    public function assets(): GameAssets
    {
        return new GameAssets('blockli', 'var(--color-edge)', 'var(--color-line)', 'Blockli', new GameCover('blockli'), $this->credit(), $this->creditUrl());
    }
}
