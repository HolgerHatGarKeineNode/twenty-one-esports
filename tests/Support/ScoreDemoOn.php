<?php

namespace Tests\Support;

use App\Games\GameRegistry;
use App\Support\Scores\Sources\FakeScoreSource;
use Illuminate\Support\Facades\Route;

/**
 * The score demo game switched on for one test (plan "AoE2 und Trackmania",
 * P4): the switch on, the registry rebuilt from the config as at boot, the
 * score routes loaded as routes/web.php loads them when a score game is
 * registered at boot (the test app boots with it off), and one fake source
 * bound for the whole test.
 */
final class ScoreDemoOn
{
    public static function play(): FakeScoreSource
    {
        config(['esports.score_games.demo' => true]);
        app()->forgetInstance(GameRegistry::class);

        if (! Route::has('scores.show')) {
            Route::middleware('web')->group(base_path('routes/score.php'));
            app('router')->getRoutes()->refreshNameLookups();
            app('router')->getRoutes()->refreshActionLookups();
        }

        $fake = new FakeScoreSource;
        app()->instance(FakeScoreSource::class, $fake);

        return $fake;
    }
}
