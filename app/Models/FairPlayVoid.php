<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One result a link voided (P41): a series or a chess game between two
 * accounts of the same person, what it was before, and the Elo the revert
 * took back (null when it moved no Elo that could still be corrected).
 * Unlinking does not bring it back.
 *
 * @property int $id
 * @property int $account_link_id
 * @property 'series'|'chess' $source
 * @property int $source_id
 * @property int|null $match_number
 * @property array<string, mixed> $previous
 * @property array{reverted: array{0: int, 1: int}, applied: array{0: int, 1: int}|null}|null $elo
 * @property Carbon|null $created_at
 * @property-read AccountLink $link
 */
#[Fillable(['account_link_id', 'source', 'source_id', 'match_number', 'previous', 'elo'])]
class FairPlayVoid extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'previous' => 'array',
            'elo' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AccountLink, $this>
     */
    public function link(): BelongsTo
    {
        return $this->belongsTo(AccountLink::class, 'account_link_id');
    }
}
