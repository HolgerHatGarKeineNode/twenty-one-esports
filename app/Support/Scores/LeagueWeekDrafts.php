<?php

namespace App\Support\Scores;

use App\Enums\NotificationKind;
use App\Games\Blockfill;
use App\Games\GameRegistry;
use App\Games\TrackmaniaNationsForever;
use App\Models\Admin;
use App\Models\LeagueWeek;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Board;
use App\Support\LeagueTime;
use App\Support\Notifications\Notice;
use App\Support\Notifications\Notifier;
use App\Support\Stacker\BlockfillDifficulty;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Tmnf\TmnfWeeks;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * The admins approve every league week before it starts (user 2026-10-02:
 * "kein automatisches weiter für die Woche"), for each game whose weeks the
 * league opens by itself (LeagueWeek::GAMES: Blockfill, TMNF).
 *
 * - prepare(), every hour from the game's weeks command: the next week is
 *   made as a draft with the previous week's settings once its draft time is
 *   reached (`esports.league_weeks`, default Thursday 12:00 Berlin of the
 *   week before); a week that runs nothing and has no draft yet gets one
 *   too, so it can still be approved late. Every admin gets a bell entry
 *   once per draft, and a reminder `reminder_hours` before the planned start
 *   while it is still not approved.
 * - approve(): the week may start. It starts at its planned start (the
 *   game's weeks command, every hour at minute 0), or at once when the
 *   approval came later; it ends on the following Monday 00:00 either way
 *   (startTime()). A TMNF week waits in addition for the listener to see the
 *   server on its track (TmnfTrackSwitch).
 * - update(): new settings; an approved week needs approving again, so no
 *   change starts unseen.
 *
 * A draft is a LeagueWeek row only: it has no tournament, so no page, list,
 * calendar event, bot note or stream slide can show it. Not approved, a week
 * never starts; the previous one ends with its results as always.
 */
final class LeagueWeekDrafts
{
    public function __construct(private GameRegistry $games, private Notifier $notifier) {}

    /** Whether the game is one the admins plan, and registered. */
    public function plans(string $game): bool
    {
        return in_array($game, LeagueWeek::GAMES, true) && $this->games->find($game) !== null;
    }

    /**
     * When the draft of the week starting at `$start` is made: `draft_weekday`
     * `draft_time` (Europe/Berlin) of the week before, in UTC.
     */
    public static function draftMoment(CarbonInterface $start): CarbonImmutable
    {
        $config = (array) config('esports.league_weeks', []);
        $weekday = max(1, min(7, (int) ($config['draft_weekday'] ?? 4)));
        [$hour, $minute] = array_map(intval(...), explode(':', (string) ($config['draft_time'] ?? '12:00')) + [1 => '0']);

        return CarbonImmutable::instance($start)->setTimezone(BlockfillWeeks::TIMEZONE)->subWeek()->startOfDay()
            ->addDays($weekday - 1)->setTime($hour, $minute)->utc();
    }

    /**
     * The hourly step of a game: makes the drafts that are due and sends the
     * admins what they have not heard yet. Returns next week's draft, if there is one.
     */
    public function prepare(string $game, ?CarbonInterface $now = null): ?LeagueWeek
    {
        if (! $this->plans($game)) {
            return null;
        }

        $now = CarbonImmutable::instance($now ?? now());
        $current = BlockfillWeeks::startOf($now);
        $next = BlockfillWeeks::endOf($current);

        // This week runs nothing and nobody planned it (the game was just switched on): a draft to approve late.
        if ($this->tournamentOf($game, $current) === null && $this->find($game, $current) === null) {
            $this->draft($game, $current);
        }

        $draft = $now->greaterThanOrEqualTo(self::draftMoment($next)) ? $this->draft($game, $next) : $this->find($game, $next);

        foreach ($this->open($game, $now) as $week) {
            try {
                $this->tell($week, $now);
            } catch (Throwable $e) {
                // One week that fails is reported; the next run tries again (nothing was claimed).
                report($e);
            }
        }

        return $draft;
    }

    /**
     * The weeks of a game the admins still decide on: not started, and their
     * week not over. Earliest first.
     *
     * @return Collection<int, LeagueWeek>
     */
    public function open(string $game, ?CarbonInterface $now = null): Collection
    {
        $now = CarbonImmutable::instance($now ?? now());

        return LeagueWeek::query()->where('game', $game)->whereNull('tournament_id')
            ->where('starts_at', '>', BlockfillWeeks::startOf($now)->subWeek())
            ->orderBy('starts_at')->get()
            ->filter(fn (LeagueWeek $week): bool => $now->lessThan($week->endsAt()))
            ->values();
    }

    public function find(string $game, CarbonInterface $start): ?LeagueWeek
    {
        return LeagueWeek::query()->where('game', $game)->where('starts_at', CarbonImmutable::instance($start)->utc())->first();
    }

    /** The plan a week's leaderboard started from; null for a week from before the approvals. */
    public function ofTournament(Tournament $week): ?LeagueWeek
    {
        return LeagueWeek::query()->where('tournament_id', $week->id)->first();
    }

    /**
     * The approved, not yet started plan of the week starting at `$start`,
     * while `$now` lies inside that week: the week may start now.
     */
    public function startable(string $game, CarbonInterface $start, CarbonInterface $now): ?LeagueWeek
    {
        $week = $this->find($game, $start);

        if ($week === null || ! $week->isApproved() || $week->hasStarted() || $now->lessThan($week->starts_at) || ! $now->lessThan($week->endsAt())) {
            return null;
        }

        return $week;
    }

    /**
     * When an approved week starts: its planned start, or the latest of its
     * approval and (TMNF) the moment the server was on its track, rounded up
     * to the full minute, so its window is whole minutes up to the Monday it ends.
     */
    public static function startTime(LeagueWeek $week): CarbonImmutable
    {
        $start = CarbonImmutable::instance($week->starts_at);

        foreach ([$week->approved_at, $week->track_ready_at] as $moment) {
            if ($moment !== null && $moment->greaterThan($start)) {
                $start = CarbonImmutable::instance($moment);
            }
        }

        $start = $start->utc();

        return $start->second === 0 && $start->microsecond === 0 ? $start : $start->startOfMinute()->addMinute();
    }

    /**
     * Ties a started week to its plan, once.
     */
    public function started(LeagueWeek $week, Tournament $tournament): void
    {
        LeagueWeek::query()->whereKey($week->id)->whereNull('tournament_id')->update(['tournament_id' => $tournament->id, 'updated_at' => now()]);
    }

    /**
     * An admin approves the week. False when it cannot be approved (started,
     * approved already, or its week is over). A week whose planned start has
     * passed starts at once.
     */
    public function approve(LeagueWeek $week, User $admin, ?CarbonInterface $now = null): bool
    {
        $now = CarbonImmutable::instance($now ?? now());

        if (! $now->lessThan($week->endsAt())) {
            return false;
        }

        $approved = LeagueWeek::query()->whereKey($week->id)->whereNull('approved_at')->whereNull('tournament_id')
            ->update(['approved_at' => $now->utc(), 'approved_by_id' => $admin->id, 'updated_at' => $now->utc()]) === 1;

        if (! $approved) {
            return false;
        }

        $week->refresh();

        if ($now->greaterThanOrEqualTo($week->starts_at)) {
            // Late: the week starts now (a TMNF week once the server is on its track).
            $week->game === Blockfill::SLUG ? app(BlockfillWeeks::class)->open($now) : app(TmnfWeeks::class)->open($now);
        }

        return true;
    }

    /**
     * New settings for a week that has not started. Changed settings of an
     * approved week withdraw its approval. Returns whether anything changed.
     *
     * @param  array<string, mixed>  $settings
     */
    public function update(LeagueWeek $week, array $settings): bool
    {
        $settings = self::normalize($week->game, $settings);

        if ($week->hasStarted() || $settings === self::normalize($week->game, $week->settings)) {
            return false;
        }

        $week->forceFill(['settings' => $settings, 'approved_at' => null, 'approved_by_id' => null, 'track_ready_at' => null, 'warned_at' => null])->save();

        return true;
    }

    /**
     * A game's settings in their stored shape; anything unknown falls back to
     * the game's default (fail closed: never a track or a difficulty the league does not run).
     *
     * @param  array<string, mixed>|null  $settings
     * @return array<string, mixed>
     */
    public static function normalize(string $game, ?array $settings): array
    {
        $settings ??= [];

        if ($game === Blockfill::SLUG) {
            $difficulty = $settings['difficulty'] ?? null;

            return ['difficulty' => BlockfillDifficulty::isKnown($difficulty) ? $difficulty : BlockfillDifficulty::default()];
        }

        $track = $settings['track'] ?? null;
        $limit = filter_var($settings['time_limit_minutes'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => TmnfWeeks::MIN_ROUND_MINUTES, 'max_range' => TmnfWeeks::MAX_ROUND_MINUTES]]);

        return [
            'track' => is_string($track) && TmnfWeeks::track($track) !== null ? $track : (TmnfWeeks::trackFor(BlockfillWeeks::startOf(now())) ?? ''),
            'time_limit_minutes' => is_int($limit) ? $limit : null,
        ];
    }

    /**
     * The settings a new draft starts from: the previous week's plan, or for
     * the first plan of a game the week that runs (TMNF: its track) or the defaults.
     *
     * @return array<string, mixed>
     */
    public function previousSettings(string $game, CarbonInterface $start): array
    {
        $previous = LeagueWeek::query()->where('game', $game)->where('starts_at', '<', CarbonImmutable::instance($start)->utc())->orderByDesc('starts_at')->first();

        if ($previous !== null) {
            return self::normalize($game, $previous->settings);
        }

        if ($game === TrackmaniaNationsForever::SLUG) {
            $running = Tournament::query()->where('game', $game)->where('slug', 'like', Tournament::TMNF_WEEK_SLUG)->where('starts_at', '<', CarbonImmutable::instance($start)->utc())->orderByDesc('starts_at')->first();

            return self::normalize($game, ['track' => $running->score_course ?? TmnfWeeks::trackFor($start)]);
        }

        return self::normalize($game, []);
    }

    /** "TMNF" or "Blockfill": the game as the admin lines name it. */
    public static function gameName(string $game): string
    {
        return $game === TrackmaniaNationsForever::SLUG ? 'TMNF' : 'Blockfill';
    }

    /**
     * Every admin (the board and the `admins` table) with an account, by id.
     *
     * @return Collection<int, User>
     */
    public static function admins(): Collection
    {
        $pubkeys = array_values(array_unique([...Board::pubkeys(), ...Admin::query()->pluck('pubkey')->all()]));

        return User::query()->whereIn('pubkey', $pubkeys)->orderBy('id')->get();
    }

    /**
     * A bell entry for every admin, in their language. `$title` and `$body` are
     * English keys with `:game`, `:week` and `:start` filled in for the week,
     * and `$extra` besides.
     *
     * @param  array<string, string|int>  $extra
     */
    public function tellAdmins(LeagueWeek $week, string $title, string $body, array $extra = []): int
    {
        $told = 0;

        foreach (self::admins() as $admin) {
            $locale = $admin->locale ?? (string) config('app.locale');
            $values = ['game' => self::gameName($week->game), 'week' => $week->weekNumber(), 'start' => LeagueTime::stamp($week->starts_at, null, $locale)] + $extra;

            try {
                // The bell only (remote: false): an admin acts on it on the site, on the admin page it links.
                $this->notifier->send($admin, NotificationKind::LeagueWeekApproval, new Notice(
                    __($title, $values, $locale),
                    __($body, $values, $locale),
                    route('admin.league-weeks'),
                    null,
                    __('Review the week', [], $locale),
                ), remote: false);
                $told++;
            } catch (Throwable $e) {
                // One admin whose entry fails does not keep the others from theirs.
                report($e);
            }
        }

        return $told;
    }

    /**
     * The draft of the week starting at `$start`, made once with the previous week's settings.
     */
    private function draft(string $game, CarbonImmutable $start): LeagueWeek
    {
        $existing = $this->find($game, $start);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return LeagueWeek::query()->create(['game' => $game, 'starts_at' => $start->utc(), 'settings' => $this->previousSettings($game, $start)]);
        } catch (UniqueConstraintViolationException) {
            // Another run made it first.
            return $this->find($game, $start) ?? throw new \RuntimeException('The league week draft vanished.');
        }
    }

    /**
     * The approval request once per draft, and the reminder once, each claimed before it goes out.
     */
    private function tell(LeagueWeek $week, CarbonImmutable $now): void
    {
        if ($week->isApproved()) {
            return;
        }

        if ($week->notified_at === null && $this->claim($week, 'notified_at', $now)) {
            $this->tellAdmins($week, ':game week :week needs your approval', 'It starts :start once an admin checked its settings and approved it. Not approved, it does not start.');

            return;
        }

        $hours = (int) config('esports.league_weeks.reminder_hours', 24);
        $due = $week->starts_at->toImmutable()->subHours($hours);

        if ($week->reminded_at === null && $now->greaterThanOrEqualTo($due) && $now->lessThan($week->starts_at) && $this->claim($week, 'reminded_at', $now)) {
            $this->tellAdmins($week, 'Reminder: :game week :week is not approved yet', 'It should start :start. Not approved, it does not start, and players see "Next week starts soon".');
        }
    }

    private function claim(LeagueWeek $week, string $column, CarbonImmutable $now): bool
    {
        return LeagueWeek::query()->whereKey($week->id)->whereNull($column)->whereNull('approved_at')->update([$column => $now->utc(), 'updated_at' => $now->utc()]) === 1;
    }

    private function tournamentOf(string $game, CarbonImmutable $start): ?Tournament
    {
        return $game === Blockfill::SLUG ? app(BlockfillWeeks::class)->find($start) : app(TmnfWeeks::class)->find($start);
    }
}
