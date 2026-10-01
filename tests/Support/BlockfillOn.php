<?php

namespace Tests\Support;

use App\Games\GameRegistry;
use Illuminate\Support\Facades\Route;

/**
 * Blockfill switched on for one test (plan "Blockfill", P2): the switch on,
 * the registry rebuilt from the config as at boot (P4: Blockfill is then a
 * score game), and routes/stacker.php and routes/score.php routed, as
 * routes/web.php does when the switch is on at boot (the test app boots
 * with it off).
 */
final class BlockfillOn
{
    public static function play(): void
    {
        config(['esports.blockfill.enabled' => true]);
        app()->forgetInstance(GameRegistry::class);

        if (! Route::has('stacker.runs.issue')) {
            Route::middleware('web')->group(base_path('routes/stacker.php'));
        }

        if (! Route::has('scores.show')) {
            Route::middleware('web')->group(base_path('routes/score.php'));
        }

        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();
    }

    /**
     * A reference run from tests/Fixtures/stacker: its seed, settings,
     * expected result and encoded replay.
     *
     * @return array{seed: string, settings: array{das: int, arr: int, sdf: int}, expected: array{ticks: int, lines: int, stateHash: string}, replay: string}
     */
    public static function fixture(string $name): array
    {
        $fixture = json_decode((string) file_get_contents(base_path("tests/Fixtures/stacker/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
        $fixture['replay'] = trim((string) file_get_contents(base_path("tests/Fixtures/stacker/{$name}.replay")));

        return $fixture;
    }
}
