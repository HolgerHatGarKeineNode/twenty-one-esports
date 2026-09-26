<?php

namespace App\Models;

use App\Support\SeasonChain\ParameterChange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One Parameter Change (`2158`) of the public change log: in force for every
 * attestation from `effective_at` on, never before.
 *
 * @property int $id
 * @property int $season_id
 * @property Carbon $signed_at
 * @property Carbon $effective_at
 * @property int|null $changed_by_id
 * @property string $changed_by_pubkey
 * @property string $reason
 * @property array{weights?: array<string, int>, shares?: array<string, int>, daily?: array<string, int>, pairlimit?: array{0: int, 1: int}|null, subtree?: int|null, moves?: int|null} $parameters the changed parameters only
 * @property string $tip_event_id
 * @property int|null $nostr_event_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Season $season
 * @property-read User|null $changedBy
 * @property-read NostrEvent|null $nostrEvent
 */
#[Fillable(['season_id', 'signed_at', 'effective_at', 'changed_by_id', 'changed_by_pubkey', 'reason', 'parameters', 'tip_event_id', 'nostr_event_id'])]
class SeasonParameterChange extends Model
{
    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
            'effective_at' => 'datetime',
            'parameters' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Season, $this>
     */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_id');
    }

    /**
     * @return BelongsTo<NostrEvent, $this>
     */
    public function nostrEvent(): BelongsTo
    {
        return $this->belongsTo(NostrEvent::class);
    }

    public function toChange(): ParameterChange
    {
        $c = $this->parameters;

        return new ParameterChange(
            CarbonImmutable::instance($this->signed_at),
            CarbonImmutable::instance($this->effective_at),
            $this->changed_by_pubkey,
            $this->reason,
            $c['weights'] ?? [],
            $c['shares'] ?? [],
            $c['daily'] ?? [],
            $c['pairlimit'] ?? null,
            $c['subtree'] ?? null,
            $c['moves'] ?? null,
        );
    }
}
