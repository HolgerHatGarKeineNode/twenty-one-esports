<?php

use App\Enums\TournamentFormat;
use App\Models\IncomingPayment;
use App\Models\NostrEvent;
use App\Support\Nostr\SignedEvent;
use App\Support\Prizes\IncomingPayments;
use App\Support\Prizes\PoolInvoices;
use App\Support\Prizes\PoolRefusal;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Tournaments\TournamentPrizePool;
use App\Support\Tournaments\TournamentPublisher;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestSigner;

/*
| Security gate on 8a171405, F2: the zap sponsors' wall must not cost more per
| page view the more people zap. Each receipt is verified once, when the
| league signs it; a page view is one query over the verified rows,
| remembered for the request; zap requests are small.
*/

test('the wall reads verified rows in one query per request and never re-verifies a receipt per view', function () {
    $league = fakeWallet();
    config(['esports.wallet.open_invoices_per_user' => 1000]);
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    $tournament->forceFill(['prize_target_sats' => 100_000])->save();
    app(TournamentPublisher::class)->republish($tournament->refresh());
    $signer = new TestSigner;

    foreach (range(1, 30) as $i) {
        $request = SignedEvent::fromInput($signer->sign(9734, [['amount', '1000'], ['p', (string) LeagueKey::poolPubkey()], ['a', (string) $tournament->address()]], '', now()->getTimestamp() + $i));
        $payment = app(PoolInvoices::class)->forZapRequest($request, $request->toJson(), 1);
        $league->settleIncoming($payment->payment_hash);
        app(IncomingPayments::class)->check($payment, 0);
    }

    expect(IncomingPayment::query()->where('zap_verified', true)->count())->toBe(30);

    // The stored receipts become unreadable: a view that re-verified them would count nothing.
    NostrEvent::query()->where('kind', 9735)->update(['raw' => '{}']);
    app()->forgetScopedInstances();

    // The tournament's own calendar event (its address) is loaded first: only the receipts are in question.
    $tournament->refresh()->load('event');
    DB::flushQueryLog();
    DB::enableQueryLog();
    $first = app(TournamentPrizePool::class)->for($tournament);
    $second = app(TournamentPrizePool::class)->for($tournament);
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($first['zaps'])->toBe(30)->and($second['zaps'])->toBe(30)
        ->and($first['zappers'])->toHaveCount(1)
        ->and($queries->filter(fn (string $query): bool => str_contains($query, 'incoming_payments'))->count())->toBe(1)
        ->and($queries->filter(fn (string $query): bool => str_contains($query, 'nostr_events'))->count())->toBe(0);
});

test('a zap request is small: a long comment or a large request is refused before any invoice', function () {
    $league = fakeWallet();
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    $signer = new TestSigner;
    $tags = [['amount', '1000'], ['p', (string) LeagueKey::poolPubkey()], ['a', (string) $tournament->address()]];
    $invoices = count($league->invoices);

    $long = SignedEvent::fromInput($signer->sign(9734, $tags, str_repeat('x', PoolInvoices::MAX_ZAP_COMMENT + 1), now()->getTimestamp()));
    $large = SignedEvent::fromInput($signer->sign(9734, [...$tags, ['relays', ...array_fill(0, 200, 'wss://relay.example.org')]], '', now()->getTimestamp()));

    foreach ([$long, $large] as $request) {
        expect(fn () => app(PoolInvoices::class)->forZapRequest($request, $request->toJson(), 1))->toThrow(PoolRefusal::class, 'too long');
    }

    $ok = SignedEvent::fromInput($signer->sign(9734, $tags, str_repeat('x', PoolInvoices::MAX_ZAP_COMMENT), now()->getTimestamp()));
    expect(app(PoolInvoices::class)->forZapRequest($ok, $ok->toJson(), 1)->pot)->toBe($tournament->potAccount())
        ->and(count($league->invoices))->toBe($invoices + 1);
});
