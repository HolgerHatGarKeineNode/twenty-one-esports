<?php

namespace Tests\Support;

use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Support\Board\CheckersRules;
use Illuminate\Support\Facades\Route;

/**
 * Checkers switched on for a test (plan "Mühle und Dame", P4): the board game
 * switch and the checkers entry on, the registry rebuilt from the config and
 * the board game page routed, as routes/web.php does when the switch is on at
 * boot (the test app boots with it off).
 */
final class CheckersGame
{
    public static function play(): GameRegistry
    {
        config(['esports.board_games.enabled' => true, 'esports.board_games.games.checkers.enabled' => true]);
        app()->forgetInstance(GameRegistry::class);

        if (! Route::has('board.show')) {
            Route::middleware('web')->group(base_path('routes/board.php'));
            app('router')->getRoutes()->refreshNameLookups();
            app('router')->getRoutes()->refreshActionLookups();
        }

        return app(GameRegistry::class);
    }

    /**
     * Puts a hand-made position on a running game's board, with the side to
     * move it names.
     *
     * @param  array<string, 'w'|'b'|'W'|'B'>  $pieces
     * @param  'w'|'b'  $turn
     */
    public static function setUp(BoardGame $game, array $pieces, string $turn = 'w'): BoardGame
    {
        $rules = new CheckersRules;
        $board = array_fill_keys(CheckersRules::squares(), null);

        foreach ($pieces as $square => $piece) {
            if (! array_key_exists($square, $board)) {
                throw new \InvalidArgumentException("{$square} is no dark square.");
            }

            $board[$square] = $piece;
        }

        $game->forceFill(['position' => $rules->serialize(['board' => $board, 'turn' => $turn, 'quiet' => 0]), 'turn' => $turn])->save();

        return $game->refresh();
    }
}
