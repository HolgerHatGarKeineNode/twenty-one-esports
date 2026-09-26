<?php

use App\Events\ChessGameStarted;
use App\Events\ChessInviteChanged;
use App\Models\ChessQueueEntry;
use App\Models\User;
use App\Support\Chess\ChessInvites;
use App\Support\Chess\ChessQueue;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The lobby hears pairings and invites by push, not by polling
|--------------------------------------------------------------------------
|
| Pairing, an accepted invite and an invite received reach the lobby on the
| player's private `App.Models.User.{id}` channel. These tests pin what goes
| over that channel (who hears it, and nothing beyond ids, a status and a
| URL) and when the lobby still asks the server on its own.
|
*/

// Whole seconds: the queue stores joined_at without milliseconds.
beforeEach(fn () => $this->freezeSecond());

/**
 * @param  list<PrivateChannel>|PrivateChannel  $channels
 * @return list<string>
 */
function channelNames(array|PrivateChannel $channels): array
{
    return array_map(fn (PrivateChannel $channel) => $channel->name, is_array($channels) ? $channels : [$channels]);
}

test('a pairing reaches both players on their own private channel, with the game id and URL only', function () {
    Event::fake([ChessGameStarted::class]);
    [$anna, $bert] = User::factory()->count(2)->create();

    app(ChessQueue::class)->join($anna);
    $game = app(ChessQueue::class)->join($bert);

    Event::assertDispatchedTimes(ChessGameStarted::class, 1);
    Event::assertDispatched(ChessGameStarted::class, function (ChessGameStarted $event) use ($anna, $bert, $game) {
        expect($event->broadcastAs())->toBe('chess.game-started')
            ->and(channelNames($event->broadcastOn()))->toEqualCanonicalizing(['private-App.Models.User.'.$anna->id, 'private-App.Models.User.'.$bert->id])
            ->and($event->broadcastWith())->toBe(['gameId' => $game->id, 'url' => route('games.show', $game)]);

        return true;
    });
});

test('an invite, its acceptance and its withdrawal reach both players, without anything about them', function () {
    Event::fake([ChessInviteChanged::class, ChessGameStarted::class]);
    [$anna, $bert] = User::factory()->count(2)->create();
    $invites = app(ChessInvites::class);

    $invite = $invites->invite($anna, $bert);
    $invites->close($invite, $anna);
    $second = $invites->invite($anna, $bert);
    $game = $invites->accept($second, $bert);

    $heard = [];
    Event::assertDispatched(ChessInviteChanged::class, function (ChessInviteChanged $event) use ($anna, $bert, &$heard) {
        expect($event->broadcastAs())->toBe('chess.invite')
            ->and(channelNames($event->broadcastOn()))->toBe(['private-App.Models.User.'.$anna->id, 'private-App.Models.User.'.$bert->id])
            ->and(array_keys($event->broadcastWith()))->toBe(['inviteId', 'status']);
        $heard[] = $event->broadcastWith();

        return true;
    });

    expect($heard)->toBe([
        ['inviteId' => $invite->id, 'status' => 'pending'],
        ['inviteId' => $invite->id, 'status' => 'withdrawn'],
        ['inviteId' => $second->id, 'status' => 'pending'],
        ['inviteId' => $second->id, 'status' => 'accepted'],
    ]);
    // Accepted: the game itself is the news, to both.
    Event::assertDispatched(ChessGameStarted::class, fn (ChessGameStarted $event) => $event->broadcastWith() === ['gameId' => $game->id, 'url' => route('games.show', $game)]
        && channelNames($event->broadcastOn()) === ['private-App.Models.User.'.$game->white_id, 'private-App.Models.User.'.$game->black_id]);
});

test('only the player may listen on their own channel', function () {
    // The suite broadcasts to `null`, which authorises nothing: switch to a
    // Pusher-protocol driver and register the app's channels on it.
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb' => [
        'driver' => 'reverb', 'key' => 'key', 'secret' => 'secret', 'app_id' => 'app', 'options' => ['host' => '127.0.0.1', 'port' => 1, 'scheme' => 'http', 'useTLS' => false],
    ]]);
    Broadcast::purge('reverb');
    require base_path('routes/channels.php');
    [$anna, $bert] = User::factory()->count(2)->create();

    $this->actingAs($anna)->post('/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => 'private-App.Models.User.'.$anna->id])->assertOk();
    $this->actingAs($anna)->post('/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => 'private-App.Models.User.'.$bert->id])->assertForbidden();
});

test('the searching lobby does not poll: it asks once the range can widen, and at the slow net at most', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    app(ChessQueue::class)->join($anna);
    $net = fn () => (int) now()->addSeconds(30)->getTimestampMs();

    // Range opens every 30 s, the net is 30 s: the net comes first, the widening half a second after it.
    $lobby = Livewire::actingAs($anna)->test('pages::chess.lobby');
    $html = $lobby->html();
    expect($html)->not->toContain('wire:poll')
        ->and($lobby->get('checkAt'))->toBe($net())
        ->and($html)->toContain('data-check-at="'.$lobby->get('checkAt').'"');

    // A longer net leaves the widening in charge; once the range is at its maximum, only the net.
    config(['esports.chess.lobby_poll_seconds' => 3600]);
    $this->travel(40)->seconds();
    expect(Livewire::actingAs($anna)->test('pages::chess.lobby')->get('checkAt'))->toBe((int) ChessQueueEntry::query()->sole()->joined_at->addSeconds(60)->getTimestampMs() + 500);
    $this->travel(10)->minutes();
    expect(Livewire::actingAs($anna)->test('pages::chess.lobby')->get('checkAt'))->toBe((int) now()->addHour()->getTimestampMs());

    // Never below 30 s, whatever the config says.
    config(['esports.chess.lobby_poll_seconds' => 2]);
    expect(Livewire::actingAs($anna)->test('pages::chess.lobby')->get('checkAt'))->toBe($net());

    // Nothing to wait for: no check at all.
    expect(Livewire::actingAs($bert)->test('pages::chess.lobby')->get('checkAt'))->toBeNull();
});

test('the lobby waiting for a friend asks when the invite expires, or at the slow net', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $invite = app(ChessInvites::class)->invite($anna, $bert);

    $lobby = Livewire::actingAs($anna)->test('pages::chess.lobby');
    expect($lobby->html())->not->toContain('wire:poll')->toContain('data-test="waiting-for-friend"')
        ->and($lobby->get('checkAt'))->toBe((int) now()->addSeconds(30)->getTimestampMs());

    config(['esports.chess.lobby_poll_seconds' => 3600]);
    expect(Livewire::actingAs($anna)->test('pages::chess.lobby')->get('checkAt'))->toBe((int) $invite->expires_at->getTimestampMs() + 500);
});

test('the next widening of the search range', function () {
    $user = User::factory()->create();
    app(ChessQueue::class)->join($user);
    $entry = ChessQueueEntry::query()->sole();
    $queue = app(ChessQueue::class);

    expect($queue->nextWidening($entry)?->equalTo($entry->joined_at->copy()->addSeconds(30)))->toBeTrue();
    $this->travel(30)->seconds();
    expect($queue->nextWidening($entry)?->equalTo($entry->joined_at->copy()->addSeconds(60)))->toBeTrue();
    // 150 + 3 × 150 = 600, the maximum: nothing left to widen.
    $this->travel(60)->seconds();
    expect($queue->range($entry))->toBe(600)
        ->and($queue->nextWidening($entry))->toBeNull();
});
