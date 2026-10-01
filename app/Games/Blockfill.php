<?php

namespace App\Games;

use App\Enums\StackerRunStatus;
use App\Models\ScoreRun;
use App\Models\StackerRun;
use App\Support\Scores\Sources\ReplayScoreSource;

/**
 * Blockfill, the league's own stacking game (plan "Blockfill", P4), as a
 * score game: one mode, `40-blocks` (clear 40 rows), timed in milliseconds
 * from the verified ticks, the lowest time wins.
 *
 * The league reads no game account: the player is the logged-in user who
 * played the run, and the only source is the league's own replay of it
 * (ReplayScoreSource). So there is no account id to claim or confirm
 * (accountService() null) and no manual submission (acceptsManual() false).
 * The week's leaderboard is opened and joined by App\Support\Stacker\BlockfillWeeks.
 *
 * Registered as a score game only while `esports.blockfill.enabled` is on
 * (AppServiceProvider::scoreGames()).
 */
final class Blockfill extends ScoreGame
{
    public const SLUG = 'blockfill';

    public const MODE = '40-blocks';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Blockfill';
    }

    public function modes(): array
    {
        return [
            self::MODE => new GameMode(self::MODE, '40 blocks', 1, [], [], 'player', false),
        ];
    }

    public function metric(GameMode $mode): ScoreMetric
    {
        return ScoreMetric::time();
    }

    public function courseLabel(): string
    {
        return 'Mode';
    }

    /**
     * The mode is the course: a run is always 40 blocks on a fresh seed.
     */
    public function isCourse(string $course): bool
    {
        return $course === self::MODE;
    }

    public function sources(): array
    {
        return [ReplayScoreSource::class];
    }

    public function acceptsManual(): bool
    {
        return false;
    }

    /**
     * A ranked week mines only once its top 3 are reviewed (plan
     * "Blockfill", P7): a valid input log proves no human, so the fastest
     * runs are looked at before the league signs a block for one of them.
     * The look is the league's own replay (reviewsItself()); an admin's only
     * where that found cheat hints.
     */
    public function reviewedPlaces(): int
    {
        return 3;
    }

    /**
     * A run of the replay source whose verified Blockfill run carries no
     * cheat hint (hints.js found none in the league's own replay of it): it
     * needs no admin, only the review time. A run with hints, approved or
     * not, and a run without the hints record (fail-closed) wait for an
     * admin's click (ScoreLeaderboards::confirmReview()).
     */
    public function reviewsItself(ScoreRun $run): bool
    {
        if ($run->source !== ReplayScoreSource::KEY || $run->external_id === null) {
            return false;
        }

        $stacker = StackerRun::query()->find((int) $run->external_id);
        $flags = $stacker?->flags['hints']['flags'] ?? null;

        // No hint, or an admin already looked at the hinted run on /admin/blockfill and approved it: one look is enough.
        $approved = ($stacker?->flags['review']['decision'] ?? null) === 'approved';

        return $stacker !== null && $stacker->status === StackerRunStatus::Verified && ($flags === [] || $approved);
    }

    /**
     * Milliseconds of a run of `$ticks` (60 ticks a second), rounded down
     * as the wall-clock bracket counts them (StackerRuns).
     */
    public static function milliseconds(int $ticks): int
    {
        return intdiv($ticks * 1000, 60);
    }

    /**
     * The cover from the design drafts (plan "Blockfill", P6): `public/images/games/blockfill-{480,1280}.{webp,jpg}`.
     */
    public function assets(): GameAssets
    {
        return new GameAssets('grid', 'var(--color-btc)', 'var(--color-btc-deep)', 'Blockfill', new GameCover(self::SLUG));
    }
}
