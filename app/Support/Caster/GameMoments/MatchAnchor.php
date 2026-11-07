<?php

namespace App\Support\Caster\GameMoments;

use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Support\Caster\SceneFacts;
use Throwable;

/**
 * Which match a running game is, spoken, for the `match` field of its
 * moments and live-game facts (user 2026-10-12: "Er redet immer von
 * Schachfiguren Weiß oder Schwarz, ohne den Matchbezug herzustellen"): the
 * game and its mode, the tournament it is played for or the rated ladder or
 * a free game, a team match's board, and the league match number when it has
 * one ("Blitz-Schachpartie im Turnier Herbst-Cup, Match Nummer 412").
 * CasterVoice::ANCHOR_RULE makes the first sentence of a comment say it.
 * Only the game's own row; a part that cannot be read is left out.
 */
final class MatchAnchor
{
    public static function of(ChessGame|BoardGame $game, string $gameName, ?string $mode = null): string
    {
        $what = ($mode === null || $mode === '' ? '' : $mode.'-').$gameName.'partie';
        $tournament = null;

        try {
            $tournament = $game->tournament_match_id === null ? null : SceneFacts::spoken($game->tournamentMatch?->tournament?->name);
        } catch (Throwable) {
            // No tournament name readable: the anchor goes without it.
        }

        $context = match (true) {
            $tournament !== null => 'im Turnier '.$tournament,
            $game->series_match_id !== null && $game->board !== null => 'am Brett '.$game->board.' eines Clan-Duells',
            (bool) $game->rated => 'gewertet in der Ladder',
            default => 'als freie Partie',
        };
        $number = $game->series_match_id !== null ? null : $game->number;

        return $what.' '.$context.($number === null ? '' : ', Match Nummer '.$number);
    }
}
