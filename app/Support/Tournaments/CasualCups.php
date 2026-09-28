<?php

namespace App\Support\Tournaments;

use App\Enums\ChessGameStatus;
use App\Enums\ChessInviteStatus;
use App\Enums\SeriesResolution;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\ChessGame;
use App\Models\ChessInvite;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentRound;
use App\Models\TournamentSignup;
use App\Support\SeasonChain\LeagueKey;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The league's automatic casual cups (P25): per enabled game one cup series,
 * "<Game> Casual Cup #n", with at most one cup open at a time. Runs as the
 * first step of the tournament clock (`tournaments:tick`, TournamentScheduler)
 * and reuses the tournament flow: the league publishes the cup like an admin
 * tournament (TournamentPublisher::openSignup), players sign up as usual
 * (TournamentSignups), the draw commits to a Bitcoin block (TournamentDraws),
 * the P8a engine builds a double elimination bracket (byes to the top seeds,
 * seeded at random, grand final without a reset) and the runner moves it.
 *
 * What is the cups' own:
 *
 * - Opening: a game with no open cup gets the next one `gap_hours` after the
 *   last one ended. The number is the highest one of the series plus one; a
 *   called-off cup gives its number back, so there are no gaps. The unique
 *   indexes on (series, number) and on the open series keep two runs from
 *   ever opening two cups (SQLite has no row locks).
 * - Sign-up (P27): the cup opens small (`sizes`, 4 places) and grows to the
 *   next size whenever only one place is left, until `growth_freeze_minutes`
 *   before sign-up closes ({@see grow()}); each growth is a new version of
 *   the 31923 (its content names the places). Full at the last size, or full
 *   once growth is frozen, it starts at once. At the close it plays with
 *   whoever signed up: `min_players` or more a double elimination, 2 and more
 *   a small cup's live evening; fewer than 2 extend sign-up once by
 *   `extension_hours`, then the cup is called off and its players are told.
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
 * Each cup is handled on its own: one that fails is reported and the others
 * go on. Every transition is a conditional update or happens under the
 * tournament's lock, so a second run changes nothing.
 */
final class CasualCups
{
    /** Drawn games before the deciding one: game 1, the colours swapped, then Armageddon. */
    public const ARMAGEDDON_AFTER_DRAWS = 2;

    public function __construct(
        private TournamentPublisher $publisher,
        private TournamentDraws $draws,
        private TournamentRunner $runner,
        private CasualCupNotices $notices,
    ) {}

    /* ---------- Configuration --------------------------------------------------------------------------------- */

    /**
     * The games whose cup series runs, each with a known cup setup.
     *
     * @return list<string>
     */
    public static function enabledGames(): array
    {
        $games = (array) config('esports.casual_cups.games', []);

        return array_values(array_filter(array_map(strval(...), (array) config('esports.casual_cups.enabled', [])), fn (string $game): bool => isset($games[$game])));
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

    /**
     * The sizes a cup grows through (P27), smallest first.
     *
     * @return non-empty-list<int>
     */
    public static function sizes(): array
    {
        $sizes = array_values(array_unique(array_filter(array_map(intval(...), (array) config('esports.casual_cups.sizes', [4, 8, 16])), fn (int $size): bool => $size >= 2)));
        sort($sizes);

        return $sizes === [] ? [16] : $sizes;
    }

    /** The most places a cup can grow to. */
    public static function capacity(): int
    {
        return max(self::sizes());
    }

    /** The size after this capacity, or null at the last. */
    public static function nextSize(int $capacity): ?int
    {
        foreach (self::sizes() as $size) {
            if ($size > $capacity) {
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
     * time (20:00 Europe/Berlin) on the window's last evening, the latest
     * one at least an hour before the deadline. It may lie before the window
     * opened (a window shortened by the hard cap): then the match is due at once.
     */
    public static function autoSlot(CarbonInterface $windowEndsAt): CarbonImmutable
    {
        [$hour, $minute] = array_map(intval(...), explode(':', (string) config('esports.casual_cups.auto_slot', '20:00')) + [1 => '0']);
        $end = CarbonImmutable::instance($windowEndsAt)->setTimezone((string) config('esports.casual_cups.timezone', 'Europe/Berlin'));
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
        return $cup->isCasualCup() && $cup->format !== TournamentFormat::DoubleElimination;
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

        if ($players === 2 && ! $cup->profile()->isChess()) {
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
     * When the evening starts: `start` in the cups' timezone,
     * `days_after_close` days after sign-up closed.
     */
    public static function eveningStart(CarbonInterface $closedAt): CarbonImmutable
    {
        $evening = (array) config('esports.casual_cups.evening', []);
        [$hour, $minute] = array_map(intval(...), explode(':', (string) ($evening['start'] ?? '20:00')) + [1 => '0']);

        return CarbonImmutable::instance($closedAt)->setTimezone((string) config('esports.casual_cups.timezone', 'Europe/Berlin'))
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
            $this->isolated(function () use ($game, &$done): void {
                $done['opened'] += $this->ensure($game) === null ? 0 : 1;
            });
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
     * Open the next cup of this game unless one is open or the last one
     * ended less than `gap_hours` ago. Null when nothing was opened. Fail
     * closed: without the league key nothing is created or published.
     */
    public function ensure(string $game): ?Tournament
    {
        $setup = self::setup($game);

        if ($setup['mode'] === '' || LeagueKey::fromConfig() === null || Tournament::query()->where('cup_open_series', $game)->exists()) {
            return null;
        }

        $lastEnded = Tournament::query()->where('cup_series', $game)->max('cup_ended_at');

        if ($lastEnded !== null && CarbonImmutable::parse($lastEnded)->addHours((int) config('esports.casual_cups.gap_hours', 24))->isFuture()) {
            return null;
        }

        $number = (int) Tournament::query()->where('cup_series', $game)->max('cup_number') + 1;
        $closesAt = CarbonImmutable::now()->addHours((int) config('esports.casual_cups.signup_hours', 72));
        $profile = GameProfile::for($game, $setup['mode']);

        try {
            return DB::transaction(function () use ($game, $setup, $number, $closesAt, $profile): Tournament {
                $cup = Tournament::query()->create([
                    'name' => "{$setup['name']} Casual Cup #{$number}",
                    'game' => $game,
                    'mode' => $setup['mode'],
                    'format' => TournamentFormat::DoubleElimination,
                    'options' => FormatOptions::fromArray(['bestOf' => $setup['best_of'], 'finalBestOf' => $setup['final_best_of'], 'grandFinal' => 'single'], $profile)->toArray(),
                    'capacity' => self::sizes()[0],
                    'starts_at' => $closesAt,
                    'time_window' => self::maxDays() * ($profile->isDaily() ? 1 : 1440),
                    'on_site' => false,
                    'results_mode' => TournamentResultsMode::Players,
                    'status' => TournamentStatus::Draft,
                    'created_by_id' => null,
                    'cup_series' => $game,
                    'cup_number' => $number,
                    'cup_open_series' => $game,
                ]);

                return $this->publisher->openSignup($cup, $closesAt);
            });
        } catch (UniqueConstraintViolationException) {
            // Another run opened this game's cup (or took this number) first.
            return null;
        }
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
        if ($signedUp >= self::minPlayers() || self::isEvening($cup)) {
            $this->draws->close($cup);

            return null;
        }

        if ($this->toEvening($cup, $signedUp)) {
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
        $next = self::nextSize($cup->capacity);

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
                'starts_at' => self::eveningStart($locked->signup_closes_at),
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

    private function extend(Tournament $cup): bool
    {
        return DB::transaction(function () use ($cup): bool {
            $locked = Tournament::query()->with('event')->lockForUpdate()->findOrFail($cup->id);

            if ($locked->status !== TournamentStatus::Signup || $locked->cup_extended_at !== null || $locked->signup_closes_at?->isFuture()) {
                return false;
            }

            $closesAt = ($locked->signup_closes_at ?? now())->toImmutable()->addHours((int) config('esports.casual_cups.extension_hours', 48));
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
            $endsAt = CarbonImmutable::now()->addHours(self::windowHours($players))->min($cap);

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
            ->with(['slots.participant', 'chessGame', 'seriesMatch'])->orderBy('id')->get();

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

        // A live evening's games start at their round's start (S2).
        if ($invited || $match->chessGame !== null || self::isEvening($match->tournament)) {
            return true;
        }

        // A series match (S3) is started when the check-in for its agreed time (or the auto slot) opens.
        if (! $match->tournament->profile()->isChess()) {
            return ! self::seriesStartsAt($match)->subMinutes(max(0, (int) config('esports.casual.checkin_before_minutes', 10)))->isFuture();
        }

        return ! self::autoSlot($endsAt)->isFuture();
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
            : self::autoSlot($match->round->window_ends_at);
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
