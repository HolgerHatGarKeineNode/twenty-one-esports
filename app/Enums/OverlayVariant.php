<?php

namespace App\Enums;

/**
 * What an OBS overlay preset shows (App\Models\OverlayPreset, plan "OBS-Broadcast-Overlays", P2): the league's
 * live overlay for everyday streaming, one tournament's overlay, the full-screen break scene, or one tournament's
 * full-screen bracket. The tournament and bracket variants need a tournament.
 */
enum OverlayVariant: string
{
    case LeagueLive = 'league-live';
    case Tournament = 'tournament';
    case Break = 'break';
    case Bracket = 'bracket';

    public function label(): string
    {
        return match ($this) {
            self::LeagueLive => __('League live overlay'),
            self::Tournament => __('Tournament overlay'),
            self::Break => __('Break scene'),
            self::Bracket => __('Bracket, full screen'),
        };
    }

    public function needsTournament(): bool
    {
        return $this === self::Tournament || $this === self::Bracket;
    }

    /** A full-screen scene covers the picture; an overlay keeps the centre free for the stream. */
    public function isFullScreen(): bool
    {
        return $this === self::Break || $this === self::Bracket;
    }
}
