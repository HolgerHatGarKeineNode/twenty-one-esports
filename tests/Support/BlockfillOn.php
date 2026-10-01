<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Route;

/**
 * Blockfill switched on for one test (plan "Blockfill", P2): the switch on
 * and routes/stacker.php routed, as routes/web.php does when the switch is
 * on at boot (the test app boots with it off).
 */
final class BlockfillOn
{
    public static function play(): void
    {
        config(['esports.blockfill.enabled' => true]);

        if (! Route::has('stacker.runs.issue')) {
            Route::middleware('web')->group(base_path('routes/stacker.php'));
            app('router')->getRoutes()->refreshNameLookups();
            app('router')->getRoutes()->refreshActionLookups();
        }
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
