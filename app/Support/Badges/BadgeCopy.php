<?php

namespace App\Support\Badges;

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
        return match ($game.'/'.$mode) {
            'chess/blitz' => __('Chess blitz'),
            'chess/correspondence' => __('Chess daily'),
            default => ($game === 'rocket-league' ? 'Rocket League' : ucfirst($game)).' '.$mode,
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

    /** `pre-season` → "Pre-Season", `season-2` → "Season 2". */
    public static function season(string $slug): string
    {
        return $slug === 'pre-season' ? __('Pre-Season') : ucwords(str_replace('-', ' ', $slug));
    }
}
