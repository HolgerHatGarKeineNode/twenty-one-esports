<?php

use App\Enums\ChessInviteStatus;
use App\Events\ChessGameStarted;
use App\Events\ChessInviteChanged;
use App\Models\ChessGame;
use App\Models\ChessQueueEntry;
use App\Models\User;
use App\Support\Chess\ChessInvites;
use App\Support\Chess\ChessQueue;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

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

test('accepting an invite is refused while either side plays a live game, with the reason on the lobby', function (string $busy, string $message, ChessInviteStatus $after) {
    [$anna, $bert] = User::factory()->count(2)->create();
    $invite = app(ChessInvites::class)->invite($anna, $bert);
    // The live game began after the invite went out (a race the start's own withdrawal cannot see).
    $running = ChessGame::factory()->create(['white_id' => $busy === 'inviter' ? $anna->id : $bert->id]);
    Event::fake([ChessInviteChanged::class, ChessGameStarted::class]);

    Livewire::actingAs($bert)->test('pages::chess.lobby')
        ->call('acceptInvite', $invite->id)
        ->assertSet('error', $message)
        ->assertNoRedirect();

    expect($invite->refresh()->status)->toBe($after)
        ->and(ChessGame::query()->pluck('id')->all())->toBe([$running->id]);
    Event::assertNotDispatched(ChessGameStarted::class);
})->with([
    'the inviter plays: the invite is closed' => ['inviter', 'That player is already in another live game, so the invite is closed.', ChessInviteStatus::Withdrawn],
    'the invitee plays: the invite stays for after the game' => ['invitee', 'You are in a live game. One live game at a time: finish it, then accept the invite.', ChessInviteStatus::Pending],
]);

test('a live game that starts withdraws both players\' other open invites and takes them out of the queue', function (string $start) {
    [$anna, $bert, $carl, $dora, $eve] = User::factory()->count(5)->create();
    $invites = app(ChessInvites::class);
    $queue = app(ChessQueue::class);

    $toCarl = $invites->invite($anna, $carl);   // Anna's own open invite
    $fromDora = $invites->invite($dora, $bert); // an invite Bert has not answered
    $unrelated = $invites->invite($eve, $carl);
    $queue->join($dora);
    // Dora waits far out of reach, so the queue pairs Anna and Bert with each other.
    ChessQueueEntry::query()->where('user_id', $dora->id)->update(['rating' => 1900]);
    Event::fake([ChessInviteChanged::class, ChessGameStarted::class]);

    $queue->join($anna);
    $game = $start === 'queue'
        ? $queue->join($bert)
        : $invites->accept($invites->invite($bert, $anna), $anna);

    expect($game)->toBeInstanceOf(ChessGame::class)
        ->and($toCarl->refresh()->status)->toBe(ChessInviteStatus::Withdrawn)
        ->and($fromDora->refresh()->status)->toBe(ChessInviteStatus::Withdrawn)
        ->and($unrelated->refresh()->status)->toBe(ChessInviteStatus::Pending)
        ->and(ChessQueueEntry::query()->pluck('user_id')->all())->toBe([$dora->id]);

    foreach ([$toCarl, $fromDora] as $withdrawn) {
        Event::assertDispatched(ChessInviteChanged::class, fn (ChessInviteChanged $event) => $event->inviteId === $withdrawn->id && $event->status === 'withdrawn');
    }
    Event::assertNotDispatched(ChessInviteChanged::class, fn (ChessInviteChanged $event) => $event->inviteId === $unrelated->id);
})->with(['queue', 'invite']);
