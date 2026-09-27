<?php

use App\Support\Wallet\NwcCipher;
use App\Support\Wallet\NwcConnection;
use swentel\nostr\Encryption\Nip04;
use swentel\nostr\Key\Key;

/*
| NIP-47 encryption and connection strings (P9).
*/

// Made with nostr-tools 2.x (nip04.encrypt) for a key pair whose shared x
// coordinate starts with a zero byte (0031e0bb…), throwaway keys.
const NIP04_FIXTURE = [
    'sender' => '249f40c93daaa2f2ee915ffcf799d05525bacb707b580680892cfa0a62315533',
    'recipient_secret' => '2950351691f05a41b9f54722a8a4ce3749628497ac3779800ec77c4451e7d405',
    'ciphertext' => 'AT70tEfQ23nXENCChgKfvvOv90n/NMEQ3W8sN8fAgm6bli4HVT4Sx76BV3swTrmFM+iD7JaWYLUtH1ejN6pi5Q==?iv=3lqx3jc3WrFZGD2Oy8WItw==',
    'plaintext' => '{"result_type":"pay_invoice","result":{"preimage":"00"}}',
];

test('NIP-04 reads what nostr-tools wrote, also where swentel/nostr-php keys AES wrong', function () {
    expect(NwcCipher::decrypt(NwcCipher::NIP04, NIP04_FIXTURE['ciphertext'], NIP04_FIXTURE['recipient_secret'], NIP04_FIXTURE['sender']))->toBe(NIP04_FIXTURE['plaintext'])
        ->and(fn () => Nip04::decrypt(NIP04_FIXTURE['ciphertext'], NIP04_FIXTURE['recipient_secret'], NIP04_FIXTURE['sender']))->toThrow(Exception::class);
});

test('both schemes round-trip between two keys, and a wrong key cannot read', function () {
    $alice = bin2hex(random_bytes(32));
    $bob = bin2hex(random_bytes(32));
    $mallory = bin2hex(random_bytes(32));
    $key = new Key;

    foreach ([NwcCipher::NIP44, NwcCipher::NIP04] as $scheme) {
        $payload = NwcCipher::encrypt($scheme, 'hello wallet', $alice, $key->getPublicKey($bob));

        try {
            // NIP-04 has no MAC: a wrong key usually fails the padding, rarely yields noise.
            $stolen = NwcCipher::decrypt($scheme, $payload, $mallory, $key->getPublicKey($alice));
        } catch (RuntimeException) {
            $stolen = null;
        }

        expect(NwcCipher::decrypt($scheme, $payload, $bob, $key->getPublicKey($alice)))->toBe('hello wallet')
            ->and($stolen)->not->toBe('hello wallet');
    }
});

test('a connection string parses with several relays and a secret in hex or nsec, and shows no secret', function () {
    $secret = bin2hex(random_bytes(32));
    $wallet = (new Key)->getPublicKey(bin2hex(random_bytes(32)));
    $uri = 'nostr+walletconnect://'.$wallet.'?relay='.rawurlencode('wss://relay.one').'&relay='.rawurlencode('wss://relay.two/x').'&secret='.$secret.'&lud16=league%40example.org';
    $connection = NwcConnection::fromUri($uri);

    expect($connection->walletPubkey)->toBe($wallet)
        ->and($connection->relays)->toBe(['wss://relay.one', 'wss://relay.two/x'])
        ->and($connection->clientPubkey)->toBe((new Key)->getPublicKey($secret))
        ->and($connection->withSecret(fn (string $value): string => $value))->toBe($secret)
        ->and(NwcConnection::fromUri(str_replace($secret, (new Key)->convertPrivateKeyToBech32($secret), $uri))?->clientPubkey)->toBe($connection->clientPubkey);

    foreach ([print_r($connection, true), var_export((array) $connection, true), json_encode($connection)] as $shown) {
        expect(str_contains((string) $shown, $secret))->toBeFalse();
    }

    expect(fn () => serialize($connection))->toThrow(LogicException::class);

    foreach (['', 'https://example.org', 'nostr+walletconnect://'.$wallet.'?secret='.$secret, 'nostr+walletconnect://'.$wallet.'?relay=wss%3A%2F%2Fr', 'nostr+walletconnect://nope?relay=wss%3A%2F%2Fr&secret='.$secret, 'nostr+walletconnect://'.$wallet.'?relay=https%3A%2F%2Fr&secret='.$secret] as $bad) {
        expect(NwcConnection::fromUri($bad))->toBeNull();
    }
});
