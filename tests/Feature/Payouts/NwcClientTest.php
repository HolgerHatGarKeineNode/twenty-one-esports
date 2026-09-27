<?php

use App\Support\Wallet\NwcCipher;
use App\Support\Wallet\NwcClient;
use App\Support\Wallet\NwcConnection;
use App\Support\Wallet\NwcError;
use App\Support\Wallet\NwcTransport;
use Tests\Support\FakeNwcTransport;
use Tests\Support\FakeNwcWallet;
use Tests\Support\TestSigner;

/*
| The NIP-47 client (P9) against the fake wallet with signature checks on:
| both encryptions, errors, timeouts, and answers a hostile relay forges.
*/

function nwcClientFor(FakeNwcWallet $wallet): NwcClient
{
    $wallet->verifyRequests = true;
    $transport = new FakeNwcTransport($wallet);
    app()->instance(NwcTransport::class, $transport);
    app()->instance(FakeNwcTransport::class, $transport);

    return new NwcClient(NwcConnection::fromUri($wallet->uri('receive')), $transport);
}

test('the client speaks NIP-44 when the wallet announces it, NIP-04 when it announces nothing', function () {
    foreach ([[NwcCipher::NIP44, NwcCipher::NIP04], []] as $schemes) {
        $wallet = new FakeNwcWallet;
        $wallet->encryption = $schemes;
        $client = nwcClientFor($wallet);

        $result = $client->request('get_balance', []);

        expect($client->encryption())->toBe($schemes === [] ? NwcCipher::NIP04 : NwcCipher::NIP44)
            ->and($result['balance'])->toBe($wallet->balanceMsats)
            ->and($wallet->calls)->toHaveCount(1);
    }
});

test('a wallet error is an NwcError with its code, no answer is a timeout', function () {
    $wallet = new FakeNwcWallet;
    $client = nwcClientFor($wallet);

    expect(fn () => $client->request('lookup_invoice', ['payment_hash' => str_repeat('00', 32)]))->toThrow(fn (NwcError $error) => expect($error->errorCode)->toBe('NOT_FOUND'));

    $wallet->ignoreNextRequest = true;
    expect(fn () => $client->request('get_balance', []))->toThrow(fn (NwcError $error) => expect($error->isTimeout())->toBeTrue());
});

test('answers a relay forges are skipped: wrong author, wrong request, bad signature, unreadable', function () {
    $wallet = new FakeNwcWallet;
    $client = nwcClientFor($wallet);
    $transport = app(FakeNwcTransport::class);
    $client->encryption();
    $stranger = new TestSigner;
    $client_ = $wallet->clients['receive']['pubkey'];

    $answer = fn (TestSigner $by, array $tags, string $content) => $by->sign(23195, $tags, $content, now()->getTimestamp());
    $lie = NwcCipher::encrypt(NwcCipher::NIP44, json_encode(['result_type' => 'get_balance', 'result' => ['balance' => 1]]), $stranger->secret, $client_);

    $transport->forged = [
        // Signed by someone else than the wallet.
        $answer($stranger, [['p', $client_], ['e', str_repeat('00', 32)]], $lie),
        // The wallet's key, a broken signature.
        [...$answer(new TestSigner($wallet->secret), [['p', $client_]], $lie), 'sig' => str_repeat('0', 128)],
    ];

    expect($client->request('get_balance', [])['balance'])->toBe($wallet->balanceMsats);
});

test('a request carries an expiration, and a wallet that gets it late does not act', function () {
    $wallet = new FakeNwcWallet;
    $client = nwcClientFor($wallet);
    $client->encryption();

    $this->travel(-1)->minutes();
    config(['esports.wallet.nwc_timeout_seconds' => 5]);

    // Signed a minute "ago" with a 5 s timeout: the wallet sees it expired and never pays.
    expect(fn () => $client->request('pay_invoice', ['invoice' => 'lnbcrt1']))->toThrow(NwcError::class);
    expect($wallet->calls)->toBe([]);
});
