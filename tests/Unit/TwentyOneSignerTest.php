<?php

use App\Support\TwentyOne\TwentyOneSigner;
use swentel\nostr\Key\Key;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;
use Tests\Support\TestSigner;

test('the signer does not reveal its secret to dumps, exports or serialization', function () {
    $key = new TestSigner;
    $signer = TwentyOneSigner::fromNsec((new Key)->convertPrivateKeyToBech32($key->secret));

    $dump = (new CliDumper)->dump((new VarCloner)->cloneVar($signer), true);
    $export = var_export($signer, true);
    $cast = print_r((array) $signer, true);

    expect($signer->pubkey)->toBe($key->pubkey)
        ->and($dump)->not->toContain($key->secret)
        ->and($export)->not->toContain($key->secret)
        ->and($cast)->not->toContain($key->secret)
        ->and(fn () => serialize($signer))->toThrow(Exception::class);
});
