<?php

use App\Support\Lightning\Bolt11;
use Tests\Support\Bolt11Fixture;

/*
| BOLT11 parsing (P9), against the regtest invoices of the NIP's relay proof:
| real invoices signed by a Lightning node, with the receipts and payouts
| that reference them.
*/

/**
 * The first JSON block after a heading of docs/nips/esports.md.
 *
 * @return array<string, mixed>
 */
function nipExample(string $heading): array
{
    $doc = (string) file_get_contents(__DIR__.'/../../../docs/nips/esports.md');
    $from = strpos($doc, $heading);
    expect($from)->not->toBeFalse();
    preg_match('/```json\n(.*?)\n```/s', $doc, $match, 0, (int) $from);

    return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed>  $event
 */
function nipTag(array $event, string $name): ?string
{
    foreach ($event['tags'] as $tag) {
        if ($tag[0] === $name) {
            return $tag[1];
        }
    }

    return null;
}

test('the sponsor receipt of the NIP: amount, description hash and preimage match the invoice', function () {
    $receipt = nipExample('#### Zap receipt (`9735`), sponsor');
    $invoice = Bolt11::decode((string) nipTag($receipt, 'bolt11'));
    $request = json_decode((string) nipTag($receipt, 'description'), true);

    expect($invoice)->not->toBeNull()
        ->and($invoice->network)->toBe('bcrt')
        ->and($invoice->amountMsats)->toBe(50_000_000)
        ->and((string) $invoice->amountMsats)->toBe(nipTag($request, 'amount'))
        ->and($invoice->descriptionHash)->toBe(hash('sha256', (string) nipTag($receipt, 'description')))
        ->and($invoice->paymentHash)->toBe(hash('sha256', (string) hex2bin((string) nipTag($receipt, 'preimage'))))
        ->and($invoice->expiry)->toBe(3600);
});

test('the payouts of the NIP: the preimage hashes to the invoice’s payment hash', function () {
    foreach (['#### Payout (`2157`), alice' => 4_462_000, '#### Payout (`2157`), carol, with the bounty' => 22_050_000] as $heading => $msats) {
        $payout = nipExample($heading);
        $invoice = Bolt11::decode((string) nipTag($payout, 'bolt11'));

        expect($invoice->amountMsats)->toBe($msats)
            ->and($invoice->paymentHash)->toBe(hash('sha256', (string) hex2bin((string) nipTag($payout, 'preimage'))));
    }
});

test('a broken checksum, a foreign string or a half amount is no invoice', function () {
    $good = nipTag(nipExample('#### Payout (`2157`), alice'), 'bolt11');

    expect(Bolt11::decode(substr($good, 0, -1).($good[-1] === 'q' ? 'p' : 'q')))->toBeNull()
        ->and(Bolt11::decode('lnurl1dp68gurn8ghj7'))->toBeNull()
        ->and(Bolt11::decode('npub1sn0wdenkukak0d9dfczzeacvhkrgz92ak56egt7vdgzn8pv2wfqqhrjdv9'))->toBeNull()
        ->and(Bolt11::decode(strtoupper($good)))->not->toBeNull()
        ->and(Bolt11::decode('lightning:'.$good)?->amountMsats)->toBe(4_462_000);

    // 15p is 1.5 msat: not a whole millisatoshi, so no invoice (BOLT11: a `p` amount ends in 0); 10p is 1 msat.
    $hash = str_repeat('ab', 32);
    expect(Bolt11::decode(Bolt11Fixture::encode(0, $hash, $hash, 60, time(), amount: '15p')))->toBeNull()
        ->and(Bolt11::decode(Bolt11Fixture::encode(0, $hash, $hash, 60, time(), amount: '10p'))?->amountMsats)->toBe(1);
});

test('the test fixtures round-trip through the parser', function () {
    foreach ([1000, 21_000_000, 123_456, 7] as $msats) {
        $fixture = Bolt11Fixture::make($msats, str_repeat('ef', 32), 900);
        $invoice = Bolt11::decode($fixture['invoice']);

        expect($invoice->amountMsats)->toBe($msats)
            ->and($invoice->paymentHash)->toBe($fixture['payment_hash'])
            ->and($invoice->descriptionHash)->toBe(str_repeat('ef', 32))
            ->and($invoice->expiry)->toBe(900)
            ->and($invoice->isExpired())->toBeFalse();
    }
});
