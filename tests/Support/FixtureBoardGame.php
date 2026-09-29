<?php

namespace Tests\Support;

use App\Games\BoardGame;
use App\Games\GameAssets;
use App\Games\GameMode;
use App\Games\GameRegistry;
use App\Support\Board\BoardRules;
use Illuminate\Support\Facades\Route;

/**
 * A made-up board game for the switch audit of plan "Mühle und Dame" (P1):
 * no board game is playable yet, and this one proves that every switch
 * between chess and the series treats a board game as neither. Test-only,
 * bound into the container by the test that needs it. Since P2 it plays
 * three men's morris (FixtureBoardRules) on the board game core.
 */
final class FixtureBoardGame extends BoardGame
{
    public const SLUG = 'fixture-board';

    /**
     * The registered games plus this board game, bound as the registry.
     */
    public static function register(): GameRegistry
    {
        $registry = new GameRegistry([...array_values(app(GameRegistry::class)->all()), new self]);
        app()->instance(GameRegistry::class, $registry);

        return $registry;
    }

    /**
     * Board games switched on with this one registered, and the board game
     * page routed, as routes/web.php does when the switch is on at boot
     * (the test app boots with it off).
     */
    public static function play(): GameRegistry
    {
        config(['esports.board_games.enabled' => true]);

        if (! Route::has('board.show')) {
            Route::middleware('web')->group(base_path('routes/board.php'));
            app('router')->getRoutes()->refreshNameLookups();
            app('router')->getRoutes()->refreshActionLookups();
        }

        $registry = app(GameRegistry::class);

        return $registry->find(self::SLUG) === null ? self::register() : $registry;
    }

    public function rules(): BoardRules
    {
        return new FixtureBoardRules;
    }

    public function slug(): string
    {
        return self::SLUG;
    }

    public function name(): string
    {
        return 'Fixture Board';
    }

    public function modes(): array
    {
        return ['blitz' => new GameMode('blitz', 'Blitz 5+3', 1, [], [], 'player', true, '300+3')];
    }

    public function resultSchema(GameMode $mode): array
    {
        return ['result' => 'one of 1-0, 0-1, 1/2-1/2'];
    }

    public function validateResult(GameMode $mode, array $result): array
    {
        return in_array($result['result'] ?? null, ['1-0', '0-1', '1/2-1/2'], true) ? [] : ['result'];
    }

    public function assets(): GameAssets
    {
        return new GameAssets('trophy', 'var(--color-edge)', 'var(--color-line)', 'FB');
    }
}
