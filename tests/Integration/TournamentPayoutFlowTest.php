<?php

use App\Enums\IncomingPaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\TournamentFormat;
use App\Models\LedgerTransfer;
use App\Models\NostrEvent;
use App\Models\RelayDelivery;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Lightning\Bolt11;
use App\Support\Payouts\PayoutApproval;
use App\Support\Prizes\PotTopUps;
use Illuminate\Support\Facades\Process;
use Tests\Integration\Support\RelayCheck;
use Tests\Integration\Support\Stack;

pest()->group('integration');

/*
|--------------------------------------------------------------------------
| P9 in the real stack: a tournament pot, topped up and paid out
|--------------------------------------------------------------------------
|
| The prize pot of a chess tournament is the tournament's own NWC wallet
| (user, 2026-09-27), here the fake NIP-47 wallet service on the local
| `nak serve` relay (never a real wallet). A top-up invoice is made by that
| wallet over the relay, a payer settles it, and `wallet:sync` sees it with
| lookup_invoice and reads the pot's balance. The prizes are fixed amounts.
| After the admin check, each payout is paid by `payouts:run` from the same
| wallet — the winner's by TWO such processes started at once, which race
| for the same payout in the one SQLite file. The wallet must see exactly
| one `pay_invoice` per invoice, every payout has its Payout (2157) on the
| relay with a preimage that hashes to its invoice, and no zap receipt
| (9735) exists: a top-up is a plain invoice.
|
| The stack's league wallet is the same fake service; that no tournament
| flow reaches the league wallet or its ledger is proven in the feature
| suite (WalletTest), with separate fakes.
|
*/

test('a tournament pot: topped up through its own wallet, paid once per winner by racing processes, proven on the relay', function () {
    $stack = Stack::instance();
    $stack->wallet();
    $relay = new RelayCheck($stack->relayUrl);

    // A finished chess tournament of four with fixed prizes; the players' Lightning addresses are at the fake LNURL server.
    $tournament = Stack::retryOnLock(fn () => runningChess(TournamentFormat::SingleElimination, 4));
    Stack::retryOnLock(fn () => publishForPool($tournament));
    Stack::retryOnLock(fn () => $tournament->refresh()->forceFill([
        'pot_nwc_uri' => $stack->nwc->uri('pay', $stack->relayUrl), 'pot_balance_sats' => 0,
        'prize_mode' => Tournament::PRIZES_FIXED, 'prize_fixed' => [10_500, 6_300, 2_100],
    ])->save());

    foreach ($tournament->participants()->get() as $participant) {
        User::query()->whereKey($participant->user_id)->update(['lud16' => 'player'.$participant->id.'@'.$stack->nwc->lnurlHost()]);
    }

    // Anyone adds sats: the invoice comes from the pot's own wallet.
    $payment = Stack::retryOnLock(fn () => app(PotTopUps::class)->invoice($tournament->refresh(), 21_000));
    expect($payment->pot)->toBe('tournament:'.$tournament->id)->and($payment->source)->toBe('topup')
        ->and($payment->status)->toBe(IncomingPaymentStatus::Pending)
        ->and(Bolt11::decode($payment->bolt11)?->paymentHash)->toBe($payment->payment_hash);

    $stack->nwc->settle($payment->payment_hash);
    $stack->artisan('wallet:sync');
    expect($payment->refresh()->status)->toBe(IncomingPaymentStatus::Settled)
        ->and($tournament->refresh()->pot_balance_sats)->toBeGreaterThanOrEqual(21_000)
        ->and(collect($stack->nwc->state()['calls'])->where('method', 'lookup_invoice')->where('payment_hash', $payment->payment_hash))->not->toBeEmpty()
        ->and(LedgerTransfer::query()->count())->toBe(0);

    Stack::retryOnLock(fn () => playOutAsDirector($tournament));
    $admin = anAdmin();
    Stack::retryOnLock(fn () => app(PayoutApproval::class)->approve($tournament->refresh(), $admin));
    $payouts = $tournament->payouts()->get();
    expect($payouts)->toHaveCount(4);

    // The winner's payout: two processes at once (a double click on two servers).
    $winner = $payouts->firstWhere('place', 1);
    $first = $stack->artisanInBackground('payouts:run '.$winner->id.' --start');
    $second = $stack->artisanInBackground('payouts:run '.$winner->id.' --start');
    $first->wait();
    $second->wait();

    foreach ($payouts->where('place', '>', 1) as $payout) {
        $stack->artisan('payouts:run '.$payout->id.' --start');
    }

    // And the retry of a job that already succeeded.
    $stack->artisan('payouts:run '.$winner->id.' --start');
    $stack->artisan('payouts:run '.$winner->id);

    $paid = $tournament->payouts()->get();
    $payRequests = collect($stack->nwc->state()['calls'])->where('method', 'pay_invoice');

    expect($paid->pluck('status')->unique()->all())->toBe([PayoutStatus::Paid])
        ->and($payRequests->where('client', 'pay')->pluck('payment_hash')->sort()->values()->all())->toBe($paid->pluck('payment_hash')->sort()->values()->all())
        ->and($payRequests->where('client', 'receive')->all())->toBe([])
        ->and($stack->nwc->state()['paid'])->toHaveCount(4)
        ->and($winner->refresh()->amount_sats)->toBe(10_500)
        ->and($paid->where('place', 3)->pluck('amount_sats')->all())->toBe([1_050, 1_050]);

    // On the relay (published by the real queue worker): a Payout per player.
    $payoutEvents = [];

    // Bounded by time, not by rounds: a `nak req` against a busy relay can take seconds.
    for ($deadline = microtime(true) + 30; microtime(true) < $deadline && count($payoutEvents) < 4;) {
        $payoutEvents = $relay->events('-k 2157 -t a='.escapeshellarg((string) $tournament->address()));
        usleep(250_000);
    }

    $diagnosis = json_encode([
        'relay 31923' => count($relay->raw('-k 31923')),
        'nak' => (function () use ($stack): string {
            $run = Process::timeout(20)->run('nak req -k 31923 -l 3 '.escapeshellarg($stack->relayUrl).' </dev/null');

            return $run->exitCode().' '.substr($run->output(), 0, 200).' ERR '.substr($run->errorOutput(), -400);
        })(),
        'kinds' => collect($relay->raw('-l 20000'))->countBy('kind')->all(),
        'relays' => RelayDelivery::query()->distinct()->pluck('relay')->all(),
        'mine' => $stack->relayUrl,
        'stored 2157' => NostrEvent::query()->where('kind', 2157)->count(),
        'deliveries' => RelayDelivery::query()->whereIn('nostr_event_id', NostrEvent::query()->where('kind', 2157)->select('id'))->get(['accepted', 'message'])->toArray(),
    ]);

    // A top-up is a plain invoice: no zap receipt anywhere.
    expect($payoutEvents)->toHaveCount(4, $diagnosis)
        ->and($relay->events('-k 9735 -t a='.escapeshellarg((string) $tournament->address())))->toBe([])
        ->and(NostrEvent::query()->where('kind', 9735)->count())->toBe(0);

    foreach ($payoutEvents as $event) {
        $invoice = Bolt11::decode((string) $event->tag('bolt11'));

        expect($event->pubkey)->toBe($tournament->event->pubkey)
            ->and(hash('sha256', (string) hex2bin((string) $event->tag('preimage'))))->toBe($invoice?->paymentHash)
            ->and($paid->pluck('pubkey'))->toContain($event->tag('p'));
    }
});
