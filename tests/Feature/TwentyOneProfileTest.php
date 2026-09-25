<?php

use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use App\Support\TwentyOne\EventBuilder;
use App\Support\TwentyOne\TwentyOneSigner;
use Illuminate\Support\Facades\Artisan;
use swentel\nostr\Key\Key;
use Tests\Support\TestSigner;

pest()->group('nostr');

beforeEach(function () {
    $this->key = new TestSigner;
    $this->nsec = (new Key)->convertPrivateKeyToBech32($this->key->secret);

    config([
        'twentyone.nostr.nsec' => $this->nsec,
        'twentyone.nostr.npub' => NostrKeys::hexToNpub($this->key->pubkey),
    ]);
});

test('the profile and relay list are signed by the configured key', function () {
    $signer = TwentyOneSigner::fromNsec($this->nsec);
    $builder = new EventBuilder;

    $profile = SignedEvent::fromInput($signer->sign($builder->profile([
        'name' => 'TWENTY ONE Esports',
        'website' => 'https://esports.einundzwanzig.space',
        'lud16' => null,
        'about' => '',
    ])));
    $relayList = SignedEvent::fromInput($signer->sign($builder->relayList(['wss://nos.lol', 'wss://relay.damus.io'])));

    expect($profile->kind)->toBe(0)
        ->and($profile->pubkey)->toBe($this->key->pubkey)
        ->and($profile->content)->toBe('{"name":"TWENTY ONE Esports","website":"https://esports.einundzwanzig.space"}')
        ->and($profile->hasValidSignature())->toBeTrue()
        ->and($relayList->kind)->toBe(10002)
        ->and($relayList->tags)->toBe([['r', 'wss://nos.lol'], ['r', 'wss://relay.damus.io']])
        ->and($relayList->hasValidSignature())->toBeTrue();
});

test('the command refuses to sign without a usable nsec', function (?string $nsec, ?string $npub, string $error) {
    if ($nsec !== 'configured') {
        config(['twentyone.nostr.nsec' => $nsec]);
    }

    if ($npub !== null) {
        config(['twentyone.nostr.npub' => $npub]);
    }

    $this->artisan('twentyone:profile', ['--dry-run' => true])
        ->expectsOutputToContain($error)
        ->assertExitCode(1);
})->with([
    'unset' => [null, null, 'TWENTYONE_NOSTR_NSEC is not set.'],
    'not bech32' => ['nsec1notakey', null, 'TWENTYONE_NOSTR_NSEC is not a valid nsec.'],
    'an npub' => ['npub1mapwq05fcwe2qk5ftd7qeerwpswwzpgcjfkum8w23csruj2dyjwq6lu3y5', null, 'TWENTYONE_NOSTR_NSEC is not a valid nsec.'],
    'another key than the npub' => ['configured', 'npub1mapwq05fcwe2qk5ftd7qeerwpswwzpgcjfkum8w23csruj2dyjwq6lu3y5', 'TWENTYONE_NOSTR_NSEC does not belong to TWENTYONE_NOSTR_NPUB.'],
]);

test('the command never prints the secret key', function () {
    $dryRunExit = Artisan::call('twentyone:profile', ['--dry-run' => true]);
    $dryRun = Artisan::output();
    // Port 1 refuses the connection at once: the failure path prints too.
    $publishExit = Artisan::call('twentyone:profile', ['--relays' => 'ws://127.0.0.1:1']);
    $publish = Artisan::output();

    expect($dryRunExit)->toBe(0)
        ->and($dryRun)->toContain('"kind": 0', '"kind": 10002', $this->key->pubkey)
        ->and($publishExit)->toBe(1)
        ->and($publish)->toContain('kind 0 ws://127.0.0.1:1 failed', 'kind 10002 ws://127.0.0.1:1 failed');

    foreach ([$dryRun, $publish] as $output) {
        expect($output)->not->toContain($this->nsec)
            ->and($output)->not->toContain($this->key->secret);
    }
});
