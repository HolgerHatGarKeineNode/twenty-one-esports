<?php

use App\Support\FairPlay\ReceivedZaps;
use App\Support\Nostr\SignedEvent;
use Tests\Support\Bolt11Fixture;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| Fair play: zap receipts an account received (plan "Profile standard and
| impersonation", P3, soft mark "zaps")
|--------------------------------------------------------------------------
|
| A kind-9735 receipt counts only when it names the account, carries the
| zapper's validly signed kind-9734 request naming the account too, the
| zapper is not the account itself, and the invoice is for at least 1 sat.
| The receipt's own signer is the zapping service, which nobody here trusts:
| the zapper is read from the signed request.
|
*/

beforeEach(function () {
    $this->freezeSecond();
});

/**
 * A receipt by a zapping service for a request $zapper signed, the request naming $requestFor and the receipt $receiptFor.
 *
 * @param  Closure(array<string, mixed>): array<string, mixed>|null  $tamper  changes the signed request before it is embedded
 */
function rzReceipt(TestSigner $zapper, string $receiptFor, ?string $requestFor = null, int $msats = 21_000, ?Closure $tamper = null): SignedEvent
{
    $request = $zapper->sign(9734, [['p', $requestFor ?? $receiptFor], ['amount', (string) $msats], ['relays', 'wss://relay.example.com']], 'gg', now()->getTimestamp() - 5);
    $request = $tamper === null ? $request : $tamper($request);
    $description = (string) json_encode($request);
    $invoice = Bolt11Fixture::make($msats, hash('sha256', $description), timestamp: now()->getTimestamp() - 5)['invoice'];

    return SignedEvent::fromInput((new TestSigner)->sign(9735, [['p', $receiptFor], ['bolt11', $invoice], ['description', $description]], '', now()->getTimestamp()));
}

test('a receipt with the zapper\'s validly signed request for the account counts the zapper and the sats', function () {
    $recipient = new TestSigner;
    $zapper = new TestSigner;
    $receipt = rzReceipt($zapper, $recipient->pubkey, msats: 21_000);

    $parsed = ReceivedZaps::parse($receipt, $recipient->pubkey);

    expect($parsed)->not->toBeNull()
        ->and(collect($parsed)->except('zapped_at')->all())->toBe([
            'receipt_id' => $receipt->id,
            'recipient_pubkey' => $recipient->pubkey,
            'zapper_pubkey' => $zapper->pubkey,
            'sats' => 21,
        ])
        ->and($parsed['zapped_at']->getTimestamp())->toBe(now()->getTimestamp())
        ->and(ReceivedZaps::parse(rzReceipt($zapper, $recipient->pubkey, msats: 1000), $recipient->pubkey)['sats'] ?? null)->toBe(1);
});

test('a receipt does not count when', function (Closure $receipt) {
    $recipient = new TestSigner;

    expect(ReceivedZaps::parse($receipt($recipient), $recipient->pubkey))->toBeNull();
})->with([
    'the request\'s signature is forged (content changed after signing)' => [fn (TestSigner $recipient): SignedEvent => rzReceipt(new TestSigner, $recipient->pubkey, tamper: fn (array $request): array => [...$request, 'content' => 'forged'])],
    'the request\'s signature is replaced' => [fn (TestSigner $recipient): SignedEvent => rzReceipt(new TestSigner, $recipient->pubkey, tamper: fn (array $request): array => [...$request, 'sig' => str_repeat('0', 128)])],
    'the account zapped itself' => [fn (TestSigner $recipient): SignedEvent => rzReceipt($recipient, $recipient->pubkey)],
    'the request names someone else' => [fn (TestSigner $recipient): SignedEvent => rzReceipt(new TestSigner, $recipient->pubkey, requestFor: (new TestSigner)->pubkey)],
    'the receipt names someone else' => [fn (TestSigner $recipient): SignedEvent => rzReceipt(new TestSigner, (new TestSigner)->pubkey)],
    'the invoice is for less than 1 sat' => [fn (TestSigner $recipient): SignedEvent => rzReceipt(new TestSigner, $recipient->pubkey, msats: 999)],
]);
