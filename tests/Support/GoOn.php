<?php

namespace Tests\Support;

use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Support\Board\GoRules;
use Illuminate\Support\Facades\Route;

/**
 * Go switched on for a test (DerCaddy, 2026-10-27): the board game switch and the entries of the sizes asked for on,
 * the registry rebuilt from the config and the board game pages routed, as routes/web.php does when the switch is on
 * at boot (the test app boots with it off). As BlockliOn.
 */
final class GoOn
{
    /**
     * @param  list<int>  $sizes
     */
    public static function play(array $sizes = [9, 13, 19]): GameRegistry
    {
        $config = ['esports.board_games.enabled' => true];

        foreach ($sizes as $size) {
            $config["esports.board_games.games.go-{$size}.enabled"] = true;
        }

        config($config);
        app()->forgetInstance(GameRegistry::class);

        if (! Route::has('board.show')) {
            Route::middleware('web')->group(base_path('routes/board.php'));
            app('router')->getRoutes()->refreshNameLookups();
            app('router')->getRoutes()->refreshActionLookups();
        }

        return app(GameRegistry::class);
    }

    /**
     * Puts a hand-made position on a running game's board, written as GoRules serializes it ("9/9/9/9/4x4/9/9/9/9 o - 0"
     * is a black stone on e5 and White to move), and its record of moves stays as it was.
     */
    public static function setUp(BoardGame $game, string $position): BoardGame
    {
        $rules = new GoRules((int) substr($game->game, 3));
        $parsed = $rules->deserialize($position);

        $game->forceFill(['position' => $rules->serialize($parsed), 'turn' => $rules->turn($parsed)])->save();

        return $game->refresh();
    }
}
