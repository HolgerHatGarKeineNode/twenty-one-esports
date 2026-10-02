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
use App\Http\Controllers\OnSitePingController;
use App\Jobs\PublishNostrEvent;
use App\Jobs\SendNostrDm;
use App\Jobs\SendWebPush;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\PushSubscription;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessInvites;
use App\Support\Chess\ChessSettings;
use App\Support\Chess\DailyChallenges;
use App\Support\Notifications\ChessNotifications;
use App\Support\Notifications\Notice;
use App\Support\Notifications\Notifier;
use App\Support\Notifications\OnSite;
use App\Support\Notifications\WebPush;
use App\Support\Tournaments\CasualCups;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\NineMensMorrisOn;
use Tests\Support\TestSigner;

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
    'push only: 15-minute 1v1 start reminder' => [NotificationKind::CasualReminder, 'none', ['push']],
    'push only: tournament match reminder (30 and 5 min)' => [NotificationKind::TournamentReminder, 'none', ['push']],
    'push only: cup game now' => [NotificationKind::CupGameNow, 'none', ['push']],
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

test('a player on the site gets the bell only; 75 s after the last ping, push and DM go out again', function () {
    $anna = User::factory()->create();
    $bert = reachPlayer();

    $this->actingAs($bert)->post(route('presence.ping'))->assertNoContent();
    app(DailyChallenges::class)->challenge($anna, $bert);

    expect(remoteTo($bert))->toBe(['push' => 0, 'dm' => 0])
        ->and($bert->notifications()->count())->toBe(1);

    $this->travel(74)->seconds();
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

test('the ping marks only the player who sent it for 75 s, and a signed-out tab is told to stop without an error', function () {
    [$anna, $bert] = User::factory()->count(2)->create();

    $this->post(route('presence.ping'))->assertNoContent()->assertHeader('X-On-Site', 'signed-out');
    $this->actingAs($anna)->get('/presence/ping')->assertStatus(404);
    expect(app(OnSite::class)->isOnSite($anna))->toBeFalse();

    $this->actingAs($anna)->post(route('presence.ping'))->assertNoContent()->assertHeaderMissing('X-On-Site');

    expect(app(OnSite::class)->isOnSite($anna))->toBeTrue()
        ->and(app(OnSite::class)->isOnSite($bert))->toBeFalse();

    // The page stopped pinging (hidden or closed): 75 s later she counts as away.
    $this->travel(75)->seconds();
    expect(app(OnSite::class)->isOnSite($anna))->toBeFalse();
});

test('more pings than a minute allows are told to slow down, still without an error status', function () {
    $anna = User::factory()->create();

    foreach (range(1, OnSitePingController::PER_MINUTE) as $ping) {
        $this->actingAs($anna)->post(route('presence.ping'))->assertNoContent()->assertHeaderMissing('X-On-Site');
    }

    $this->actingAs($anna)->post(route('presence.ping'))->assertNoContent()->assertHeader('X-On-Site', 'slow')->assertHeader('Retry-After');
});

test('the on-site check comes before the hourly "your move" slot: a move seen on the site does not use up the next push', function () {
    $anna = reachPlayer();
    $bert = reachPlayer();
    $games = app(ChessGameService::class);
    $game = $games->start($anna, $bert, ChessGame::CORRESPONDENCE);

    app(OnSite::class)->seen($bert);
    $games->move($game, $anna, 'e2e4');                      // t0: Bert is on the site: bell only
    expect(remoteTo($bert)['push'])->toBe(0);

    $this->travel(20)->minutes();
    $games->move($game->refresh(), $bert, 'e7e5');
    $this->travel(20)->minutes();
    $games->move($game->refresh(), $anna, 'g1f3');           // t40: Bert away, moved 20 min ago: pushed

    expect(remoteTo($bert))->toBe(['push' => 1, 'dm' => 0]);
});

test('a per-game "push" on a live game sends nothing: the kind still decides', function () {
    $anna = reachPlayer();
    $bert = reachPlayer();
    $blitz = app(ChessGameService::class)->start($anna, $bert);
    $blitz->forceFill(['black_notify' => 'push'])->save();

    app(Notifier::class)->send($bert, NotificationKind::YourMove, reachNotice(), $blitz);

    expect(remoteTo($bert))->toBe(['push' => 0, 'dm' => 0]);
});

/* ---------- Casual cups: what is on now is no DM ---------------------------------------------------------- */

test('a cup play-now invite and a cup game the league started reach an away player by push, never by DM', function () {
    Http::fake();
    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.casual_cups.enabled' => ['chess']]);
    $cup = runningCup(4);
    cupTick();
    $match = openCupMatches($cup)->first();
    [$white, $black] = matchPlayers($match);
    $players = openCupMatches($cup)->flatMap(fn ($m) => matchPlayers($m));

    foreach ($players as $player) {
        [$browserKey] = WebPush::generateKeyPair();
        PushSubscription::query()->create(['user_id' => $player->id, 'endpoint' => 'https://push.example.test/'.$player->id, 'public_key' => WebPush::base64UrlEncode($browserKey), 'auth_token' => WebPush::base64UrlEncode(random_bytes(16))]);
        $player->forceFill(['chess_settings' => ['dm' => true, 'push' => true]])->save();
    }

    Bus::fake([SendWebPush::class, SendNostrDm::class, PublishNostrEvent::class]);
    app(ChessInvites::class)->inviteToCupMatch($black, $match);

    expect(remoteTo($white->refresh()))->toBe(['push' => 1, 'dm' => 0])
        ->and($white->notifications()->where('type', 'cup_game_now')->count())->toBe(1);

    Bus::fake([SendWebPush::class, SendNostrDm::class, PublishNostrEvent::class]);
    $this->travelTo(CasualCups::autoSlot(openCupMatches($cup)->first()->round->window_ends_at));
    cupTick();

    expect(ChessGame::query()->whereNotNull('tournament_match_id')->count())->toBeGreaterThan(0)
        ->and($players->sum(fn (User $user) => remoteTo($user)['dm']))->toBe(0)
        ->and($players->sum(fn (User $user) => remoteTo($user)['push']))->toBeGreaterThan(0);
});

/* ---------- "Your move": at the board, and once an hour ---------------------------------------------------- */

test('ten quick daily chess moves send the opponent one push over the burst, and no bell entry for any move', function () {
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
        // The game bar shows whose turn it is; a bell entry per move buried what matters (user, 2026-10-02).
        ->and($bert->notifications()->where('type', 'your_move')->count())->toBe(0)
        ->and($anna->notifications()->where('type', 'your_move')->count())->toBe(0);

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

    expect($here->notifications()->count())->toBe(0)
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
        ->and($bert->notifications()->where('type', 'your_move')->count())->toBe(0);
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

/* ---------- What the pages say goes out ------------------------------------------------------------------- */

test('the correspondence hub says what goes out, for every combination of channels and switches', function (array $settings, string $state, string $text) {
    $anna = User::factory()->create(['locale' => 'en', 'chess_settings' => $settings]);
    app(ChessGameService::class)->start($anna, User::factory()->create(), ChessGame::CORRESPONDENCE);

    $html = $this->actingAs($anna)->get(route('me.correspondence'))->assertOk()->getContent();
    preg_match('~data-test="correspondence-reach">(.*?)</span>~s', $html, $reach);

    expect($html)->toContain($state)
        ->and(preg_replace('/\s+/', ' ', trim(html_entity_decode($reach[1] ?? ''))))->toBe($text);
})->with([
    'push and DM on' => [['push' => true, 'dm' => true], 'Notifications on', 'A browser push for an opponent\'s move, at most once an hour and only while you are away. Nostr DM and browser push 6 h before your deadline.'],
    'push on, DM off' => [['push' => true, 'dm' => false], 'Notifications on', 'A browser push for an opponent\'s move, at most once an hour and only while you are away. Browser push 6 h before your deadline.'],
    'DM on, push off' => [['push' => false, 'dm' => true], 'Notifications on', 'Opponent moves show here and in the bell. Nostr DM 6 h before your deadline.'],
    'DM never chosen, push off' => [['push' => false], 'Notifications on', 'Opponent moves show here and in the bell. Nostr DM 6 h before your deadline.'],
    'your move switched off' => [['push' => true, 'dm' => true, 'triggers' => ['your_move' => false]], 'Notifications on', 'Opponent moves show here and in the bell. Nostr DM and browser push 6 h before your deadline.'],
    'reminder switched off' => [['push' => true, 'dm' => true, 'triggers' => ['reminder' => false]], 'Notifications on', 'A browser push for an opponent\'s move, at most once an hour and only while you are away. No reminder before your deadline.'],
    'both off' => [['push' => false, 'dm' => false], 'Notifications off', ''],
]);

test('the hub and the Notifier agree: what the hub promises for a move and a reminder is what goes out while away', function (bool $push, bool $dm) {
    $anna = reachPlayer(['push' => $push, 'dm' => $dm]);
    $bert = reachPlayer();
    $games = app(ChessGameService::class);
    $game = $games->start($bert, $anna, ChessGame::CORRESPONDENCE);
    $daily = (new ChessGame)->forceFill(['mode' => ChessGame::CORRESPONDENCE]);

    $games->move($game, $bert, 'e2e4');
    $move = remoteTo($anna);
    $this->travel(18 * 60 + 1)->minutes();
    $this->artisan('chess:daily-reminders')->assertSuccessful();
    $all = remoteTo($anna);

    expect(['push' => $move['push'] > 0, 'dm' => $move['dm'] > 0])->toBe(['push' => in_array('push', $anna->chessSettings()->remoteChannels(NotificationKind::YourMove, $daily), true), 'dm' => false])
        ->and(['push' => $all['push'] - $move['push'] > 0, 'dm' => $all['dm'] - $move['dm'] > 0])
        ->toBe(['push' => in_array('push', $anna->chessSettings()->remoteChannels(NotificationKind::Reminder, $daily), true), 'dm' => in_array('dm', $anna->chessSettings()->remoteChannels(NotificationKind::Reminder, $daily), true)]);
})->with([true, false])->with([true, false]);

test('each settings row says how far its kind reaches, and says it true', function () {
    $reach = collect(NotificationKind::cases())->reject(fn (NotificationKind $kind) => $kind->pageOnly())->mapWithKeys(fn (NotificationKind $kind) => [$kind->value => $kind->reach()])->all();
    $dmKinds = array_values(array_map(fn (NotificationKind $kind) => $kind->value, array_filter(NotificationKind::cases(), fn (NotificationKind $kind) => $kind->dmAllowed())));

    expect(array_keys(array_filter($reach, fn (string $text) => str_contains($text, 'DM'))))->toBe($dmKinds)
        ->and($reach['game_over'])->toBe('bell; push only for correspondence games')
        ->and($reach['opponent_resigned'])->toBe('bell; push only for correspondence games')
        ->and($reach['invite_accepted'])->toBe('bell; push only for an invite link')
        ->and($reach['your_move'])->toBe('push at most once an hour per game; the game bar shows your turn')
        ->and($reach['reminder'])->toBe('bell, push and DM, even while you are here')
        ->and($reach['cup_game_now'])->toBe('bell and push')
        ->and($reach['game_started'])->toBe('bell and push');
});

test('a player who switched tournament news off keeps the live cup calls off until they choose that switch', function () {
    $off = ChessSettings::fromArray(['triggers' => ['tournament_news' => false]]);
    $chosen = ChessSettings::fromArray(['triggers' => ['tournament_news' => false, 'cup_game_now' => true]]);
    $fresh = ChessSettings::fromArray([]);

    expect($off->wants(NotificationKind::CupGameNow->value))->toBeFalse()
        ->and($chosen->wants(NotificationKind::CupGameNow->value))->toBeTrue()
        ->and($fresh->wants(NotificationKind::CupGameNow->value))->toBeTrue()
        ->and(ChessSettings::fromArray(['triggers' => ['tournament_news' => true]])->wants(NotificationKind::CupGameNow->value))->toBeTrue();
});
