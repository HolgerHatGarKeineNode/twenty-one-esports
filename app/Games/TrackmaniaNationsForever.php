<?php

namespace App\Games;

use App\Support\Tmnf\TmnfIngest;

/**
 * TrackMania Nations Forever on the league's own dedicated server (plan
 * "Trackmania und Restposten"), as a score game: one mode, time attack, the
 * course is a track's UID, the lowest time in milliseconds wins.
 *
 * The league reads the server itself (`tmnf:listen`, App\Support\Tmnf): every
 * finish the server reports is a run of the server source, verified as read,
 * because the server timed it. The player's TMNF login is their private
 * account id (`tmnf` in users.gamer_tags); a login maps to a league player only
 * once they linked it with a one-time code typed into the server chat
 * (App\Support\Tmnf\TmnfLinks), never in public. No API poller, no manual
 * submission. The weekly leaderboard is opened and joined by
 * App\Support\Tmnf\TmnfWeeks.
 *
 * Registered as a score game only while `esports.tmnf.enabled` is on
 * (AppServiceProvider::scoreGames()).
 */
final class TrackmaniaNationsForever extends ScoreGame
{
    public const SLUG = 'tmnf';

    public const MODE = 'time-attack';

    /** The private account service: the player's TMNF login. */
    public const SERVICE = 'tmnf';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'TrackMania Nations Forever';
    }

    public function modes(): array
    {
        return [
            self::MODE => new GameMode(self::MODE, 'Time attack', 1, [], [], 'player', false),
        ];
    }

    public function metric(GameMode $mode): ScoreMetric
    {
        return ScoreMetric::time();
    }

    public function courseLabel(): string
    {
        return 'Track';
    }

    /**
     * A TMNF track UID: 20 to 32 letters, digits and underscores (A01-Race is `BeySZdnfuSh4nHY5xztiXLmlrXe`).
     */
    public function isCourse(string $course): bool
    {
        return preg_match('/^[A-Za-z0-9_]{20,32}$/', $course) === 1;
    }

    public function accountService(): string
    {
        return self::SERVICE;
    }

    public function accountLabel(): string
    {
        return 'TMNF login';
    }

    /**
     * The listener stores finishes through this adapter; a bridge may post the league's JSON format to it too.
     */
    public function serverIngest(): string
    {
        return TmnfIngest::class;
    }

    public function acceptsManual(): bool
    {
        return false;
    }

    public function assets(): GameAssets
    {
        return new GameAssets('flag', 'var(--color-tmnf)', 'var(--color-tmnf-deep)', 'TMNF', new GameCover(self::SLUG));
    }
}
