<?php

use App\Enums\IncomingPaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\TournamentFormat;
use App\Models\IncomingPayment;
use App\Models\NostrEvent;
use App\Models\RelayDelivery;
use App\Models\User;
use App\Support\Lightning\Bolt11;
use App\Support\Payouts\PayoutApproval;
use App\Support\Prizes\PrizePool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\Integration\Support\RelayCheck;
use Tests\Integration\Support\Stack;
use Tests\Support\TestSigner;

pest()->group('integration');

/*
|--------------------------------------------------------------------------
| P9 in the real stack: a tournament pool funded by a zap and paid out
|--------------------------------------------------------------------------
|
| The prize pool of a chess tournament, against a fake NIP-47 wallet
| service on the local `nak serve` relay (never a real wallet): a zap
| request goes through the app server's own LNURL endpoint, the wallet
| makes the invoice over the relay, a payer settles it, `wallet:sync`
| books it and the LNURL server key's receipt (9735) reaches the relay.
| After the admin check, each payout is paid by `payouts:run` — the winner's
| by TWO such processes started at once, which race for the same payout in
| the one SQLite file. The wallet must see exactly one `pay_invoice` per
| invoice, and every payout has its Payout (2157) on the relay, with a
| preimage that hashes to its invoice.
|
*/

test('a tournament pool: zapped through the LNURL endpoint, paid once per winner by racing processes, proven on the relay', function () {
    $stack = Stack::instance();
    $stack->wallet();
    $relay = new RelayCheck($stack->relayUrl);

    // A finished chess tournament of four; the players' Lightning addresses are at the fake LNURL server.
    $tournament = Stack::retryOnLock(fn () => runningChess(TournamentFormat::SingleElimination, 4));
    Stack::retryOnLock(fn () => publishForPool($tournament));
    $tournament->refresh();

    foreach ($tournament->participants()->get() as $participant) {
        User::query()->whereKey($participant->user_id)->update(['lud16' => 'player'.$participant->id.'@'.$stack->nwc->lnurlHost()]);
    }

    // A zap through the app server's own Lightning address.
    $pay = Http::get($stack->baseUrl.'/.well-known/lnurlp/pool')->throw()->json();
    expect($pay['allowsNostr'])->toBeTrue();

    $fan = new TestSigner;
    $request = json_encode($fan->sign(9734, [
        ['relays', $stack->relayUrl], ['amount', '21000000'], ['p', (string) PrizePool::poolPubkey()], ['a', (string) $tournament->address()], ['k', '31923'],
    ], 'Go!', now()->getTimestamp()));
    $pr = Http::get($pay['callback'], ['amount' => 21_000_000, 'nostr' => $request])->throw()->json('pr');
    $payment = IncomingPayment::query()->where('payment_hash', Bolt11::decode((string) $pr)?->paymentHash)->sole();

    expect($payment->pot)->toBe('tournament:'.$tournament->id)->and($payment->status)->toBe(IncomingPaymentStatus::Pending);

    $stack->nwc->settle($payment->payment_hash);
    $stack->artisan('wallet:sync');
    expect($payment->refresh()->status)->toBe(IncomingPaymentStatus::Settled)
        ->and(app(PrizePool::class)->fundedSats($tournament))->toBe(21_000);

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
        ->and($winner->refresh()->amount_sats)->toBe(10_500);

    // On the relay (published by the real queue worker): a Payout per player and the zap receipt.
    $payoutEvents = [];
    $receipts = [];

    // Bounded by time, not by rounds: a `nak req` against a busy relay can take seconds.
    for ($deadline = microtime(true) + 30; microtime(true) < $deadline && (count($payoutEvents) < 4 || $receipts === []);) {
        $payoutEvents = $relay->events('-k 2157 -t a='.escapeshellarg((string) $tournament->address()));
        $receipts = $relay->events('-k 9735 -t a='.escapeshellarg((string) $tournament->address()));
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

    expect($payoutEvents)->toHaveCount(4, $diagnosis)->and($receipts)->toHaveCount(1)
        ->and($receipts[0]->tag('P'))->toBe($fan->pubkey)
        ->and(hash('sha256', (string) $receipts[0]->tag('description')))->toBe(Bolt11::decode((string) $receipts[0]->tag('bolt11'))?->descriptionHash);

    foreach ($payoutEvents as $event) {
        $invoice = Bolt11::decode((string) $event->tag('bolt11'));

        expect($event->pubkey)->toBe($tournament->event->pubkey)
            ->and(hash('sha256', (string) hex2bin((string) $event->tag('preimage'))))->toBe($invoice?->paymentHash)
            ->and($paid->pluck('pubkey'))->toContain($event->tag('p'));
    }
});
