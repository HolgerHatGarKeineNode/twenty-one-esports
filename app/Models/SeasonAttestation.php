<?php

namespace App\Models;

use App\Support\SeasonChain\Candidate;
use App\Support\SeasonChain\ConsensusRule;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One league attestation (`2154`) of a rated result in a chain season, in
 * attestation order (id). A candidate that mines has a `height`; one that
 * does not names the first rule it failed.
 *
 * @property int $id
 * @property int $season_id
 * @property string $source `series` or `chess`
 * @property int $source_id
 * @property int $board
 * @property int|null $match_number
 * @property string $label
 * @property string $game
 * @property string $mode
 * @property string $ladder_address
 * @property Carbon $attested_at
 * @property array<string, mixed>|null $candidate
 * @property int|null $height
 * @property int|null $rule
 * @property string|null $reason
 * @property string|null $subject
 * @property int|null $era
 * @property int $reward_per_player
 * @property int $reward
 * @property string|null $link_event_id
 * @property string $event_id
 * @property int|null $nostr_event_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Season $season
 * @property-read NostrEvent|null $nostrEvent
 */
#[Fillable([
    'season_id', 'source', 'source_id', 'board', 'match_number', 'label', 'game', 'mode', 'ladder_address', 'attested_at',
    'candidate', 'height', 'rule', 'reason', 'subject', 'era', 'reward_per_player', 'reward', 'link_event_id', 'event_id', 'nostr_event_id',
])]
class SeasonAttestation extends Model
{
    public const SERIES = 'series';

    public const CHESS = 'chess';

    protected function casts(): array
    {
        return [
            'attested_at' => 'datetime',
            'candidate' => 'array',
            'height' => 'integer',
            'rule' => 'integer',
            'era' => 'integer',
            'reward_per_player' => 'integer',
            'reward' => 'integer',
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
     * @return BelongsTo<NostrEvent, $this>
     */
    public function nostrEvent(): BelongsTo
    {
        return $this->belongsTo(NostrEvent::class);
    }

    public function mines(): bool
    {
        return $this->height !== null;
    }

    public function isCandidate(): bool
    {
        return $this->candidate !== null;
    }

    public function toCandidate(): ?Candidate
    {
        return $this->candidate === null ? null : Candidate::fromArray($this->candidate);
    }

    public function consensusRule(): ?ConsensusRule
    {
        return $this->rule === null ? null : ConsensusRule::tryFrom($this->rule);
    }

    /**
     * The winning players of the block (pubkeys).
     *
     * @return list<string>
     */
    public function winners(): array
    {
        return array_values(array_map(strval(...), (array) ($this->candidate['winners'] ?? [])));
    }
}
