<?php

namespace App\Support\Prizes;

use App\Enums\IncomingPaymentStatus;
use App\Models\IncomingPayment;
use App\Models\NostrEvent;
use App\Models\Tournament;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Wallet\Ledger;
use App\Support\Wallet\NwcError;
use App\Support\Wallet\ReceivingWallet;
use App\Support\Wallet\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Invoices of the league wallet that got paid, for every pot it books: the
 * reserve (the Season-Chain's) and each tournament's pot (user, 2026-10-02:
 * every pot is booked in the league wallet). Looked up at the league's
 * receiving wallet by payment hash, then booked once and receipted once.
 *
 * Settling is a compare-and-set from `pending` to `settled`, so two checks
 * of the same invoice book it once. A tournament's invoice settled at or
 * after its pot closed is `late` and booked to the reserve instead (NIP
 * "Counting the pool", check 5): it changes no prize. A zap gets its receipt
 * (`9735`), signed by the LNURL server key with `created_at` = the settle
 * time, to the league relays.
 *
 * An invoice the wallet still calls pending, or never answers about, is
 * expired here once its expiry and a grace passed (re-gate F-B), so it
 * neither stays in every `wallet:sync` run nor holds the open-invoice caps.
 */
final class IncomingPayments
{
    /**
     * How long past its expiry an invoice is still looked up. After that it
     * is expired here, whatever the wallet answers or if it never answers.
     */
    public const EXPIRY_GRACE_SECONDS = 600;

    public function __construct(private Ledger $ledger) {}

    /**
     * Look the invoice up at the wallet (at most every few seconds) and
     * settle or expire it. Returns it fresh.
     */
    public function check(IncomingPayment $payment, int $minSeconds = 3): IncomingPayment
    {
        return $this->lookUp($payment, $minSeconds)[0];
    }

    /**
     * @return array{0: IncomingPayment, 1: bool} the payment, fresh, and whether the wallet timed out
     */
    private function lookUp(IncomingPayment $payment, int $minSeconds): array
    {
        if ($payment->status !== IncomingPaymentStatus::Pending || ! self::isLeaguePot($payment)) {
            return [$payment, false];
        }

        if (self::overdue($payment)) {
            IncomingPayment::query()->whereKey($payment->id)->where('status', IncomingPaymentStatus::Pending)->update(['status' => IncomingPaymentStatus::Expired]);

            return [$payment->refresh(), false];
        }

        if ($payment->checked_at !== null && $payment->checked_at->getTimestamp() > now()->getTimestamp() - $minSeconds) {
            return [$payment, false];
        }

        $wallet = ReceivingWallet::fromConfig();

        if ($wallet === null) {
            return [$payment, false];
        }

        $payment->forceFill(['checked_at' => now()])->save();

        try {
            $transaction = $wallet->lookup($payment->payment_hash);
        } catch (NwcError $error) {
            Log::info('Pool invoice lookup failed', ['payment' => $payment->id, 'code' => $error->errorCode]);

            return [$payment, $error->isTimeout()];
        }

        if ($transaction !== null && $transaction->isSettled()) {
            $this->settle($payment, $transaction);
        } elseif ($payment->expires_at->isPast() && ($transaction === null || in_array($transaction->state, ['expired', 'failed'], true))) {
            IncomingPayment::query()->whereKey($payment->id)->where('status', IncomingPaymentStatus::Pending)->update(['status' => IncomingPaymentStatus::Expired]);
        }

        return [$payment->refresh(), false];
    }

    /**
     * Every open invoice, for the scheduler (`wallet:sync`).
     */
    public function checkAll(): int
    {
        $settled = 0;
        $slow = false;

        IncomingPayment::query()->where(fn ($query) => $query->where('pot', IncomingPayment::RESERVE)->orWhere('pot', 'like', 'tournament:%'))
            ->where('status', IncomingPaymentStatus::Pending)
            ->where('created_at', '>', now()->subDay())
            ->orderBy('id')
            ->each(function (IncomingPayment $payment) use (&$settled, &$slow): void {
                // A wallet that timed out is not asked again in this run (gate F-B); an overdue invoice is still expired.
                if ($slow && ! self::overdue($payment)) {
                    return;
                }

                [$fresh, $timedOut] = $this->lookUp($payment, 20);
                $slow = $slow || $timedOut;
                $settled += $fresh->status === IncomingPaymentStatus::Settled ? 1 : 0;
            });

        return $settled;
    }

    public function settle(IncomingPayment $payment, WalletTransaction $transaction): void
    {
        $preimage = $transaction->preimage !== null && hash('sha256', (string) hex2bin($transaction->preimage)) === $payment->payment_hash ? $transaction->preimage : null;
        $settledAt = now()->setTimestamp(min($transaction->settledAt ?? now()->getTimestamp(), now()->getTimestamp()));

        DB::transaction(function () use ($payment, $preimage, $settledAt): void {
            $tournament = $payment->pot === IncomingPayment::RESERVE ? null
                : Tournament::query()->whereKey($payment->tournament_id)->lockForUpdate()->first();

            // A tournament's pot takes nothing once it closed (the admin check): later sats go to the reserve.
            $late = $tournament === null ? $payment->pot !== IncomingPayment::RESERVE
                : $tournament->pool_closed_at !== null && $settledAt->greaterThanOrEqualTo($tournament->pool_closed_at);

            $claimed = IncomingPayment::query()->whereKey($payment->id)->where('pot', $payment->pot)->where('status', IncomingPaymentStatus::Pending)
                ->update(['status' => IncomingPaymentStatus::Settled, 'settled_at' => $settledAt, 'preimage' => $preimage, 'late' => $late]);

            if ($claimed !== 1) {
                return;
            }

            $payment->refresh();
            $this->ledger->contribution($payment, $late ? Ledger::RESERVE : $payment->pot);

            if ($payment->zap_request !== null) {
                $receipt = $this->receipt($payment);

                // A zap into an open tournament pot: its receipt verified once, here (gate F2), never per page view.
                if ($receipt !== null && $tournament !== null && ! $late) {
                    $this->verifyZap($payment, $receipt, $tournament);
                }
            }
        });
    }

    /** The reserve, or one tournament's pot: the pots this wallet books. */
    private static function isLeaguePot(IncomingPayment $payment): bool
    {
        return $payment->pot === IncomingPayment::RESERVE
            || ($payment->tournament_id !== null && $payment->pot === IncomingPayment::tournamentPot($payment->tournament_id));
    }

    private static function overdue(IncomingPayment $payment): bool
    {
        return $payment->expires_at->getTimestamp() + self::EXPIRY_GRACE_SECONDS < now()->getTimestamp();
    }

    /**
     * The zap receipt (NIP-57 `9735`) for a settled zap.
     */
    private function receipt(IncomingPayment $payment): ?NostrEvent
    {
        $key = LeagueKey::lnurl();
        $request = SignedEvent::fromInput(json_decode((string) $payment->zap_request, true));

        if ($key === null || $request === null || $payment->settled_at === null) {
            return null;
        }

        $tags = [['p', (string) ($request->tag('p') ?? '')], ['P', $request->pubkey]];

        foreach (['a', 'e', 'k'] as $name) {
            if ($request->tag($name) !== null) {
                $tags[] = [$name, (string) $request->tag($name)];
            }
        }

        $tags[] = ['bolt11', $payment->bolt11];
        $tags[] = ['description', (string) $payment->zap_request];

        if ($payment->preimage !== null) {
            $tags[] = ['preimage', $payment->preimage];
        }

        $event = $key->publish(9735, $tags, '', $payment->settled_at->getTimestamp());
        $payment->forceFill(['receipt_event_id' => $event->id])->save();

        return $event;
    }

    /**
     * Verify a tournament zap's receipt once ({@see ZapReceipts}) and mark the
     * row: only a verified zap of exactly its invoice's amount, by the
     * request's author, goes on the wall and on top of the pot.
     */
    private function verifyZap(IncomingPayment $payment, NostrEvent $receipt, Tournament $tournament): void
    {
        $lnurl = LeagueKey::lnurl()?->pubkey();
        $pool = LeagueKey::poolPubkey();
        $zap = $lnurl === null || $pool === null ? null : ZapReceipts::verify(json_decode($receipt->raw, true), $tournament, $lnurl, $pool);

        if ($zap !== null && $zap['sats'] === $payment->amount_sats && $zap['payer'] === $payment->payer_pubkey) {
            $payment->forceFill(['zap_verified' => true])->save();
            app(ZapSponsors::class)->forget($tournament->id);
        }
    }
}
