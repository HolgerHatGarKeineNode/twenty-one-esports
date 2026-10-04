<?php

namespace App\Providers;

use App\Games\Blockfill;
use App\Games\BoardGame;
use App\Games\Contracts\Game;
use App\Games\GameRegistry;
use App\Games\ScoreDemo;
use App\Games\ScoreGame;
use App\Games\TrackmaniaNationsForever;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Board\LiveGameGuard;
use App\Support\Clans\ClanStats;
use App\Support\Engagement\ClanHashrate;
use App\Support\PageMeta;
use App\Support\Prizes\WalletPrizePool;
use App\Support\Rating\RatingSettings;
use App\Support\RequestMemo;
use App\Support\SeasonChain\AnchoredTrustFacts;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Settings\LeagueSettings;
use App\Support\Stacker\NodeVerifier;
use App\Support\Stacker\StackerRuns;
use App\Support\Stacker\Verifier;
use App\Support\Tournaments\TournamentPrizePool;
use App\Support\TwentyOne\Stream\StreamImages;
use App\Support\Wallet\NwcTransport;
use App\Support\Wallet\WebsocketNwcTransport;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\DevCommands;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Component;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GameRegistry::class, fn (): GameRegistry => new GameRegistry(GameRegistry::ordered([
            ...array_map(fn (string $class): Game => $this->app->make($class), config('esports.games', [])),
            ...$this->boardGames(),
            ...$this->scoreGames(),
        ], (array) config('esports.game_order.first', []), (array) config('esports.game_order.last', []))));

        // The stream daemon keeps one bounded map of data URIs (StreamImages).
        $this->app->singleton(StreamImages::class);

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

        // NIP-47 travels over the wallet's relay (P9); the feature tests put a fake wallet here.
        $this->app->bind(NwcTransport::class, WebsocketNwcTransport::class);

        // The tournament page's prize pool section reads the league's pools (P9).
        $this->app->bind(TournamentPrizePool::class, WalletPrizePool::class);

        // Blockfill runs are replayed in Node (plan "Blockfill", P2); the feature tests bind a fake.
        $this->app->bind(Verifier::class, NodeVerifier::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // One live game at a time across chess and the board games (plan "Mühle und Dame", P5).
        LiveGameGuard::register();

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
        // Score servers (plan "AoE2 und Trackmania", P4): a server posts its finishes in batches, never hundreds a minute.
        RateLimiter::for('score-ingest', fn (Request $request): Limit => Limit::perMinute(120)->by($request->ip()));

        // The player picker (<x-player-picker>) asks once per typing pause.
        RateLimiter::for('player-search', fn (Request $request): Limit => Limit::perMinute(60)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        // Invoices into the prize pools (P9): each one asks the wallet, so a limit per IP.
        RateLimiter::for('invoices', fn (Request $request): Limit => Limit::perMinute((int) config('esports.wallet.invoices_per_minute', 10))->by($request->ip()));

        // The site search (P16): three capped lookups per request, open to guests, so per IP.
        RateLimiter::for('search', fn (Request $request): Limit => Limit::perMinute(30)->by($request->ip()));

        // The live status (P20b): every open page asks every 15 s, many viewers may share one IP.
        RateLimiter::for('live-status', fn (Request $request): Limit => Limit::perMinute((int) config('esports.live.status_per_minute', 240))->by($request->ip()));

        // Share cards and badge art (P11): drawn with GD on a miss, so a tight limit per IP.
        RateLimiter::for('cards', fn (Request $request): Limit => Limit::perMinute((int) config('esports.badges.cards_per_minute'))->by($request->ip()));

        // Blockfill (plan "Blockfill", P2): per player (the routes need a login), per network (IPv6 by /64, so many
        // accounts behind one address share it) and, for issues, one budget for everyone, which caps what the league
        // stores per minute. Defined here, not in routes/stacker.php, so a cached route table finds them.
        RateLimiter::for('stacker-issue', fn (Request $request): array => [
            Limit::perSecond(1, (int) config('esports.blockfill.issue_every_seconds'))->by('stacker-issue-gap:'.$request->user()?->getAuthIdentifier()),
            Limit::perHour((int) config('esports.blockfill.issue_per_hour'))->by('stacker-issue-hour:'.$request->user()?->getAuthIdentifier()),
            Limit::perHour((int) config('esports.blockfill.issue_per_ip_per_hour'))->by('stacker-issue-net:'.StackerRuns::network($request->ip())),
            Limit::perMinute((int) config('esports.blockfill.issue_per_ip_per_minute'))->by('stacker-issue-net-minute:'.StackerRuns::network($request->ip())),
            Limit::perMinute((int) config('esports.blockfill.issue_global_per_minute'))->by('stacker-issue-global'),
        ]);
        // The result screen asks for a submitted run's verdict about once a second until it has one.
        RateLimiter::for('stacker-status', fn (Request $request): Limit => Limit::perMinute(120)->by('stacker-status:'.$request->user()?->getAuthIdentifier()));
        RateLimiter::for('stacker-submit', fn (Request $request): array => [
            Limit::perMinute((int) config('esports.blockfill.submits_per_minute'))->by('stacker-submit:'.$request->user()?->getAuthIdentifier()),
            Limit::perMinute((int) config('esports.blockfill.submits_per_ip_per_minute'))->by('stacker-submit-net:'.StackerRuns::network($request->ip())),
        ]);

        RateLimiter::for('profiles', fn (Request $request): Limit => Limit::perMinute((int) config('esports.profiles.throttle_per_minute'))
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        // The rating and league settings are looked up once per request
        // (RatingSettings, LeagueSettings); a queue worker keeps one request
        // for its whole life, so once per job.
        Event::listen(JobProcessing::class, function (): void {
            RatingSettings::forget();
            LeagueSettings::forget();
        });

        // A request's memos (RequestMemo) end with its response.
        Event::listen(RequestHandled::class, fn (RequestHandled $event) => RequestMemo::close($event->request));

        // `composer dev` also runs the scheduler: the chess flag sweep
        // (routes/console.php) is part of how a clock runs out.
        DevCommands::artisan('schedule:work', 'schedule');

        // Live chess moves, clocks and presence need the websocket server.
        DevCommands::artisan('reverb:start', 'reverb');
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    /**
     * The board games switched on in `esports.board_games` (plan "Mühle und
     * Dame"): none while the switch is off, which is the default. An entry
     * whose class is missing, is no BoardGame or names another slug stays off
     * and is logged: a wrong line there must never take chess down with it.
     *
     * @return list<BoardGame>
     */
    private function boardGames(): array
    {
        if (! config('esports.board_games.enabled')) {
            return [];
        }

        $games = [];

        foreach ((array) config('esports.board_games.games', []) as $slug => $entry) {
            $class = $entry['class'] ?? null;

            if (! ($entry['enabled'] ?? false) || $class === null) {
                continue;
            }

            $game = is_string($class) && is_a($class, BoardGame::class, true) ? $this->app->make($class) : null;

            if (! $game instanceof BoardGame || $game->slug() !== $slug) {
                Log::warning('Board game entry left off: its class is no board game of this slug.', ['slug' => $slug, 'class' => $class]);

                continue;
            }

            $games[] = $game;
        }

        return $games;
    }

    /**
     * The score games of `esports.score_games` (plan "AoE2 und Trackmania",
     * P4): the demo while its switch is on, Blockfill while its switch is on
     * (plan "Blockfill", P4), TrackMania Nations Forever while its switch is
     * on (plan "Trackmania und Restposten"), then every listed class. None by default. A
     * class that is no ScoreGame stays off and is logged, as a wrong board
     * game entry does.
     *
     * @return list<ScoreGame>
     */
    private function scoreGames(): array
    {
        $classes = [
            ...(config('esports.score_games.demo') ? [ScoreDemo::class] : []),
            ...(config('esports.blockfill.enabled') ? [Blockfill::class] : []),
            ...(config('esports.tmnf.enabled') ? [TrackmaniaNationsForever::class] : []),
            ...(array) config('esports.score_games.games', []),
        ];
        $games = [];

        foreach ($classes as $class) {
            $game = is_string($class) && is_a($class, ScoreGame::class, true) ? $this->app->make($class) : null;

            if (! $game instanceof ScoreGame) {
                Log::warning('Score game entry left off: its class is no score game.', ['class' => $class]);

                continue;
            }

            $games[] = $game;
        }

        return $games;
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Performance plan P1(b): a relation read that was not eager loaded is logged, never thrown, and only outside
        // production (P2 fixes the findings, then the handler throws). One line per model.relation and place per process.
        Model::preventLazyLoading(! app()->isProduction());
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
            $where = self::lazyLoadingPlace();
            $key = $model::class.'.'.$relation.' @ '.$where;

            if (isset(self::$lazyLoadingSeen[$key])) {
                return;
            }

            self::$lazyLoadingSeen[$key] = true;
            Log::warning('lazy-loading-violation '.$key, [
                'model' => $model::class,
                'relation' => $relation,
                'where' => $where,
                'request' => request()->method().' '.request()->path(),
            ]);
        });
    }

    /** @var array<string, true> */
    private static array $lazyLoadingSeen = [];

    /**
     * Where a lazy-loaded relation was read: the first frame outside vendor/ and this provider. A class under app/
     * or a test names its file and line; a Blade view its source (the compiled file ends in a `PATH ... ENDPATH`
     * comment); a Livewire single-file component its name (`livewire:pages::games.show::render`), found on the
     * component's own frame, because its compiled class carries no source path.
     */
    private static function lazyLoadingPlace(): string
    {
        $inCompiledComponent = false;

        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
            if ($inCompiledComponent && ($frame['object'] ?? null) instanceof Component && ! str_contains($frame['function'], '{closure')) {
                return 'livewire:'.$frame['object']->getName().'::'.$frame['function'];
            }

            $file = $frame['file'] ?? null;

            if (! is_string($file) || str_contains($file, '/vendor/') || $file === __FILE__) {
                continue;
            }

            if (str_contains($file, '/storage/framework/views/') && str_contains($file, '/livewire/classes/')) {
                $inCompiledComponent = true;

                continue;
            }

            if (str_contains($file, '/storage/framework/views/')) {
                $tail = (string) @file_get_contents($file, false, null, max(0, (int) @filesize($file) - 400));
                $source = preg_match('~PATH (.+?) ENDPATH~', $tail, $match) === 1 ? $match[1] : $file;

                return str_replace(base_path().'/', '', $source);
            }

            return str_replace(base_path().'/', '', $file).':'.($frame['line'] ?? 0);
        }

        return 'unknown';
    }
}
