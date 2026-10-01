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
    private const MEMO = 'seasons.live';

    /**
     * The season whose [Block 0, ends) contains $at.
     *
     * The live season now is looked up once per HTTP request: the home page
     * asks it once per game (Ladders::isOpen()), so without the memo every
     * new game was one more query. It is kept on the request (like
     * LeagueSettings), forgotten when a season is saved or deleted, and only
     * trusted while the kept season still contains now. Console processes
     * (queue workers, the stream daemon, the scheduler) keep one request for
     * their whole life and would never see Block 0 released by another
     * process, so they always ask the database; the test runner keeps it
     * because every test request is a fresh request. A moved test clock
     * (travel()) is a different memo.
     */
    public static function live(?CarbonImmutable $at = null): ?Season
    {
        if ($at !== null || (app()->runningInConsole() && ! app()->runningUnitTests())) {
            return self::query($at ?? CarbonImmutable::now());
        }

        $now = CarbonImmutable::now();
        $attributes = request()->attributes;
        $memo = self::MEMO.'.'.(CarbonImmutable::hasTestNow() ? CarbonImmutable::getTestNow()->format('U.u') : 'now');
        $kept = $attributes->get($memo);

        if (is_array($kept) && ($kept['season'] === null || $kept['season']->isLiveAt($now))) {
            return $kept['season'];
        }

        $season = self::query($now);
        $attributes->set($memo, ['season' => $season]);

        return $season;
    }

    /** Forgets the live season kept on this request (a season was saved or deleted). */
    public static function forget(): void
    {
        $attributes = request()->attributes;

        foreach (array_keys($attributes->all()) as $key) {
            if (str_starts_with((string) $key, self::MEMO.'.')) {
                $attributes->remove($key);
            }
        }
    }

    private static function query(CarbonImmutable $at): ?Season
    {
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
