<?php

namespace App\Enums;

use App\Support\Tournaments\GameProfile;

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

    /**
     * The mode's one-line title in the format chooser's radio (rule R11): it fits a 326 px radio at 390 px in German and
     * English. The tournament page keeps the longer label().
     */
    public function choice(): string
    {
        return match ($this) {
            self::Players => __('Players report results'),
            self::Director => __('Directors enter results'),
        };
    }

    /**
     * The radio title for a game the league server plays ({@see GameProfile::playedOnLeague()}): there "players report"
     * was wrong, nobody reports a game played here (DerCaddy, 2026-11-04: "bei den Spielen im Browser macht die Auswahl
     * ... keinen Sinn"), so the choice is where the games are played. Directors move chess and the board games to real
     * boards; Proof of Pong and Hyperbitcoinization stay here in either mode, so their directors' title stays.
     */
    public function choiceFor(GameProfile $profile): string
    {
        return match (true) {
            ! $profile->playedOnLeague() => $this->choice(),
            $this === self::Players => __('Here in the browser'),
            ! $profile->playedOnLeague(true) => __('At real boards, directors enter'),
            default => $this->choice(),
        };
    }

    /** What the mode means for a game the league server plays ({@see choiceFor()}). */
    public function helpFor(GameProfile $profile): string
    {
        return match (true) {
            ! $profile->playedOnLeague() => $this->help(),
            $this === self::Players => __('Every game runs here on the league; its result comes from the game itself, nobody reports it.'),
            ! $profile->playedOnLeague(true) => __('For on-site events at real boards: no game starts here. You and the directors you name enter every result. Every entry is saved with name and time.'),
            default => $this->help(),
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
