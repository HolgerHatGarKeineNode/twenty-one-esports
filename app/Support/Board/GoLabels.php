<?php

namespace App\Support\Board;

/**
 * The words of Go's board (DerCaddy, 2026-10-27), translated once for the board script (resources/js/boardGame.js):
 * the stone input (a stone shown first, set by a second tap; the pass that asks before it ends the game) and the
 * count as it stands. A game's page and the practice page both hand them to the board.
 */
final class GoLabels
{
    /**
     * @return array{stones: array<string, string>, result: array<string, string>, count: array<string, string>}
     */
    public static function board(): array
    {
        return [
            'stones' => [
                'tap' => __('Tap a point: your stone shows there. Tap it again to place it.'),
                'click' => __('Click a point to place your stone.'),
                'confirm' => __('Tap the stone again or press Set stone.'),
                'illegal' => __('No stone may go here (no liberty, or a ko).'),
                'taken' => __('A stone is already here.'),
                'keys' => __('Arrow keys move the stone, Enter places it, Escape takes it away.'),
                'at' => __('Stone at :point.'),
                'enter' => __('Enter places it.'),
                'passed' => __(':side passed. If you pass too, the game ends and is counted.'),
                'endGame' => __('Pass again to end the game?'),
                // Before a player's first pass of a game, with the count after it (boardGame.js passEndLine).
                // A move that took stones, for a screen reader (the move list shows "×2").
                'captured' => __(':count captured'),
                'firstPass' => __('Counted as it stands, dead stones too. If both pass:'),
            ],
            // A game's result (pages/board/partials/result, boardGame.js resultHead, resultWhy).
            'result' => [
                'resignation' => __(':name resigned'),
                'timeout' => __(':name ran out of time'),
                'uncounted' => __('The game was not counted.'),
            ],
            'count' => [
                'line' => __('Count now: :first · :second → :lead'),
                'final' => __('Final count: :first · :second → :lead'),
                'side' => __(':colour :points'),
                'komi' => __(':colour :points + :komi = :score'),
                'lead' => __(':colour +:points'),
                'level' => __('level'),
                'result' => __(':colour wins by :points.'),
                'tie' => __('A tie.'),
                // The score beside the board (pages/board/partials/go-score, boardGame.js leadLine).
                'leads' => __(':colour leads by :points.'),
                'even' => __('Both are level.'),
                'uncounted' => __('Not counted.'),
                'show' => __('Show area'),
                'hide' => __('Hide area'),
            ],
        ];
    }
}
