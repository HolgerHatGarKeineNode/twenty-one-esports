<?php

namespace App\Support\Hyper;

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Games\Hyperbitcoinization;
use App\Models\Tournament;
use App\Models\User;
use App\Support\LeagueTime;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentChampion;
use App\Support\Tournaments\TournamentPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Hyperbitcoinization's weekend cup (plan "Hyperbitcoinization", P5): a light casual cup on the tournament flow,
 * nothing of its own beyond opening it. Behind `esports.hyper.cups.enabled` (off by default) and the league key.
 *
 * - **Opening** ({@see tick()}, every minute from the tournament clock): while no cup is open, the next one,
 *   "Hyperbitcoinization Weekend Cup #n", is published for sign-up (TournamentPublisher::openSignup()) until the
 *   coming `weekday` `time` in the league's zone, at least a day away; it starts then.
 * - **Sign-up, draw, bracket**: the tournament's own (TournamentSignups, TournamentDraws from the Bitcoin block),
 *   as Free for All: tables of `table` seats, the best `advance` of each move on, until one final table.
 * - **Tables**: the league starts every table at once (TournamentMatchMaker), **unrated**, and bots fill a table
 *   short of players up to `table` seats, so two players are a cup.
 * - **Winner**: the final table's place 1 (TournamentChampion); {@see wins()} counts a player's cups for the
 *   winner badge on the ladder and the profile.
 *
 * A cup is a tournament whose options carry `hyper_cup` ({@see isCup()}); it is no casual cup of CasualCups
 * (that one pairs two sides per match, a chess or series flow).
 */
final class HyperCups
{
    public const OPTION = 'hyper_cup';

    private const WINS_CACHE = 'hyper-cups:wins';

    public function __construct(private TournamentPublisher $publisher) {}

    public static function enabled(): bool
    {
        return (bool) config('esports.hyper.enabled') && (bool) config('esports.hyper.cups.enabled');
    }

    public static function isCup(Tournament $tournament): bool
    {
        return $tournament->game === Hyperbitcoinization::SLUG && ($tournament->options[self::OPTION] ?? false) === true;
    }

    /**
     * The cup to show: the open one (sign-up, drawing, running), else null.
     */
    public static function current(): ?Tournament
    {
        return Tournament::query()->where('game', Hyperbitcoinization::SLUG)
            ->whereIn('status', [TournamentStatus::Signup, TournamentStatus::Drawing, TournamentStatus::Running])
            ->get()->first(self::isCup(...));
    }

    /**
     * Opens the next cup when none is open. Returns the cup opened, null when nothing was. Fail closed: switched
     * off or without the league key nothing is created or published; a failure is reported, never thrown.
     */
    public function tick(): ?Tournament
    {
        if (! self::enabled() || LeagueKey::fromConfig() === null || self::current() !== null) {
            return null;
        }

        // A draft left behind by a publish that failed is published, never a second one created.
        $draft = Tournament::query()->where('game', Hyperbitcoinization::SLUG)->where('status', TournamentStatus::Draft)->whereNull('created_by_id')->get()->first(self::isCup(...));

        try {
            return DB::transaction(function () use ($draft): Tournament {
                $startsAt = self::nextStart(CarbonImmutable::now());
                $cup = $draft ?? $this->draft($startsAt);

                return $this->publisher->openSignup($cup, $startsAt);
            });
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * The coming `weekday` `time` in the league's zone, at least a day after `$now` (a sign-up of one day at least).
     */
    public static function nextStart(CarbonImmutable $now): CarbonImmutable
    {
        [$hour, $minute] = array_map(intval(...), explode(':', (string) config('esports.hyper.cups.time', '18:00')) + [1 => '0']);
        $weekday = (string) config('esports.hyper.cups.weekday', 'saturday');
        $local = $now->setTimezone(LeagueTime::zone());
        $start = $local->is($weekday) ? $local->setTime($hour, $minute) : $local->next($weekday)->setTime($hour, $minute);

        while ($start->lessThan($local->addDay())) {
            $start = $start->addWeek();
        }

        return $start->utc();
    }

    /**
     * How many weekend cups each player won (user id => count), read from the finished cups and kept for an hour.
     *
     * @return array<int, int>
     */
    public static function wins(): array
    {
        /** @var array<int, int> */
        return Cache::remember(self::WINS_CACHE, now()->addHour(), function (): array {
            $wins = [];
            $champions = app(TournamentChampion::class);

            foreach (Tournament::query()->where('game', Hyperbitcoinization::SLUG)->where('status', TournamentStatus::Finished)->get()->filter(self::isCup(...)) as $cup) {
                $userId = $champions->of($cup)?->user_id;

                if ($userId !== null) {
                    $wins[(int) $userId] = ($wins[(int) $userId] ?? 0) + 1;
                }
            }

            return $wins;
        });
    }

    public static function winsOf(User $user): int
    {
        return self::wins()[$user->id] ?? 0;
    }

    /** A cup ended: the winner badge reads the new champion at once. */
    public static function forgetWins(): void
    {
        Cache::forget(self::WINS_CACHE);
    }

    private function draft(CarbonImmutable $startsAt): Tournament
    {
        $mode = (string) config('esports.hyper.cups.mode', 'live');
        $profile = GameProfile::for(Hyperbitcoinization::SLUG, $mode);
        $number = Tournament::query()->where('game', Hyperbitcoinization::SLUG)->whereNull('created_by_id')->get()->filter(self::isCup(...))->count() + 1;
        $options = FormatOptions::fromArray([
            'heatSize' => (int) config('esports.hyper.cups.table', 4),
            'heatAdvance' => (int) config('esports.hyper.cups.advance', 1),
        ], $profile)->toArray();

        return Tournament::query()->create([
            'name' => "Hyperbitcoinization Weekend Cup #{$number}",
            'game' => Hyperbitcoinization::SLUG,
            'mode' => $mode,
            'format' => TournamentFormat::FreeForAll,
            'options' => [...$options, self::OPTION => true],
            'capacity' => max(2, (int) config('esports.hyper.cups.capacity', 32)),
            'starts_at' => $startsAt,
            // One evening live, two weeks by correspondence, in the profile's unit.
            'time_window' => $profile->isDaily() ? 14 : 360,
            'on_site' => false,
            'results_mode' => TournamentResultsMode::Players,
            'status' => TournamentStatus::Draft,
            'created_by_id' => null,
        ]);
    }
}
