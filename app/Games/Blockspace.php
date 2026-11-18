<?php

namespace App\Games;

use App\Support\Board\BlockspaceDrawing;
use App\Support\Board\BlockspaceRules;
use App\Support\Board\BoardRules;

/**
 * Blockspace, a corner-laying game of two miners and a mempool on 9 x 21, blitz 5+3 (with the
 * fuse: the oldest transaction falls out by itself), rapid 10+5 or one move a day, played on
 * the board game core. Registered through `config('esports.board_games.games.blockspace')`,
 * off until ESPORTS_BOARD_GAME_BLOCKSPACE switches it on; the rules are {@see BlockspaceRules}.
 */
final class Blockspace extends BoardGame
{
    public const SLUG = 'blockspace';

    public const RESULTS = ['1-0', '0-1', '1/2-1/2'];

    public function rules(): BoardRules
    {
        return new BlockspaceRules;
    }

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Blockspace';
    }

    public function modes(): array
    {
        return [
            'blitz' => new GameMode('blitz', 'Blitz 5+3', 1, [], [], 'player', true, '300+3'),
            'rapid' => new GameMode('rapid', 'Rapid 10+5', 1, [], [], 'player', true, '600+5'),
            'correspondence' => new GameMode('correspondence', 'Correspondence', 1, [], [], 'player', true, '1/86400'),
        ];
    }

    /**
     * Every transaction a side set is one move for consensus rule 2 (coordinator's decision, 2026-11-18): the sides
     * move White, Black, Black, White, so there is no full move, and a game sets 42 transactions at most (measured
     * on 2 000 played-out games: 16 to 34, never the 39 plies that 20 full moves are). A pass is no move here: 13
     * passes in a row end no game, so passes alone could pad any game past the minimum.
     */
    public function minedMoves(array $moves): int
    {
        return count(array_filter($moves, fn (string $move): bool => $move !== BlockspaceRules::PASS));
    }

    public function resultSchema(GameMode $mode): array
    {
        return ['result' => 'one of '.implode(', ', self::RESULTS)];
    }

    public function validateResult(GameMode $mode, array $result): array
    {
        return in_array($result['result'] ?? null, self::RESULTS, true) ? [] : ['result'];
    }

    /** Orange and Blue, the colours of the stones (BlockspaceDrawing::FILL), not White and Black. */
    public function sides(): array
    {
        return [
            'w' => new BoardSide('Orange', 'white', BlockspaceDrawing::FILL['w'], BlockspaceDrawing::FILL['w']),
            'b' => new BoardSide('Blue', 'black', BlockspaceDrawing::FILL['b'], BlockspaceDrawing::FILL['b']),
        ];
    }

    public function assets(): GameAssets
    {
        // The cover carries a line of text, so it comes in both languages of the league.
        $cover = new GameCover(app()->getLocale() === 'de' ? 'blockspace-de' : 'blockspace');

        return new GameAssets('blockspace', 'var(--color-edge)', 'var(--color-line)', 'Blockspace', $cover, $this->credit(), $this->creditUrl());
    }
}
