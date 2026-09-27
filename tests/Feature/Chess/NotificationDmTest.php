<?php

use App\Jobs\PublishNostrEvent;
use App\Jobs\SendNostrDm;
use App\Models\ChatMute;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessInvites;
use App\Support\Chess\ChessRuleViolation;
use App\Support\Chess\ChessSettings;
use App\Support\Chess\DailyChallenges;
use App\Support\Notifications\ChessNotifications;
use App\Support\Notifications\NotificationDmOptOut;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * Nostr DMs by default for what needs an offline player (challenge,
 * daily move, reminder, clan join request), the signed
 * opt-out at the end of every DM, and the brakes on the challenger's side.
 */

beforeEach(function () {
    $this->freezeTime();
    Queue::fake([SendNostrDm::class, PublishNostrEvent::class]);
    config(['esports.notifications.nsec' => bin2hex(random_bytes(32))]);
});

/**
 * @return list<string> the text of every DM queued for this user
 */
function dmsTo(User $user): array
{
    return Queue::pushed(SendNostrDm::class)->filter(fn (SendNostrDm $job) => $job->user->is($user))->map(fn (SendNostrDm $job) => $job->text)->values()->all();
}

function dmRefusal(Closure $action): ?string
{
    try {
        $action();
    } catch (ChessRuleViolation $refused) {
        return $refused->reason;
    }

    return null;
}

/**
 * The opt-out URL inside a DM text.
 */
function optOutUrlIn(string $text): string
{
    preg_match('~(https?://\S+/notifications/dm/\d+\?\S+)$~', $text, $match);

    return $match[1] ?? '';
}

test('a player who never chose gets a DM for a challenge, one who switched DMs off gets none', function () {
    $anna = User::factory()->create();
    $fresh = User::factory()->create(['chess_settings' => null]);
    $off = User::factory()->create(['chess_settings' => ['dm' => false]]);
    $challenges = app(DailyChallenges::class);

    $challenges->challenge($anna, $fresh);
    $challenges->challenge($anna, $off);

    expect(dmsTo($fresh))->toHaveCount(1)
        ->and(dmsTo($off))->toBe([]);
});

test('the default covers only the kinds that need the player, an explicit on covers every remote one', function () {
    $fresh = User::factory()->create();
    $on = User::factory()->create(['chess_settings' => ['dm' => true]]);

    expect(collect(ChessSettings::triggers())->filter(fn (string $trigger) => $fresh->chessSettings()->dmFor($trigger))->values()->all())
        ->toBe(['challenge', 'your_move', 'reminder', 'clan_join_request'])
        ->and(collect(ChessSettings::triggers())->every(fn (string $trigger) => $on->chessSettings()->dmFor($trigger)))->toBeTrue();

    // A daily game over is remote, but not in the default set.
    $games = app(ChessGameService::class);
    $game = $games->start($fresh, $on, ChessGame::CORRESPONDENCE);
    $games->move($game, $fresh, 'e2e4');
    Queue::fake([SendNostrDm::class, PublishNostrEvent::class]);
    $games->resign($game->refresh(), $on);

    expect(dmsTo($fresh))->toBe([])
        ->and(dmsTo($on))->toHaveCount(1);
});

test('the settings switch shows a default DM as on and its first tap stores off', function () {
    $fresh = User::factory()->create(['chess_settings' => ['sound' => false]]);

    Livewire::actingAs($fresh)->test('pages::settings.chess')
        ->assertSeeHtml('role="switch" aria-checked="true" aria-label="Notifications by Nostr DM" data-test="switch-dm"')
        ->call('toggle', 'sound');

    // Another switch saved: the DM choice stays unmade.
    expect($fresh->refresh()->chess_settings['dm'] ?? null)->toBeNull();

    Livewire::actingAs($fresh)->test('pages::settings.chess')->call('toggle', 'dm');
    expect($fresh->refresh()->chessSettings()->dm)->toBeFalse();

    Livewire::actingAs($fresh)->test('pages::settings.chess')->call('toggle', 'dm');
    expect($fresh->refresh()->chessSettings()->dm)->toBeTrue();
});

test('every DM ends with the opt-out line in the recipient\'s language, and its link works without logging in', function (string $locale, string $line) {
    $anna = User::factory()->create();
    $bert = User::factory()->create(['locale' => $locale]);

    app(DailyChallenges::class)->challenge($anna, $bert);

    [$text] = dmsTo($bert);
    $lines = explode("\n", $text);
    $url = optOutUrlIn($text);

    expect(end($lines))->toBe($line.' '.$url)
        ->and($url)->toStartWith(url('/notifications/dm/'.$bert->id));

    $this->assertGuest();
    $this->get($url)->assertOk()->assertSee($locale === 'de' ? 'Alle DMs abschalten' : 'Turn off all DMs');
})->with([
    'en' => ['en', 'Turn off these DMs:'],
    'de' => ['de', 'Diese DMs abschalten:'],
]);

test('the signed opt-out turns every DM off without a login; a bad signature is refused', function () {
    $anna = User::factory()->create();
    $bert = User::factory()->create();
    $url = NotificationDmOptOut::url($bert);

    $this->get(str_replace('signature=', 'signature=0', $url))->assertForbidden();
    $this->get(url('/notifications/dm/'.$bert->id))->assertForbidden();
    // The signature is the user's: another id with it is refused.
    $this->get(str_replace('/dm/'.$bert->id.'?', '/dm/'.$anna->id.'?', $url))->assertForbidden();
    $this->post(str_replace('signature=', 'signature=0', $url), ['scope' => 'all'])->assertForbidden();

    // Opening the link changes nothing (a link preview must not unsubscribe).
    $this->get($url)->assertOk();
    expect($bert->refresh()->chessSettings()->dm)->toBeNull();

    $this->post($url, ['scope' => 'all'])->assertRedirect();
    $this->assertGuest();

    expect($bert->refresh()->chessSettings()->dm)->toBeFalse();
    $this->get($url)->assertOk()->assertSee('Nostr DMs are off for you.');

    app(DailyChallenges::class)->challenge($anna, $bert);

    expect(dmsTo($bert))->toBe([]);
});

test('the challenge-only opt-out stops challenge DMs and keeps the rest', function () {
    $anna = User::factory()->create();
    $bert = User::factory()->create();

    $this->post(NotificationDmOptOut::url($bert), ['scope' => 'challenge'])->assertRedirect();

    expect($bert->refresh()->chessSettings()->wants('challenge'))->toBeFalse()
        ->and($bert->chessSettings()->dm)->toBeNull();

    app(DailyChallenges::class)->challenge($anna, $bert);
    expect(dmsTo($bert))->toBe([]);

    $games = app(ChessGameService::class);
    $game = $games->start($anna, $bert, ChessGame::CORRESPONDENCE);
    $games->move($game, $anna, 'e2e4');

    expect(dmsTo($bert))->toHaveCount(1);
});

test('a blitz "your move" never becomes a DM, whatever the player chose', function () {
    $anna = User::factory()->create(['chess_settings' => ['dm' => true]]);
    $bert = User::factory()->create(['chess_settings' => ['dm' => true]]);
    $games = app(ChessGameService::class);

    $blitz = $games->start($anna, $bert);
    $blitz->forceFill(['white_notify' => 'dm', 'black_notify' => 'dm'])->save();
    $games->move($blitz, $anna, 'e2e4');
    // Called directly too: the guard is in the Notifier, not only in who calls it.
    app(ChessNotifications::class)->yourMove($blitz->refresh());

    expect(dmsTo($bert))->toBe([]);

    // The same call for a daily game does send one: the guard is what stops it.
    $daily = $games->start($anna, $bert, ChessGame::CORRESPONDENCE);
    $daily->forceFill(['black_notify' => 'dm'])->save();
    $games->move($daily, $anna, 'e2e4');

    expect(dmsTo($bert))->toHaveCount(1);
});

test('a blitz pairing and a blitz invite stay in the app: no DM, even with DMs switched on', function () {
    $anna = User::factory()->create(['chess_settings' => ['dm' => true]]);
    $bert = User::factory()->create(['chess_settings' => ['dm' => true]]);

    app(ChessInvites::class)->invite($anna, $bert);
    app(ChessNotifications::class)->matchFound(app(ChessGameService::class)->start($anna, $bert));

    expect(dmsTo($anna))->toBe([])
        ->and(dmsTo($bert))->toBe([]);
});

test('a challenger may send only so many challenges a day, per player and in total', function () {
    config(['esports.chess.challenges_per_recipient_per_day' => 2, 'esports.chess.challenges_per_day' => 3]);
    $anna = User::factory()->create();
    [$bert, $cleo, $dora] = User::factory()->count(3)->create()->all();
    $challenges = app(DailyChallenges::class);

    // Withdrawn each time, so "one open challenge per pair" is not what stops it.
    $challenges->close($challenges->challenge($anna, $bert), $anna);
    $challenges->close($challenges->challenge($anna, $bert), $anna);

    expect(dmRefusal(fn () => $challenges->challenge($anna, $bert)))->toBe('challenge_limit');

    $challenges->challenge($anna, $cleo);

    expect(dmRefusal(fn () => $challenges->challenge($anna, $dora)))->toBe('challenge_limit')
        ->and(dmsTo($bert))->toHaveCount(2)
        ->and(dmsTo($dora))->toBe([]);

    // A day later both limits are clear again.
    $this->travel(1)->days();
    expect(dmRefusal(fn () => $challenges->challenge($anna, $dora)))->toBeNull();

    // The page says so.
    $this->travelBack();
    $this->freezeTime();
    Livewire::actingAs($anna)->test('pages::chess.challenge')
        ->set('to', (string) $bert->id)
        ->call('send')
        ->assertSet('error', fn (string $error) => str_starts_with($error, 'You sent as many challenges as a day allows.'));
});

test('a challenge from a player the recipient muted sends no DM', function () {
    $anna = User::factory()->create();
    $bert = User::factory()->create();
    $cleo = User::factory()->create(['name' => 'Cleo']);
    ChatMute::query()->create(['user_id' => $bert->id, 'muted_pubkey' => $anna->pubkey]);

    app(DailyChallenges::class)->challenge($anna, $bert);
    app(DailyChallenges::class)->challenge($cleo, $bert);

    expect(dmsTo($bert))->toHaveCount(1)
        ->and(dmsTo($bert)[0])->toContain($cleo->displayName());
});

test('a link in the challenge message never reaches the DM', function () {
    $anna = User::factory()->create();
    $bert = User::factory()->create();

    app(DailyChallenges::class)->challenge($anna, $bert, 'random', "gl hf https://evil.example/x\nwww.evil.example evil.example/y nostr:npub1evilevilevil 1.e4 e5 2.Nf3");

    [$text] = dmsTo($bert);
    [, $body] = explode("\n", $text);

    expect($text)->not->toContain('evil.example')
        ->and($text)->not->toContain('nostr:')
        ->and($body)->toContain('"gl hf 1.e4 e5 2.Nf3"');
});

test('the notification settings are linked from the account menu and the daily games card', function () {
    $bert = User::factory()->create();
    $settings = route('settings.chess');

    $this->actingAs($bert)->get(route('me.correspondence'))
        ->assertOk()
        ->assertSee('href="'.$settings.'#notifications"', false)
        ->assertSee('data-test="account-menu-notifications"', false)
        ->assertSee('data-test="mobile-notifications"', false)
        ->assertSee('data-test="correspondence-notifications-change"', false);

    $this->actingAs($bert)->get($settings)->assertOk()->assertSee('id="notifications"', false)->assertSee('data-test="dm-explained"', false);
});
