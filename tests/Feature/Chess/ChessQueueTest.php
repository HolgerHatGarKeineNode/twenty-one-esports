<?php

use App\Enums\ChessInviteStatus;
use App\Events\ChessGameStarted;
use App\Models\ChessGame;
use App\Models\ChessQueueEntry;
use App\Models\User;
use App\Support\Chess\ChessInvites;
use App\Support\Chess\ChessQueue;
use Illuminate\Support\Facades\Event;

beforeEach(fn () => $this->freezeTime());

test('the queue pairs two searching players into a casual blitz game', function () {
    Event::fake([ChessGameStarted::class]);
    [$anna, $bert] = User::factory()->count(2)->create();
    $queue = app(ChessQueue::class);

    expect($queue->join($anna))->toBeNull()
        ->and($queue->entryOf($anna))->not->toBeNull();

    $game = $queue->join($bert);

    expect($game)->toBeInstanceOf(ChessGame::class)
        ->and([$game->white_id, $game->black_id])->toEqualCanonicalizing([$anna->id, $bert->id])
        ->and($game->rated)->toBeFalse()
        ->and($game->mode)->toBe('blitz')
        ->and(ChessQueueEntry::query()->count())->toBe(0)
        // A player who waited is sent to the game by the next poll.
        ->and($queue->pair($anna)?->id)->toBe($game->id);

    Event::assertDispatched(ChessGameStarted::class, fn (ChessGameStarted $event) => $event->gameId === $game->id);
});

test('the pairing range widens the longer a player waits', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $queue = app(ChessQueue::class);

    $queue->join($anna);
    $entry = $queue->entryOf($anna);
    expect($queue->range($entry))->toBe(150);

    // Bert sits 250 away: out of reach for both at first.
    ChessQueueEntry::query()->create(['user_id' => $bert->id, 'mode' => 'blitz', 'rated' => false, 'rating' => 1250, 'joined_at' => now()]);
    expect($queue->pair($bert))->toBeNull();

    $this->travel(29)->seconds();
    expect($queue->pair($anna))->toBeNull();

    $this->travel(1)->seconds();
    expect($queue->range($entry))->toBe(300)
        ->and($queue->pair($anna))->toBeInstanceOf(ChessGame::class);

    $this->travel(10)->minutes();
    expect($queue->range($entry))->toBe(600);
});

test('a friend who is online can be invited and the accepted invite starts the game', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $invites = app(ChessInvites::class);
    app(ChessQueue::class)->join($anna);

    $invite = $invites->invite($anna, $bert);
    expect($invites->incoming($bert)->pluck('id')->all())->toBe([$invite->id]);

    $game = $invites->accept($invite, $bert);

    expect([$game->white_id, $game->black_id])->toEqualCanonicalizing([$anna->id, $bert->id])
        ->and($invite->refresh()->status)->toBe(ChessInviteStatus::Accepted)
        // Anna was still searching: the invite game takes her out of the queue.
        ->and(ChessQueueEntry::query()->count())->toBe(0);
});
