<?php

use Mdanter\Ecc\Crypto\Signature\SchnorrSigner;
use swentel\nostr\Event\Event;
use swentel\nostr\Sign\Sign;
use Tests\Support\FastSchnorr;
use Tests\Support\TestSigner;

// The test processes sign with tests/Support/FastSign.php instead of the
// constant-time vendor signer (an order of magnitude slower). These guard that
// the stand-in still produces signatures the rest of the world accepts.

it('reproduces the official BIP-340 test vector 0', function () {
    $signature = FastSchnorr::sign(
        str_repeat('0', 63).'3',
        str_repeat('0', 64),
        str_repeat("\0", 32),
    );

    expect(strtoupper($signature))->toBe('E907831F80848D1069A5371B402410364BDF1C5F8307B0084C55F1CE2DCA821525F66A4A85EA8B71E482A74F382D2CE5EBEEE8FDB2172F477DF4900D310536C0');
});

it('signs events so that the vendor verifier accepts them, and rejects a changed one', function () {
    $signer = new TestSigner;
    $event = $signer->sign(1, [['t', 'fast']], 'hello');

    $verifier = new SchnorrSigner;

    expect($verifier->verify($signer->pubkey, $event['sig'], $event['id']))->toBeTrue()
        ->and($verifier->verify($signer->pubkey, $event['sig'], hash('sha256', 'another event')))->toBeFalse();
});

it('is the Sign class every `new Sign` in this process gets', function () {
    // Not the vendor file: the app's own signers would otherwise still pay for the slow maths.
    expect((new ReflectionClass(Sign::class))->getFileName())->toEndWith('tests/Support/FastSign.php');
});

it('keeps the id the event already carries and fills pubkey and signature', function () {
    $signer = new TestSigner;
    $event = (new Event)->setKind(1)->setContent('x')->setCreatedAt(1_700_000_000);

    (new Sign)->signEvent($event, $signer->secret);

    expect($event->getPublicKey())->toBe($signer->pubkey)
        ->and($event->getId())->toBe(hash('sha256', Sign::serializeEvent($event)))
        ->and($event->getSignature())->toHaveLength(128);
});
