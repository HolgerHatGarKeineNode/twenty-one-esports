<?php

namespace App\Models;

use App\Enums\LineupRole;
use App\Games\GameMode;
use App\Games\GameRegistry;
use Database\Factories\LineupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The team a clan fields for one game and mode (NIP kind 32151,
 * `d` = `<clan slug>/<game>/<mode>`).
 *
 * The league signs lineups with the clan owner's key only, so the lineup
 * address is known before the lineup is first published and stays stable;
 * the NIP also allows other captains, whose events would carry a different
 * address (reported as a NIP gap in P4). `event_id` is null while the lineup
 * lists fewer players than the mode needs: such a lineup is not valid on
 * Nostr yet (rule 8) and is kept in the database only.
 *
 * @property int $id
 * @property int $clan_id
 * @property string $game
 * @property string $mode
 * @property string|null $event_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Clan $clan
 */
#[Fillable(['clan_id', 'game', 'mode', 'event_id'])]
class Lineup extends Model
{
    /** @use HasFactory<LineupFactory> */
    use HasFactory;

    public const KIND = 32151;

    /**
     * @return BelongsTo<Clan, $this>
     */
    public function clan(): BelongsTo
    {
        return $this->belongsTo(Clan::class);
    }

    /**
     * @return HasMany<LineupSeat, $this>
     */
    public function seats(): HasMany
    {
        return $this->hasMany(LineupSeat::class);
    }

    public function d(): string
    {
        return $this->clan->slug.'/'.$this->game.'/'.$this->mode;
    }

    public function address(): string
    {
        return self::KIND.':'.$this->clan->owner_pubkey.':'.$this->d();
    }

    public function gameMode(): GameMode
    {
        $mode = app(GameRegistry::class)->mode($this->game, $this->mode);

        return $mode ?? throw new \LogicException("Lineup {$this->id} has an unknown mode.");
    }

    /**
     * Seats that count towards the minimum (captain, player), accepted or not.
     */
    public function listedCount(): int
    {
        return $this->seats->filter(fn (LineupSeat $seat) => $seat->role->countsTowardsMinimum())->count();
    }

    /**
     * Accepted captain and player seats of players who are still in this clan.
     */
    public function activeCount(): int
    {
        return $this->seats->filter(fn (LineupSeat $seat) => $seat->role->countsTowardsMinimum() && $seat->isActive($this->clan_id))->count();
    }

    /**
     * Enough active players to take a challenge.
     */
    public function isReady(): bool
    {
        return $this->activeCount() >= $this->gameMode()->lineupMinimum();
    }

    /**
     * An acting captain of this lineup (NIP "Terminology"): still a member of
     * the clan, and the lineup's author (the clan owner, who signs lineups in
     * this league) or seated as its captain.
     */
    public function isActingCaptain(?User $user): bool
    {
        if ($user === null || $user->clanMember?->clan_id !== $this->clan_id) {
            return false;
        }

        if ($this->clan->owner_id === $user->id) {
            return true;
        }

        $seat = $this->seats->firstWhere('user_id', $user->id);

        return $seat !== null && $seat->role === LineupRole::Captain && $seat->accepted_at !== null;
    }

    /**
     * Accepted seat of a player who is still in the clan (captain, player or sub).
     */
    public function activeSeatOf(?User $user): ?LineupSeat
    {
        if ($user === null) {
            return null;
        }

        $seat = $this->seats->firstWhere('user_id', $user->id);

        return $seat !== null && $seat->isActive($this->clan_id) ? $seat : null;
    }

    /**
     * Active seats, captain and players first, subs last, in seat order.
     *
     * @return list<LineupSeat>
     */
    public function activeSeats(): array
    {
        return array_values($this->seats
            ->filter(fn (LineupSeat $seat) => $seat->isActive($this->clan_id))
            ->sortBy(fn (LineupSeat $seat) => [$seat->role === LineupRole::Substitute ? 1 : 0, $seat->id])
            ->all());
    }
}
