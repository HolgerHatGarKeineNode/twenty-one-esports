<?php

namespace App\Support\Badges;

use App\Games\BoardGame;
use App\Games\GameRegistry;
use App\Support\Rating\RankTiers;

/**
 * The words on a rank badge and a share card, in the current locale: the
 * ladder ("Chess blitz", "Rocket League 2v2") and the badge's name and
 * description (NIP "Rank badges": game, mode and rank; rank, season and
 * league in words).
 */
final class BadgeCopy
{
    public static function ladder(string $game, string $mode): string
    {
        return match (true) {
            $game === 'chess' && $mode === 'blitz' => __('Chess blitz'),
            $game === 'chess' && $mode === 'rapid' => __('Chess rapid'),
            $game === 'chess' && $mode === 'correspondence' => __('Chess daily'),
            // A board game's correspondence mode (plan "Mühle und Dame", P8): every board game, switched on or kept for later.
            $mode === 'correspondence' && (app(GameRegistry::class)->isBoard($game) || in_array($game, BoardGame::RESERVED_SLUGS, true)) => __(':game correspondence', ['game' => __(app(GameRegistry::class)->name($game))]),
            default => app(GameRegistry::class)->name($game).' '.$mode,
        };
    }

    public static function name(string $game, string $mode, string $tier): string
    {
        return self::ladder($game, $mode).' · '.RankTiers::label($tier);
    }

    public static function description(string $game, string $mode, string $tier, string $season): string
    {
        return __(':rank in :ladder, :season of the TWENTY ONE Esports league. Compare it with the ladder named in the badge.', [
            'rank' => RankTiers::label($tier),
            'ladder' => self::ladder($game, $mode),
            'season' => self::season($season),
        ]);
    }

    /**
     * A season's name on screen, the one source for it (plan "Season vs.
     * Casual", P2, user decision 2026-11-24): the seasons are numbered in
     * their order, so the first one, signed as `pre-season`, is "Season 1",
     * `season-1` after it is "Season 2". The slug stays in data, events and
     * URLs. Before Block 0 there is no season and nothing calls this.
     */
    public static function season(string $slug): string
    {
        if ($slug === 'pre-season') {
            return __('Season :number', ['number' => 1]);
        }

        return preg_match('/^season-(\d+)$/', $slug, $match) === 1
            ? __('Season :number', ['number' => (int) $match[1] + 1])
            : ucwords(str_replace('-', ' ', $slug));
    }
}
