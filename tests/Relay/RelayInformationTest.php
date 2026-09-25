<?php

use GuzzleHttp\Client;

test('local ndak relay serves its NIP-11 information document', function (int $port) {
    $response = (new Client(['timeout' => 2]))->get("http://127.0.0.1:{$port}", [
        'headers' => ['Accept' => 'application/nostr+json'],
    ]);

    expect(json_decode((string) $response->getBody(), true))->toHaveKey('description');
})->with([
    'rnostr' => 7777,
    'strfry' => 7780,
    'khatru' => 7782,
]);
