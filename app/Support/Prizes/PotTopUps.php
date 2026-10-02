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
use App\Support\Wallet\WalletSetup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Sats into a tournament's pot from anyone (P9): a plain invoice made by the
 * league wallet (user, 2026-10-02: „das landet eh alles in eine Wallet von
 * wo aus ausgezahlt werden kann"), recorded with its payment hash and its
 * pot `tournament:<id>` before anyone sees it, and booked into that
 * tournament's account of the league ledger once the wallet reports it paid
 * ({@see IncomingPayments}). A plain top-up is part of the pot as announced
 * ("included").
 *
 * Only while the pot is open and the league wallet can make invoices;
 * otherwise the page says top-ups are not enabled. The limits of security
 * gate F2 hold here ({@see InvoiceCaps}; the page limits invoices per
 * minute). Sponsor invoices are the same, from the organizer's page, with
 * caps of their own. A cancelled tournament takes no invoices (its pot
 * closed).
 */
final class PotTopUps
{
    public function __construct(private IncomingPayments $payments) {}

    public static function enabled(Tournament $tournament): bool
    {
        return $tournament->hasLeaguePot() && $tournament->isPoolOpen() && WalletSetup::canReceive()
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
     * Look the invoice up at the league wallet (at most every few seconds)
     * and settle or expire it. Returns it fresh.
     */
    public function check(IncomingPayment $payment, int $minSeconds = 3): IncomingPayment
    {
        return $this->payments->check($payment, $minSeconds);
    }

    /**
     * @throws PoolRefusal
     */
    private function make(Tournament $tournament, int $amountSats, string $source, string $description, ?TournamentSponsor $sponsor = null): IncomingPayment
    {
        $wallet = self::enabled($tournament) ? ReceivingWallet::fromConfig() : null;

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
            Log::warning('Pot invoice: the league wallet made no invoice', ['tournament' => $tournament->id, 'code' => $error->errorCode]);

            throw new PoolRefusal(__('The league wallet did not answer. Please try again in a moment.'));
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
