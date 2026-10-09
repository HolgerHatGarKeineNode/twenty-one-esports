<?php

namespace Tests\Support;

use App\Games\GameRegistry;
use Illuminate\Support\Facades\Route;

/**
 * Proof of Pong switched on for one test (plan "Proof of Pong", P1): the switch on, the registry rebuilt from the
 * config as at boot, and routes/pong.php routed as routes/web.php does when the switch is on at boot (the test app
 * boots with it off).
 */
final class PongOn
{
    public static function play(): void
    {
        config(['esports.pong.enabled' => true]);
        app()->forgetInstance(GameRegistry::class);

        if (! Route::has('pong.index')) {
            Route::middleware('web')->group(base_path('routes/pong.php'));
        }

        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();
    }
}
