<?php

namespace App\Models;

use App\Enums\PayoutStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One player's season settlement (P37, NIP "Payout": one payout per player
 * and season): the rewards of their blocks the review did not void, paid
 * from the league wallet's paying connection. Written when an admin
 * approves the settlement list (App\Support\SeasonChain\SeasonSettlement),
 * then only moved by App\Support\Payouts\PayoutRunner, the same state
 * machine and guards as a tournament payout ({@see TournamentPayout}).
 *
 * `idempotency_key` is fixed per (season, player) and unique. `heights`
 * are the blocks it pays (an `e` each in the 2157). `lud16` is the address
 * an admin approved and the only one ever paid; the profile's address
 * never shows as text on a page.
 *
 * @property int $id
 * @property int $season_id
 * @property int|null $user_id
 * @property string $pubkey
 * @property string $name
 * @property int $blocks
 * @property list<int> $heights
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
 * @property-read Season $season
 * @property-read User|null $user
 * @property-read NostrEvent|null $event
 */
#[Fillable(['season_id', 'user_id', 'pubkey', 'name', 'blocks', 'heights', 'amount_sats', 'idempotency_key', 'lud16', 'status', 'reason'])]
class SeasonPayout extends Model
{
    protected function casts(): array
    {
        return [
            'blocks' => 'integer',
            'heights' => 'array',
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
     * The fixed key of a season payout: one per season and player.
     */
    public static function keyFor(int $seasonId, string $pubkey): string
    {
        return hash('sha256', 'esports-season-payout-v1|'.$seasonId.'|'.$pubkey);
    }

    /**
     * The Payout (2157) of a season settlement (NIP "Payout"): the genesis,
     * the player, one `e` per block paid, the invoice and its preimage.
     *
     * @return list<list<string>>
     */
    public function payoutTags(string $preimage, string $relay): array
    {
        $season = $this->season;
        $tags = [['e', $season->genesisId(), $relay], ['p', $this->pubkey]];

        foreach (SeasonAttestation::query()->where('season_id', $season->id)->whereIn('height', $this->heights)->orderBy('height')->pluck('event_id') as $id) {
            $tags[] = ['e', (string) $id, $relay];
        }

        $tags[] = ['bolt11', (string) $this->bolt11];
        $tags[] = ['preimage', $preimage];
        $tags[] = ['alt', 'Esports payout: '.$season->slug.' settlement for '.$this->name.', '.$this->amount_sats.' sats'];

        return $tags;
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
            'no_lud16' => __('No Lightning address in the player’s Nostr profile yet. The sats wait for the claim window.'),
            'lud16_changed' => __('Lightning address changed since approval. An admin approves the new one before it is paid.'),
            'lud16_frozen' => __('Lightning address changed less than 72 hours ago. An admin can approve it once the 72 hours have passed.'),
            'account_deleted' => __('The account was deleted. The sats go back to the league reserve.'),
            'linked_account' => __('Withheld: an admin linked this account to another account of the same player. Only the main account wins prizes; an admin reviews it.'),
            'lnurl_unreachable' => __('The Lightning address did not answer.'),
            'lnurl_invalid' => __('The Lightning address is not a valid LNURL-pay endpoint.'),
            'amount_out_of_range' => __('The Lightning address does not accept this amount.'),
            'invoice_mismatch' => __('The Lightning address returned an invoice that does not match (amount, description or network).'),
            'invoice_expired' => __('The invoice expired before it was paid.'),
            'insufficient_balance' => __('The payout wallet does not hold enough sats. Top up the payout wallet, then retry.'),
            'wallet_busy' => __('Another payment from the league wallet was under way, so nothing was sent. It is tried again.'),
            'budget_exceeded' => __('The payout wallet’s budget for payouts is used up. Raise it or top up the payout wallet, then retry.'),
            'wallet_error' => __('The payout wallet refused the payment. Top up the payout wallet if it is low, then retry.'),
            'unconfirmed' => __('The wallet has not confirmed the payment yet. It is checked again before anything else happens.'),
            'needs_check' => __('The outcome is unknown and the invoice has expired. Check the wallet, then release the payout if it was not paid.'),
            'released' => __('Released after a manual check of the wallet: not paid.'),
            default => __('The payment did not go through.'),
        };
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<NostrEvent, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(NostrEvent::class, 'event_id');
    }
}
