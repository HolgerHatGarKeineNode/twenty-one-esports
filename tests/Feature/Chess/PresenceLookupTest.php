<?php

use App\Models\User;
use App\Support\Chess\PresenceLookup;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Exceptions;
use Pusher\ApiErrorException;
use Pusher\Pusher;

function presenceAnswering(Throwable $error): PresenceLookup
{
    $pusher = Mockery::mock(Pusher::class);
    $pusher->shouldReceive('get')->andThrow($error);
    $broadcaster = Mockery::mock(PusherBroadcaster::class);
    $broadcaster->shouldReceive('getPusher')->andReturn($pusher);
    $manager = Mockery::mock(BroadcastManager::class);
    $manager->shouldReceive('connection')->andReturn($broadcaster);

    return new PresenceLookup($manager);
}

test('an empty presence channel (Reverb 404) is no error report, and still cannot tell', function () {
    Exceptions::fake();

    expect(presenceAnswering(new ApiErrorException('{}', 404))->online(User::factory()->make(['id' => 7])))->toBeNull();

    Exceptions::assertNothingReported();
});

test('any other websocket server failure is still reported', function () {
    Exceptions::fake();

    expect(presenceAnswering(new ApiErrorException('{}', 500))->online(User::factory()->make(['id' => 7])))->toBeNull();

    Exceptions::assertReported(ApiErrorException::class);
});
