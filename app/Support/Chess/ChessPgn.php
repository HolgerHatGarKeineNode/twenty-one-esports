<?php

namespace App\Support\Chess;

use App\Enums\ChessEndReason;
use App\Models\ChessGame;
use App\Models\ChessMove;

/**
 * The PGN of a game: the "Game file" of ChessGameDone and the `content` of
 * the NIP-64 note (kind 64) the players sign.
 *
 * NIP-64 asks publishers for PGN "export format": the Seven Tag Roster first
 * and in order (Event, Site, Date, Round, White, Black, Result), movetext
 * lines of at most 80 characters, the result as the movetext terminator.
 *
 * The tag values are frozen when the game starts (`pgn_headers`): a player
 * renaming their Nostr profile mid-game must not change the text the other
 * player already signed, and every note of a daily game carries identical
 * headers (NIP "Game Record": a move is the previous PGN plus one move).
 */
final class ChessPgn
{
    /**
     * The frozen tag pairs, without Result and Termination.
     *
     * @return array<string, string>
     */
    public static function headersFor(ChessGame $game): array
    {
        if (is_array($game->pgn_headers) && $game->pgn_headers !== []) {
            return $game->pgn_headers;
        }

        $date = ($game->created_at ?? now())->utc();

        $headers = [
            'Event' => $game->isCorrespondence() ? 'TWENTY ONE esports, casual daily chess' : 'TWENTY ONE esports, casual blitz',
            'Site' => route('games.show', $game),
            'Date' => $date->format('Y.m.d'),
            'Round' => '-',
            'White' => $game->white->displayName(),
            'Black' => $game->black->displayName(),
            'TimeControl' => $game->isCorrespondence()
                ? '1/'.intdiv($game->initial_ms, 1000)
                : ($game->initial_ms / 1000).'+'.($game->increment_ms / 1000),
        ];

        if ($game->start_fen !== null) {
            $headers += ['SetUp' => '1', 'FEN' => $game->start_fen];
        }

        return $headers;
    }

    /**
     * The game as stored: its moves and result (`*` while it runs).
     */
    public static function of(ChessGame $game): string
    {
        $sans = array_values($game->moves->map(fn (ChessMove $move): string => $move->san)->all());

        return self::build($game, $sans, $game->result ?? '*', $game->end_reason);
    }

    /**
     * The PGN for any move list of this game, e.g. the one a daily move is
     * about to produce, so the player can sign it before the server stores it.
     *
     * @param  list<string>  $sans
     */
    public static function build(ChessGame $game, array $sans, string $result, ?ChessEndReason $reason): string
    {
        $frozen = self::headersFor($game);

        $headers = [];

        foreach (['Event', 'Site', 'Date', 'Round', 'White', 'Black'] as $key) {
            $headers[$key] = $frozen[$key] ?? '?';
        }

        $headers['Result'] = $result;

        foreach ($frozen as $key => $value) {
            if (! array_key_exists($key, $headers)) {
                $headers[$key] = $value;
            }
        }

        $headers['Termination'] = match ($reason) {
            ChessEndReason::Timeout => 'time forfeit',
            ChessEndReason::Aborted, ChessEndReason::Abandoned => 'abandoned',
            null => 'unterminated',
            default => 'normal',
        };

        $lines = array_map(
            fn (string $key, string $value) => '['.$key.' "'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"]',
            array_keys($headers),
            $headers,
        );

        $moves = [];

        foreach ($sans as $index => $san) {
            $moves[] = ($index % 2 === 0 ? (intdiv($index, 2) + 1).'. ' : '').$san;
        }

        return implode("\n", $lines)."\n\n".wordwrap(trim(implode(' ', $moves).' '.$result), 80)."\n";
    }
}
