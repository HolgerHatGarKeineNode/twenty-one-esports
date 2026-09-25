<?php

use App\Events\ChessGameStarted;
use App\Events\UserNotified;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessInvites;
use App\Support\Chess\ChessQueue;
use App\Support\Chess\DailyChallenges;
use App\Support\Notifications\ClanNotifications;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function () {
    $this->freezeTime();
    Event::fake([UserNotified::class, ChessGameStarted::class]);
});

/**
 * @return list<array{0: int, 1: string}> [user id, kind] of every broadcast
 */
function broadcastAlerts(): array
{
    return Event::dispatched(UserNotified::class)->map(fn (array $args) => [$args[0]->userId, $args[0]->alert['kind']])->values()->all();
}

test('a pairing tells both players, in the bell and on their open page, with a countdown into the game', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $queue = app(ChessQueue::class);

    $queue->join($anna);
    $game = $queue->join($bert);

    expect(broadcastAlerts())->toEqualCanonicalizing([[$anna->id, 'match_found'], [$bert->id, 'match_found']]);

    $alert = Event::dispatched(UserNotified::class)->first()[0]->alert;
    expect($alert)->toMatchArray(['url' => route('games.show', $game), 'redirect' => true, 'sound' => 'matchFound', 'action' => 'Play now'])
        ->and($alert['title'])->toStartWith('Opponent found: ')
        ->and($anna->notifications()->sole()->id)->toBe(collect(Event::dispatched(UserNotified::class))->first(fn ($args) => $args[0]->userId === $anna->id)[0]->alert['id']);
});

test('each kind of notification is stored for the right player and can be marked read', function (string $kind) {
    [$anna, $bert] = User::factory()->count(2)->create();
    $games = app(ChessGameService::class);

    $recipient = match ($kind) {
        'invite' => (function () use ($anna, $bert) {
            app(ChessInvites::class)->invite($anna, $bert);

            return $bert;
        })(),
        'invite_accepted' => (function () use ($anna, $bert) {
            $invites = app(ChessInvites::class);
            $invites->accept($invites->invite($anna, $bert), $bert);

            return $anna;
        })(),
        'challenge' => (function () use ($anna, $bert) {
            app(DailyChallenges::class)->challenge($anna, $bert);

            return $bert;
        })(),
        'game_started' => (function () use ($anna, $bert) {
            $challenges = app(DailyChallenges::class);
            $challenges->accept($challenges->challenge($anna, $bert), $bert);

            return $anna;
        })(),
        'your_move' => (function () use ($anna, $bert, $games) {
            $games->move($games->start($anna, $bert, ChessGame::CORRESPONDENCE), $anna, 'e2e4');

            return $bert;
        })(),
        'reminder' => (function () use ($anna, $bert, $games) {
            $games->start($anna, $bert, ChessGame::CORRESPONDENCE);
            $this->travel(18)->hours();
            $this->artisan('chess:daily-reminders')->assertSuccessful();

            return $anna;
        })(),
        'opponent_resigned', 'game_over' => (function () use ($anna, $bert, $games, $kind) {
            // Blitz: in the app only. Bert resigns: Anna hears he resigned, Bert gets the result.
            $games->resign($games->start($anna, $bert), $bert);

            return $kind === 'opponent_resigned' ? $anna : $bert;
        })(),
        'clan_join_request' => (function () use ($bert) {
            $clan = Clan::factory()->create();
            app(ClanNotifications::class)->joinRequested($clan, $bert);

            return $clan->owner;
        })(),
    };

    $notification = $recipient->notifications()->where('type', $kind)->sole();
    expect(broadcastAlerts())->toContain([$recipient->id, $kind])
        ->and($notification->data['url'])->toStartWith('http');

    Livewire::actingAs($recipient)->test('notification-bell')
        ->assertSee($notification->data['title'])
        ->call('markRead', $notification->id);

    expect($notification->refresh()->read_at)->not->toBeNull();
})->with(['invite', 'invite_accepted', 'challenge', 'game_started', 'your_move', 'reminder', 'opponent_resigned', 'game_over', 'clan_join_request']);

test('the bell opens a notification, marks everything read and never touches another player\'s', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $challenges = app(DailyChallenges::class);
    $challenges->challenge($bert, $anna);
    $challenges->challenge(User::factory()->create(), $anna);
    $other = User::factory()->create();
    $challenges->challenge($anna, $other);
    $first = $anna->notifications()->oldest()->first();

    Livewire::actingAs($anna)->test('notification-bell')
        ->assertSeeHtml('data-test="bell-count">2<')
        ->call('open', $first->id)
        ->assertRedirect($first->data['url']);

    expect($anna->unreadNotifications()->count())->toBe(1);

    Livewire::actingAs($anna)->test('notification-bell')->call('markAllRead')->assertDontSeeHtml('data-test="bell-count"');

    expect($anna->unreadNotifications()->count())->toBe(0)
        ->and($other->unreadNotifications()->count())->toBe(1);

    Livewire::actingAs($anna)->test('notification-bell')->call('markRead', $other->notifications()->sole()->id)->assertNotFound();
});

test('a switched-off event is not stored, not broadcast and not pushed', function () {
    $quiet = User::factory()->create(['chess_settings' => ['triggers' => ['match_found' => false, 'challenge' => false]]]);
    $anna = User::factory()->create();
    $queue = app(ChessQueue::class);

    $queue->join($quiet);
    $queue->join($anna);
    app(DailyChallenges::class)->challenge($anna, $quiet);

    expect(broadcastAlerts())->toBe([[$anna->id, 'match_found']])
        ->and($quiet->notifications()->count())->toBe(0);
});

test('a broken notification store never undoes the pairing', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    Schema::drop('notifications');
    $queue = app(ChessQueue::class);

    $queue->join($anna);

    expect($queue->join($bert))->toBeInstanceOf(ChessGame::class);
    Event::assertNotDispatched(UserNotified::class);
});

test('sound, volume and every notification switch are saved from the chess settings', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.chess')
        ->call('toggle', 'sound')
        ->call('setVolume', 35)
        ->call('toggleTrigger', 'match_found')
        ->assertDispatched('sound-settings', enabled: false, volume: 35)
        ->call('setVolume', 101)
        ->assertStatus(422);

    $settings = $user->refresh()->chessSettings();
    expect($settings->sound)->toBeFalse()
        ->and($settings->volume)->toBe(35)
        ->and($settings->wants('match_found'))->toBeFalse()
        ->and($settings->wants('clan_join_request'))->toBeTrue();
});
