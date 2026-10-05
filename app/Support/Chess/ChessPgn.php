<?php

namespace App\Support\Chess;

use App\Enums\ChessEndReason;
use App\Models\ChessGame;
use App\Models\ChessMove;

/**
 * The PGN of a game: the "Game file" of ChessGameDone and the `content` of
 * the NIP-64 note (kind 64) the league signs when the game ends, which a
 * player may also post to their profile (NIP rev. 9.4).
 *
 * NIP-64 asks publishers for PGN "export format" (PGN standard 3.2, 8.1,
 * 8.2): the Seven Tag Roster first and in order (Event, Site, Date, Round,
 * White, Black, Result), every further tag pair after it in ASCII order by
 * tag name, one tag pair per line, one empty line before the movetext,
 * movetext lines of fewer than 80 printing characters, the result as the
 * movetext terminator. A tag value is one line without tabs (4.2), so a
 * player name carrying a newline or tab from its Nostr profile is folded to
 * single spaces.
 *
 * The tag values are frozen when the game starts (`pgn_headers`): a player
 * renaming their Nostr profile mid-game must not change the game's text, and
 * a daily game begun before rev. 9.4 already has per-move notes with these
 * headers (then: a move was the previous PGN plus one move).
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
            // The league signs the record of a rated game (NIP rev. 9.4): its Event must not say casual.
            // The mode from the registry (plan "Schach Rapid und Clan", P3): "rapid", "blitz", "daily chess".
            'Event' => 'TWENTY ONE esports, '.($game->rated ? 'rated' : 'casual').' '.($game->isCorrespondence() ? 'daily chess' : ChessModes::word($game->mode)),
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

        // A board of a team match (NIP rev. 9.22): PGN 9.2.5 "Board", the board number in a team event.
        if ($game->series_match_id !== null && $game->board !== null) {
            $headers['Board'] = (string) $game->board;
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

        $supplemental = array_diff_key($frozen, $headers);
        $supplemental['Termination'] = match ($reason) {
            ChessEndReason::Timeout => 'time forfeit',
            ChessEndReason::Aborted, ChessEndReason::Abandoned, ChessEndReason::Forfeit => 'abandoned',
            ChessEndReason::Director => 'adjudication',
            null => 'unterminated',
            default => 'normal',
        };

        // Export format: after the Seven Tag Roster, "any additional tag pairs appear in ASCII order by tag name".
        ksort($supplemental, SORT_STRING);
        $headers += $supplemental;

        $lines = array_map(
            fn (string $key, string $value) => '['.$key.' "'.str_replace(['\\', '"'], ['\\\\', '\\"'], self::tagValue($value)).'"]',
            array_keys($headers),
            $headers,
        );

        // Move numbers continue from the start position (FEN fields 2 and 6). A game whose
        // first move is Black's opens with "N..." (export format, 8.2.2.2).
        $fen = explode(' ', $game->start_fen ?? ChessGame::START_FEN);
        $number = max(1, (int) ($fen[5] ?? 1));
        $whiteToMove = ($fen[1] ?? 'w') !== 'b';
        $moves = [];

        foreach ($sans as $index => $san) {
            if ($whiteToMove) {
                $moves[] = $number.'. '.$san;
            } else {
                $moves[] = ($index === 0 ? $number.'... ' : '').$san;
                $number++;
            }

            $whiteToMove = ! $whiteToMove;
        }

        // Export format: movetext lines have "less than 80 printing characters", so 79 at most.
        return implode("\n", $lines)."\n\n".wordwrap(trim(implode(' ', $moves).' '.$result), 79)."\n";
    }

    /**
     * A tag value as export format allows it: one line, no control
     * characters (newline, tab, ...), whitespace runs folded to one space,
     * `?` when nothing is left ("unknown" in the Seven Tag Roster).
     */
    private static function tagValue(string $value): string
    {
        $folded = trim((string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}\s]+/u', ' ', $value));

        return $folded === '' ? '?' : $folded;
    }
}
