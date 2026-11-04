<?php

namespace Tests\Support;

use App\Games\Blockspace;
use App\Games\GameRegistry;
use Illuminate\Support\Facades\Route;

/**
 * Blockspace switched on for a test: the board game switch
 * and the Blockspace entry on, the registry rebuilt from the config and the
 * board game pages routed, as routes/web.php does when the switch is on at
 * boot (the test app boots with it off).
 */
final class BlockspaceOn
{
    public static function play(): GameRegistry
    {
        config(['esports.board_games.enabled' => true, 'esports.board_games.games.'.Blockspace::SLUG.'.enabled' => true]);
        app()->forgetInstance(GameRegistry::class);

        if (! Route::has('board.show')) {
            Route::middleware('web')->group(base_path('routes/board.php'));
            app('router')->getRoutes()->refreshNameLookups();
            app('router')->getRoutes()->refreshActionLookups();
        }

        return app(GameRegistry::class);
    }
}
