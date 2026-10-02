<?php

namespace App\Models;

use App\Games\Blockfill;
use App\Games\TrackmaniaNationsForever;
use App\Support\Stacker\BlockfillWeeks;
use Database\Factories\LeagueWeekFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One week of a league game that opens its weeks by itself (Blockfill, TMNF),
 * as the admins plan it (user 2026-10-02: "kein automatisches weiter für die
 * Woche. Erst müssen die Admins ran können, um die Einstellungen zu ändern
 * und dann Freigabe."): a draft with the previous week's settings, then
 * approved by an admin, then started as the week's leaderboard
 * (`tournament_id`). Nothing of it is public before it started: players see
 * the tournament, never this row (App\Support\Scores\LeagueWeekDrafts).
 *
 * `settings` per game: Blockfill `{difficulty: <engine id>}`, the week's rules as their engine id
 * (App\Support\Stacker\BlockfillRules: bf1, t60e5g1s1c9, or a frozen id of the first weeks such as bf1hard),
 * TMNF `{track: <UId>, time_limit_minutes: int|null}` (null: the server's own limit of a round).
 *
 * @property int $id
 * @property string $game
 * @property Carbon $starts_at the planned start, Monday 00:00 Europe/Berlin
 * @property array<string, mixed> $settings
 * @property Carbon|null $approved_at
 * @property int|null $approved_by_id
 * @property int|null $tournament_id
 * @property Carbon|null $notified_at
 * @property Carbon|null $reminded_at
 * @property Carbon|null $track_ready_at
 * @property Carbon|null $warned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $approver
 * @property-read Tournament|null $tournament
 */
#[Fillable(['game', 'starts_at', 'settings'])]
class LeagueWeek extends Model
{
    /** @use HasFactory<LeagueWeekFactory> */
    use HasFactory;

    /** The games whose weeks the league opens by itself, and so the admins approve. */
    public const GAMES = [Blockfill::SLUG, TrackmaniaNationsForever::SLUG];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'settings' => 'array',
            'approved_at' => 'datetime',
            'notified_at' => 'datetime',
            'reminded_at' => 'datetime',
            'track_ready_at' => 'datetime',
            'warned_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    /**
     * @return BelongsTo<Tournament, $this>
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    public function hasStarted(): bool
    {
        return $this->tournament_id !== null;
    }

    /** Monday 00:00 Europe/Berlin after the planned start: the week ends there, whenever it started. */
    public function endsAt(): Carbon
    {
        return Carbon::instance(BlockfillWeeks::endOf($this->starts_at));
    }

    /** Its ISO week number in Berlin, "TMNF week 42". */
    public function weekNumber(): int
    {
        return (int) $this->starts_at->toImmutable()->setTimezone(BlockfillWeeks::TIMEZONE)->format('W');
    }
}
