<?php

test('nostr.json names the TWENTY ONE pubkey and its relays', function () {
    config([
        'twentyone.nostr.npub' => 'npub1mapwq05fcwe2qk5ftd7qeerwpswwzpgcjfkum8w23csruj2dyjwq6lu3y5',
        'twentyone.relays.public' => ['wss://nos.lol'],
    ]);
    $pubkey = 'df42e03e89c3b2a05a895b7c0ce46e0c1ce10518926dcd9dca8e203e494d249c';

    $this->get(route('nostr.nip05', ['name' => 'esports']))
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertExactJson(['names' => ['esports' => $pubkey], 'relays' => [$pubkey => ['wss://nos.lol']]]);
});

test('nostr.json knows no other name', function () {
    $this->get(route('nostr.nip05', ['name' => 'admin']))
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', '*')
        ->assertContent('{"names":{}}');
});
