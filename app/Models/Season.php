<?php

namespace App\Models;

use App\Support\SeasonChain\ConsensusParameters;
use App\Support\SeasonChain\SeasonParameters;
use Carbon\CarbonImmutable;
use Database\Factories\SeasonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A released chain season (NIP "Season Genesis"), written once at Block 0.
 * The rows of App\Support\SeasonChain\SeasonChains are the only writers.
 *
 * @property int $id
 * @property string $slug
 * @property string $league_pubkey
 * @property int $supply
 * @property int $subsidy
 * @property int $halving_seconds
 * @property int $claim_seconds
 * @property int $minimum_trust
 * @property array{weights: array<string, int>, shares: array<string, int>, daily: array<string, int>, pairlimit: array{0: int, 1: int}, subtree: int, moves: int} $parameters
 * @property string $genesis_message
 * @property string $digest
 * @property Carbon $genesis_at
 * @property Carbon $ends_at
 * @property int|null $genesis_event_id
 * @property int|null $release_event_id
 * @property int|null $admin_list_event_id
 * @property int|null $announcement_event_id
 * @property int|null $released_by_id
 * @property string $released_by_pubkey
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read NostrEvent|null $genesisEvent
 * @property-read User|null $releasedBy
 */
#[Fillable([
    'slug', 'league_pubkey', 'supply', 'subsidy', 'halving_seconds', 'claim_seconds', 'minimum_trust', 'parameters',
    'genesis_message', 'digest', 'genesis_at', 'ends_at', 'genesis_event_id', 'release_event_id', 'admin_list_event_id',
    'announcement_event_id', 'released_by_id', 'released_by_pubkey',
])]
class Season extends Model
{
    /** @use HasFactory<SeasonFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'supply' => 'integer',
            'subsidy' => 'integer',
            'halving_seconds' => 'integer',
            'claim_seconds' => 'integer',
            'minimum_trust' => 'integer',
            'parameters' => 'array',
            'genesis_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<NostrEvent, $this>
     */
    public function genesisEvent(): BelongsTo
    {
        return $this->belongsTo(NostrEvent::class, 'genesis_event_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_id');
    }

    /**
     * @return HasMany<SeasonParameterChange, $this>
     */
    public function parameterChanges(): HasMany
    {
        return $this->hasMany(SeasonParameterChange::class);
    }

    /**
     * @return HasMany<SeasonAttestation, $this>
     */
    public function attestations(): HasMany
    {
        return $this->hasMany(SeasonAttestation::class);
    }

    /** Block 0 <= $at < ends. */
    public function isLiveAt(CarbonImmutable $at): bool
    {
        return $at->getTimestamp() >= $this->genesis_at->getTimestamp() && $at->getTimestamp() < $this->ends_at->getTimestamp();
    }

    /** The genesis event id (hex): what block 1 names. */
    public function genesisId(): string
    {
        return (string) $this->genesisEvent?->event_id;
    }

    /**
     * The chain parameters of the engine: the genesis and every logged change,
     * in the order they apply.
     */
    public function chainParameters(): SeasonParameters
    {
        $p = $this->parameters;

        $season = new SeasonParameters(
            $this->slug,
            CarbonImmutable::instance($this->genesis_at),
            CarbonImmutable::instance($this->ends_at),
            $this->supply,
            $this->subsidy,
            $this->halving_seconds,
            new ConsensusParameters($p['weights'], $p['shares'], $p['daily'], $p['pairlimit'][0], $p['pairlimit'][1], $p['subtree'], $p['moves']),
            $this->claim_seconds,
        );

        foreach ($this->parameterChanges()->orderBy('effective_at')->orderBy('signed_at')->orderBy('id')->get() as $change) {
            $season->addChange($change->toChange());
        }

        return $season;
    }
}
