<?php

namespace App\Models;

use App\Enums\PayoutStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One player's prize from one tournament (P9, NIP "Payout", rule 28: at most
 * one per player and tournament). Written when an admin approves the
 * payouts, then only moved by App\Support\Payouts\PayoutRunner.
 *
 * `idempotency_key` is fixed per (tournament, recipient, place) and unique:
 * approving twice cannot create a second row. `lud16` is the Lightning
 * address the admin approved (at the check, or later for an open payout),
 * and the only one ever paid: a profile change after that sets the payout
 * back to `open` (`lud16_changed`) until an admin approves the new address. `bolt11` and
 * `payment_hash` are kept from the first invoice on: a retry pays the same
 * invoice or looks it up, never a second invoice while the first may be
 * paid. `reason` explains `open`, `failed` and an unconfirmed `paying`.
 *
 * @property int $id
 * @property int $tournament_id
 * @property int|null $user_id
 * @property int|null $participant_id
 * @property string $pubkey
 * @property string $name
 * @property int $place
 * @property int $amount_sats
 * @property string $idempotency_key
 * @property string|null $lud16
 * @property PayoutStatus $status
 * @property string|null $reason
 * @property string|null $bolt11
 * @property string|null $payment_hash
 * @property Carbon|null $invoice_expires_at
 * @property string|null $preimage
 * @property int|null $fees_msats
 * @property int $attempts
 * @property Carbon|null $lease_until
 * @property string|null $lease_owner
 * @property Carbon|null $last_attempt_at
 * @property Carbon|null $paid_at
 * @property int|null $event_id the league's 2157
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tournament $tournament
 * @property-read User|null $user
 * @property-read TournamentParticipant|null $participant
 * @property-read NostrEvent|null $event
 */
#[Fillable(['tournament_id', 'user_id', 'participant_id', 'pubkey', 'name', 'place', 'amount_sats', 'idempotency_key', 'lud16', 'status', 'reason'])]
class TournamentPayout extends Model
{
    protected function casts(): array
    {
        return [
            'place' => 'integer',
            'amount_sats' => 'integer',
            'status' => PayoutStatus::class,
            'invoice_expires_at' => 'datetime',
            'fees_msats' => 'integer',
            'attempts' => 'integer',
            'lease_until' => 'datetime',
            'last_attempt_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * The fixed key of a payout: one per tournament, recipient and place.
     */
    public static function keyFor(int $tournamentId, string $pubkey, int $place): string
    {
        return hash('sha256', 'esports-payout-v1|'.$tournamentId.'|'.$pubkey.'|'.$place);
    }

    /**
     * The Payout (2157) of a tournament settlement (NIP "Payout"): the
     * tournament, the player, the invoice and its preimage.
     *
     * @return list<list<string>>
     */
    public function payoutTags(string $preimage, string $relay): array
    {
        $tournament = $this->tournament;

        return [
            ['a', (string) $tournament->address(), $relay],
            ['p', $this->pubkey],
            ['bolt11', (string) $this->bolt11],
            ['preimage', $preimage],
            ['alt', 'Esports payout: '.$tournament->name.', place '.$this->place.', '.$this->amount_sats.' sats'],
        ];
    }

    /**
     * What the pages say about a payout that is not simply paid.
     */
    public function reasonText(): ?string
    {
        if ($this->reason === null) {
            return null;
        }

        return match ($this->reason) {
            'no_lud16' => __('No Lightning address in the player’s Nostr profile yet. The prize waits until they add one.'),
            'lud16_changed' => __('Lightning address changed since approval. An admin approves the new one before it is paid.'),
            'account_deleted' => __('The account was deleted. The prize stays in the pool.'),
            'linked_account' => __('Withheld: an admin linked this account to another account of the same player. Only the main account wins prizes; an admin reviews it.'),
            'lnurl_unreachable' => __('The Lightning address did not answer.'),
            'lnurl_invalid' => __('The Lightning address is not a valid LNURL-pay endpoint.'),
            'amount_out_of_range' => __('The Lightning address does not accept this amount.'),
            'invoice_mismatch' => __('The Lightning address returned an invoice that does not match (amount, description or network).'),
            'invoice_expired' => __('The invoice expired before it was paid.'),
            'wallet_error' => __('The league wallet refused the payment.'),
            'insufficient_balance' => __('The league wallet does not hold enough sats.'),
            'balance_unread' => __('The league wallet did not tell its balance, so nothing was sent. It is tried again.'),
            'wallet_busy' => __('Another payment from the league wallet was under way, so nothing was sent. It is tried again.'),
            'budget_exceeded' => __('The league wallet’s budget for payouts is used up.'),
            'unconfirmed' => __('The wallet has not confirmed the payment yet. It is checked again before anything else happens.'),
            'needs_check' => __('The outcome is unknown and the invoice has expired. Check the wallet, then release the payout if it was not paid.'),
            'released' => __('Released after a manual check of the wallet: not paid.'),
            default => __('The payment did not go through.'),
        };
    }

    /**
     * @return BelongsTo<Tournament, $this>
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<TournamentParticipant, $this>
     */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(TournamentParticipant::class);
    }

    /**
     * @return BelongsTo<NostrEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(NostrEvent::class, 'event_id');
    }
}
