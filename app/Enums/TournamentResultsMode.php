<?php

namespace App\Enums;

/**
 * Who enters results (TOURNAMENT-FORMATS.md, section 6): the players, with a
 * report and an acceptance like every series, or the tournament directors
 * (the creator plus named directors), without the players' confirmation.
 */
enum TournamentResultsMode: string
{
    case Players = 'players';
    case Director = 'director';

    public function label(): string
    {
        return match ($this) {
            self::Players => __('Players report results'),
            self::Director => __('Tournament directors enter results'),
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::Players => __('Players enter the score, the other side accepts it. Problems go to an admin.'),
            self::Director => __('For on-site events. You and the directors you name enter and correct every result; players can’t report. Every entry is saved with name and time and shown as “Entered by the tournament director”.'),
        };
    }
}
