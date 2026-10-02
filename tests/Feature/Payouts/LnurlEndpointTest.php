<?php

use App\Enums\TournamentFormat;
use App\Models\IncomingPayment;
use App\Support\Lightning\Bolt11;
use App\Support\Prizes\PoolInvoices;
use App\Support\SeasonChain\LeagueKey;
use Tests\Support\TestSigner;

/*
| The league's own Lightning address pool@<host> (P9, LUD-06/LUD-16 with
| NIP-57): what wallets read, and the callback that turns a zap request into
| an invoice of the league wallet for the reserve or, naming a tournament's
| calendar event by `a`, for that tournament's pot (user, 2026-10-02). A
| tournament's own LNURL is the same endpoint with `?pot=<id>`.
*/

test('the pay request names the LNURL server key and allows zaps; fail closed without the wallet', function () {
    fakeWallet();

    $this->getJson('/.well-known/lnurlp/pool')->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertJson([
            'tag' => 'payRequest',
            'callback' => route('lnurl.callback', ['username' => 'pool']),
            'minSendable' => 1000,
            'allowsNostr' => true,
            'nostrPubkey' => LeagueKey::lnurl()?->pubkey(),
            'metadata' => PoolInvoices::metadata(),
        ]);

    $this->getJson('/.well-known/lnurlp/alice')->assertNotFound()->assertJson(['status' => 'ERROR']);

    config(['esports.wallet.nwc_receive_uri' => null]);
    $this->getJson('/.well-known/lnurlp/pool')->assertOk()->assertJson(['status' => 'ERROR'])->assertJsonMissing(['tag' => 'payRequest']);
});

test('a zap request through the callback gets a reserve invoice whose description hash is the request as sent', function () {
    fakeWallet();
    $signer = new TestSigner;
    $request = $signer->sign(9734, [['relays', 'wss://relay.example.org'], ['amount', '21000000'], ['p', (string) LeagueKey::poolPubkey()]], 'Good luck', now()->getTimestamp());
    // As a client may send it: its own key order and spacing, which the hash must keep.
    $raw = json_encode($request, JSON_PRETTY_PRINT);

    $pr = $this->getJson('/lnurlp/pool/callback?'.http_build_query(['amount' => 21_000_000, 'nostr' => $raw]))->assertOk()->json('pr');
    $invoice = Bolt11::decode((string) $pr);
    $payment = IncomingPayment::query()->sole();

    expect($invoice->amountMsats)->toBe(21_000_000)
        ->and($invoice->descriptionHash)->toBe(hash('sha256', $raw))
        ->and($payment->pot)->toBe(IncomingPayment::RESERVE)
        ->and($payment->zap_request)->toBe($raw)
        ->and($payment->comment)->toBe('Good luck');
});

test('the callback refuses what it cannot count, routes a zap naming a tournament into its pot, and a plain payment goes to the reserve', function () {
    fakeWallet();
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    $closed = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    $closed->forceFill(['pool_closed_at' => now()])->save();
    $pool = (string) LeagueKey::poolPubkey();
    $sign = fn (array $tags): string => json_encode((new TestSigner)->sign(9734, [['amount', '5000'], ['p', $pool], ...$tags], '', now()->getTimestamp()));
    $request = $sign([]);

    // Not whole sats; a request for another amount; a broken request; a pot that closed; two tournaments; an event that is not the tournament's.
    $this->getJson('/lnurlp/pool/callback?amount=1500')->assertJson(['status' => 'ERROR']);
    $this->getJson('/lnurlp/pool/callback?'.http_build_query(['amount' => 6000, 'nostr' => $request]))->assertJson(['status' => 'ERROR']);
    $this->getJson('/lnurlp/pool/callback?'.http_build_query(['amount' => 5000, 'nostr' => '{"kind":9734}']))->assertJson(['status' => 'ERROR']);
    $this->getJson('/lnurlp/pool/callback?'.http_build_query(['amount' => 5000, 'nostr' => $sign([['a', (string) $closed->address()]])]))->assertJson(['status' => 'ERROR']);
    $this->getJson('/lnurlp/pool/callback?'.http_build_query(['amount' => 5000, 'nostr' => $sign([['a', (string) $tournament->address()], ['a', (string) $closed->address()]])]))->assertJson(['status' => 'ERROR', 'reason' => __('This tournament takes no zaps into its pot right now.')]);
    $this->getJson('/lnurlp/pool/callback?'.http_build_query(['amount' => 5000, 'nostr' => $sign([['a', (string) $tournament->address()], ['e', str_repeat('ab', 32)]])]))->assertJson(['status' => 'ERROR', 'reason' => __('The zap request names another event than the tournament.')]);
    $this->getJson('/lnurlp/pool/callback?'.http_build_query(['amount' => 5000, 'nostr' => $sign([['a', '31923:'.str_repeat('cd', 32).':'.$tournament->slug]])]))->assertJson(['status' => 'ERROR', 'reason' => __('This tournament takes no zaps into its pot right now.')]);
    expect(IncomingPayment::query()->count())->toBe(0);

    // A zap naming the tournament (with its current version and kind, as clients send them) goes into its pot.
    $this->getJson('/lnurlp/pool/callback?'.http_build_query(['amount' => 5000, 'nostr' => $sign([['a', (string) $tournament->address()], ['e', (string) $tournament->event->event_id], ['k', '31923']])]))->assertOk()->assertJsonStructure(['pr']);
    expect(IncomingPayment::query()->sole())->pot->toBe($tournament->potAccount())->tournament_id->toBe($tournament->id)->source->toBe('zap');

    $this->getJson('/lnurlp/pool/callback?amount=3000&comment=thanks')->assertOk()->assertJsonStructure(['pr']);
    expect(IncomingPayment::query()->latest('id')->first())->pot->toBe('reserve')->source->toBe('lnurl')->amount_sats->toBe(3);
});

test('a tournament’s LNURL takes plain payments into its pot and zaps only for it; a closed pot takes nothing', function () {
    fakeWallet();
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    $other = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    $pool = (string) LeagueKey::poolPubkey();

    $this->getJson('/.well-known/lnurlp/pool?pot='.$tournament->id)->assertOk()->assertJson([
        'tag' => 'payRequest',
        'callback' => route('lnurl.callback', ['username' => 'pool', 'pot' => $tournament->id]),
        'allowsNostr' => true,
        'metadata' => PoolInvoices::metadata($tournament),
    ]);
    // The QR code on the page is exactly this LNURL.
    expect(PoolInvoices::lnurl($tournament))->not->toBe(PoolInvoices::lnurl());

    $pr = $this->getJson('/lnurlp/pool/callback?pot='.$tournament->id.'&amount=21000')->assertOk()->json('pr');
    expect(Bolt11::decode((string) $pr)?->descriptionHash)->toBe(hash('sha256', PoolInvoices::metadata($tournament)))
        ->and(IncomingPayment::query()->sole())->pot->toBe($tournament->potAccount())->source->toBe('lnurl');

    // A zap for another tournament, or for none, through this LNURL is refused rather than counted in the wrong pot.
    foreach ([[['a', (string) $other->address()]], []] as $tags) {
        $request = json_encode((new TestSigner)->sign(9734, [['amount', '5000'], ['p', $pool], ...$tags], '', now()->getTimestamp()));
        $this->getJson('/lnurlp/pool/callback?'.http_build_query(['pot' => $tournament->id, 'amount' => 5000, 'nostr' => $request]))->assertJson(['status' => 'ERROR']);
    }

    $tournament->forceFill(['pool_closed_at' => now()])->save();
    $this->getJson('/.well-known/lnurlp/pool?pot='.$tournament->id)->assertJson(['status' => 'ERROR']);
    $this->getJson('/lnurlp/pool/callback?pot='.$tournament->id.'&amount=21000')->assertJson(['status' => 'ERROR']);
    $this->getJson('/lnurlp/pool/callback?pot=999999&amount=21000')->assertJson(['status' => 'ERROR']);
    expect(IncomingPayment::query()->count())->toBe(1);
});
