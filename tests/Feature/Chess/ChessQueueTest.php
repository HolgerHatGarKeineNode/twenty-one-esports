<?php

use App\Enums\ChessInviteStatus;
use App\Events\ChessGameStarted;
use App\Events\ChessInviteChanged;
use App\Events\LookingToPlayChanged;
use App\Models\ChessGame;
use App\Models\ChessInvite;
use App\Models\ChessQueueEntry;
use App\Models\User;
use App\Support\Chess\ChessInvites;
use App\Support\Chess\ChessQueue;
use App\Support\Chess\PresenceLookup;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
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
        // Anna searched until she invited: one intent at a time (P5e).
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
    // Dora is gone, so Bert's search does not answer her open invite (P5e).
    $presence = Mockery::mock(PresenceLookup::class);
    $presence->shouldReceive('online')->andReturnUsing(fn (User $user) => ! $user->is($dora));
    app()->instance(PresenceLookup::class, $presence);
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
        // Anna searches, so Bert's invite starts the game at once (P5e).
        : $invites->invite($bert, $anna)->game;

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

/**
 * The in-app notification kinds a player got, oldest first.
 *
 * @return list<string>
 */
function noticeKinds(User $user): array
{
    return array_values($user->notifications()->oldest()->get()->map(fn ($notification) => $notification->data['kind'])->all());
}

test('inviting a player who searches starts the game at once, as a found match for both', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    app(ChessQueue::class)->join($bert);
    Event::fake([ChessGameStarted::class]);

    $lobby = Livewire::actingAs($anna)->test('pages::chess.lobby')->call('invite', $bert->id);

    $game = ChessGame::query()->sole();
    $invite = ChessInvite::query()->sole();
    $lobby->assertRedirect(route('games.show', $game));
    expect([$game->white_id, $game->black_id])->toEqualCanonicalizing([$anna->id, $bert->id])
        ->and($invite->status)->toBe(ChessInviteStatus::Accepted)
        ->and($invite->chess_game_id)->toBe($game->id)
        ->and(ChessQueueEntry::query()->count())->toBe(0)
        ->and(noticeKinds($anna))->toBe(['match_found'])
        ->and(noticeKinds($bert))->toBe(['match_found']);
    Event::assertDispatched(ChessGameStarted::class, fn (ChessGameStarted $event) => $event->gameId === $game->id);
});

test('an invite to a player who is not searching stays an ordinary open invite', function () {
    [$anna, $bert] = User::factory()->count(2)->create();

    $invite = app(ChessInvites::class)->invite($anna, $bert);

    expect($invite->status)->toBe(ChessInviteStatus::Pending)
        ->and($invite->chess_game_id)->toBeNull()
        ->and(ChessGame::query()->count())->toBe(0)
        ->and(noticeKinds($bert))->toBe(['invite']);
});

test('a player who searches and sends an invite leaves the queue', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    app(ChessQueue::class)->join($anna);

    Livewire::actingAs($anna)->test('pages::chess.lobby')
        ->assertSeeHtml('data-test="searching"')
        ->call('invite', $bert->id)
        ->assertNoRedirect()
        ->assertDontSeeHtml('data-test="searching"')
        ->assertSeeHtml('data-test="waiting-for-friend"');

    expect(ChessQueueEntry::query()->count())->toBe(0);
});

test('"Find opponent" with an open invite pairs with its inviter at once', function () {
    [$anna, $bert, $carl] = User::factory()->count(3)->create();
    app(ChessQueue::class)->join($carl); // someone else waits in range; the invite still comes first
    $invite = app(ChessInvites::class)->invite($anna, $bert);

    $lobby = Livewire::actingAs($bert)->test('pages::chess.lobby')->call('findOpponent');

    $game = ChessGame::query()->sole();
    $lobby->assertRedirect(route('games.show', $game));
    expect([$game->white_id, $game->black_id])->toEqualCanonicalizing([$anna->id, $bert->id])
        ->and($invite->refresh()->status)->toBe(ChessInviteStatus::Accepted)
        ->and(ChessQueueEntry::query()->pluck('user_id')->all())->toBe([$carl->id])
        ->and(noticeKinds($anna))->toBe(['match_found'])
        ->and(noticeKinds($bert))->toBe(['invite', 'match_found']);
});

test('"Find opponent" does not answer an invite whose inviter is gone or playing', function (string $inviter) {
    [$anna, $bert] = User::factory()->count(2)->create();
    $invite = app(ChessInvites::class)->invite($anna, $bert);
    $presence = Mockery::mock(PresenceLookup::class);
    $presence->shouldReceive('online')->andReturn($inviter !== 'offline');
    app()->instance(PresenceLookup::class, $presence);

    if ($inviter === 'playing') {
        ChessGame::factory()->create(['white_id' => $anna->id]);
    }

    Livewire::actingAs($bert)->test('pages::chess.lobby')
        ->call('findOpponent')
        ->assertNoRedirect()
        ->assertSeeHtml('data-test="searching"');

    expect(ChessQueueEntry::query()->pluck('user_id')->all())->toBe([$bert->id])
        ->and($invite->refresh()->status)->toBe($inviter === 'offline' ? ChessInviteStatus::Pending : ChessInviteStatus::Withdrawn);
})->with(['offline', 'playing']);

test('the online list knows whom the open invite goes to until it is answered, withdrawn or expired', function (string $end) {
    [$anna, $bert] = User::factory()->count(2)->create();
    config(['esports.chess.invite_seconds' => 120]);

    $lobby = Livewire::actingAs($anna)->test('pages::chess.lobby')
        ->assertSet('invitedUserId', null)
        ->call('invite', $bert->id)
        ->assertSet('invitedUserId', $bert->id)
        ->assertSet('invitedUntilMs', now()->addSeconds(120)->startOfSecond()->getTimestampMs());

    match ($end) {
        'withdrawn' => $lobby->call('withdrawInvite'),
        'declined' => app(ChessInvites::class)->close(ChessInvite::query()->sole(), $bert),
        'expired' => $this->travel(121)->seconds(),
    };

    // The inviter's page renders again on the invite broadcast (declined) or its poll (expired).
    $lobby->call('$refresh')->assertSet('invitedUserId', null)->assertSet('invitedUntilMs', 0);
})->with(['withdrawn', 'declined', 'expired']);

test('"Looking to play" stores the wanted state, not a flip, and announces only a change', function () {
    $anna = User::factory()->create();
    Event::fake([LookingToPlayChanged::class]);

    $lobby = Livewire::actingAs($anna)->test('pages::chess.lobby');
    $lobby->call('setLookingToPlay', true)->assertReturned(true);
    $lobby->call('setLookingToPlay', true)->assertReturned(true);
    expect($anna->refresh()->looking_to_play)->toBe('chess/blitz');

    $lobby->call('setLookingToPlay', false)->assertReturned(false);
    expect($anna->refresh()->looking_to_play)->toBeNull();

    Event::assertDispatchedTimes(LookingToPlayChanged::class, 2);
});

test('a pairing that loses every retry to a deadlock tells the player instead of failing', function (string $action) {
    [$anna, $bert] = User::factory()->count(2)->create();
    $invite = app(ChessInvites::class)->invite($anna, $bert);

    if ($action === 'findOpponent') {
        $invite->forceFill(['status' => ChessInviteStatus::Withdrawn])->save();
        app(ChessQueue::class)->join($anna);
    }

    // Postgres aborts the transaction that loses a deadlock on the locked rows.
    // Level 1 is the test's own transaction: only the pairing's is hit.
    DB::listen(function (QueryExecuted $query): void {
        if (DB::transactionLevel() > 1 && preg_match('/from "(chess_invites|chess_queue_entries)"/', $query->sql) === 1) {
            throw new PDOException('SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected');
        }
    });

    Livewire::actingAs($bert)->test('pages::chess.lobby')
        ->call($action, ...($action === 'acceptInvite' ? [$invite->id] : []))
        ->assertSet('error', 'Someone else answered first. Please try again.')
        ->assertNoRedirect();

    expect(ChessGame::query()->count())->toBe(0);
})->with(['acceptInvite', 'findOpponent']);
