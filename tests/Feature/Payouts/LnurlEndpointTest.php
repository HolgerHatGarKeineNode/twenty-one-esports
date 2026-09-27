<?php

use App\Enums\TournamentFormat;
use App\Models\IncomingPayment;
use App\Support\Lightning\Bolt11;
use App\Support\Prizes\PoolInvoices;
use App\Support\Prizes\PrizePool;
use App\Support\SeasonChain\LeagueKey;
use Tests\Support\TestSigner;

/*
| The league's own Lightning address pool@<host> (P9, LUD-06/LUD-16 with
| NIP-57): what wallets read, and the callback that turns a zap request
| into an invoice of the right pot.
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

test('a zap request through the callback gets an invoice for its pot whose description hash is the request as sent', function () {
    fakeWallet();
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    $signer = new TestSigner;
    $request = $signer->sign(9734, [['relays', 'wss://relay.example.org'], ['amount', '21000000'], ['p', (string) PrizePool::poolPubkey()], ['a', (string) $tournament->address()], ['k', '31923']], 'Good luck', now()->getTimestamp());
    // As a client may send it: its own key order and spacing, which the hash must keep.
    $raw = json_encode($request, JSON_PRETTY_PRINT);

    $pr = $this->getJson('/lnurlp/pool/callback?'.http_build_query(['amount' => 21_000_000, 'nostr' => $raw]))->assertOk()->json('pr');
    $invoice = Bolt11::decode((string) $pr);
    $payment = IncomingPayment::query()->sole();

    expect($invoice->amountMsats)->toBe(21_000_000)
        ->and($invoice->descriptionHash)->toBe(hash('sha256', $raw))
        ->and($payment->pot)->toBe('tournament:'.$tournament->id)
        ->and($payment->zap_request)->toBe($raw)
        ->and($payment->comment)->toBe('Good luck');
});

test('the callback refuses what it cannot count, and a plain payment goes to the reserve', function () {
    fakeWallet();
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    $request = json_encode((new TestSigner)->sign(9734, [['amount', '5000'], ['p', (string) PrizePool::poolPubkey()], ['a', (string) $tournament->address()]], '', now()->getTimestamp()));

    // Not whole sats; a request for another amount; a broken request.
    $this->getJson('/lnurlp/pool/callback?amount=1500')->assertJson(['status' => 'ERROR']);
    $this->getJson('/lnurlp/pool/callback?'.http_build_query(['amount' => 6000, 'nostr' => $request]))->assertJson(['status' => 'ERROR']);
    $this->getJson('/lnurlp/pool/callback?'.http_build_query(['amount' => 5000, 'nostr' => '{"kind":9734}']))->assertJson(['status' => 'ERROR']);
    expect(IncomingPayment::query()->count())->toBe(0);

    $this->getJson('/lnurlp/pool/callback?amount=3000&comment=thanks')->assertOk()->assertJsonStructure(['pr']);
    expect(IncomingPayment::query()->sole())->pot->toBe('reserve')->source->toBe('lnurl')->amount_sats->toBe(3);
});
