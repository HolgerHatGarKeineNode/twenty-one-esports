<?php

namespace App\Support\Chess;

use App\Enums\ChessEndReason;
use PChess\Chess\Chess;
use PChess\Chess\Move;
use PChess\Chess\Piece;

/**
 * The laws of chess, delegated to p-chess/chess (MIT, the maintained
 * successor of the abandoned ryanhs/chess.php port of chess.js).
 *
 * Moves travel as UCI (`e2e4`, `e7e8q`): unambiguous, and the client's
 * chess.js produces them without knowing our SAN rules. SAN is derived here,
 * so the move list never shows what a client claimed, only what the server
 * played.
 */
final class ChessRules
{
    public const UCI_PATTERN = '/^([a-h][1-8])([a-h][1-8])([qrbn])?$/';

    /**
     * A board with the given moves played from the start position (or `fen`).
     *
     * @param  list<string>  $ucis
     */
    public static function replay(array $ucis, ?string $fen = null): Chess
    {
        $chess = new Chess($fen);

        foreach ($ucis as $uci) {
            if (self::play($chess, $uci) === null) {
                throw new \UnexpectedValueException("Stored move {$uci} is illegal in {$chess->fen()}.");
            }
        }

        return $chess;
    }

    /**
     * Play one UCI move; null (and an unchanged board) when it is not legal.
     * A promotion must name its piece: `e7e8` alone is refused.
     */
    public static function play(Chess $chess, string $uci): ?Move
    {
        if (preg_match(self::UCI_PATTERN, $uci, $parts) !== 1) {
            return null;
        }

        return $chess->move(['from' => $parts[1], 'to' => $parts[2], 'promotion' => $parts[3] ?? null]);
    }

    /**
     * How the game ends in this position, if it ends by the rules: checkmate,
     * stalemate, insufficient material, threefold repetition, 50-move rule.
     * The draws are applied automatically, nobody has to claim them.
     *
     * @param  list<string>  $fens  every position of the game, start position first, current last
     * @return array{0: '1-0'|'0-1'|'1/2-1/2', 1: ChessEndReason}|null
     */
    public static function outcome(Chess $chess, array $fens): ?array
    {
        if ($chess->inCheckmate()) {
            return [$chess->fen()[strpos($chess->fen(), ' ') + 1] === 'w' ? '0-1' : '1-0', ChessEndReason::Checkmate];
        }

        if ($chess->inStalemate()) {
            return ['1/2-1/2', ChessEndReason::Stalemate];
        }

        if ($chess->insufficientMaterial()) {
            return ['1/2-1/2', ChessEndReason::InsufficientMaterial];
        }

        if (self::repeatedThreeTimes($fens)) {
            return ['1/2-1/2', ChessEndReason::ThreefoldRepetition];
        }

        if ($chess->halfMovesExceeded()) {
            return ['1/2-1/2', ChessEndReason::FiftyMoveRule];
        }

        return null;
    }

    /**
     * The current (last) position occurred at least three times. Counted from
     * the FEN ourselves rather than p-chess's history, which never records
     * the start position and so misses a repetition of it.
     *
     * @param  list<string>  $fens
     */
    public static function repeatedThreeTimes(array $fens): bool
    {
        if ($fens === []) {
            return false;
        }

        $current = self::positionKey($fens[array_key_last($fens)]);

        return count(array_filter($fens, fn (string $fen) => self::positionKey($fen) === $current)) >= 3;
    }

    /**
     * Placement, side to move, castling rights and en-passant square: the
     * part of a FEN that makes two positions "the same" for repetition.
     */
    public static function positionKey(string $fen): string
    {
        return implode(' ', array_slice(explode(' ', $fen), 0, 4));
    }

    /**
     * Whether `color` still has anything but its king. A player whose clock
     * runs out against a lone king draws instead of losing (FIDE 6.9).
     *
     * @param  'w'|'b'  $color
     */
    public static function hasMoreThanKing(string $fen, string $color): bool
    {
        $placement = explode(' ', $fen)[0];
        $pieces = $color === Piece::WHITE ? 'PNBRQ' : 'pnbrq';

        return strpbrk($placement, $pieces) !== false;
    }
}
