<?php

namespace App\Providers;

use App\Games\Contracts\Game;
use App\Games\GameRegistry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GameRegistry::class, fn (): GameRegistry => new GameRegistry(
            array_map(fn (string $class): Game => $this->app->make($class), config('esports.games', [])),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Gate::define('admin', fn (User $user): bool => $user->isAdmin());

        // `composer dev` also runs the scheduler: the chess flag sweep
        // (routes/console.php) is part of how a clock runs out.
        DevCommands::artisan('schedule:work', 'schedule');

        // Live chess moves, clocks and presence need the websocket server.
        DevCommands::artisan('reverb:start', 'reverb');
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );
    }
}
