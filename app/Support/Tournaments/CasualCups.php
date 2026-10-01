<?php

namespace App\Support\Tournaments;

use App\Enums\BoardGameStatus;
use App\Enums\BoardInviteStatus;
use App\Enums\ChessGameStatus;
use App\Enums\ChessInviteStatus;
use App\Enums\SeriesResolution;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Games\BoardGame;
use App\Games\GameRegistry;
use App\Models\BoardGame as BoardGameModel;
use App\Models\BoardInvite;
use App\Models\ChessGame;
use App\Models\ChessInvite;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentRound;
use App\Models\TournamentSignup;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Settings\LeagueSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * The league's automatic casual cups (P25): per enabled game and region
 * (EU, US; user 2026-09-28) one cup series, "<Game> Casual Cup EU #n", with
 * at most one cup open at a time per series. Runs as the
 * first step of the tournament clock (`tournaments:tick`, TournamentScheduler)
 * and reuses the tournament flow: the league publishes the cup like an admin
 * tournament (TournamentPublisher::openSignup), players sign up as usual
 * (TournamentSignups), the draw commits to a Bitcoin block (TournamentDraws),
 * the P8a engine builds a double elimination bracket (byes to the top seeds,
 * seeded at random, grand final without a reset) and the runner moves it.
 *
 * What is the cups' own:
 *
 * - Opening: a series with no open cup gets the next one `gap_hours` after
 *   the last one ended. The number is the highest one of the series plus
 *   one; a called-off cup gives its number back, so there are no gaps. The
 *   unique indexes on (series, number) and on the open series keep two runs
 *   from ever opening two cups (SQLite has no row locks). It starts at its
 *   game's slot on its region's clock ({@see nextSlot()}), the first one
 *   at least `min_signup_hours` away; sign-up closes at the start.
 * - Sign-up (P27): the cup opens small (`sizes`, 4 places) and grows to the
 *   next size whenever only one place is left, until `growth_freeze_minutes`
 *   before sign-up closes ({@see grow()}); each growth is a new version of
 *   the 31923 (its content names the places). Full at the last size, or full
 *   once growth is frozen, it starts at once. At the close it plays with
 *   whoever signed up: `min_players` or more a double elimination, 2 and more
 *   a small cup's live evening; fewer than 2 extend sign-up once to
 *   the game's next slot, then the cup is called off and its players are told.
 * - Rounds: a round opens as soon as the one before it is done and gets a
 *   window (48 h, 36 h with more than 8 players), capped at `max_days` after
 *   the start. A chess match starts when one player invites the other and
 *   they accept ("Play your cup match", ChessInvites::inviteToCupMatch), or
 *   by the league at the auto slot on the window's last evening
 *   ({@see autoSlot()}). What is not decided by the deadline, and no game is
 *   under way, the league decides ({@see decision()}).
 * - Chess draws: a second game with the colours swapped, then Armageddon
 *   (a draw advances Black; TournamentRunner::chessGameFinished()).
 * - Small cups (S2): with fewer than `min_players` at the close the
 *   cup is not called off but switched to a small format ({@see formatFor()}:
 *   2 players one match, 3 to 5 a round robin) and played as one live
 *   evening: the rounds follow each other after a short break and the
 *   league starts every game at its round's start. The switch is the one
 *   new version of the 31923 with the evening's start and end.
 *
 * - Lobby cups (P10, Lobbies): a lobby game's cup (Age of Empires II) is
 *   Free for All in one round from the start. It opens small and grows
 *   like every cup, through its own sizes (Lobbies::cupSizes(): 4, 8, then
 *   a full lobby of 8 at a time up to Lobbies::cupCapacity(), 40); full
 *   once growth is frozen, it starts at once. At the close it plays with
 *   3 or more (Lobbies::minEntries()); fewer extend sign-up once, then call
 *   it off, never a small cup's evening. Its one round opens with the draw,
 *   its window the lobby's set-up, time limit and report time.
 *
 * Each cup is handled on its own: one that fails is reported and the others
 * go on. Every transition is a conditional update or happens under the
 * tournament's lock, so a second run changes nothing.
 */
final class CasualCups
{
    /** Drawn games before the deciding one: game 1, the colours swapped, then Armageddon. */
    public const ARMAGEDDON_AFTER_DRAWS = 2;

    private const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public function __construct(
        private TournamentPublisher $publisher,
        private TournamentDraws $draws,
        private TournamentRunner $runner,
        private CasualCupNotices $notices,
    ) {}

    /* ---------- Configuration --------------------------------------------------------------------------------- */

    /**
     * The games whose cup series runs, each with a known cup setup. A board
     * game (plan "Mühle und Dame", P5) runs its cups only while it is
     * switched on (in the registry); off, its cups stop opening.
     *
     * @return list<string>
     */
    public static function enabledGames(): array
    {
        $games = (array) config('esports.casual_cups.games', []);
        $registry = app(GameRegistry::class);

        return array_values(array_filter(array_map(strval(...), (array) config('esports.casual_cups.enabled', [])),
            // Never a score game (plan "AoE2 und Trackmania", P4): a cup pairs players, a score game has no pairing.
            fn (string $game): bool => isset($games[$game]) && ! $registry->isScore($game) && (! in_array($game, BoardGame::RESERVED_SLUGS, true) || $registry->isBoard($game))));
    }

    /**
     * @return array{name: string, mode: string, best_of: int, final_best_of: int}
     */
    public static function setup(string $game): array
    {
        $setup = (array) config("esports.casual_cups.games.{$game}", []);

        return [
            'name' => (string) ($setup['name'] ?? $game),
            'mode' => (string) ($setup['mode'] ?? ''),
            'best_of' => (int) ($setup['best_of'] ?? 1),
            'final_best_of' => (int) ($setup['final_best_of'] ?? 1),
        ];
    }

    /* ---------- Regions ---------------------------------------------------------------------------------------- */

    /**
     * The regions every game runs a cup series in, in config order; the
     * first one is where the cups opened before the regions went. A region
     * with an unknown zone is left out (it opens no cup).
     *
     * @return array<string, array{label: string, timezone: string}>
     */
    public static function regions(): array
    {
        $regions = [];

        foreach ((array) config('esports.casual_cups.regions', []) as $key => $region) {
            $region = (array) $region;
            $timezone = (string) ($region['timezone'] ?? '');

            if (! is_string($key) || $key === '' || ! in_array($timezone, timezone_identifiers_list(), true)) {
                continue;
            }

            $regions[$key] = ['label' => (string) ($region['label'] ?? strtoupper($key)), 'timezone' => $timezone];
        }

        return $regions;
    }

    /**
     * A game's weekly cup slot (user, 2026-09-30: the cups spread over the
     * weekend): its `slot`, else the cups' default `slot`, the same local
     * time in every region. Throws for a slot that names no weekday or
     * time, so such a game opens no cup (fail closed; the clock reports it).
     *
     * @return array{weekday: string, hour: int, minute: int}
     */
    public static function slotOf(string $game): array
    {
        $slot = (array) (LeagueSettings::get("esports.casual_cups.games.{$game}.slot") ?? LeagueSettings::get('esports.casual_cups.slot'));
        $weekday = strtolower((string) ($slot['weekday'] ?? ''));
        $time = (string) ($slot['time'] ?? '');

        if (! in_array($weekday, self::WEEKDAYS, true) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $parts) !== 1) {
            throw new InvalidArgumentException("No valid casual cup slot for game [{$game}].");
        }

        return ['weekday' => $weekday, 'hour' => (int) $parts[1], 'minute' => (int) $parts[2]];
    }

    /** Whether this instant is a slot of the game on the region's clock. */
    public static function isSlot(string $game, string $region, CarbonInterface $at): bool
    {
        $slot = self::slotOf($game);
        $local = CarbonImmutable::instance($at)->setTimezone(self::regions()[$region]['timezone'] ?? throw new InvalidArgumentException("Unknown casual cup region [{$region}]."));

        return strtolower($local->englishDayOfWeek) === $slot['weekday'] && $local->hour === $slot['hour'] && $local->minute === $slot['minute'] && $local->second === 0;
    }

    /** The series of a game in a region: "chess-eu". */
    public static function seriesKey(string $game, string $region): string
    {
        return "{$game}-{$region}";
    }

    /** The region of a cup (its series' suffix); null for a cup opened before the regions. */
    public static function regionOf(Tournament $cup): ?string
    {
        foreach (array_keys(self::regions()) as $region) {
            if ($cup->cup_series !== null && str_ends_with($cup->cup_series, "-{$region}")) {
                return $region;
            }
        }

        return null;
    }

    /** "EU", "US"; null for a cup without a region. */
    public static function regionLabel(Tournament $cup): ?string
    {
        $region = self::regionOf($cup);

        return $region === null ? null : self::regions()[$region]['label'];
    }

    /** The zone a cup keeps its evening and auto slots in: its region's, else the cups' default. */
    public static function timezoneOf(Tournament $cup): string
    {
        $region = self::regionOf($cup);

        return $region === null ? self::defaultTimezone() : self::regions()[$region]['timezone'];
    }

    /** The zone of a cup without a region, and of a player without a zone of their own. */
    public static function defaultTimezone(): string
    {
        return (string) config('esports.casual_cups.timezone', 'Europe/Berlin');
    }

    public static function minSignupHours(): int
    {
        return max(0, (int) LeagueSettings::get('esports.casual_cups.min_signup_hours'));
    }

    /**
     * The game's first slot in the region at or after `$notBefore`: the
     * game's weekday and time ({@see slotOf()}) on the region's own clock,
     * so the hour stays put across daylight saving (Europe and the US
     * switch on different dates).
     */
    public static function nextSlot(string $game, string $region, CarbonInterface $notBefore): CarbonImmutable
    {
        $timezone = self::regions()[$region]['timezone'] ?? throw new InvalidArgumentException("Unknown casual cup region [{$region}].");
        $slot = self::slotOf($game);
        $day = CarbonImmutable::instance($notBefore)->setTimezone($timezone)->startOfDay();

        // The weekday comes round within eight days, the eighth for a slot earlier that same weekday.
        foreach (range(0, 7) as $offset) {
            $at = $day->addDays($offset)->setTime($slot['hour'], $slot['minute']);

            if (strtolower($at->englishDayOfWeek) === $slot['weekday'] && $at->greaterThanOrEqualTo($notBefore)) {
                return $at->utc();
            }
        }

        throw new LogicException("No slot of game [{$game}] in region [{$region}] within a week.");
    }

    /** When a cup of this game and region opened at `$from` starts, and its sign-up closes. */
    public static function startFor(string $game, string $region, CarbonInterface $from): CarbonImmutable
    {
        return self::nextSlot($game, $region, CarbonImmutable::instance($from)->addHours(self::minSignupHours()));
    }

    /**
     * The sizes a cup grows through (P27), smallest first.
     *
     * @return non-empty-list<int>
     */
    public static function sizes(): array
    {
        $sizes = array_values(array_unique(array_filter(array_map(intval(...), (array) LeagueSettings::get('esports.casual_cups.sizes')), fn (int $size): bool => $size >= 2)));
        sort($sizes);

        return $sizes === [] ? [16] : $sizes;
    }

    /** The most places a cup can grow to. */
    public static function capacity(): int
    {
        return max(self::sizes());
    }

    /**
     * The sizes this cup grows through: a lobby cup's own (P10,
     * Lobbies::cupSizes(): 4, 8, then a full lobby at a time up to 40),
     * else {@see sizes()}.
     *
     * @return non-empty-list<int>
     */
    public static function sizesOf(Tournament $cup): array
    {
        return Lobbies::isLobby($cup) ? Lobbies::cupSizes($cup->game) : self::sizes();
    }

    /** The size after the cup's capacity, or null at its last. */
    public static function nextSize(Tournament $cup): ?int
    {
        foreach (self::sizesOf($cup) as $size) {
            if ($size > $cup->capacity) {
                return $size;
            }
        }

        return null;
    }

    /** Growth stops `growth_freeze_minutes` before sign-up closes (P27). */
    public static function growthFrozen(Tournament $cup): bool
    {
        return $cup->signup_closes_at === null
            || ! $cup->signup_closes_at->toImmutable()->subMinutes(max(0, (int) config('esports.casual_cups.growth_freeze_minutes', 60)))->isFuture();
    }

    public static function minPlayers(): int
    {
        return max(2, (int) config('esports.casual_cups.min_players', 6));
    }

    /**
     * The entries a cup draws with at its close: a lobby cup's minimum
     * (P10), 2 at a small cup's evening, else `min_players`.
     */
    public static function minEntries(Tournament $cup): int
    {
        return match (true) {
            Lobbies::isLobby($cup) => Lobbies::minEntries($cup->game),
            self::isEvening($cup) => 2,
            default => self::minPlayers(),
        };
    }

    public static function maxDays(): int
    {
        return max(1, (int) config('esports.casual_cups.max_days', 14));
    }

    /**
     * The window of a round: shorter with more than 8 players (a 16-slot bracket has more rounds).
     */
    public static function windowHours(int $players): int
    {
        return max(1, (int) config($players > 8 ? 'esports.casual_cups.large_window_hours' : 'esports.casual_cups.window_hours', 48));
    }

    /**
     * When the league starts a chess match nobody started: the configured
     * time (20:00) in the cup's zone ({@see timezoneOf()}, the default zone
     * without one) on the window's last evening, the latest one at least an
     * hour before the deadline. It may lie before the window opened (a
     * window shortened by the hard cap): then the match is due at once.
     */
    public static function autoSlot(CarbonInterface $windowEndsAt, ?string $timezone = null): CarbonImmutable
    {
        [$hour, $minute] = array_map(intval(...), explode(':', (string) config('esports.casual_cups.auto_slot', '20:00')) + [1 => '0']);
        $end = CarbonImmutable::instance($windowEndsAt)->setTimezone($timezone ?? self::defaultTimezone());
        $slot = $end->setTime($hour, $minute);

        if ($slot->greaterThan($end->subHour())) {
            $slot = $slot->subDay();
        }

        return $slot->utc();
    }

    /* ---------- Small cups: one live evening (S2) ------------------------------------------------------------ */

    /**
     * A cup switched to a small format: played as one live evening.
     */
    public static function isEvening(Tournament $cup): bool
    {
        // A lobby cup (P10) is Free for All from the start and never an evening.
        return $cup->isCasualCup() && $cup->format !== TournamentFormat::DoubleElimination && ! Lobbies::isLobby($cup);
    }

    /**
     * The cup's format for this many players at its last close; null = call
     * it off. From `min_players` on the double elimination stays; 2 players
     * play one match (chess: `duel_games` games with the colours
     * alternating, a round robin of two; the series games a best of
     * `duel_best_of`); 3 to 5 a round robin, one game per pairing, ranked by
     * points, then head-to-head, then wins, then the order the draw's block
     * hash seeded (the lot).
     *
     * @return array{format: TournamentFormat, options: array<string, mixed>}|null
     */
    public static function formatFor(Tournament $cup, int $players): ?array
    {
        if ($players < 2) {
            return null;
        }

        if ($players >= self::minPlayers()) {
            return ['format' => TournamentFormat::DoubleElimination, 'options' => $cup->options];
        }

        $evening = (array) config('esports.casual_cups.evening', []);

        if ($players === 2 && $cup->profile()->isSeries()) {
            $bestOf = (int) ($evening['duel_best_of'] ?? 3);

            return ['format' => TournamentFormat::SingleElimination, 'options' => ['bestOf' => $bestOf, 'finalBestOf' => $bestOf]];
        }

        return ['format' => TournamentFormat::RoundRobin, 'options' => [
            'iterations' => $players === 2 ? (int) ($evening['duel_games'] ?? 3) : 1,
            'rankBy' => 'points',
            'roundRobinTieBreaks' => ['head-to-head', 'match-wins'],
        ]];
    }

    /**
     * The evening of a small cup as planned: rounds, the games each player
     * plays, their play time (the game profile's length per game) and the
     * whole evening with the breaks, in minutes.
     *
     * @return array{rounds: int, games_per_player: int, round_minutes: int, play_minutes: int, span_minutes: int}
     */
    public static function eveningPlan(Tournament $cup, int $players): array
    {
        $options = $cup->formatOptions();
        $gameMinutes = $cup->profile()->gameLength;
        $break = max(0, (int) config('esports.casual_cups.evening.break_minutes', 3));

        if ($cup->format === TournamentFormat::SingleElimination) {
            [$rounds, $gamesPerRound, $games] = [1, $options->finalBestOf, $options->finalBestOf];
        } else {
            [$rounds, $gamesPerRound, $games] = [($players + $players % 2 - 1) * $options->iterations, 1, ($players - 1) * $options->iterations];
        }

        $roundMinutes = (int) ceil($gamesPerRound * $gameMinutes);

        return [
            'rounds' => $rounds,
            'games_per_player' => $games,
            'round_minutes' => $roundMinutes,
            'play_minutes' => (int) ceil($games * $gameMinutes),
            'span_minutes' => $rounds * $roundMinutes + ($rounds - 1) * $break,
        ];
    }

    /**
     * When the evening starts: `start` in the cup's zone (its region's,
     * {@see timezoneOf()}), `days_after_close` days after sign-up closed.
     */
    public static function eveningStart(CarbonInterface $closedAt, ?string $timezone = null): CarbonImmutable
    {
        $evening = (array) LeagueSettings::get('esports.casual_cups.evening');
        [$hour, $minute] = array_map(intval(...), explode(':', (string) ($evening['start'] ?? '20:00')) + [1 => '0']);

        return CarbonImmutable::instance($closedAt)->setTimezone($timezone ?? self::defaultTimezone())
            ->addDays((int) ($evening['days_after_close'] ?? 1))->setTime($hour, $minute)->utc();
    }

    /** The players of a cup: its entries once drawn, its sign-ups before. */
    public static function players(Tournament $cup): int
    {
        return $cup->participants()->count() ?: TournamentSignup::query()->where('tournament_id', $cup->id)->active()->count();
    }

    /* ---------- The clock ------------------------------------------------------------------------------------- */

    /**
     * @return array{opened: int, grown: int, extended: int, evenings: int, cancelled: int, rounds: int, decided: int}
     */
    public function tick(): array
    {
        $done = ['opened' => 0, 'grown' => 0, 'extended' => 0, 'evenings' => 0, 'cancelled' => 0, 'rounds' => 0, 'decided' => 0];

        // Cups that ended some other way (the last result, an admin's call-off) give back their open place first.
        foreach (Tournament::query()->whereNotNull('cup_open_series')->whereIn('status', [TournamentStatus::Finished, TournamentStatus::Cancelled])->get() as $cup) {
            $this->isolated(fn () => $this->release($cup));
        }

        foreach (Tournament::query()->whereNotNull('cup_open_series')->where('status', TournamentStatus::Signup)->get() as $cup) {
            $this->isolated(function () use ($cup, &$done): void {
                $step = $this->settleSignup($cup);

                if ($step !== null) {
                    $done[$step]++;
                }
            });
        }

        foreach (Tournament::query()->whereNotNull('cup_open_series')->where('status', TournamentStatus::Running)->whereNull('paused_at')->get() as $cup) {
            $this->isolated(function () use ($cup, &$done): void {
                $done['rounds'] += $this->openRounds($cup);
                $done['decided'] += $this->decideOverdue($cup);
            });
        }

        foreach (self::enabledGames() as $game) {
            foreach (array_keys(self::regions()) as $region) {
                $this->isolated(function () use ($game, $region, &$done): void {
                    $done['opened'] += $this->ensure($game, $region) === null ? 0 : 1;
                });
            }
        }

        return $done;
    }

    /**
     * @param  callable(): mixed  $step
     */
    private function isolated(callable $step): void
    {
        try {
            $step();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /* ---------- Opening a cup --------------------------------------------------------------------------------- */

    /**
     * Open the next cup of this game in this region unless one is open or
     * the last one ended less than `gap_hours` ago; it starts at the
     * game's next slot on the region's clock at least `min_signup_hours`
     * away. Null when nothing
     * was opened. Fail closed: without the league key (or with an unknown
     * region) nothing is created or published.
     */
    public function ensure(string $game, string $region): ?Tournament
    {
        $setup = self::setup($game);
        $label = self::regions()[$region]['label'] ?? null;
        $series = self::seriesKey($game, $region);

        if ($setup['mode'] === '' || $label === null || LeagueKey::fromConfig() === null || Tournament::query()->where('cup_open_series', $series)->exists()) {
            return null;
        }

        $lastEnded = Tournament::query()->where('cup_series', $series)->max('cup_ended_at');

        if ($lastEnded !== null && CarbonImmutable::parse($lastEnded)->addHours((int) config('esports.casual_cups.gap_hours', 24))->isFuture()) {
            return null;
        }

        $number = (int) Tournament::query()->where('cup_series', $series)->max('cup_number') + 1;
        $closesAt = self::startFor($game, $region, now());
        $profile = GameProfile::for($game, $setup['mode']);
        // A lobby game's cup (P10) is one lobby match from the start; it opens small and grows like every cup.
        $lobby = Lobbies::isLobbyGame($game);

        try {
            return DB::transaction(function () use ($game, $series, $label, $setup, $number, $closesAt, $profile, $lobby): Tournament {
                $cup = Tournament::query()->create([
                    'name' => self::cupName($setup['name'], $label, $number),
                    'game' => $game,
                    'mode' => $setup['mode'],
                    'format' => $lobby ? TournamentFormat::FreeForAll : TournamentFormat::DoubleElimination,
                    'options' => FormatOptions::fromArray($lobby ? Lobbies::options($game) : ['bestOf' => $setup['best_of'], 'finalBestOf' => $setup['final_best_of'], 'grandFinal' => 'single'], $profile)->toArray(),
                    'capacity' => $lobby ? Lobbies::cupSizes($game)[0] : self::sizes()[0],
                    'starts_at' => $closesAt,
                    'time_window' => self::maxDays() * ($profile->isDaily() ? 1 : 1440),
                    'on_site' => false,
                    'results_mode' => TournamentResultsMode::Players,
                    'status' => TournamentStatus::Draft,
                    'created_by_id' => null,
                    'cup_series' => $series,
                    'cup_number' => $number,
                    'cup_open_series' => $series,
                ]);

                return $this->publisher->openSignup($cup, $closesAt);
            });
        } catch (UniqueConstraintViolationException) {
            // Another run opened this series' cup (or took this number) first.
            return null;
        }
    }

    /** "Chess Casual Cup EU #2". */
    public static function cupName(string $game, string $regionLabel, int $number): string
    {
        return "{$game} Casual Cup {$regionLabel} #{$number}";
    }

    /* ---------- Sign-up --------------------------------------------------------------------------------------- */

    /**
     * Before the close: grow when one place is left, start at once when full
     * and it cannot grow. At the close: a double elimination with enough
     * players, a small cup's evening with 2 or more, else extend sign-up
     * once, then call it off.
     *
     * @return 'grown'|'extended'|'evenings'|'cancelled'|null
     */
    private function settleSignup(Tournament $cup): ?string
    {
        $signedUp = $this->signedUp($cup);

        if ($cup->signup_closes_at?->isFuture() && $this->grow($cup, $signedUp)) {
            return 'grown';
        }

        if ($signedUp >= $cup->capacity && $cup->signup_closes_at?->isFuture()) {
            // Full: sign-up closes now and the draw commits to the next block.
            Tournament::query()->whereKey($cup->id)->where('status', TournamentStatus::Signup)->where('signup_closes_at', '>', now())
                ->update(['signup_closes_at' => now(), 'starts_at' => now()]);
            $cup->refresh();
        }

        if ($cup->signup_closes_at === null || $cup->signup_closes_at->isFuture()) {
            return null;
        }

        // Switched already (a draw that could not commit yet is tried again).
        if ($signedUp >= self::minEntries($cup) || self::isEvening($cup)) {
            $this->draws->close($cup);

            return null;
        }

        // A lobby cup (P10) has no small format: too few extend sign-up once, then it is called off.
        if (! Lobbies::isLobby($cup) && $this->toEvening($cup, $signedUp)) {
            $this->draws->close($cup->refresh());

            return 'evenings';
        }

        if ($cup->cup_extended_at === null) {
            return $this->extend($cup) ? 'extended' : null;
        }

        return $this->cancel($cup) ? 'cancelled' : null;
    }

    /**
     * Raise a cup with one place (or none) left to the next size, before
     * growth is frozen: a conditional update on the capacity seen, so two
     * runs grow it once, then a new version of the 31923 (its content names
     * the places). A sign-up that lands meanwhile only makes it fuller.
     */
    public function grow(Tournament $cup, int $signedUp): bool
    {
        $next = self::nextSize($cup);

        if ($next === null || $cup->status !== TournamentStatus::Signup || self::isEvening($cup) || self::growthFrozen($cup) || $cup->capacity - $signedUp > 1) {
            return false;
        }

        return DB::transaction(function () use ($cup, $next): bool {
            $grown = Tournament::query()->whereKey($cup->id)->where('status', TournamentStatus::Signup)->where('capacity', $cup->capacity)
                ->update(['capacity' => $next]);

            if ($grown !== 1) {
                return false;
            }

            $this->publisher->republish(Tournament::query()->with('event')->lockForUpdate()->findOrFail($cup->id));

            return true;
        });
    }

    /**
     * Fit a cup opened before P27 to the growing sign-up: the smallest size
     * that holds its players, while growth is still open; the calendar event
     * gets a new version. Idempotent: a cup that fits already is left alone.
     */
    public function fitCapacity(Tournament $cup): bool
    {
        if (! $cup->isCasualCup() || $cup->status !== TournamentStatus::Signup || self::isEvening($cup) || Lobbies::isLobby($cup) || self::growthFrozen($cup)) {
            return false;
        }

        $signedUp = $this->signedUp($cup);
        $fitting = collect(self::sizes())->first(fn (int $size): bool => $size >= $signedUp) ?? self::capacity();

        if ($fitting >= $cup->capacity) {
            return false;
        }

        return DB::transaction(function () use ($cup, $fitting): bool {
            $fitted = Tournament::query()->whereKey($cup->id)->where('status', TournamentStatus::Signup)->where('capacity', $cup->capacity)
                ->update(['capacity' => $fitting]);

            if ($fitted !== 1) {
                return false;
            }

            $this->publisher->republish(Tournament::query()->with('event')->lockForUpdate()->findOrFail($cup->id));

            return true;
        });
    }

    /**
     * Shrink a lobby cup opened with all its places (P10, before it grew)
     * to the first of its sizes above its sign-ups (Lobbies::cupSizeFor():
     * 1 in, 4 places; 9 in, 16), while growth is still open; the calendar
     * event gets a new version in the same transaction, so a refused
     * republish leaves the cup as it was published. Counted under the
     * tournament's lock, which a sign-up takes too: never below who is in.
     * Idempotent: a cup at or below its step is left alone.
     */
    public function fitLobbyCapacity(Tournament $cup): bool
    {
        if (! $cup->isCasualCup() || $cup->status !== TournamentStatus::Signup || ! Lobbies::isLobby($cup) || self::growthFrozen($cup)) {
            return false;
        }

        return DB::transaction(function () use ($cup): bool {
            $locked = Tournament::query()->with('event')->lockForUpdate()->findOrFail($cup->id);
            $fitting = Lobbies::cupSizeFor($locked->game, $this->signedUp($locked));

            if ($locked->status !== TournamentStatus::Signup || ! Lobbies::isLobby($locked) || $fitting >= $locked->capacity) {
                return false;
            }

            $locked->forceFill(['capacity' => $fitting])->save();
            $this->publisher->republish($locked);

            return true;
        });
    }

    /**
     * Split the cups opened before the regions (user, 2026-09-28: separate
     * EU and US cups) and open the other regions' first cups:
     *
     * - every such cup joins its game's series in the first region (EU), so
     *   the numbering goes on there; one that ended keeps its name;
     * - an open one is renamed "<Game> Casual Cup EU #n", and one still in
     *   sign-up moves to the game's next EU slot at least `min_signup_hours`
     *   from now, never earlier than it was once somebody signed up; the
     *   calendar event gets a new version, the players who signed up hear
     *   the new start once;
     * - then every enabled game opens its cup in each other region (US)
     *   like the clock would ({@see ensure()}).
     *
     * Idempotent: a cup with a region is never touched again, and the open
     * series' unique index keeps a second run from opening a second cup.
     *
     * @return array{mapped: int, renamed: int, moved: int, opened: int}
     */
    public function splitIntoRegions(): array
    {
        $done = ['mapped' => 0, 'renamed' => 0, 'moved' => 0, 'opened' => 0];
        $regions = self::regions();
        $first = array_key_first($regions);

        if ($first === null) {
            return $done;
        }

        $legacy = Tournament::query()->whereNotNull('cup_series')->orderBy('id')->get()->filter(fn (Tournament $cup): bool => self::regionOf($cup) === null);

        foreach ($legacy as $cup) {
            $moved = DB::transaction(function () use ($cup, $first, $regions, &$done): bool {
                $locked = Tournament::query()->with('event')->lockForUpdate()->findOrFail($cup->id);

                if ($locked->cup_series === null || self::regionOf($locked) !== null) {
                    return false;
                }

                $game = $locked->cup_series;
                $locked->cup_series = self::seriesKey($game, $first);
                $done['mapped']++;

                if ($locked->cup_open_series === null) {
                    $locked->save();

                    return false;
                }

                $locked->cup_open_series = $locked->cup_series;

                if ($locked->cup_number !== null) {
                    $locked->name = self::cupName(self::setup($game)['name'], $regions[$first]['label'], $locked->cup_number);
                    $done['renamed']++;
                }

                $moved = false;

                if ($locked->status === TournamentStatus::Signup && ! self::isEvening($locked)) {
                    $notBefore = CarbonImmutable::now()->addHours(self::minSignupHours());

                    // Somebody signed up for the old time: the cup may start later, never earlier.
                    if ($this->signedUp($locked) > 0) {
                        $notBefore = $notBefore->max($locked->starts_at);
                    }

                    $startsAt = self::nextSlot($locked->game, $first, $notBefore);

                    if (! $startsAt->equalTo($locked->starts_at) || ! $startsAt->equalTo($locked->signup_closes_at)) {
                        $locked->forceFill(['starts_at' => $startsAt, 'signup_closes_at' => $startsAt]);
                        $moved = true;
                        $done['moved']++;
                    }
                }

                $locked->save();
                // The new name (and start) is a new version of the 31923.
                $this->publisher->republish($locked);

                return $moved;
            });

            if ($moved) {
                $this->notices->moved($cup->refresh());
            }
        }

        foreach (self::enabledGames() as $game) {
            foreach (array_keys($regions) as $region) {
                if ($region !== $first && $this->ensure($game, $region) !== null) {
                    $done['opened']++;
                }
            }
        }

        return $done;
    }

    /**
     * Move the open cups nobody signed up for to their game's slot (user,
     * 2026-09-30: the cups spread over the weekend, the ones opened before
     * too): a cup in sign-up with no active sign-up whose start is not a
     * slot of its game on its region's clock starts at the game's next slot
     * that leaves `min_signup_hours` of sign-up from now; sign-up closes
     * there, so the growth freeze follows. Its calendar event gets a new
     * version (as an extension does); nobody is told, as nobody signed up.
     * A cup with a sign-up, a live evening, a cup past its close and a cup
     * without a region keep their start. One log line per moved cup.
     *
     * Idempotent: a moved cup starts on a slot, so a second run skips it.
     *
     * @return list<array{id: int, name: string, from: string, to: string}>
     */
    public function moveToGameSlots(): array
    {
        $moved = [];
        $cups = Tournament::query()->whereNotNull('cup_open_series')->where('status', TournamentStatus::Signup)
            ->where('signup_closes_at', '>', now())->orderBy('id')->get();

        foreach ($cups as $cup) {
            $region = self::regionOf($cup);

            if ($region === null || self::isEvening($cup) || self::isSlot($cup->game, $region, $cup->starts_at) || $this->signedUp($cup) > 0) {
                continue;
            }

            $move = DB::transaction(function () use ($cup, $region): ?array {
                $locked = Tournament::query()->with('event')->lockForUpdate()->findOrFail($cup->id);

                // Checked again under the lock: a sign-up that landed meanwhile keeps the cup where it is.
                if ($locked->status !== TournamentStatus::Signup || ! $locked->signup_closes_at?->isFuture() || $this->signedUp($locked) > 0) {
                    return null;
                }

                $from = $locked->starts_at->toImmutable();
                $startsAt = self::startFor($locked->game, $region, now());
                $locked->forceFill(['starts_at' => $startsAt, 'signup_closes_at' => $startsAt])->save();
                // The new start is a new version of the 31923 (NIP "Tournaments": a change of time).
                $this->publisher->republish($locked);

                return ['id' => $locked->id, 'name' => $locked->name, 'from' => $from->toIso8601String(), 'to' => $startsAt->toIso8601String()];
            });

            if ($move !== null) {
                Log::info('Casual cup moved to its game slot', $move);
                $moved[] = $move;
            }
        }

        return $moved;
    }

    /**
     * Switch a cup with 2 to 5 players to its small format and its live
     * evening: one new version of the 31923 with the evening's start and
     * end, and a notice to every player.
     */
    private function toEvening(Tournament $cup, int $players): bool
    {
        $format = self::formatFor($cup, $players);

        if ($format === null || $format['format'] === TournamentFormat::DoubleElimination) {
            return false;
        }

        $switched = DB::transaction(function () use ($cup, $format): bool {
            $locked = Tournament::query()->with('event')->lockForUpdate()->findOrFail($cup->id);

            if ($locked->status !== TournamentStatus::Signup || $locked->signup_closes_at === null || $locked->signup_closes_at->isFuture() || self::isEvening($locked)) {
                return false;
            }

            $locked->forceFill([
                'format' => $format['format'],
                'options' => FormatOptions::fromArray($format['options'], $locked->profile())->toArray(),
                'starts_at' => self::eveningStart($locked->signup_closes_at, self::timezoneOf($locked)),
            ])->save();
            $this->publisher->republish($locked);

            return true;
        });

        if ($switched) {
            $this->notices->eveningAnnounced($cup->refresh(), self::planOf($cup));
        }

        return $switched;
    }

    /**
     * @return array{rounds: int, games_per_player: int, round_minutes: int, play_minutes: int, span_minutes: int}
     */
    public static function planOf(Tournament $cup): array
    {
        return self::eveningPlan($cup, self::players($cup));
    }

    private function signedUp(Tournament $cup): int
    {
        return TournamentSignup::query()->where('tournament_id', $cup->id)->active()->count();
    }

    /**
     * Extend sign-up once, to the game's next slot on the region's clock
     * after the close (a cup without a region: the first region's).
     */
    private function extend(Tournament $cup): bool
    {
        return DB::transaction(function () use ($cup): bool {
            $locked = Tournament::query()->with('event')->lockForUpdate()->findOrFail($cup->id);

            if ($locked->status !== TournamentStatus::Signup || $locked->cup_extended_at !== null || $locked->signup_closes_at?->isFuture()) {
                return false;
            }

            $region = self::regionOf($locked) ?? (string) array_key_first(self::regions());
            // Strictly after the close, and never in the past (a clock that was down for a week).
            $closesAt = self::nextSlot($locked->game, $region, CarbonImmutable::now()->max($locked->signup_closes_at ?? now())->addSecond());
            $locked->forceFill(['signup_closes_at' => $closesAt, 'starts_at' => $closesAt, 'cup_extended_at' => now()])->save();
            // The new close is a new version of the 31923 (NIP "Tournaments": a change of time).
            $this->publisher->republish($locked);

            return true;
        });
    }

    /**
     * Call off a cup with too few players after its extension: its number
     * goes back to the series and its players are told.
     */
    private function cancel(Tournament $cup): bool
    {
        $players = array_values(array_unique(TournamentSignup::query()->where('tournament_id', $cup->id)->active()->get()
            ->flatMap(fn (TournamentSignup $signup): array => $signup->members)->map(intval(...))->all()));

        $cancelled = DB::transaction(function () use ($cup): bool {
            $locked = Tournament::query()->with('event')->lockForUpdate()->findOrFail($cup->id);

            if ($locked->status !== TournamentStatus::Signup || $locked->signup_closes_at?->isFuture()) {
                return false;
            }

            $locked->forceFill(['status' => TournamentStatus::Cancelled, 'cup_number' => null, 'cup_open_series' => null, 'cup_ended_at' => now()])->save();
            // A new version of the 31923 says it is called off (NIP-52 has no status for it).
            $this->publisher->republish($locked);

            return true;
        });

        if ($cancelled) {
            $this->notices->calledOff($cup->refresh(), $players);
        }

        return $cancelled;
    }

    /**
     * A cup that finished or was called off elsewhere gives back its open
     * place (and, called off, its number), so the next one can open.
     */
    private function release(Tournament $cup): void
    {
        $values = ['cup_open_series' => null, 'cup_ended_at' => now()];

        if ($cup->status === TournamentStatus::Cancelled) {
            $values['cup_number'] = null;
        }

        Tournament::query()->whereKey($cup->id)->whereNotNull('cup_open_series')->update($values);
    }

    /* ---------- Rounds ---------------------------------------------------------------------------------------- */

    /**
     * Open every round whose predecessors are done: it gets its deadline, and
     * its players are told. The first one is the cup's real start (the 31923
     * gets it as a new version); the hard cap counts from there.
     */
    private function openRounds(Tournament $cup): int
    {
        $opened = 0;
        $previousDone = true;

        $previous = null;

        foreach ($this->rounds($cup) as $round) {
            if ($round->window_ends_at === null) {
                if (! $previousDone || (self::isEvening($cup) && ! self::eveningRoundDue($cup, $previous)) || ! $this->openRound($cup, $round)) {
                    break;
                }

                $opened++;
                $cup->refresh();
                $round->refresh();
            }

            $previousDone = $round->status === 'closed';
            $previous = $round;
        }

        return $opened;
    }

    /**
     * A live evening's round starts at the evening's start (the first) or a
     * break after the round before it closed.
     */
    private static function eveningRoundDue(Tournament $cup, ?TournamentRound $previous): bool
    {
        $due = $previous === null
            ? $cup->starts_at->toImmutable()
            : ($previous->closed_at ?? now())->toImmutable()->addMinutes(max(0, (int) config('esports.casual_cups.evening.break_minutes', 3)));

        return ! $due->isFuture();
    }

    private function openRound(Tournament $cup, TournamentRound $round): bool
    {
        $evening = self::isEvening($cup);

        $opened = DB::transaction(function () use ($cup, $round, $evening): bool {
            $locked = Tournament::query()->with('event')->lockForUpdate()->findOrFail($cup->id);

            // A live evening round: planned length plus grace; the evening's start and end were published at the switch.
            if ($evening) {
                $minutes = self::planOf($locked)['round_minutes'] + max(0, (int) config('esports.casual_cups.evening.grace_minutes', 15));

                return TournamentRound::query()->whereKey($round->id)->whereNull('window_ends_at')->update(['window_ends_at' => now()->addMinutes($minutes)]) === 1;
            }

            $first = ! TournamentRound::query()->whereHas('stage', fn ($query) => $query->where('tournament_id', $locked->id))->whereNotNull('window_ends_at')->exists();

            if ($first) {
                $locked->forceFill(['starts_at' => now()])->save();
            }

            $cap = $locked->starts_at->toImmutable()->addDays(self::maxDays());
            $players = $locked->participants()->count();
            // A lobby cup's one round (P10) lasts the lobby: set-up, the time limit, then the players' report.
            $endsAt = Lobbies::isLobby($locked)
                ? CarbonImmutable::now()->addMinutes(Lobbies::plannedMinutes($locked->game) + max(0, (int) (Lobbies::config($locked->game)['report_minutes'] ?? 60)))
                : CarbonImmutable::now()->addHours(self::windowHours($players))->min($cap);

            if (TournamentRound::query()->whereKey($round->id)->whereNull('window_ends_at')->update(['window_ends_at' => $endsAt]) !== 1) {
                return false;
            }

            if ($first) {
                $this->publisher->republish($locked);
            }

            return true;
        });

        // At a live evening the league starts every game itself and says so then (the game-started notice).
        if ($opened && ! $evening) {
            $this->notices->roundOpened($cup, $round->refresh());
        }

        return $opened;
    }

    /**
     * @return Collection<int, TournamentRound>
     */
    private function rounds(Tournament $cup): Collection
    {
        return TournamentRound::query()->whereHas('stage', fn ($query) => $query->where('tournament_id', $cup->id))
            ->with('stage')->get()
            ->sortBy(fn (TournamentRound $round): array => [$round->stage->number, $round->number])->values();
    }

    /**
     * Decide every match of a round past its deadline that is not decided
     * and has no game under way ({@see decision()}).
     */
    private function decideOverdue(Tournament $cup): int
    {
        $decided = 0;
        $matches = TournamentMatch::query()->where('tournament_id', $cup->id)->where('status', 'ready')
            ->where('bracket', '!=', 'bye')->whereNull('result')->whereNull('held')
            ->whereHas('round', fn ($query) => $query->whereNotNull('window_ends_at')->where('window_ends_at', '<=', now()))
            ->with(['slots.participant', 'chessGame', 'seriesMatch', 'boardGame'])->orderBy('id')->get();

        foreach ($matches as $match) {
            if (count($match->slots) !== 2 || self::isUnderWay($match)) {
                continue;
            }

            $stored = DB::transaction(function () use ($cup, $match): bool {
                $locked = TournamentMatch::query()->with('slots.participant')->lockForUpdate()->findOrFail($match->id);

                if ($locked->result !== null || $locked->status !== 'ready') {
                    return false;
                }

                $this->runner->store($locked, self::decision($cup, $locked));

                return true;
            });

            $decided += $stored ? 1 : 0;
        }

        if ($decided > 0) {
            $this->runner->sync($cup->refresh());
        }

        return $decided;
    }

    /**
     * A game or series of this match is being played: the deadline lets it finish.
     */
    public static function isUnderWay(TournamentMatch $match): bool
    {
        $game = $match->chessGame;

        if ($game !== null && ! $match->isReplaced($game->id) && $game->status === ChessGameStatus::Active) {
            return true;
        }

        $board = $match->boardGame;

        if ($board !== null && ! $match->isReplaced($board->id) && $board->status === BoardGameStatus::Active) {
            return true;
        }

        $series = $match->seriesMatch;

        return $series !== null && ! $match->isReplaced($series->id) && $series->resolution !== SeriesResolution::Void && ! $series->status->hasResult();
    }

    /* ---------- Starting a match ------------------------------------------------------------------------------ */

    /**
     * Whether the league may start this cup match's game now: its round is
     * open, and the match has begun already (a replay after a draw, a
     * restart) or its auto slot has come ({@see autoSlot()}). A match its
     * players start themselves goes through their invite, with `$invited`.
     */
    public static function mayStart(TournamentMatch $match, bool $invited = false): bool
    {
        $endsAt = $match->round->window_ends_at;

        if ($endsAt === null) {
            return false;
        }

        // A live evening's games start at their round's start (S2); a lobby (P10) is open once its round is.
        if ($invited || $match->chessGame !== null || $match->boardGame !== null || self::isEvening($match->tournament) || Lobbies::isLobby($match->tournament)) {
            return true;
        }

        // A series match (S3) is started when the check-in for its agreed time (or the auto slot) opens.
        if ($match->tournament->profile()->isSeries()) {
            return ! self::seriesStartsAt($match)->subMinutes(max(0, (int) config('esports.casual.checkin_before_minutes', 10)))->isFuture();
        }

        return ! self::autoSlot($endsAt, self::timezoneOf($match->tournament))->isFuture();
    }

    /**
     * When a cup's series match starts (S3): the agreed time (CupSchedules),
     * at a live evening its round's start (now), else the auto slot.
     */
    public static function seriesStartsAt(TournamentMatch $match): CarbonImmutable
    {
        $agreed = CupSchedules::agreedAt($match);

        if ($agreed !== null) {
            return $agreed;
        }

        return self::isEvening($match->tournament) || $match->round->window_ends_at === null
            ? CarbonImmutable::now()
            : self::autoSlot($match->round->window_ends_at, self::timezoneOf($match->tournament));
    }

    /* ---------- The league's decision ------------------------------------------------------------------------- */

    /**
     * An undecided match at its deadline (or both sides missing their game
     * twice): the one side that tried to play advances (sent or accepted a
     * "Play your cup match" invite, or opened the board); otherwise, both
     * or neither, a visible draw of lots ("advanced by draw"). Decided by
     * the league, without a game: nothing is rated.
     *
     * @return array<string, mixed>
     */
    public static function decision(Tournament $cup, TournamentMatch $match): array
    {
        $acted = self::actedSlots($match);

        // A round robin (a small cup, S2) keeps the table fair: nobody who did not play gets a point.
        if (count($acted) !== 1 && $cup->format === TournamentFormat::RoundRobin) {
            return ['winner' => null, 'double_loss' => true, 'games_won' => [0.0, 0.0], 'points' => [], 'forfeit' => true, 'decided' => 'noshow', 'label' => __('double no-show'), 'by' => 'league'];
        }

        if (count($acted) === 1) {
            $winner = $acted[0];
            $decided = 'acted';
            $label = __('advanced: tried to play');
        } else {
            // Drawn when it is needed, never from something known before (a lot fixed by the
            // published seed would tell its winner that waiting pays).
            $winner = random_int(0, 1);
            $decided = 'lot';
            $label = __('advanced by draw');
        }

        return [
            'winner' => $winner,
            'games_won' => $winner === 0 ? [1.0, 0.0] : [0.0, 1.0],
            'points' => [],
            'forfeit' => true,
            'decided' => $decided,
            'label' => $label,
            'by' => 'league',
        ];
    }

    /**
     * The slots (0, 1) whose player tried to play this match.
     *
     * @return list<int>
     */
    public static function actedSlots(TournamentMatch $match): array
    {
        $acted = [];

        foreach (ChessInvite::query()->where('tournament_match_id', $match->id)->get() as $invite) {
            $acted[] = $invite->inviter_id;

            if ($invite->status === ChessInviteStatus::Accepted) {
                $acted[] = $invite->invitee_id;
            }
        }

        foreach (ChessGame::query()->where('tournament_match_id', $match->id)->where('id', '>', (int) $match->replaced_through)->get() as $game) {
            if ($game->white_seen_at !== null) {
                $acted[] = $game->white_id;
            }

            if ($game->black_seen_at !== null) {
                $acted[] = $game->black_id;
            }
        }

        // A board game match (P5): sending or accepting its invite, and moving on its board, is trying to play.
        foreach (BoardInvite::query()->where('tournament_match_id', $match->id)->get() as $invite) {
            $acted[] = $invite->inviter_id;

            if ($invite->status === BoardInviteStatus::Accepted) {
                $acted[] = $invite->invitee_id;
            }
        }

        foreach (BoardGameModel::query()->where('tournament_match_id', $match->id)->where('id', '>', (int) $match->replaced_through)->get() as $board) {
            if ($board->ply >= 1) {
                $acted[] = $board->white_id;
            }

            if ($board->ply >= 2) {
                $acted[] = $board->black_id;
            }
        }

        // A series match (S3): proposing or accepting a time, and checking in, is trying to play.
        $acted[] = $match->schedule['by'] ?? null;
        $acted[] = $match->schedule['accepted_by'] ?? null;

        foreach (SeriesMatch::query()->where('tournament_match_id', $match->id)->get() as $series) {
            foreach (SeriesMatch::SIDES as $side) {
                if ($series->readyAt($side) !== null) {
                    array_push($acted, ...$series->rosterSide($side));
                }
            }
        }

        $acted = array_values(array_filter($acted, fn (mixed $id): bool => is_int($id)));
        $slots = [];

        foreach ($match->slots as $slot) {
            if (array_intersect($slot->participant?->memberIds() ?? [], $acted) !== []) {
                $slots[] = $slot->slot;
            }
        }

        return $slots;
    }
}
