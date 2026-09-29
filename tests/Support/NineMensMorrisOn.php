<?php

namespace Tests\Support;

use App\Games\GameRegistry;
use App\Games\NineMensMorris;
use Illuminate\Support\Facades\Route;

/**
 * Nine men's morris switched on for one test (plan "Mühle und Dame", P3):
 * both board game switches on, the registry rebuilt from the config as at
 * boot, and the board game page routed, as routes/web.php does when the
 * switch is on at boot (the test app boots with it off).
 */
final class NineMensMorrisOn
{
    /**
     * A whole game, worked out by hand: no mill until White's ninth man
     * closes b6 d6 f6 and takes g1; Black puts its last man back on g1, and
     * White's first step g7-g4 walls in all eight black men (every neighbour
     * of a black man is taken), so White wins with "No move left" at ply 19.
     */
    public const BLOCKING_GAME = [
        'b2', 'd2', 'b6', 'd3', 'c3', 'd5', 'c5', 'e3', 'd1', 'e5', 'd6', 'f2', 'e4', 'f4', 'g7', 'g1',
        'f6xg1', 'g1', 'g7-g4',
    ];

    public static function play(): GameRegistry
    {
        config(['esports.board_games.enabled' => true, 'esports.board_games.games.'.NineMensMorris::SLUG.'.enabled' => true]);
        app()->forgetInstance(GameRegistry::class);

        if (! Route::has('board.show')) {
            Route::middleware('web')->group(base_path('routes/board.php'));
            app('router')->getRoutes()->refreshNameLookups();
            app('router')->getRoutes()->refreshActionLookups();
        }

        return app(GameRegistry::class);
    }
}
