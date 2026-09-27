<?php

namespace App\Models;

use App\Enums\IncomingPaymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An invoice the league's wallet made for one pot (P9, NIP "Pots and zap
 * targets"): a zap to a tournament, an anonymous zap, a sponsor invoice, or a
 * plain LNURL payment (the reserve). The payment hash attributes it to
 * exactly one pot; a pot's total is the sum of its settled, not late rows.
 *
 * `source`: `zap` (signed by the payer), `anonymous` (a throwaway key the
 * league signed with), `sponsor` (the sponsor desk key), `lnurl` (no zap
 * request: the reserve). `late`: settled after the pot closed, so it
 * counts for the reserve instead (NIP "Counting the pool", check 5).
 *
 * @property int $id
 * @property string $pot `tournament:<id>` or `reserve`
 * @property int|null $tournament_id
 * @property int|null $sponsor_id
 * @property string $source
 * @property string $payment_hash
 * @property string $bolt11
 * @property int $amount_sats
 * @property string|null $zap_request the signed 9734, exactly as received
 * @property string|null $payer_pubkey
 * @property string|null $comment
 * @property IncomingPaymentStatus $status
 * @property Carbon $expires_at
 * @property Carbon|null $settled_at
 * @property string|null $preimage
 * @property bool $late
 * @property int|null $receipt_event_id the 9735
 * @property Carbon|null $checked_at last lookup at the wallet
 * @property int|null $requester_user_id the logged-in user who asked for the invoice
 * @property string|null $requester_ip_hash HMAC of the requester's IP (never the IP itself)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tournament|null $tournament
 * @property-read TournamentSponsor|null $sponsor
 * @property-read NostrEvent|null $receipt
 */
#[Fillable(['pot', 'tournament_id', 'sponsor_id', 'source', 'payment_hash', 'bolt11', 'amount_sats', 'zap_request', 'payer_pubkey', 'comment',
    'status', 'expires_at', 'settled_at', 'preimage', 'late', 'receipt_event_id', 'checked_at', 'requester_user_id', 'requester_ip_hash'])]
class IncomingPayment extends Model
{
    public const RESERVE = 'reserve';

    protected function casts(): array
    {
        return [
            'amount_sats' => 'integer',
            'status' => IncomingPaymentStatus::class,
            'expires_at' => 'datetime',
            'settled_at' => 'datetime',
            'late' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }

    public static function tournamentPot(int $tournamentId): string
    {
        return 'tournament:'.$tournamentId;
    }

    /**
     * @return BelongsTo<Tournament, $this>
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    /**
     * @return BelongsTo<TournamentSponsor, $this>
     */
    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(TournamentSponsor::class, 'sponsor_id');
    }

    /**
     * @return BelongsTo<NostrEvent, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(NostrEvent::class, 'receipt_event_id');
    }
}
