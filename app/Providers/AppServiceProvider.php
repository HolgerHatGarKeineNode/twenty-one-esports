<?php

namespace App\Providers;

use App\Games\Contracts\Game;
use App\Games\GameRegistry;
use App\Models\User;
use App\Support\PageMeta;
use App\Support\SeasonChain\NoTrustFacts;
use App\Support\SeasonChain\TrustFacts;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        $this->app->scoped(PageMeta::class);

        // No trust job yet: rule 1 of the season chain fails closed (P7c).
        $this->app->bind(TrustFacts::class, NoTrustFacts::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Gate::define('admin', fn (User $user): bool => $user->isAdmin());

        // Profile hand-ins (P10a): one batch per page load is the normal case.
        // Invite links (P6b): the codes are unguessable anyway; this keeps a
        // scanner from hammering the landing and the preview renderer.
        RateLimiter::for('invites', fn (Request $request): Limit => Limit::perMinute(60)->by($request->ip()));

        RateLimiter::for('profiles', fn (Request $request): Limit => Limit::perMinute((int) config('esports.profiles.throttle_per_minute'))
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

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
