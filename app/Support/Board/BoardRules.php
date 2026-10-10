<?php

namespace App\Support\Board;

use InvalidArgumentException;

/**
 * The rules of one board game other than chess (plan "Mühle und Dame", P2):
 * everything the board game core (BoardGameService) needs to know about a
 * game, and nothing about players, clocks or the database. An implementation
 * is plain PHP without the framework, unit-testable from hand-made positions
 * (NineMensMorrisRules in P3, CheckersRules in P4).
 *
 * Positions: the rules work on their own position type and store it as text
 * (serialize/deserialize); the core keeps only that text and never looks
 * inside it. Sides are `w` (moves first) and `b`.
 *
 * Moves: a move is a string in the game's own encoding, and legalMoves() is
 * the only truth about what may be played. The core plays a move only if it
 * is in that list; apply() is never called with anything else. A player makes
 * a move by clicking points of the board in the order path() names (a
 * placement is one point, a step two, a capture chain or a step closing a
 * mill with its removal more), so the board needs no game knowledge of its
 * own: it narrows the legal moves click by click and proposes the one whose
 * path it completed; the server decides.
 *
 * @template TPosition
 */
interface BoardRules
{
    /**
     * @return TPosition the start position
     */
    public function start(): mixed;

    /**
     * @param  TPosition  $position
     */
    public function serialize(mixed $position): string;

    /**
     * @return TPosition
     *
     * @throws InvalidArgumentException for text that is no position of this game
     */
    public function deserialize(string $position): mixed;

    /**
     * @param  TPosition  $position
     * @return 'w'|'b' the side to move
     */
    public function turn(mixed $position): string;

    /**
     * Every legal move of the side to move; empty when it has none (the game
     * is over then: outcome() says how).
     *
     * @param  TPosition  $position
     * @return list<string>
     */
    public function legalMoves(mixed $position): array;

    /**
     * The point ids a player clicks, in order, to make this move. Among the
     * legal moves of one position no path is the beginning of another (a
     * capture chain that can go on is no legal move yet), so the board knows
     * a move is complete when its path is. A game whose view asks for the
     * block input (`input` = `blocks`, Blockli) differs: its board finds a
     * block by its crossing and direction, the path `[crossing, h|v]`, and
     * a pawn move by the last point of its path.
     *
     * @return list<string>
     */
    public function path(string $move): array;

    /**
     * The position after a move from legalMoves($position).
     *
     * @param  TPosition  $position
     * @return TPosition
     */
    public function apply(mixed $position, string $move): mixed;

    /**
     * The move as players write it (the move list, the game record).
     *
     * @param  TPosition  $position  the position before the move
     */
    public function notation(mixed $position, string $move): string;

    /**
     * How the game ended in this position, or null while it goes on.
     * `reason` is a stable snake_case code of this game's rules (the page
     * translates it), never one of the core's own (BoardEndReason).
     *
     * @param  TPosition  $position
     * @param  list<string>  $history  every serialized position from the start up to and including this one (repetition rules)
     * @return array{result: '1-0'|'0-1'|'1/2-1/2', reason: string}|null
     */
    public function outcome(mixed $position, array $history): ?array;

    /**
     * Every reason code outcome() can return, with its English label; the
     * page translates the label.
     *
     * @return array<string, string>
     */
    public function reasons(): array;

    /**
     * What the board shows, in the units of a `width` x `height` SVG view
     * box: the lines and cells drawn under the pieces, the points a player
     * can click (with their id) and the piece on each occupied point.
     * `input`, if set, asks the board for another way of clicking than path
     * by path: `blocks` (Blockli) moves the pawn with one tap on its target
     * and shows a block at the crossing nearest to a tap, to be confirmed.
     * `repetitions`, if set, is the repetition limit of a game whose pieces
     * and side to move are its whole position (Blockli): the board counts how
     * often the position has stood there and warns at the last time before
     * the draw.
     *
     * @param  TPosition  $position
     * @return array{width: int, height: int, lines: list<array{0: int, 1: int, 2: int, 3: int}>, cells: list<array{x: int, y: int, size: int}>, points: list<array{id: string, x: int, y: int}>, pieces: array<string, array{side: 'w'|'b', kind: string}>, input?: 'blocks', repetitions?: int}
     */
    public function view(mixed $position): array;
}
