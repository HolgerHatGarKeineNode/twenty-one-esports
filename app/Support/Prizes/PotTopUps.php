<?php

namespace App\Support\Prizes;

use App\Enums\IncomingPaymentStatus;
use App\Enums\TournamentStatus;
use App\Models\IncomingPayment;
use App\Models\Tournament;
use App\Models\TournamentSponsor;
use App\Models\User;
use App\Support\Wallet\NwcError;
use App\Support\Wallet\ReceivingWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Sats into a tournament's pot from anyone (P9, user 2026-09-27: every
 * tournament has its own NWC wallet and pot): a plain invoice made by that
 * wallet (NIP-47 `make_invoice`), recorded with its payment hash before
 * anyone sees it, and settled once that same wallet reports it paid
 * (`lookup_invoice`). The league's wallet, ledger and LNURL key are never
 * used, so there is no NIP-57 zap receipt: only the LNURL server of the
 * wallet that made an invoice could sign one (NIP rev. 9).
 *
 * Only while the pot is open and its connection may make and look up
 * invoices (`pot_can_receive`, from `get_info` when it was connected);
 * otherwise the page says top-ups are not enabled. The limits of security
 * gate F2 hold here too ({@see InvoiceCaps}; the page limits invoices per
 * minute), and the wallet is reached only through the relay guard of F1.
 * Sponsor invoices are the same, from the organizer's page, with caps of
 * their own. A cancelled tournament takes no invoices (its pot closed).
 */
final class PotTopUps
{
    /**
     * How long past its expiry an invoice is still looked up. After that it
     * is expired here, whatever its wallet answers or if it never answers
     * (re-gate F-B): a wallet that says `pending` forever must not keep it
     * in every wallet:sync run.
     */
    public const EXPIRY_GRACE_SECONDS = 600;

    public function __construct(private PotBalances $balances) {}

    public static function enabled(Tournament $tournament): bool
    {
        return $tournament->hasOwnWallet() && $tournament->isPoolOpen() && $tournament->pot_can_receive === true
            && $tournament->status !== TournamentStatus::Cancelled;
    }

    /**
     * @throws PoolRefusal
     */
    public function invoice(Tournament $tournament, int $amountSats): IncomingPayment
    {
        PoolInvoices::checkAmount($amountSats);

        return $this->make($tournament, $amountSats, 'topup', 'Prize pot: '.$tournament->name);
    }

    /**
     * @throws PoolRefusal
     */
    public function sponsorInvoice(TournamentSponsor $sponsor, User $user): IncomingPayment
    {
        if (! Gate::forUser($user)->allows('manage-tournament', $sponsor->tournament)) {
            throw new PoolRefusal(__('Only the organizer of this tournament or an admin can change its prize pool.'));
        }

        // Their own caps (security gate F-B), outside the top-up caps: a few unpaid per tournament, and per organizer and hour.
        $open = IncomingPayment::query()->where('tournament_id', $sponsor->tournament_id)->where('source', 'sponsor')
            ->where('status', IncomingPaymentStatus::Pending)->where('expires_at', '>', now())->count();

        if ($open >= (int) config('esports.wallet.sponsor_invoices_open_per_tournament', 3)) {
            throw new PoolRefusal(__('This tournament has too many unpaid sponsor invoices. Wait until one is paid or expires.'));
        }

        $key = 'pot-sponsor-invoice:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, (int) config('esports.wallet.sponsor_invoices_per_hour', 10))) {
            throw new PoolRefusal(__('Too many sponsor invoices this hour. Try again in :minutes min.', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)]));
        }

        RateLimiter::hit($key, 3600);

        return $this->make($sponsor->tournament, $sponsor->pledged_sats, 'sponsor', 'Sponsor: '.$sponsor->name.' – '.$sponsor->tournament->name, $sponsor);
    }

    /**
     * Look the invoice up at the tournament's wallet (at most every few
     * seconds) and settle or expire it; a settled top-up reads the pot's
     * balance again. Returns it fresh.
     */
    public function check(IncomingPayment $payment, int $minSeconds = 3): IncomingPayment
    {
        return $this->lookUp($payment, $minSeconds)[0];
    }

    /**
     * @return array{0: IncomingPayment, 1: bool} the payment, fresh, and whether its wallet timed out
     */
    private function lookUp(IncomingPayment $payment, int $minSeconds): array
    {
        if ($payment->status !== IncomingPaymentStatus::Pending) {
            return [$payment, false];
        }

        if (self::overdue($payment)) {
            IncomingPayment::query()->whereKey($payment->id)->where('status', IncomingPaymentStatus::Pending)->update(['status' => IncomingPaymentStatus::Expired]);

            return [$payment->refresh(), false];
        }

        $tournament = $payment->tournament_id === null ? null : Tournament::query()->find($payment->tournament_id);

        if ($tournament === null || $payment->pot !== IncomingPayment::tournamentPot($tournament->id)
            || ($payment->checked_at !== null && $payment->checked_at->getTimestamp() > now()->getTimestamp() - $minSeconds)) {
            return [$payment, false];
        }

        $wallet = $tournament->hasOwnWallet() ? ReceivingWallet::fromUri($tournament->pot_nwc_uri) : null;

        if ($wallet === null) {
            return [$payment, false];
        }

        $payment->forceFill(['checked_at' => now()])->save();

        try {
            $transaction = $wallet->lookup($payment->payment_hash);
        } catch (NwcError $error) {
            Log::info('Pot invoice lookup failed', ['payment' => $payment->id, 'code' => $error->errorCode]);

            return [$payment, $error->isTimeout()];
        }

        if ($transaction !== null && $transaction->isSettled()) {
            $preimage = $transaction->preimage !== null && hash('sha256', (string) hex2bin($transaction->preimage)) === $payment->payment_hash ? $transaction->preimage : null;
            $settledAt = now()->setTimestamp(min($transaction->settledAt ?? now()->getTimestamp(), now()->getTimestamp()));
            $late = $tournament->pool_closed_at !== null && $settledAt->greaterThanOrEqualTo($tournament->pool_closed_at);

            $claimed = IncomingPayment::query()->whereKey($payment->id)->where('status', IncomingPaymentStatus::Pending)
                ->update(['status' => IncomingPaymentStatus::Settled, 'settled_at' => $settledAt, 'preimage' => $preimage, 'late' => $late]);

            if ($claimed === 1 && ! $late) {
                $this->balances->read($tournament);
            }
        } elseif ($payment->expires_at->isPast() && ($transaction === null || in_array($transaction->state, ['expired', 'failed'], true))) {
            IncomingPayment::query()->whereKey($payment->id)->where('status', IncomingPaymentStatus::Pending)->update(['status' => IncomingPaymentStatus::Expired]);
        }

        return [$payment->refresh(), false];
    }

    /**
     * Every open pot invoice, for the scheduler (`wallet:sync`).
     */
    public function checkAll(): int
    {
        $settled = 0;

        $slow = [];

        IncomingPayment::query()->where('pot', 'like', 'tournament:%')->where('status', IncomingPaymentStatus::Pending)
            ->where('created_at', '>', now()->subDay())
            ->orderBy('id')
            ->each(function (IncomingPayment $payment) use (&$settled, &$slow): void {
                // A pot wallet that timed out once is not asked again in this run: one slow wallet costs one timeout (gate F-B).
                // An overdue invoice is still expired: that needs no wallet.
                $wallet = 'tournament:'.$payment->tournament_id;

                if (isset($slow[$wallet]) && ! self::overdue($payment)) {
                    return;
                }

                [$fresh, $timedOut] = $this->lookUp($payment, 20);

                if ($timedOut) {
                    $slow[$wallet] = true;
                }

                $settled += $fresh->status === IncomingPaymentStatus::Settled ? 1 : 0;
            });

        return $settled;
    }

    private static function overdue(IncomingPayment $payment): bool
    {
        return $payment->expires_at->getTimestamp() + self::EXPIRY_GRACE_SECONDS < now()->getTimestamp();
    }

    /**
     * @throws PoolRefusal
     */
    private function make(Tournament $tournament, int $amountSats, string $source, string $description, ?TournamentSponsor $sponsor = null): IncomingPayment
    {
        $wallet = self::enabled($tournament) ? ReceivingWallet::fromUri($tournament->pot_nwc_uri) : null;

        if ($wallet === null) {
            throw new PoolRefusal(__('Top-ups are not enabled for this pot.'));
        }

        $requester = InvoiceCaps::requester();

        // Sponsor invoices come from the organizers' own page; everyone else holds a few unpaid invoices at most (F2).
        if ($source !== 'sponsor') {
            InvoiceCaps::check($requester);
        }

        try {
            $invoice = $wallet->makePlainInvoice($amountSats, mb_substr($description, 0, 200), (int) config('esports.wallet.invoice_expiry_seconds', 900));
        } catch (NwcError $error) {
            Log::warning('Pot invoice: the tournament wallet made no invoice', ['tournament' => $tournament->id, 'code' => $error->errorCode]);

            throw new PoolRefusal(__('The pot’s wallet did not answer. Please try again in a moment.'));
        }

        return DB::transaction(fn (): IncomingPayment => IncomingPayment::query()->create([
            'pot' => IncomingPayment::tournamentPot($tournament->id),
            'tournament_id' => $tournament->id,
            'sponsor_id' => $sponsor?->id,
            'source' => $source,
            ...$requester,
            'payment_hash' => $invoice->paymentHash,
            'bolt11' => $invoice->invoice,
            'amount_sats' => $amountSats,
            'status' => IncomingPaymentStatus::Pending,
            'expires_at' => PoolInvoices::expiry($invoice),
        ]));
    }
}
