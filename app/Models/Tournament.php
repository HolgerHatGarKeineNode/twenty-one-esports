<?php

namespace App\Models;

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Games\Blockfill;
use App\Games\GameRegistry;
use App\Games\TrackmaniaNationsForever;
use App\Support\Nostr\NostrKeys;
use App\Support\Series\Ladders;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Tournaments\DurationRange;
use App\Support\Tournaments\Estimator;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\Lobbies;
use App\Support\Tournaments\TournamentDeadlines;
use Carbon\CarbonImmutable;
use Database\Factories\TournamentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A special tournament (P8), created by an admin or an organizer an admin
 * unlocked. Its matches count for Elo like every other match, but never mine
 * season-chain blocks: the chain belongs to the season, a tournament has its
 * own prize pot (P9).
 *
 * `capacity` is the number of participants the format was chosen for,
 * `time_window` the time the organizer has, in the game's unit (minutes, or
 * days for daily chess), `stations` the boards or stations on site (null =
 * online), `times` the organizer's own planning values (game, setup, break).
 *
 * @property int $id
 * @property string $name
 * @property string|null $description the organizer's own words, shown in the page hero and the 31923 content
 * @property string $game
 * @property string $mode
 * @property TournamentFormat $format
 * @property array<string, mixed> $options see {@see FormatOptions}
 * @property int $capacity
 * @property Carbon $starts_at
 * @property int $time_window
 * @property bool $on_site
 * @property int|null $stations
 * @property array{game?: float, setup?: float, break?: float}|null $times
 * @property TournamentResultsMode $results_mode
 * @property TournamentStatus $status
 * @property string|null $seed
 * @property int|null $created_by_id
 * @property bool $opened_by_league a score window the league opened itself (BlockfillWeeks::open()); only such a window can mine (SeasonChains::attestScoreWindow()). Never mass assignable
 * @property string|null $slug `d` of the tournament's NIP-52 calendar event (31923)
 * @property Carbon|null $signup_closes_at
 * @property Carbon|null $published_at
 * @property int|null $event_id the league's 31923
 * @property int|null $draw_height the Bitcoin block the draw committed to (NIP 2155 `draw`)
 * @property string|null $draw_hash its hash once mined: the draw and bracket seed
 * @property int|null $draw_event_id the league's 2155 (only with a solo pool)
 * @property Carbon|null $draw_committed_at when the draw committed to `draw_height`
 * @property string|null $ladder_address the ladder frozen with the first 31923 version; null = unrated (NIP rev. 7)
 * @property int|null $checkin_minutes the tournament's own deadlines (P18); null = the league default ({@see TournamentDeadlines})
 * @property int|null $noshow_minutes
 * @property int|null $report_hours
 * @property int|null $response_minutes
 * @property int|null $prize_target_sats the organizer's goal for the pool (P9); shown, never paid from by itself
 * @property list<int>|null $prize_split percent per place, null = {@see self::DEFAULT_SPLIT}
 * @property Carbon|null $pool_opened_at the pot is open since then (set at publish)
 * @property Carbon|null $pool_closed_at receipts after it count for the reserve (the admin check at the end)
 * @property Carbon|null $payouts_approved_at
 * @property int|null $payouts_approved_by_id
 * @property string|null $pot_source `league` (the pot is booked in the league wallet), `wallet` (legacy: the tournament's own NWC wallet, kept only for pots whose payouts were approved before 2026-10-02) or null (no pot)
 * @property string|null $prize_mode `percent` (null) or `fixed`
 * @property list<int>|null $prize_fixed sats per place in `fixed` mode
 * @property bool|null $pot_can_receive legacy own wallet: its connection may `make_invoice`; unused for league pots
 * @property string|null $pot_nwc_uri legacy: the tournament's own NWC connection (encrypted at rest, never shown); kept, unused, after a pot moved to the league wallet
 * @property string|null $pot_lud16 legacy: the Lightning address of that wallet
 * @property int|null $pot_balance_sats legacy: last balance read from the own wallet
 * @property Carbon|null $pot_balance_at legacy: when that balance was read
 * @property string|null $pot_balance_error legacy: why the latest read failed
 * @property Carbon|null $paused_at set while an organizer or admin paused the running tournament (P18, TournamentControl)
 * @property string|null $cup_series its casual cup series (P25, CasualCups): "<game>-<region>", "chess-eu"; null for every other tournament
 * @property int|null $cup_number its number in that series; null once called off (the number is taken again)
 * @property string|null $cup_open_series the series while the cup is open, null once it ended (unique: one open cup per game and region)
 * @property Carbon|null $cup_extended_at when its sign-up was extended (once)
 * @property Carbon|null $cup_ended_at when it finished or was called off
 * @property string|null $score_course the course a score game's leaderboard is played on (plan "AoE2 und Trackmania", P4); null for every other tournament
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 * @property-read Collection<int, User> $directors
 * @property-read Collection<int, TournamentParticipant> $participants
 * @property-read Collection<int, TournamentStage> $stages
 * @property-read Collection<int, TournamentMatch> $matches
 * @property-read Collection<int, TournamentSignup> $signups
 * @property-read Collection<int, TournamentBan> $bans
 * @property-read NostrEvent|null $event
 * @property-read NostrEvent|null $drawEvent
 * @property-read Collection<int, TournamentSponsor> $sponsors
 * @property-read Collection<int, TournamentPayout> $payouts
 */
#[Fillable(['name', 'description', 'game', 'mode', 'format', 'options', 'capacity', 'starts_at', 'time_window', 'on_site', 'stations', 'times', 'results_mode', 'status', 'seed', 'created_by_id',
    'slug', 'signup_closes_at', 'published_at', 'event_id', 'draw_height', 'draw_hash', 'draw_event_id', 'draw_committed_at', 'ladder_address',
    'checkin_minutes', 'noshow_minutes', 'report_hours', 'response_minutes',
    'prize_target_sats', 'prize_split', 'pool_opened_at', 'pool_closed_at', 'payouts_approved_at', 'payouts_approved_by_id',
    'pot_source', 'pot_nwc_uri', 'pot_lud16', 'pot_balance_sats', 'pot_balance_at', 'pot_balance_error', 'paused_at',
    'prize_mode', 'prize_fixed', 'pot_can_receive',
    'cup_series', 'cup_number', 'cup_open_series', 'cup_extended_at', 'cup_ended_at', 'score_course'])]
#[Hidden(['pot_nwc_uri'])]
class Tournament extends Model
{
    /** @use HasFactory<TournamentFactory> */
    use HasFactory;

    /** NIP-52 time-based calendar event: the tournament (NIP "Tournaments"). */
    public const CALENDAR_EVENT = 31923;

    /** NIP-52 calendar: the league's list of tournaments, `d` = `tournaments`. */
    public const CALENDAR = 31924;

    /** Substitutes a lineup may bring on top of the mode's size. */
    public const SUBSTITUTES = 2;

    /** Percent of the pool per place when the organizer sets no split (open question 10). */
    public const DEFAULT_SPLIT = [50, 30, 20];

    /**
     * A cancelled tournament's pot closes with it (security gate on 55ef30e):
     * no top-up or sponsor invoice is made for it any more. One place for
     * every way a tournament is cancelled (the admin's abort, the draw's
     * automatic cancel), so a new one cannot forget it.
     */
    protected static function booted(): void
    {
        static::saving(function (Tournament $tournament): void {
            if ($tournament->status === TournamentStatus::Cancelled && $tournament->pool_opened_at !== null && $tournament->pool_closed_at === null) {
                $tournament->setAttribute('pool_closed_at', now());
            }
        });
    }

    protected function casts(): array
    {
        return [
            'format' => TournamentFormat::class,
            'options' => 'array',
            'capacity' => 'integer',
            'starts_at' => 'datetime',
            'time_window' => 'integer',
            'on_site' => 'boolean',
            'opened_by_league' => 'boolean',
            'stations' => 'integer',
            'times' => 'array',
            'results_mode' => TournamentResultsMode::class,
            'status' => TournamentStatus::class,
            'signup_closes_at' => 'datetime',
            'published_at' => 'datetime',
            'draw_height' => 'integer',
            'draw_committed_at' => 'datetime',
            'checkin_minutes' => 'integer',
            'noshow_minutes' => 'integer',
            'report_hours' => 'integer',
            'response_minutes' => 'integer',
            'prize_target_sats' => 'integer',
            'prize_split' => 'array',
            'pool_opened_at' => 'datetime',
            'pool_closed_at' => 'datetime',
            'payouts_approved_at' => 'datetime',
            'pot_nwc_uri' => 'encrypted',
            'pot_balance_sats' => 'integer',
            'pot_balance_at' => 'datetime',
            'paused_at' => 'datetime',
            'prize_fixed' => 'array',
            'pot_can_receive' => 'boolean',
            'cup_number' => 'integer',
            'cup_extended_at' => 'datetime',
            'cup_ended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * The named tournament directors (the creator directs without being named).
     *
     * @return BelongsToMany<User, $this>
     */
    public function directors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tournament_directors')->withPivot('added_by_id')->withTimestamps();
    }

    /**
     * @return HasMany<TournamentParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(TournamentParticipant::class);
    }

    /**
     * @return HasMany<TournamentStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(TournamentStage::class)->orderBy('number');
    }

    /**
     * @return HasMany<TournamentMatch, $this>
     */
    public function matches(): HasMany
    {
        return $this->hasMany(TournamentMatch::class);
    }

    /**
     * @return HasMany<TournamentSignup, $this>
     */
    public function signups(): HasMany
    {
        return $this->hasMany(TournamentSignup::class)->orderBy('id');
    }

    /**
     * Players blocked from signing up again.
     *
     * @return HasMany<TournamentBan, $this>
     */
    public function bans(): HasMany
    {
        return $this->hasMany(TournamentBan::class);
    }

    /**
     * The moderation log, newest first.
     *
     * @return HasMany<TournamentModerationEntry, $this>
     */
    public function moderationEntries(): HasMany
    {
        return $this->hasMany(TournamentModerationEntry::class)->orderByDesc('id');
    }

    /**
     * The director log, newest first.
     *
     * @return HasMany<TournamentResultEntry, $this>
     */
    public function resultEntries(): HasMany
    {
        return $this->hasMany(TournamentResultEntry::class)->orderByDesc('id');
    }

    /**
     * @return HasMany<TournamentSponsor, $this>
     */
    public function sponsors(): HasMany
    {
        return $this->hasMany(TournamentSponsor::class)->orderBy('id');
    }

    /**
     * @return HasMany<TournamentPayout, $this>
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(TournamentPayout::class)->orderBy('place')->orderBy('name');
    }

    /**
     * Percent of the pool per place, first place first (P9).
     *
     * @return list<int>
     */
    public function prizeSplit(): array
    {
        return $this->prize_split === null ? self::DEFAULT_SPLIT : array_map(intval(...), $this->prize_split);
    }

    /** Zaps and sponsor invoices are taken: the pool is open and not yet closed. */
    public function isPoolOpen(): bool
    {
        return $this->pool_opened_at !== null && $this->pool_closed_at === null;
    }

    /**
     * A pot booked in the league wallet (user, 2026-10-02: „das landet eh alles
     * in eine Wallet von wo aus ausgezahlt werden kann"): its sats are the
     * tournament's account in the league ledger.
     */
    public const POT_LEAGUE = 'league';

    /**
     * Legacy: a pot in the tournament's own NWC wallet. Only pots whose
     * payouts were approved before the league wallet took over keep it, to
     * finish paying from that wallet.
     */
    public const POT_WALLET = 'wallet';

    public const PRIZES_PERCENT = 'percent';

    public const PRIZES_FIXED = 'fixed';

    /** `percent` (a share of the pot per place) or `fixed` (sats per place). */
    public function prizeMode(): string
    {
        return $this->prize_mode === self::PRIZES_FIXED ? self::PRIZES_FIXED : self::PRIZES_PERCENT;
    }

    /**
     * Sats per place in `fixed` mode (empty in `percent` mode).
     *
     * @return list<int>
     */
    public function prizeFixed(): array
    {
        return $this->prizeMode() === self::PRIZES_FIXED ? array_map(intval(...), $this->prize_fixed ?? []) : [];
    }

    /** The tournament has a prize pot (booked in the league wallet, or a legacy own wallet). */
    public function hasPot(): bool
    {
        return in_array($this->pot_source, [self::POT_LEAGUE, self::POT_WALLET], true);
    }

    /** The pot is booked in the league wallet (every new pot). */
    public function hasLeaguePot(): bool
    {
        return $this->pot_source === self::POT_LEAGUE;
    }

    /** Legacy: the pot lives in the tournament's own NWC wallet (approved before the league wallet took over). */
    public function hasOwnWallet(): bool
    {
        return $this->pot_source === self::POT_WALLET;
    }

    /** The ledger account of this tournament's pot in the league wallet. */
    public function potAccount(): string
    {
        return 'tournament:'.$this->id;
    }

    /**
     * @return BelongsTo<NostrEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(NostrEvent::class, 'event_id');
    }

    /**
     * @return BelongsTo<NostrEvent, $this>
     */
    public function drawEvent(): BelongsTo
    {
        return $this->belongsTo(NostrEvent::class, 'draw_event_id');
    }

    /**
     * NIP-01 address of the published calendar event, `31923:<league>:<slug>`;
     * null for a draft.
     */
    public function address(): ?string
    {
        return $this->event === null || $this->slug === null ? null : self::CALENDAR_EVENT.':'.$this->event->pubkey.':'.$this->slug;
    }

    public function naddr(): ?string
    {
        return $this->event === null || $this->slug === null ? null : NostrKeys::naddr(self::CALENDAR_EVENT, $this->event->pubkey, $this->slug);
    }

    /**
     * Players per team: the mode's team size (1 for chess and RL 1v1); a Hyperbitcoinization clan bracket's from
     * its options (P5b, FormatOptions::$teamSize).
     */
    public function teamSize(): int
    {
        $profile = $this->profile();

        if ($profile->isHyper()) {
            return $profile->teamSize;
        }

        return $profile->entersTeams() ? (int) app(GameRegistry::class)->mode($this->game, $this->mode)?->teamSize : 1;
    }

    /**
     * A lineup fields the mode's size plus up to this many substitutes
     * (open question 10, CEO default 2026-09-26).
     */
    public function maxLineupSize(): int
    {
        return $this->teamSize() + self::SUBSTITUTES;
    }

    public function isSignupOpen(): bool
    {
        return $this->status === TournamentStatus::Signup
            && $this->signup_closes_at !== null
            && $this->signup_closes_at->isFuture();
    }

    /**
     * Draft or sign-up: nothing is committed to a block yet, so format, game
     * and entries may still change (the edit page, TournamentEditor).
     */
    public function isBeforeDraw(): bool
    {
        return in_array($this->status, [TournamentStatus::Draft, TournamentStatus::Signup], true);
    }

    /**
     * The players a clan lineup's entry may field in this tournament: the
     * members its captain entered (the players its sign-up consent names),
     * without anyone blocked for this tournament. Today's seats of the
     * lineup are not the entry: a player who joined the lineup after the
     * sign-up, or was blocked, never plays for it here. Null when the
     * lineup has no participant here.
     *
     * @return list<int>|null
     */
    public function entryPlayersOf(int $lineupId): ?array
    {
        $participant = $this->participants()->where('lineup_id', $lineupId)->first();

        if ($participant === null) {
            return null;
        }

        $blocked = $this->bans()->pluck('user_id')->map(intval(...))->all();

        return array_values(array_diff($participant->memberIds(), $blocked));
    }

    /**
     * The ladder a match paired now is rated on: the frozen ladder, while that
     * same ladder is still open (NIP rev. 7 "Rated or unrated"); else null.
     */
    public function openLadder(): ?string
    {
        return $this->ladder_address !== null && Ladders::address($this->game, $this->mode) === $this->ladder_address
            ? $this->ladder_address
            : null;
    }

    /**
     * Paused by an organizer or admin (P18): no deadline runs and no new
     * match starts until it is resumed (TournamentControl).
     */
    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }

    /**
     * One of the league's automatic casual cups (P25, CasualCups): no Elo
     * ladder, random seeding, round windows instead of a start time.
     */
    public function isCasualCup(): bool
    {
        return $this->cup_series !== null;
    }

    /**
     * The special tournaments: every one but the casual cups. Only they head
     * a page as its "next tournament" (user, 2026-09-28: casual cups stay a
     * side mention, the special tournaments matter more).
     *
     * @param  Builder<Tournament>  $query
     */
    #[Scope]
    protected function special(Builder $query): void
    {
        $query->whereNull('cup_series');
    }

    /**
     * @param  Builder<Tournament>  $query
     */
    #[Scope]
    protected function casualCup(Builder $query): void
    {
        $query->whereNotNull('cup_series');
    }

    /** The `slug` of a Blockfill week, `blockfill-<monday>` (App\Support\Stacker\BlockfillWeeks::slugOf()), as a LIKE pattern. */
    public const BLOCKFILL_WEEK_SLUG = 'blockfill-____-__-__';

    /**
     * One of Blockfill's weekly leaderboards (plan "Blockfill", P4), which
     * the league opens by itself: a game's own board, not a tournament an
     * organizer set up. The lists of tournaments, their counts, a player's
     * played tournaments and the stream bot leave it out (P6,
     * exceptBlockfillWeeks()); its page shows it in the page's language
     * (title()). A Blockfill tournament an organizer made has another slug
     * (its name and id).
     */
    public function isBlockfillWeek(): bool
    {
        return $this->game === Blockfill::SLUG && preg_match('/^blockfill-\d{4}-\d{2}-\d{2}$/', (string) $this->slug) === 1;
    }

    /**
     * A Blockfill week while Blockfill is not registered (its switch off): its
     * pages answer 404 (P6), so no week left in the database renders without
     * the game's routes behind it.
     */
    public function isSwitchedOffBlockfillWeek(): bool
    {
        return $this->isBlockfillWeek() && app(GameRegistry::class)->find(Blockfill::SLUG) === null;
    }

    /**
     * Every tournament but Blockfill's weekly leaderboards (isBlockfillWeek()).
     *
     * @param  Builder<Tournament>  $query
     */
    #[Scope]
    protected function exceptBlockfillWeeks(Builder $query): void
    {
        // The slug condition is never NULL here, so NOT(...) keeps every row that is not a week (a draft has no slug yet).
        $query->whereNot(fn (Builder $week) => $week->where('game', Blockfill::SLUG)->whereNotNull('slug')->where('slug', 'like', self::BLOCKFILL_WEEK_SLUG));
    }

    /** The `slug` of a TMNF week, `tmnf-<monday>` (App\Support\Tmnf\TmnfWeeks::slugOf()), as a LIKE pattern. */
    public const TMNF_WEEK_SLUG = 'tmnf-____-__-__';

    /**
     * One of TrackMania Nations Forever's weekly leaderboards (plan
     * "Trackmania und Restposten", P2), opened by the league itself on our
     * own server's track of the week (App\Support\Tmnf\TmnfWeeks).
     */
    public function isTmnfWeek(): bool
    {
        return $this->game === TrackmaniaNationsForever::SLUG && preg_match('/^tmnf-\d{4}-\d{2}-\d{2}$/', (string) $this->slug) === 1;
    }

    /**
     * A weekly leaderboard the league opens by itself, of any game (a
     * Blockfill or a TMNF week): no organizer, no sign-up, no prize pool, no
     * bracket. The lists of tournaments and the stream bot's tournament notes
     * leave it out (exceptLeagueWeeks()); its game has pages and notes of its own.
     */
    public function isLeagueWeek(): bool
    {
        return $this->isBlockfillWeek() || $this->isTmnfWeek();
    }

    /**
     * A league week while its game is not registered (its switch off): its
     * pages answer 404, as isSwitchedOffBlockfillWeek() for Blockfill.
     */
    public function isSwitchedOffLeagueWeek(): bool
    {
        return $this->isLeagueWeek() && app(GameRegistry::class)->find($this->game) === null;
    }

    /**
     * Every tournament but the league's weekly leaderboards of any game (isLeagueWeek()).
     *
     * @param  Builder<Tournament>  $query
     */
    #[Scope]
    protected function exceptLeagueWeeks(Builder $query): void
    {
        $query->whereNot(fn (Builder $week) => $week->where('game', Blockfill::SLUG)->whereNotNull('slug')->where('slug', 'like', self::BLOCKFILL_WEEK_SLUG))
            ->whereNot(fn (Builder $week) => $week->where('game', TrackmaniaNationsForever::SLUG)->whereNotNull('slug')->where('slug', 'like', self::TMNF_WEEK_SLUG));
    }

    /**
     * The tournament's name for a page, in the page's language: a Blockfill
     * week as "Blockfill Week 41, 2026" / "Blockfill Woche 41, 2026", a TMNF
     * week as "TMNF Week 41, 2026" (their stored names are the English
     * ones); any other tournament its own name.
     */
    public function title(): string
    {
        if (! $this->isLeagueWeek()) {
            return $this->name;
        }

        $local = $this->starts_at->toImmutable()->setTimezone(BlockfillWeeks::TIMEZONE);

        return $this->isTmnfWeek()
            ? __('TMNF Week :week, :year', ['week' => $local->isoWeek(), 'year' => $local->isoWeekYear()])
            : __('Blockfill Week :week, :year', ['week' => $local->isoWeek(), 'year' => $local->isoWeekYear()]);
    }

    public function isDirectorMode(): bool
    {
        return $this->results_mode === TournamentResultsMode::Director;
    }

    /**
     * The planning values of the game and mode, with the organizer's own times.
     */
    public function profile(): GameProfile
    {
        $times = $this->times ?? [];

        // A game switched off since keeps its tournaments readable (a stand-in that plans and starts nothing).
        $profile = GameProfile::ofTournament($this->game, $this->mode)->withTimes($times['game'] ?? null, $times['setup'] ?? null, $times['break'] ?? null);

        // A Hyperbitcoinization clan bracket (P5b): its team size is an option of the tournament, not of the mode.
        return $profile->isHyper() ? $profile->withTeamSize(FormatOptions::hyperTeamSize($this->options)) : $profile;
    }

    public function formatOptions(): FormatOptions
    {
        // Drawn (P10): the options it was drawn with hold, never the lobby game's current ones.
        // Asked only for a lobby game's tournament: every other one reads its stored options either way.
        $drawn = Lobbies::isLobbyGame($this->game) && in_array($this->status, [TournamentStatus::Running, TournamentStatus::Finished, TournamentStatus::Cancelled], true)
            && $this->matches()->exists();

        return FormatOptions::fromArray($this->options, $this->profile(), ! $drawn);
    }

    /**
     * Planned duration in the game's unit, as the chooser estimated it.
     */
    public function plannedDuration(): float
    {
        $estimator = new Estimator;
        $options = $this->formatOptions();
        $profile = $this->profile();

        return $estimator->duration($this->format, $estimator->structure($this->format, $this->capacity, $options), $profile, $options, $profile->isDaily() ? null : $this->stations)->total;
    }

    /**
     * The honest range in the game's unit (P18): the plan, the typical
     * online duration and the latest, on this tournament's round clock.
     */
    public function durationRange(): DurationRange
    {
        $estimator = new Estimator;
        $options = $this->formatOptions();
        $profile = $this->profile();

        return $estimator->range($this->format, $estimator->structure($this->format, $this->capacity, $options), $profile, $options,
            $profile->isDaily() ? null : $this->stations, TournamentDeadlines::clockOf($this));
    }

    /**
     * An online tournament of a minute game has only a start; its end is
     * open (P18, user decision 2026-09-27). This is when it is expected to
     * end, and the latest if every deadline runs out: never a promise. Null
     * on site and in daily chess, which keep their planned duration.
     *
     * @return array{typical: CarbonImmutable, latest: CarbonImmutable}|null
     */
    public function expectedEnd(): ?array
    {
        if (! TournamentDeadlines::isSingleDay($this)) {
            return null;
        }

        $range = $this->durationRange();
        $start = $this->starts_at->toImmutable();

        return [
            'typical' => $start->addMinutes((int) ceil($range->typical)),
            'latest' => $start->addMinutes((int) ceil($range->latest)),
        ];
    }

    /**
     * The creator and the named directors enter results in director mode (P8b).
     */
    public function isDirectedBy(User $user): bool
    {
        return $this->created_by_id === $user->id
            || $this->directors()->whereKey($user->id)->exists();
    }

    /**
     * A draft is seen by its creator, its directors and admins only.
     */
    public function isVisibleTo(?User $user): bool
    {
        if ($this->status !== TournamentStatus::Draft) {
            return true;
        }

        return $user !== null && ($user->isAdmin() || $this->isDirectedBy($user));
    }
}
