<?php

namespace App\Support\Payouts;

use App\Enums\PayoutStatus;
use App\Models\SeasonPayout;
use App\Models\Tournament;
use App\Models\TournamentPayout;
use App\Models\User;
use App\Support\FairPlay\AccountLinks;
use App\Support\FairPlay\FairPlay;
use App\Support\Lightning\Bolt11;
use App\Support\Lightning\LightningAddress;
use App\Support\Lightning\LightningAddressFailure;
use App\Support\Prizes\PrizePool;
use App\Support\SeasonChain\LeagueKey;
use App\Support\SeasonChain\SeasonSettlement;
use App\Support\Wallet\Ledger;
use App\Support\Wallet\NwcError;
use App\Support\Wallet\PayingWallet;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pays one payout, exactly once, to the player's Lightning address, from the
 * league wallet's paying connection (`esports.wallet.nwc_uri`): a tournament
 * payout (P9) out of its tournament's account in the league ledger, a
 * season payout (P37) out of the reserve, each booked once it is paid. A
 * tournament payout approved before the league wallet took over (2026-10-02)
 * still pays from that pot's own wallet and books nothing. The only holder
 * of a {@see PayingWallet}.
 *
 * The league wallet is shared by every pot (user, 2026-10-02), so every
 * payment from it runs under ONE lock over the league wallet (security gate
 * on 8a171405, F1): the balance read, the check ({@see covered()}), the
 * payment and its booking happen as one step, never interleaved with another
 * payout's on any worker. The check: the balance, less what the wallet holds
 * for the other tournament pots and less the payments still in flight, must
 * cover the amount and its fee allowance (the 1 % reserve, at least 10
 * sats); a tournament payout must not exceed what its own account still
 * holds. Otherwise nothing is sent: the payout fails as
 * `insufficient_balance` (retryable), waits as `balance_unread` when the
 * wallet did not tell, or as `wallet_busy` when the lock stayed taken (the
 * schedule continues it).
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
    /** The lock every payment from the league wallet runs under (check, pay, book). */
    public const SPEND_LOCK = 'league-wallet-spend';

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
     * The wallet a payout is paid from: the league wallet's paying
     * connection, or for a legacy pot approved before 2026-10-02 that pot's
     * own wallet. Null without one (fail closed).
     */
    private static function wallet(TournamentPayout|SeasonPayout $payout): ?PayingWallet
    {
        return $payout instanceof TournamentPayout && $payout->tournament->hasOwnWallet()
            ? PayingWallet::forTournament($payout->tournament)
            : PayingWallet::fromConfig();
    }

    /** Paid from the league wallet and booked in its ledger (every payout but a legacy pot's). */
    private static function fromLeagueWallet(TournamentPayout|SeasonPayout $payout): bool
    {
        return ! ($payout instanceof TournamentPayout && $payout->tournament->hasOwnWallet());
    }

    /**
     * Whether the league wallet may pay this payout now, read under the
     * spend lock: `null` when it does not tell its balance; else whether the
     * balance, less what it holds for the other tournament pots (all of them
     * for a season payout, whose sats are the reserve's) and less the other
     * payments in flight, covers the amount and its fee allowance, and (a
     * tournament payout) the pot's own account still holds the amount.
     */
    private function covered(TournamentPayout|SeasonPayout $payout): ?bool
    {
        $balance = PayoutApproval::walletBalance();

        if ($balance === null) {
            return null;
        }

        $ledger = app(Ledger::class);
        $needed = $payout->amount_sats + PrizePool::feeReserve($payout->amount_sats);
        $available = $balance - self::inFlight($payout);

        if ($payout instanceof SeasonPayout) {
            return $needed <= $available - $ledger->heldForTournaments();
        }

        $account = $payout->tournament->potAccount();

        return $payout->amount_sats <= $ledger->balance($account) && $needed <= $available - $ledger->heldForTournaments($account);
    }

    /**
     * Other payouts from the league wallet whose payment may have left
     * without being booked yet (`paying` with an invoice stored): their
     * amounts and fee allowances, which a balance read may not show yet.
     */
    private static function inFlight(TournamentPayout|SeasonPayout $payout): int
    {
        $sum = 0;

        foreach ([TournamentPayout::query()->whereHas('tournament', fn ($query) => $query->where('pot_source', '!=', Tournament::POT_WALLET)), SeasonPayout::query()] as $query) {
            $rows = $query->where('status', PayoutStatus::Paying)->whereNotNull('payment_hash')
                ->when($payout::class === $query->getModel()::class, fn ($same) => $same->whereKeyNot($payout->id))
                ->pluck('amount_sats');

            foreach ($rows as $amount) {
                $sum += (int) $amount + PrizePool::feeReserve((int) $amount);
            }
        }

        return $sum;
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

        if (! self::fromLeagueWallet($payout)) {
            $this->send($payout, $wallet, $bolt11);

            return;
        }

        // The shared league wallet: balance read, check, payment and booking as one step under one lock (gate F1).
        $lock = Cache::lock(self::SPEND_LOCK, (int) config('esports.wallet.payout_lease_seconds', 180));

        try {
            $lock->block(max(0, (int) config('esports.wallet.spend_lock_wait_seconds', 15)));
        } catch (LockTimeoutException) {
            $this->note($payout, 'wallet_busy');

            return;
        }

        try {
            if (($covered = $this->covered($payout)) !== true) {
                if ($covered === null) {
                    $this->note($payout, 'balance_unread');

                    return;
                }

                Log::warning(self::kind($payout).' payout not covered by the league wallet', ['payout' => $payout->id]);
                $payout::query()->whereKey($payout->id)->where('status', PayoutStatus::Paying)->update(['status' => PayoutStatus::Failed, 'reason' => 'insufficient_balance']);

                return;
            }

            $this->send($payout, $wallet, $bolt11);
        } finally {
            $lock->release();
        }
    }

    /**
     * Send the payment and mark it paid (booked in the same step). The
     * caller holds the spend lock for the league wallet.
     */
    private function send(TournamentPayout|SeasonPayout $payout, PayingWallet $wallet, string $bolt11): void
    {
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

            // A payout leaves the league wallet: booked once, out of the reserve or the tournament's pot (a legacy pot books nothing).
            if ($payout instanceof SeasonPayout) {
                app(Ledger::class)->seasonPayout($payout);
            } elseif (self::fromLeagueWallet($payout)) {
                app(Ledger::class)->tournamentPayout($payout);
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
