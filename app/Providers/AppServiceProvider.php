<?php

namespace App\Providers;

use App\Games\Contracts\Game;
use App\Games\GameRegistry;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Clans\ClanStats;
use App\Support\Engagement\ClanHashrate;
use App\Support\PageMeta;
use App\Support\SeasonChain\AnchoredTrustFacts;
use App\Support\SeasonChain\TrustFacts;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
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

        // One PageMeta per request, kept on the request itself: a scoped
        // binding is only reset by Octane and queue workers, so in HTTP tests
        // the tags of one request leaked into the next one.
        $this->app->bind(PageMeta::class, function (Application $app): PageMeta {
            $attributes = $app->make('request')->attributes;

            if (! $attributes->get(PageMeta::class) instanceof PageMeta) {
                $attributes->set(PageMeta::class, new PageMeta);
            }

            return $attributes->get(PageMeta::class);
        });

        // One clan-statistics instance per request (security gate P10), kept on the
        // request like PageMeta above: every panel of a page shares one hashrate read.
        foreach ([ClanHashrate::class, ClanStats::class] as $perRequest) {
            $this->app->bind($perRequest, function (Application $app) use ($perRequest): object {
                $attributes = $app->make('request')->attributes;

                if (! $attributes->get($perRequest) instanceof $perRequest) {
                    $attributes->set($perRequest, $app->build($perRequest));
                }

                return $attributes->get($perRequest);
            });
        }

        // The trust job's ranks (P7d); without a run in the live season rated play stays closed.
        $this->app->bind(TrustFacts::class, AnchoredTrustFacts::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Gate::define('admin', fn (User $user): bool => $user->isAdmin());

        // Tournaments (P8): admins and the organizers an admin unlocked create
        // them; an organizer manages only their own. Directors (the creator, the
        // named ones and admins) enter results in director mode (P8b).
        Gate::define('create-tournaments', fn (User $user): bool => $user->isAdmin() || $user->isTournamentOrganizer());
        Gate::define('manage-tournament', fn (User $user, Tournament $tournament): bool => $user->isAdmin()
            || ($tournament->created_by_id === $user->id && $user->isTournamentOrganizer()));
        // Admins direct every tournament too: they enter the matches a director has an interest in
        // (App\Support\Tournaments\TournamentInterest; security gate P8b).
        Gate::define('direct-tournament', fn (User $user, Tournament $tournament): bool => $user->isAdmin() || $tournament->isDirectedBy($user));

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
