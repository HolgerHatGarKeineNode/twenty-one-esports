<?php

namespace App\Support\SeasonChain;

use App\Models\Season;
use App\Models\User;
use App\Support\PreSeason;
use Carbon\CarbonImmutable;

/**
 * Which chain season is live, and the rest state around it
 * (SEASON-CHAIN.md, "Pre-launch / between seasons", decision 5): before
 * Block 0 and between seasons only rated play and mining rest; casual games,
 * correspondence chess and every preparation go on.
 *
 * A season is live from its genesis (Block 0) until its `ends`, never from a
 * setting, so no deployment can open rated play before an admin released it.
 */
final class Seasons
{
    /** The season whose [Block 0, ends) contains $at. */
    public static function live(?CarbonImmutable $at = null): ?Season
    {
        $at ??= CarbonImmutable::now();

        return Season::query()
            ->where('genesis_at', '<=', $at)
            ->where('ends_at', '>', $at)
            ->latest('genesis_at')
            ->first();
    }

    public static function isLive(): bool
    {
        return self::live() !== null;
    }

    /** The newest released season, live or ended. */
    public static function latest(): ?Season
    {
        return Season::query()->latest('genesis_at')->first();
    }

    /**
     * `live`, `pre-launch` (no season was ever released) or `between` (the
     * last season has ended and none is live).
     *
     * @return 'live'|'pre-launch'|'between'
     */
    public static function state(): string
    {
        if (self::isLive()) {
            return 'live';
        }

        return self::latest() === null ? 'pre-launch' : 'between';
    }

    /**
     * The friendly refusal of a resting action, with the countdown to the
     * planned Block 0 when there is one. Never promises a next season.
     */
    public static function restMessage(?User $user = null): string
    {
        if (self::state() === 'between') {
            return __('The season has ended. Rated play and mining rest until the board releases a new Block 0; casual games go on.');
        }

        $block0At = PreSeason::block0At();

        if ($block0At === null) {
            return __('Casual until Block 0. Rated play and mining start when the board releases it; the date is coming soon.');
        }

        if (! $block0At->isFuture()) {
            return __('Block 0 is due. Rated play and mining start once the board releases it; until then every game is casual.');
        }

        $local = $block0At->setTimezone(PreSeason::timezoneFor($user))->settings(['locale' => app()->getLocale()]);

        return __('Casual until Block 0. Rated play and mining start :when (in :left).', [
            'when' => $local->translatedFormat('D j M, H:i'),
            'left' => self::left(PreSeason::secondsLeft()),
        ]);
    }

    /** "2 d 04:12:09" */
    public static function left(int $seconds): string
    {
        $seconds = max(0, $seconds);

        return intdiv($seconds, 86400).' '.__('d').' '.sprintf('%02d:%02d:%02d', intdiv($seconds % 86400, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }
}
