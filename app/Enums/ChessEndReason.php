<?php

namespace App\Enums;

/**
 * Why a chess game ended. Every reason except `Aborted` comes with a PGN
 * result; the draws by rule end the game on their own (the server does not
 * wait for a claim). `Abandoned`: the other player claimed the win after the
 * opponent stayed disconnected past the claim timeout (ChessOverlays).
 */
enum ChessEndReason: string
{
    case Checkmate = 'checkmate';
    case Resignation = 'resignation';
    case Timeout = 'timeout';
    case Agreement = 'agreement';
    case Stalemate = 'stalemate';
    case ThreefoldRepetition = 'threefold_repetition';
    case FiftyMoveRule = 'fifty_move_rule';
    case InsufficientMaterial = 'insufficient_material';
    case Aborted = 'aborted';
    case Abandoned = 'abandoned';
    case Director = 'director';

    /**
     * English label; views translate it.
     */
    public function label(): string
    {
        return match ($this) {
            self::Checkmate => 'Checkmate',
            self::Resignation => 'Resignation',
            self::Timeout => 'Time',
            self::Agreement => 'Draw by agreement',
            self::Stalemate => 'Stalemate',
            self::ThreefoldRepetition => 'Threefold repetition',
            self::FiftyMoveRule => '50-move rule',
            self::InsufficientMaterial => 'Insufficient material',
            self::Aborted => 'Aborted',
            self::Abandoned => 'Opponent left',
            self::Director => 'Entered by the tournament director',
        };
    }
}
