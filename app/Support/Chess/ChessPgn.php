<?php

namespace App\Support\Chess;

use App\Enums\ChessEndReason;
use App\Models\ChessGame;
use App\Models\ChessMove;

/**
 * The PGN of a game ("Game file" on ChessGameDone: copy or download). The
 * same text is what P5b publishes as NIP-64; nothing is published here.
 */
final class ChessPgn
{
    public static function of(ChessGame $game): string
    {
        $result = $game->result ?? '*';
        $date = ($game->created_at ?? now())->utc();

        $headers = [
            'Event' => 'TWENTY ONE esports, casual blitz',
            'Site' => route('games.show', $game),
            'Date' => $date->format('Y.m.d'),
            'White' => $game->white->displayName(),
            'Black' => $game->black->displayName(),
            'Result' => $result,
            'TimeControl' => ($game->initial_ms / 1000).'+'.($game->increment_ms / 1000),
            'Termination' => match ($game->end_reason) {
                ChessEndReason::Timeout => 'time forfeit',
                ChessEndReason::Aborted => 'abandoned',
                null => 'unterminated',
                default => 'normal',
            },
        ];

        if ($game->start_fen !== null) {
            $headers += ['SetUp' => '1', 'FEN' => $game->start_fen];
        }

        $lines = array_map(fn (string $key, string $value) => '['.$key.' "'.str_replace('"', "'", $value).'"]', array_keys($headers), $headers);

        $moves = $game->moves->map(fn (ChessMove $move) => ($move->ply % 2 === 1 ? intdiv($move->ply + 1, 2).'. ' : '').$move->san);

        return implode("\n", $lines)."\n\n".wordwrap(trim($moves->implode(' ').' '.$result), 80)."\n";
    }
}
