<?php

namespace App\Games;

use App\Support\Scores\JsonServerIngest;
use App\Support\Scores\Sources\FakeScoreSource;

/**
 * A made-up score game that runs the whole score flow end to end without a
 * real game (plan "AoE2 und Trackmania", P4): a time trial (milliseconds,
 * lower is better) and a highscore (points, higher is better). Registered only
 * while `esports.score_games.demo` is on (ESPORTS_SCORE_GAME_DEMO, off by
 * default), so production shows nothing of it.
 */
final class ScoreDemo extends ScoreGame
{
    public const SLUG = 'score-demo';

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Score Demo';
    }

    public function modes(): array
    {
        return [
            'time-trial' => new GameMode('time-trial', 'Time trial', 1, [], [], 'player', false),
            'highscore' => new GameMode('highscore', 'Highscore', 1, [], [], 'player', false),
        ];
    }

    public function metric(GameMode $mode): ScoreMetric
    {
        return $mode->slug === 'highscore' ? ScoreMetric::points() : ScoreMetric::time();
    }

    public function courseLabel(): string
    {
        return 'Track';
    }

    /**
     * Its only automatic source is the fake one: empty unless a test fills it.
     */
    public function sources(): array
    {
        return [FakeScoreSource::class];
    }

    /**
     * The league's own JSON finish format, so a test server can report to it.
     */
    public function serverIngest(): string
    {
        return JsonServerIngest::class;
    }

    public function accountService(): string
    {
        return 'score-demo';
    }

    public function assets(): GameAssets
    {
        return new GameAssets('clock', 'var(--color-edge)', 'var(--color-line)', 'Demo');
    }
}
