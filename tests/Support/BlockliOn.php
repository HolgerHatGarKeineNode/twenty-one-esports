<?php

namespace Tests\Support;

use App\Games\Blockli;
use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Support\Board\BlockliRules;
use Illuminate\Support\Facades\Route;

/**
 * Blockli switched on for a test (plan "Blockli", P2): the board game switch
 * and the Blockli entry on, the registry rebuilt from the config and the
 * board game pages routed, as routes/web.php does when the switch is on at
 * boot (the test app boots with it off).
 */
final class BlockliOn
{
    public static function play(): GameRegistry
    {
        config(['esports.board_games.enabled' => true, 'esports.board_games.games.'.Blockli::SLUG.'.enabled' => true]);
        app()->forgetInstance(GameRegistry::class);

        if (! Route::has('board.show')) {
            Route::middleware('web')->group(base_path('routes/board.php'));
            app('router')->getRoutes()->refreshNameLookups();
            app('router')->getRoutes()->refreshActionLookups();
        }

        return app(GameRegistry::class);
    }

    /**
     * Puts a hand-made position on a running game's board, written as the
     * rules serialize it: "e7 e3 10 10 w - 0" is White's pawn on e7, Black's
     * on e3, ten blocks each, White to move, no block set, no quiet ply.
     */
    public static function setUp(BoardGame $game, string $position): BoardGame
    {
        $rules = new BlockliRules;
        $parsed = $rules->deserialize($position);

        $game->forceFill(['position' => $rules->serialize($parsed), 'turn' => $rules->turn($parsed)])->save();

        return $game->refresh();
    }
}
