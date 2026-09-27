<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Integration\Support\RelayCheck;
use Tests\Integration\Support\Stack;

pest()->group('integration');

test('the real stack boots: app server, reverb, nak relay, fake bitcoin api, queue all reachable', function () {
    $stack = Stack::instance();

    expect(@fsockopen('127.0.0.1', $stack->appPort))->not->toBeFalse('app server (php artisan serve) is not listening')
        ->and(@fsockopen('127.0.0.1', $stack->reverbPort))->not->toBeFalse('reverb is not listening')
        ->and(@fsockopen('127.0.0.1', $stack->relayPort))->not->toBeFalse('nak serve is not listening');

    $response = Http::get($stack->baseUrl.'/__test/server-error');
    expect($response->status())->toBe(500, 'the real app server did not answer at all, or the testing fixture route is not reachable');

    $tip = Http::get($stack->bitcoin->baseUrl().'/blocks/tip/height');
    expect($tip->successful())->toBeTrue()->and(trim($tip->body()))->toBe('0');

    [$anna] = integrationPlayer('anna-smoke');
    expect(User::query()->where('name', 'anna-smoke')->exists())->toBeTrue('the DB this test process writes to is not the DB the app server reads');

    $hash = $stack->bitcoin->mine(5);
    $height = Http::get($stack->bitcoin->baseUrl().'/block-height/5');
    expect(trim($height->body()))->toBe($hash);

    $relay = new RelayCheck($stack->relayUrl);
    expect($relay->byKind(0))->toBe([]); // fresh relay, nothing published yet
});
