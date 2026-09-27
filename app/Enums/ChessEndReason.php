<?php

namespace App\Enums;

/**
 * Why a chess game ended. Every reason except `Aborted` comes with a PGN
 * result; the draws by rule end the game on their own (the server does not
 * wait for a claim). `Abandoned`: the other player claimed the win after the
 * opponent stayed disconnected past the claim timeout (ChessOverlays).
 * `Forfeit` (P18): a tournament game the league decided against a side that
 * missed its first move or withdrew (account deleted); it moves no Elo.
 * `Voided` (P18): a tournament game an organizer or admin voided (a round
 * restart, a correction that changed its pairing, the tournament called
 * off); no result, nothing rated, attested or moved in the bracket.
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
    case Forfeit = 'forfeit';
    case Voided = 'voided';

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
            self::Forfeit => 'Forfeit',
            self::Voided => 'Voided by the league',
        };
    }
}
