<?php

namespace Tests\Support;

use App\Games\GameAssets;
use App\Games\GameMode;
use App\Games\ScoreGame;
use App\Games\ScoreMetric;

/**
 * A made-up score game for tests/Feature/GameSurfacesTest.php: registered by
 * the test that needs it (never in config/esports.php), so it proves that a
 * score game added later reaches every surface with no change there. A
 * highscore in points, no cover (the views draw their stand-in), no sources
 * (manual submissions only, as the ScoreGame default).
 */
final class FakeScoreGame extends ScoreGame
{
    public const SLUG = 'pixel-sprint';

    public const MODE = 'endless';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Pixel Sprint';
    }

    public function modes(): array
    {
        return [self::MODE => new GameMode(self::MODE, 'Endless', 1, [], [], 'player', false)];
    }

    public function metric(GameMode $mode): ScoreMetric
    {
        return ScoreMetric::points();
    }

    public function assets(): GameAssets
    {
        return new GameAssets('trophy', 'var(--color-edge)', 'var(--color-line)', 'Pixel');
    }
}
