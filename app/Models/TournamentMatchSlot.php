<?php

namespace App\Models;

use App\Support\Tournaments\Engine\Slot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One side of a tournament match: `source` says where the participant comes
 * from (see App\Support\Tournaments\Engine\Slot), the participant is filled
 * in once it is known.
 *
 * @property int $id
 * @property int $tournament_match_id
 * @property int $slot
 * @property array{take: string, entrant?: int, match?: string, group?: int, rank?: int} $source
 * @property int|null $tournament_participant_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TournamentParticipant|null $participant
 */
#[Fillable(['tournament_match_id', 'slot', 'source', 'tournament_participant_id'])]
class TournamentMatchSlot extends Model
{
    protected function casts(): array
    {
        return ['slot' => 'integer', 'source' => 'array'];
    }

    /**
     * @return BelongsTo<TournamentParticipant, $this>
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(TournamentParticipant::class, 'tournament_participant_id');
    }

    public function sourceSlot(): Slot
    {
        return Slot::fromArray($this->source);
    }
}
