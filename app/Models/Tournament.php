<?php

namespace App\Models;

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Support\Nostr\NostrKeys;
use App\Support\Tournaments\Estimator;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use Database\Factories\TournamentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property string|null $slug `d` of the tournament's NIP-52 calendar event (31923)
 * @property Carbon|null $signup_closes_at
 * @property Carbon|null $published_at
 * @property int|null $event_id the league's 31923
 * @property int|null $draw_height the Bitcoin block the draw committed to (NIP 2155 `draw`)
 * @property string|null $draw_hash its hash once mined: the draw and bracket seed
 * @property int|null $draw_event_id the league's 2155 (only with a solo pool)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 * @property-read Collection<int, User> $directors
 * @property-read Collection<int, TournamentParticipant> $participants
 * @property-read Collection<int, TournamentStage> $stages
 * @property-read Collection<int, TournamentMatch> $matches
 * @property-read Collection<int, TournamentSignup> $signups
 * @property-read NostrEvent|null $event
 * @property-read NostrEvent|null $drawEvent
 */
#[Fillable(['name', 'game', 'mode', 'format', 'options', 'capacity', 'starts_at', 'time_window', 'on_site', 'stations', 'times', 'results_mode', 'status', 'seed', 'created_by_id',
    'slug', 'signup_closes_at', 'published_at', 'event_id', 'draw_height', 'draw_hash', 'draw_event_id'])]
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

    protected function casts(): array
    {
        return [
            'format' => TournamentFormat::class,
            'options' => 'array',
            'capacity' => 'integer',
            'starts_at' => 'datetime',
            'time_window' => 'integer',
            'on_site' => 'boolean',
            'stations' => 'integer',
            'times' => 'array',
            'results_mode' => TournamentResultsMode::class,
            'status' => TournamentStatus::class,
            'signup_closes_at' => 'datetime',
            'published_at' => 'datetime',
            'draw_height' => 'integer',
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
     * The director log, newest first.
     *
     * @return HasMany<TournamentResultEntry, $this>
     */
    public function resultEntries(): HasMany
    {
        return $this->hasMany(TournamentResultEntry::class)->orderByDesc('id');
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
     * Players per team: the mode's team size (1 for chess and RL 1v1).
     */
    public function teamSize(): int
    {
        return $this->profile()->entersTeams() ? (int) app(GameRegistry::class)->mode($this->game, $this->mode)?->teamSize : 1;
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

        return GameProfile::for($this->game, $this->mode)->withTimes($times['game'] ?? null, $times['setup'] ?? null, $times['break'] ?? null);
    }

    public function formatOptions(): FormatOptions
    {
        return FormatOptions::fromArray($this->options, $this->profile());
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
