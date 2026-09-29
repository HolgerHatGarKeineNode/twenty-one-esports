<?php

/*
| What leaves the site (audit 2026-09-30, "viel zu viele" DMs): the kind
| decides whether push or DM may go out at all (NotificationKind), a player
| on the site gets the bell only (OnSite), and "your move" is never a DM and
| a push at most once per game and hour, never while the player is at the
| board (YourMoveThrottle). The deadline reminder still goes out, once a turn.
*/

use App\Enums\NotificationKind;
use App\Games\NineMensMorris;
use App\Jobs\PublishNostrEvent;
use App\Jobs\SendNostrDm;
use App\Jobs\SendWebPush;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\PushSubscription;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\DailyChallenges;
use App\Support\Notifications\ChessNotifications;
use App\Support\Notifications\Notice;
use App\Support\Notifications\Notifier;
use App\Support\Notifications\OnSite;
use App\Support\Notifications\WebPush;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\Support\NineMensMorrisOn;

beforeEach(function () {
    $this->freezeTime();
    Bus::fake([SendWebPush::class, SendNostrDm::class, PublishNostrEvent::class]);

    [$public, $private] = WebPush::generateKeyPair();
    config([
        'esports.webpush.public_key' => WebPush::base64UrlEncode($public),
        'esports.webpush.private_key' => WebPush::base64UrlEncode($private),
        'esports.webpush.subject' => 'https://esports.test',
        'esports.notifications.nsec' => bin2hex(random_bytes(32)),
    ]);
});

/**
 * A player with one subscribed browser and both channels on.
 *
 * @param  array<string, mixed>  $settings
 */
function reachPlayer(array $settings = []): User
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
 * @return array{push: int, dm: int}
 */
function remoteTo(User $user): array
{
    return [
        'push' => Bus::dispatched(SendWebPush::class)->filter(fn (SendWebPush $job) => $job->subscription->user_id === $user->id)->count(),
        'dm' => Bus::dispatched(SendNostrDm::class)->filter(fn (SendNostrDm $job) => $job->user->is($user))->count(),
    ];
}

function reachNotice(): Notice
{
    return new Notice('Title', 'Body', 'https://esports.test/x');
}

/* ---------- The matrix, enforced centrally ------------------------------------------------------------------ */

test('each verdict class reaches exactly as far as the matrix says, whatever the DM switch', function (NotificationKind $kind, string $mode, array $expected) {
    $player = reachPlayer();
    $game = match ($mode) {
        'live' => app(ChessGameService::class)->start($player, User::factory()->create()),
        'correspondence' => app(ChessGameService::class)->start($player, User::factory()->create(), ChessGame::CORRESPONDENCE),
        default => null,
    };

    $sent = app(Notifier::class)->send($player, $kind, reachNotice(), $game);

    expect($sent)->toBe($expected)
        ->and($player->notifications()->count())->toBe(1);
})->with([
    'page only: blitz pairing' => [NotificationKind::MatchFound, 'none', []],
    'page only: 1v1 pairing' => [NotificationKind::CasualMatchFound, 'none', []],
    'page only: opponent joined the lobby' => [NotificationKind::CasualOpponentJoined, 'none', []],
    'live game over' => [NotificationKind::GameOver, 'live', []],
    'live opponent resigned' => [NotificationKind::OpponentResigned, 'live', []],
    'push only: correspondence game over' => [NotificationKind::GameOver, 'correspondence', ['push']],
    'push only: 5-minute no-show claim' => [NotificationKind::CasualNoShow, 'none', ['push']],
    'push only: clan join answer' => [NotificationKind::ClanJoinAnswer, 'none', ['push']],
    'push and DM: challenge' => [NotificationKind::Challenge, 'none', ['push', 'dm']],
    'push and DM: season payout' => [NotificationKind::SeasonPayout, 'none', ['push', 'dm']],
]);

test('with DMs switched on, a blitz pairing and a blitz game over send no DM and no push', function () {
    $anna = reachPlayer();
    $bert = reachPlayer();
    $games = app(ChessGameService::class);

    $game = $games->start($anna, $bert);
    app(ChessNotifications::class)->matchFound($game);
    $games->move($game, $anna, 'e2e4');
    $games->move($game->refresh(), $bert, 'e7e5');
    $games->resign($game->refresh(), $bert);

    expect(remoteTo($anna))->toBe(['push' => 0, 'dm' => 0])
        ->and(remoteTo($bert))->toBe(['push' => 0, 'dm' => 0])
        ->and($anna->notifications()->pluck('type')->sort()->values()->all())->toBe(['match_found', 'opponent_resigned']);
});

test('"your move" is never a DM, whatever the switch, the per-game choice or where the player is', function (?bool $dm, ?string $choice, bool $away) {
    $anna = reachPlayer();
    $bert = reachPlayer(['dm' => $dm]);
    $games = app(ChessGameService::class);
    $game = $games->start($anna, $bert, ChessGame::CORRESPONDENCE);
    $game->forceFill(['black_notify' => $choice])->save();

    if (! $away) {
        app(OnSite::class)->seen($bert);
    }

    $games->move($game, $anna, 'e2e4');
    app(Notifier::class)->send($bert, NotificationKind::YourMove, reachNotice(), $game->refresh());

    expect(remoteTo($bert)['dm'])->toBe(0);
})->with([
    'dm on' => true, 'dm never chosen' => null, 'dm off' => false,
])->with([
    'per game dm' => 'dm', 'per game push' => 'push', 'per game here' => 'here', 'no per game choice' => null,
])->with([
    'away' => true, 'on the site' => false,
]);

/* ---------- On the site ------------------------------------------------------------------------------------- */

test('a player on the site gets the bell only; three minutes after the last ping, push and DM go out again', function () {
    $anna = User::factory()->create();
    $bert = reachPlayer();

    $this->actingAs($bert)->post(route('presence.ping'))->assertNoContent();
    app(DailyChallenges::class)->challenge($anna, $bert);

    expect(remoteTo($bert))->toBe(['push' => 0, 'dm' => 0])
        ->and($bert->notifications()->count())->toBe(1);

    $this->travel(179)->seconds();
    expect(app(OnSite::class)->isOnSite($bert))->toBeTrue();

    $this->travel(2)->seconds();
    app(DailyChallenges::class)->challenge(User::factory()->create(), $bert);

    expect(remoteTo($bert))->toBe(['push' => 1, 'dm' => 1]);
});

test('an unreadable presence counts as away: the DM goes out (fail open)', function () {
    $bert = reachPlayer();
    app(OnSite::class)->seen($bert);
    Cache::partialMock()->shouldReceive('get')->with('on-site:'.$bert->id)->andThrow(new RuntimeException('cache down'));

    expect(app(OnSite::class)->isOnSite($bert))->toBeFalse();

    app(DailyChallenges::class)->challenge(User::factory()->create(), $bert);

    expect(remoteTo($bert))->toBe(['push' => 1, 'dm' => 1]);
});

test('the deadline reminder still goes out while the player is on the site', function () {
    $anna = reachPlayer();
    $bert = reachPlayer();
    app(ChessGameService::class)->start($anna, $bert, ChessGame::CORRESPONDENCE);

    $this->travel(18 * 60 + 1)->minutes();
    app(OnSite::class)->seen($anna);
    $this->artisan('chess:daily-reminders')->assertSuccessful();

    expect(remoteTo($anna))->toBe(['push' => 1, 'dm' => 1]);
});

test('the ping needs a login, and marks only the player who sent it', function () {
    [$anna, $bert] = User::factory()->count(2)->create();

    $this->post(route('presence.ping'))->assertRedirect(route('login'));
    $this->actingAs($anna)->get('/presence/ping')->assertStatus(404);
    expect(app(OnSite::class)->isOnSite($anna))->toBeFalse();

    $this->actingAs($anna)->post(route('presence.ping'))->assertNoContent();

    expect(app(OnSite::class)->isOnSite($anna))->toBeTrue()
        ->and(app(OnSite::class)->isOnSite($bert))->toBeFalse();
});

/* ---------- "Your move": at the board, and once an hour ---------------------------------------------------- */

test('ten quick daily chess moves send the opponent one push over the burst, and the bell every move', function () {
    $anna = reachPlayer();
    $bert = reachPlayer();
    $games = app(ChessGameService::class);
    $game = $games->start($anna, $bert, ChessGame::CORRESPONDENCE);

    foreach (['e2e4', 'e7e5', 'g1f3', 'b8c6', 'f1c4', 'g8f6', 'd2d3', 'f8c5', 'c2c3', 'd7d6'] as $i => $uci) {
        $games->move($game->refresh(), $i % 2 === 0 ? $anna : $bert, $uci);
        $this->travel(1)->minutes();
    }

    expect(remoteTo($bert))->toBe(['push' => 1, 'dm' => 0])
        ->and(remoteTo($anna))->toBe(['push' => 0, 'dm' => 0])
        ->and($bert->notifications()->where('type', 'your_move')->count())->toBe(5)
        ->and($anna->notifications()->where('type', 'your_move')->count())->toBe(5);

    // After a two-hour pause the next move reaches Anna again.
    $this->travel(2)->hours();
    $games->move($game->refresh(), $anna, 'e1g1');

    expect(remoteTo($bert))->toBe(['push' => 2, 'dm' => 0]);
});

test('away from the board, "your move" goes out at most once per game and hour', function () {
    $anna = reachPlayer();
    $bert = reachPlayer();
    $games = app(ChessGameService::class);
    $game = $games->start($anna, $bert, ChessGame::CORRESPONDENCE);

    $games->move($game, $anna, 'e2e4');                      // t0: Bert's first, pushed
    $this->travel(20)->minutes();
    $games->move($game->refresh(), $bert, 'e7e5');           // t20: Anna's first, pushed
    $this->travel(20)->minutes();
    $games->move($game->refresh(), $anna, 'g1f3');           // t40: Bert moved 20 min ago, but pushed 40 min ago
    expect(remoteTo($bert)['push'])->toBe(1);

    $this->travel(45)->minutes();
    $games->move($game->refresh(), $bert, 'b8c6');           // t85: Anna pushed 65 min ago: again
    expect(remoteTo($anna)['push'])->toBe(2);

    $this->travel(20)->minutes();
    $games->move($game->refresh(), $anna, 'f1c4');           // t105: Bert pushed 105 min ago: again
    expect(remoteTo($bert)['push'])->toBe(2)
        ->and(remoteTo($bert)['dm'] + remoteTo($anna)['dm'])->toBe(0);
});

test('the per-game "only here", the push switch and the "your move" switch each keep it off the site', function () {
    $anna = reachPlayer();
    $here = reachPlayer();
    $noPush = reachPlayer(['push' => false]);
    $switchedOff = reachPlayer(['triggers' => ['your_move' => false]]);
    $games = app(ChessGameService::class);

    foreach ([$here, $noPush, $switchedOff] as $bert) {
        $game = $games->start($anna, $bert, ChessGame::CORRESPONDENCE);
        $game->forceFill(['black_notify' => $bert->is($here) ? 'here' : null])->save();
        $games->move($game, $anna, 'e2e4');
        expect(remoteTo($bert))->toBe(['push' => 0, 'dm' => 0]);
    }

    expect($here->notifications()->count())->toBe(1)
        ->and($switchedOff->notifications()->count())->toBe(0);
});

test('with both rules at 0 every "your move" push goes out', function () {
    config(['esports.notifications.your_move' => ['at_board_minutes' => 0, 'per_game_minutes' => 0]]);
    $anna = reachPlayer();
    $bert = reachPlayer();
    $games = app(ChessGameService::class);
    $game = $games->start($anna, $bert, ChessGame::CORRESPONDENCE);

    foreach (['e2e4', 'e7e5', 'g1f3', 'b8c6'] as $i => $uci) {
        $games->move($game->refresh(), $i % 2 === 0 ? $anna : $bert, $uci);
    }

    expect(remoteTo($bert))->toBe(['push' => 2, 'dm' => 0])
        ->and(remoteTo($anna))->toBe(['push' => 2, 'dm' => 0]);
});

test('a quick correspondence board game sends the same one push over the burst', function () {
    NineMensMorrisOn::play();
    $anna = reachPlayer();
    $bert = reachPlayer();
    $games = app(BoardGameService::class);
    $game = $games->start(NineMensMorris::SLUG, $anna, $bert, BoardGame::CORRESPONDENCE);

    foreach (array_slice(NineMensMorrisOn::BLOCKING_GAME, 0, 10) as $i => $move) {
        $game = $games->move($game->refresh(), $i % 2 === 0 ? $anna : $bert, $move);
        $this->travel(1)->minutes();
    }

    expect($game->ply)->toBe(10)
        ->and(remoteTo($bert))->toBe(['push' => 1, 'dm' => 0])
        ->and(remoteTo($anna))->toBe(['push' => 0, 'dm' => 0])
        ->and($bert->notifications()->where('type', 'your_move')->count())->toBe(5);
});

/* ---------- The deadline reminder: once a turn ------------------------------------------------------------- */

test('the deadline reminder goes out once a turn, however often the sweep runs, and again for the next turn', function () {
    $anna = reachPlayer();
    $bert = reachPlayer();
    $games = app(ChessGameService::class);
    $game = $games->start($anna, $bert, ChessGame::CORRESPONDENCE);
    $reminders = fn (User $user) => $user->notifications()->where('type', 'reminder')->count();

    $this->travel(18 * 60 + 1)->minutes();
    foreach (range(1, 5) as $sweep) {
        $this->artisan('chess:daily-reminders')->assertSuccessful();
        $this->travel(5)->minutes();
    }
    expect($reminders($anna))->toBe(1)
        ->and(remoteTo($anna)['dm'])->toBe(1);

    // The move starts Bert's turn: his own reminder, once.
    $games->move($game->refresh(), $anna, 'e2e4');
    $this->travel(18 * 60 + 1)->minutes();
    foreach (range(1, 5) as $sweep) {
        $this->artisan('chess:daily-reminders')->assertSuccessful();
    }

    expect($reminders($bert))->toBe(1)
        ->and($reminders($anna))->toBe(1)
        ->and($game->refresh()->reminded_ply)->toBe(1);
});
