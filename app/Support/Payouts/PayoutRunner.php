<?php

namespace App\Support\Payouts;

use App\Enums\PayoutStatus;
use App\Models\SeasonPayout;
use App\Models\TournamentPayout;
use App\Models\User;
use App\Support\FairPlay\AccountLinks;
use App\Support\FairPlay\FairPlay;
use App\Support\Lightning\Bolt11;
use App\Support\Lightning\LightningAddress;
use App\Support\Lightning\LightningAddressFailure;
use App\Support\SeasonChain\LeagueKey;
use App\Support\SeasonChain\SeasonSettlement;
use App\Support\Wallet\Ledger;
use App\Support\Wallet\NwcError;
use App\Support\Wallet\PayingWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pays one payout, exactly once, to the player's Lightning address: a
 * tournament payout (P9) from the tournament pot's own NWC wallet, never
 * from the league wallet, and nothing is booked in the league ledger; a
 * season payout (P37) from the league wallet's paying connection
 * (`esports.wallet.nwc_uri`), booked out of the reserve once it is paid.
 * The only holder of a {@see PayingWallet}. There is no balance check
 * before a payment (user, 2026-09-28): a wallet that cannot pay fails the
 * payout with its reason, and an admin retries after a top-up.
 *
 * Three guards, each enough against a double click or a retried job:
 *
 * 1. A lease: a compare-and-set on `lease_until`. While one attempt holds
 *    it, every other returns at once. A crashed attempt's lease runs out and
 *    the reconciliation continues its work.
 * 2. The state machine, also by compare-and-set: only `pending` or `failed`
 *    become `paying`, and only `paying` becomes `paid`, so one payout has
 *    one success and one Payout event (2157).
 * 3. One invoice: once an invoice is stored, nothing pays another while the
 *    first might still be paid. A later attempt first asks the wallet for
 *    the stored payment hash (`lookup_invoice`): settled means paid; pending
 *    or no answer means wait; unknown or failed pays the SAME invoice again,
 *    which Lightning can settle only once. A new invoice replaces an expired
 *    one only after the wallet refused the payment outright; after an
 *    unanswered attempt an expired invoice stays until an admin checks the
 *    wallet and releases it.
 *
 * Fail closed: without the paying connection or the league key (the 2157
 * needs it) no attempt starts and nothing changes.
 *
 * The prize goes to the Lightning address the admin approved, never to the
 * one the profile shows now (security gate F3). If the profile's address
 * changed since, nothing is fetched: the payout goes back to `open` with
 * reason `lud16_changed` until an admin approves the new address
 * ({@see PayoutApproval::approveAddress()},
 * {@see SeasonSettlement::approveAddress()}). An `open` payout is never
 * paid. A season payout also waits while the profile's address changed less
 * than 72 h ago (`lud16_frozen`).
 */
final class PayoutRunner
{
    /** Reasons of a `failed` payout after which the wallet refused outright (a new invoice is safe). */
    private const REFUSALS = ['wallet_error', 'insufficient_balance', 'budget_exceeded'];

    public function __construct(private LightningAddress $addresses) {}

    /**
     * Work on a payout: with `$start` an admin asked to pay (a `pending` or
     * `failed` payout is claimed), without it only an unfinished `paying`
     * one is continued (the reconciliation).
     */
    public function run(TournamentPayout|SeasonPayout $payout, bool $start): void
    {
        $wallet = self::wallet($payout);

        if ($wallet === null || LeagueKey::fromConfig() === null) {
            return;
        }

        $owner = Str::random(24);

        if (! $this->lease($payout, $owner)) {
            return;
        }

        try {
            $payout->refresh();

            $refused = false;

            // A linked second account wins nothing (P41): withheld, never started, never re-routed.
            if ($start && $payout->status->isPayable() && FairPlay::isLinked($payout->pubkey)) {
                $payout::query()->whereKey($payout->id)->whereIn('status', [PayoutStatus::Pending, PayoutStatus::Failed])
                    ->update(['status' => PayoutStatus::Open, 'reason' => AccountLinks::WITHHELD]);

                return;
            }

            if ($start && $payout->status->isPayable()) {
                // The wallet refused the last attempt outright: an expired invoice may be replaced.
                $refused = $payout->status === PayoutStatus::Failed && in_array($payout->reason, self::REFUSALS, true);
                $claimed = $payout::query()->whereKey($payout->id)->whereIn('status', [PayoutStatus::Pending, PayoutStatus::Failed])
                    ->update(['status' => PayoutStatus::Paying, 'attempts' => $payout->attempts + 1, 'last_attempt_at' => now(), 'reason' => null]);

                if ($claimed !== 1) {
                    return;
                }

                $payout->refresh();
            }

            if ($payout->status === PayoutStatus::Paying) {
                $this->proceed($payout, $wallet, $refused);
            }
        } finally {
            $payout::query()->whereKey($payout->id)->where('lease_owner', $owner)->update(['lease_until' => null, 'lease_owner' => null]);
        }
    }

    /**
     * An admin checked the wallet: the unconfirmed payment did not happen.
     * The wallet is asked once more; if it knows the payment as settled, the
     * payout is paid instead. Else the invoice is dropped and the payout is
     * `failed` (retryable with a new invoice).
     */
    public function release(TournamentPayout|SeasonPayout $payout): void
    {
        $wallet = self::wallet($payout);
        $owner = Str::random(24);

        if ($wallet === null || ! $this->lease($payout, $owner)) {
            return;
        }

        try {
            $payout->refresh();

            if ($payout->status !== PayoutStatus::Paying && $payout->status !== PayoutStatus::Failed) {
                return;
            }

            if ($payout->payment_hash !== null) {
                try {
                    $known = $wallet->lookup($payout->payment_hash);
                } catch (NwcError) {
                    return;
                }

                if ($known !== null && $known->isSettled()) {
                    $this->markPaid($payout, (string) $known->preimage, $known->feesMsats);

                    return;
                }

                if ($known !== null && $known->state === 'pending') {
                    return;
                }
            }

            $payout::query()->whereKey($payout->id)->whereIn('status', [PayoutStatus::Paying, PayoutStatus::Failed])->update([
                'status' => PayoutStatus::Failed, 'reason' => 'released', 'bolt11' => null, 'payment_hash' => null, 'invoice_expires_at' => null,
            ]);
        } finally {
            $payout::query()->whereKey($payout->id)->where('lease_owner', $owner)->update(['lease_until' => null, 'lease_owner' => null]);
        }
    }

    /**
     * The wallet a payout is paid from: a tournament's from its pot's own
     * wallet (never the league's), a season's from the league wallet's
     * paying connection (never a pot's). Null without one (fail closed).
     */
    private static function wallet(TournamentPayout|SeasonPayout $payout): ?PayingWallet
    {
        return $payout instanceof SeasonPayout ? PayingWallet::fromConfig() : PayingWallet::forTournament($payout->tournament);
    }

    /** `Tournament` or `Season`, for the log. */
    private static function kind(TournamentPayout|SeasonPayout $payout): string
    {
        return $payout instanceof SeasonPayout ? 'Season' : 'Tournament';
    }

    private function lease(TournamentPayout|SeasonPayout $payout, string $owner): bool
    {
        return $payout::query()->whereKey($payout->id)
            ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<', now()))
            ->update(['lease_until' => now()->addSeconds((int) config('esports.wallet.payout_lease_seconds', 180)), 'lease_owner' => $owner]) === 1;
    }

    private function proceed(TournamentPayout|SeasonPayout $payout, PayingWallet $wallet, bool $refused): void
    {
        if ($payout->bolt11 !== null && $payout->payment_hash !== null) {
            try {
                $known = $wallet->lookup($payout->payment_hash);
            } catch (NwcError) {
                $this->note($payout, 'unconfirmed');

                return;
            }

            if ($known !== null && $known->isSettled()) {
                $this->markPaid($payout, (string) $known->preimage, $known->feesMsats);

                return;
            }

            if ($known !== null && $known->state === 'pending') {
                $this->note($payout, 'unconfirmed');

                return;
            }

            $expired = $payout->invoice_expires_at === null || $payout->invoice_expires_at->getTimestamp() <= now()->getTimestamp() + 30;

            if (! $expired) {
                $this->pay($payout, $wallet, $payout->bolt11);

                return;
            }

            // Expired and not paid as far as the wallet knows. A new invoice only after the
            // wallet refused the last attempt outright (the payout was `failed` before this claim).
            if ($refused) {
                $payout::query()->whereKey($payout->id)->where('status', PayoutStatus::Paying)
                    ->update(['bolt11' => null, 'payment_hash' => null, 'invoice_expires_at' => null]);
                $payout->refresh();
                $this->newInvoiceAndPay($payout, $wallet);

                return;
            }

            $this->note($payout, 'needs_check');

            return;
        }

        $this->newInvoiceAndPay($payout, $wallet);
    }

    private function newInvoiceAndPay(TournamentPayout|SeasonPayout $payout, PayingWallet $wallet): void
    {
        // The approved address, never the profile's current one (F3).
        $lud16 = $payout->lud16;

        if ($lud16 === null || LightningAddress::target($lud16) === null) {
            $payout::query()->whereKey($payout->id)->where('status', PayoutStatus::Paying)
                ->update(['status' => PayoutStatus::Open, 'reason' => $payout->user_id === null ? 'account_deleted' : 'no_lud16', 'bolt11' => null, 'payment_hash' => null]);

            return;
        }

        if (self::addressChanged($payout)) {
            $payout::query()->whereKey($payout->id)->where('status', PayoutStatus::Paying)
                ->update(['status' => PayoutStatus::Open, 'reason' => 'lud16_changed', 'bolt11' => null, 'payment_hash' => null]);

            return;
        }

        // A season payout never goes out while the profile's address changed within the freeze (P37: 72 h).
        if ($payout instanceof SeasonPayout && SeasonSettlement::addressFrozen($payout->user)) {
            $payout::query()->whereKey($payout->id)->where('status', PayoutStatus::Paying)
                ->update(['status' => PayoutStatus::Open, 'reason' => 'lud16_frozen', 'bolt11' => null, 'payment_hash' => null]);

            return;
        }

        try {
            $invoice = $this->addresses->invoice($lud16, $payout->amount_sats);
        } catch (LightningAddressFailure $failure) {
            $payout::query()->whereKey($payout->id)->where('status', PayoutStatus::Paying)
                ->update(['status' => PayoutStatus::Failed, 'reason' => $failure->reason]);

            return;
        }

        // Stored before it is paid: from here on every attempt pays or looks up this invoice.
        $payout::query()->whereKey($payout->id)->where('status', PayoutStatus::Paying)->update([
            'bolt11' => $invoice->invoice, 'payment_hash' => $invoice->paymentHash, 'invoice_expires_at' => now()->setTimestamp($invoice->expiresAt()),
        ]);
        $payout->refresh();

        $this->pay($payout, $wallet, $invoice->invoice);
    }

    private function pay(TournamentPayout|SeasonPayout $payout, PayingWallet $wallet, string $bolt11): void
    {
        $invoice = Bolt11::decode($bolt11);

        if ($invoice === null || $invoice->amountMsats !== $payout->amount_sats * 1000 || $invoice->paymentHash !== $payout->payment_hash) {
            $this->note($payout, 'needs_check');

            return;
        }

        try {
            $result = $wallet->pay($bolt11);
        } catch (NwcError $error) {
            if ($error->isTimeout()) {
                $this->note($payout, 'unconfirmed');

                return;
            }

            Log::warning(self::kind($payout).' payout refused by the wallet', ['payout' => $payout->id, 'code' => $error->errorCode]);
            $payout::query()->whereKey($payout->id)->where('status', PayoutStatus::Paying)->update([
                'status' => PayoutStatus::Failed,
                'reason' => match ($error->errorCode) {
                    'INSUFFICIENT_BALANCE' => 'insufficient_balance',
                    'QUOTA_EXCEEDED' => 'budget_exceeded',
                    default => 'wallet_error',
                },
            ]);

            return;
        }

        $this->markPaid($payout, $result['preimage'], $result['fees_msats']);
    }

    /**
     * Paid, once: the preimage must hash to the invoice's payment hash. Then
     * the league signs the Payout (2157).
     */
    private function markPaid(TournamentPayout|SeasonPayout $payout, string $preimage, ?int $feesMsats): void
    {
        $preimage = strtolower($preimage);

        if (preg_match('/^[0-9a-f]{64}$/', $preimage) !== 1 || hash('sha256', (string) hex2bin($preimage)) !== $payout->payment_hash) {
            Log::warning(self::kind($payout).' payout: the wallet reported a payment without a matching preimage', ['payout' => $payout->id]);
            $this->note($payout, 'unconfirmed');

            return;
        }

        DB::transaction(function () use ($payout, $preimage, $feesMsats): void {
            $claimed = $payout::query()->whereKey($payout->id)->where('status', PayoutStatus::Paying)
                ->update(['status' => PayoutStatus::Paid, 'reason' => null, 'preimage' => $preimage, 'fees_msats' => $feesMsats, 'paid_at' => now()]);

            if ($claimed !== 1) {
                return;
            }

            $payout->refresh();

            $league = LeagueKey::required();
            $relay = (string) (config('esports.relays')[0] ?? '');
            $event = $league->publish(2157, $payout->payoutTags($preimage, $relay), '', now()->getTimestamp());

            // A season payout leaves the league wallet: booked once (tournament pots are never booked).
            if ($payout instanceof SeasonPayout) {
                app(Ledger::class)->seasonPayout($payout);
            }

            $payout->forceFill(['event_id' => $event->id])->save();
        });
    }

    private function note(TournamentPayout|SeasonPayout $payout, string $reason): void
    {
        $payout::query()->whereKey($payout->id)->where('status', PayoutStatus::Paying)->update(['reason' => $reason]);
    }

    /**
     * The Lightning address the player's profile shows now, normalized; null
     * without a valid one (or without an account).
     */
    public static function currentAddress(TournamentPayout|SeasonPayout $payout): ?string
    {
        $lud16 = $payout->user_id === null ? null : User::query()->whereKey($payout->user_id)->value('lud16');

        return is_string($lud16) && LightningAddress::target($lud16) !== null ? strtolower($lud16) : null;
    }

    /**
     * Whether the profile's address is no longer the approved one. A deleted
     * account changes nothing: its approved address is still the one paid.
     */
    public static function addressChanged(TournamentPayout|SeasonPayout $payout): bool
    {
        return $payout->user_id !== null && User::query()->whereKey($payout->user_id)->exists()
            && self::currentAddress($payout) !== ($payout->lud16 === null ? null : strtolower($payout->lud16));
    }
}
