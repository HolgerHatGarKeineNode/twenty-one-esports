<?php

namespace App\Support\Prizes;

use App\Enums\IncomingPaymentStatus;
use App\Models\IncomingPayment;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Wallet\Ledger;
use App\Support\Wallet\NwcError;
use App\Support\Wallet\ReceivingWallet;
use App\Support\Wallet\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Invoices into the league reserve that got paid (the Season-Chain's pot):
 * looked up at the league's receiving wallet by payment hash, then booked
 * once and receipted once. Tournament pots never come here; their top-ups
 * are {@see PotTopUps} on each tournament's own wallet.
 *
 * Settling is a compare-and-set from `pending` to `settled`, so two checks
 * of the same invoice book it once. A zap gets its receipt (`9735`), signed
 * by the LNURL server key with `created_at` = the settle time, to the
 * league relays.
 */
final class IncomingPayments
{
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
        if ($payment->pot !== IncomingPayment::RESERVE || $payment->status !== IncomingPaymentStatus::Pending
            || ($payment->checked_at !== null && $payment->checked_at->getTimestamp() > now()->getTimestamp() - $minSeconds)) {
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

        IncomingPayment::query()->where('pot', IncomingPayment::RESERVE)->where('status', IncomingPaymentStatus::Pending)
            ->where('created_at', '>', now()->subDay())
            ->orderBy('id')
            ->each(function (IncomingPayment $payment) use (&$settled, &$slow): bool {
                [$fresh, $slow] = $this->lookUp($payment, 20);
                $settled += $fresh->status === IncomingPaymentStatus::Settled ? 1 : 0;

                // A wallet that timed out is not asked again in this run (gate F-B).
                return ! $slow;
            });

        return $settled;
    }

    public function settle(IncomingPayment $payment, WalletTransaction $transaction): void
    {
        $preimage = $transaction->preimage !== null && hash('sha256', (string) hex2bin($transaction->preimage)) === $payment->payment_hash ? $transaction->preimage : null;
        $settledAt = now()->setTimestamp(min($transaction->settledAt ?? now()->getTimestamp(), now()->getTimestamp()));

        DB::transaction(function () use ($payment, $preimage, $settledAt): void {
            $claimed = IncomingPayment::query()->whereKey($payment->id)->where('pot', IncomingPayment::RESERVE)->where('status', IncomingPaymentStatus::Pending)
                ->update(['status' => IncomingPaymentStatus::Settled, 'settled_at' => $settledAt, 'preimage' => $preimage]);

            if ($claimed !== 1) {
                return;
            }

            $payment->refresh();
            $this->ledger->contribution($payment, Ledger::RESERVE);

            if ($payment->zap_request !== null) {
                $this->receipt($payment);
            }
        });
    }

    /**
     * The zap receipt (NIP-57 `9735`) for a settled zap.
     */
    private function receipt(IncomingPayment $payment): void
    {
        $key = LeagueKey::lnurl();
        $request = SignedEvent::fromInput(json_decode((string) $payment->zap_request, true));

        if ($key === null || $request === null || $payment->settled_at === null) {
            return;
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
    }
}
