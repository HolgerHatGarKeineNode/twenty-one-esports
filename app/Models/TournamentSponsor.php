<?php

namespace App\Models;

use App\Enums\IncomingPaymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A sponsor of a tournament's prize pool (P9, NIP "Prize pool funding"): a
 * name, the pledged amount and an optional logo. The pledge is a promise;
 * the pool counts only paid invoices, and the logo shows once one is paid.
 * Logos stay in the league's storage, never on Nostr.
 *
 * @property int $id
 * @property int $tournament_id
 * @property string $name
 * @property int $pledged_sats
 * @property string|null $logo_path on the public disk
 * @property int|null $created_by_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tournament $tournament
 */
#[Fillable(['tournament_id', 'name', 'pledged_sats', 'logo_path', 'created_by_id'])]
class TournamentSponsor extends Model
{
    protected function casts(): array
    {
        return ['pledged_sats' => 'integer'];
    }

    /**
     * @return BelongsTo<Tournament, $this>
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    /**
     * @return HasMany<IncomingPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(IncomingPayment::class, 'sponsor_id');
    }

    public function paidSats(): int
    {
        return (int) $this->payments()->where('status', IncomingPaymentStatus::Settled)->where('late', false)->sum('amount_sats');
    }

    public function isPaid(): bool
    {
        return $this->paidSats() > 0;
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path === null ? null : Storage::disk('public')->url($this->logo_path);
    }
}
