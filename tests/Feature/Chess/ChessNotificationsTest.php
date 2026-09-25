<?php

use App\Jobs\PublishNostrEvent;
use App\Jobs\SendNostrDm;
use App\Jobs\SendWebPush;
use App\Models\ChessGame;
use App\Models\PushSubscription;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\DailyChallenges;
use App\Support\Notifications\WebPush;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->freezeTime();
    Bus::fake([SendWebPush::class, SendNostrDm::class, PublishNostrEvent::class]);
    Http::fake();

    [$public, $private] = WebPush::generateKeyPair();
    config([
        'esports.webpush.public_key' => WebPush::base64UrlEncode($public),
        'esports.webpush.private_key' => WebPush::base64UrlEncode($private),
        'esports.webpush.subject' => 'https://esports.test',
        'esports.notifications.nsec' => bin2hex(random_bytes(32)),
    ]);
});

/**
 * A player with a browser subscribed and both channels on.
 */
function notifiedPlayer(array $settings = []): User
{
    [$browserKey] = WebPush::generateKeyPair();
    $user = User::factory()->create(['chess_settings' => ['push' => true, 'dm' => true, ...$settings]]);
    PushSubscription::query()->create([
        'user_id' => $user->id,
        'endpoint' => 'https://push.example.test/'.$user->id,
        'public_key' => WebPush::base64UrlEncode($browserKey),
        'auth_token' => WebPush::base64UrlEncode(random_bytes(16)),
    ]);

    return $user;
}

/**
 * @return array{push: list<int>, dm: list<int>} user ids per channel
 */
function notified(): array
{
    return [
        'push' => array_values(Bus::dispatched(SendWebPush::class)->map(fn (SendWebPush $job) => $job->subscription->user_id)->all()),
        'dm' => array_values(Bus::dispatched(SendNostrDm::class)->map(fn (SendNostrDm $job) => $job->user->id)->all()),
    ];
}

test('each trigger notifies the right player on both channels', function (string $trigger) {
    $anna = notifiedPlayer();
    $bert = notifiedPlayer();
    $games = app(ChessGameService::class);

    $expected = match ($trigger) {
        'challenge received' => (function () use ($anna, $bert) {
            app(DailyChallenges::class)->challenge($anna, $bert, 'white', 'rematch?');

            return [$bert->id];
        })(),
        'your move' => (function () use ($anna, $bert, $games) {
            $game = $games->start($anna, $bert, ChessGame::CORRESPONDENCE);
            $games->move($game, $anna, 'e2e4');
            $games->move($game->refresh(), $bert, 'e7e5');
            Bus::fake([SendWebPush::class, SendNostrDm::class, PublishNostrEvent::class]);
            $games->move($game->refresh(), $anna, 'g1f3');

            // The notice names the move just played, not the first one.
            expect(Bus::dispatched(SendNostrDm::class)->sole()->text)->toContain('Nf3')->not->toContain('e4');

            return [$bert->id];
        })(),
        'deadline reminder' => (function () use ($anna, $bert, $games) {
            $games->start($anna, $bert, ChessGame::CORRESPONDENCE);
            // 6 h 01 min left: not yet (the default window is 6 h).
            $this->travel(18 * 60 - 1)->minutes();
            $this->artisan('chess:daily-reminders')->assertSuccessful();
            expect(notified())->toBe(['push' => [], 'dm' => []]);
            $this->travel(2)->minutes();
            $this->artisan('chess:daily-reminders')->assertSuccessful();
            $this->artisan('chess:daily-reminders')->assertSuccessful();

            return [$anna->id];
        })(),
        'game over' => (function () use ($anna, $bert, $games) {
            $game = $games->start($anna, $bert, ChessGame::CORRESPONDENCE);
            $games->move($game, $anna, 'e2e4');
            Bus::fake([SendWebPush::class, SendNostrDm::class, PublishNostrEvent::class]);
            $games->resign($game->refresh(), $bert);

            return [$anna->id, $bert->id];
        })(),
    };

    expect(notified())->toBe(['push' => $expected, 'dm' => $expected]);
})->with(['challenge received', 'your move', 'deadline reminder', 'game over']);

test('a switched-off trigger, a per-game choice and a live game decide what goes out', function () {
    $quiet = notifiedPlayer(['triggers' => ['challenge' => false]]);
    $dmOnly = notifiedPlayer();
    $anna = notifiedPlayer();
    $games = app(ChessGameService::class);

    app(DailyChallenges::class)->challenge($anna, $quiet);

    $daily = $games->start($anna, $dmOnly, ChessGame::CORRESPONDENCE);
    $daily->forceFill(['black_notify' => 'dm'])->save();
    $games->move($daily, $anna, 'e2e4');

    // Live games do not notify: both players are at the board.
    $blitz = $games->start($anna, notifiedPlayer());
    $games->move($blitz, $anna, 'e2e4');

    expect(notified())->toBe(['push' => [], 'dm' => [$dmOnly->id]]);
});
