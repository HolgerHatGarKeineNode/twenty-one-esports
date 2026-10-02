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
 * the pool counts only what was paid, and the logo shows once something is:
 * a paid invoice of the pot's own wallet, or sats an organizer or admin
 * marked as paid outside it (user, 2026-10-02: "Rechnung wurde anders
 * gezahlt"). Those count toward the pot like an invoice but are not in the
 * wallet, so a payout never takes them from it ({@see paidOutsideSats()}).
 * Logos stay in the league's storage, never on Nostr.
 *
 * @property int $id
 * @property int $tournament_id
 * @property string $name
 * @property int $pledged_sats
 * @property string|null $logo_path on the public disk
 * @property int|null $created_by_id
 * @property int|null $paid_outside_sats marked as paid outside the pot's wallet
 * @property string|null $paid_outside_note
 * @property int|null $paid_outside_by_id who marked it
 * @property Carbon|null $paid_outside_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tournament $tournament
 * @property-read User|null $paidOutsideBy
 */
#[Fillable(['tournament_id', 'name', 'pledged_sats', 'logo_path', 'created_by_id', 'paid_outside_sats', 'paid_outside_note', 'paid_outside_by_id', 'paid_outside_at'])]
class TournamentSponsor extends Model
{
    protected function casts(): array
    {
        return ['pledged_sats' => 'integer', 'paid_outside_sats' => 'integer', 'paid_outside_at' => 'datetime'];
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function paidOutsideBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_outside_by_id');
    }

    /** What the sponsor paid toward the pot: invoices of its wallet and sats paid outside it. */
    public function paidSats(): int
    {
        return $this->paidInWalletSats() + $this->paidOutsideSats();
    }

    /** Paid invoices of the pot's own wallet (settled before the pot closed). */
    public function paidInWalletSats(): int
    {
        return (int) $this->payments()->where('status', IncomingPaymentStatus::Settled)->where('late', false)->sum('amount_sats');
    }

    /** Marked as paid outside the pot's wallet: counts toward the pot, never in the wallet. */
    public function paidOutsideSats(): int
    {
        return (int) $this->paid_outside_sats;
    }

    /** What is still open of the pledge (never below 0). */
    public function openSats(): int
    {
        return max(0, $this->pledged_sats - $this->paidSats());
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
