<?php

use App\Enums\ChessInviteStatus;
use App\Enums\ModerationAction;
use App\Events\SiteModerationChanged;
use App\Models\Admin;
use App\Models\ChatMute;
use App\Models\ChessChallenge;
use App\Models\ChessQueueEntry;
use App\Models\PubkeyModeration;
use App\Models\ScoreAccountClaim;
use App\Models\Tournament;
use App\Models\TournamentModerationEntry;
use App\Models\User;
use App\Support\Chess\ChessRuleViolation;
use App\Support\Chess\DailyChallenges;
use App\Support\Moderation\SiteModeration;
use App\Support\Moderation\SiteModerationRefused;
use App\Support\Nostr\NostrKeys;
use App\Support\Scores\ScoreServers;
use App\Support\StreamChat\StreamChat;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Tournaments\TournamentSignups;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\ScoreDemoOn;
use Tests\Support\TestSigner;

/*
 * Site-wide moderation of a Nostr key (user, 2026-10-05: "globales Muten für
 * alle im Chat und auf der Seite und komplettes Ban von der Seite eines
 * npubs"): admins only, every step with its admin and reason, undoable. A
 * mute hides the key in every chat config and server list; a ban also ends
 * every way to take part. The client filter runs under Node
 * (tests/js/siteHidden.test.mjs), the live hide in the browser
 * (tests/Browser/SiteModerationTest.php).
 */

function moderationAdmin(): User
{
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

beforeEach(function () {
    Queue::fake();
});

test('the client filter holds: clean list, no own key, push applied, items left out', function () {
    $run = Process::path(base_path())->timeout(60)->run(['node', '--test', 'tests/js/siteHidden.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 4')->toContain('ℹ fail 0');
});

test('only admins moderate: the endpoint, the page and the service refuse everyone else', function () {
    $player = User::factory()->create();
    $target = (new TestSigner)->pubkey;

    $this->postJson(route('admin.moderation.store'), ['pubkey' => $target, 'action' => 'mute', 'reason' => 'spam'])->assertUnauthorized();
    $this->get(route('admin.moderation'))->assertRedirect(route('login'));

    $this->actingAs($player)->postJson(route('admin.moderation.store'), ['pubkey' => $target, 'action' => 'ban', 'reason' => 'spam'])->assertForbidden();
    $this->actingAs($player)->get(route('admin.moderation'))->assertForbidden();
    Livewire::actingAs($player)->test('pages::admin.moderation')->assertForbidden();

    expect(fn () => app(SiteModeration::class)->mute($player, $target, 'spam spam'))->toThrow(SiteModerationRefused::class)
        ->and(PubkeyModeration::query()->count())->toBe(0);
});

test('an admin mutes a key from the chat menu: logged with admin and reason, pushed, hidden in every chat config', function () {
    Event::fake([SiteModerationChanged::class]);
    config([
        'esports.game_chat.creator' => (new TestSigner)->pubkey,
        'twentyone.nostr.npub' => NostrKeys::hexToNpub((new TestSigner)->pubkey),
        'twentyone.stream.event.d' => 'twentyone-247',
        'twentyone.stream.relays' => ['wss://stream.example'],
    ]);
    $admin = moderationAdmin();
    $spammer = (new TestSigner)->pubkey;
    $viewer = User::factory()->create();

    $this->actingAs($admin)->postJson(route('admin.moderation.store'), ['pubkey' => $spammer, 'action' => 'mute', 'reason' => 'Obscene spam'])
        ->assertOk()->assertJson(['pubkey' => $spammer, 'hidden' => true]);

    $row = PubkeyModeration::query()->sole();
    expect($row->action)->toBe(ModerationAction::Mute)
        ->and($row->reason)->toBe('Obscene spam')
        ->and($row->actor_pubkey)->toBe($admin->pubkey)
        ->and($row->lifted_at)->toBeNull();
    Event::assertDispatched(SiteModerationChanged::class, fn (SiteModerationChanged $event): bool => $event->pubkey === $spammer && $event->hidden === true
        && $event->broadcastOn()[0]->name === 'moderation' && $event->broadcastWith() === ['pubkey' => $spammer, 'hidden' => true]);

    auth()->logout();
    $gameChat = Livewire::actingAs($viewer)->test('game-channel', ['game' => 'chess'])->instance()->config();
    $guestGameChat = (function () {
        auth()->logout();

        return Livewire::test('game-channel', ['game' => 'chess'])->instance()->config();
    })();

    expect($gameChat['hidden'])->toBe([$spammer])
        ->and($guestGameChat['hidden'])->toBe([$spammer])
        ->and(StreamChat::current()?->config($viewer)['hidden'])->toBe([$spammer])
        // Comments and RSVPs leave the viewer's own mutes and the hidden keys out alike.
        ->and(SiteModeration::leftOutFor($viewer))->toBe([$spammer]);
});

test('a muted player still sees what they wrote: their own key is not in their list', function () {
    $muted = User::factory()->create();
    PubkeyModeration::factory()->create(['pubkey' => $muted->pubkey]);

    expect(SiteModeration::hiddenFor($muted))->toBe([])
        ->and(SiteModeration::hiddenFor(null))->toBe([$muted->pubkey])
        // A mute is no ban: the player still logs in and plays.
        ->and(SiteModeration::isBanned($muted->pubkey))->toBeFalse();

    $this->actingAs($muted)->get(route('gaming.edit'))->assertOk();
    $this->assertAuthenticatedAs($muted);
});

test('the personal mute stays the viewer\'s own and says so; the admin items render for admins only', function () {
    config(['esports.game_chat.creator' => (new TestSigner)->pubkey]);
    $anna = User::factory()->create();
    $bert = User::factory()->create();
    $other = (new TestSigner)->pubkey;

    $annaChat = Livewire::actingAs($anna)->test('game-channel', ['game' => 'chess']);
    $annaChat->call('setMuted', $other, true)->assertReturned(true);

    expect(ChatMute::query()->where('user_id', $anna->id)->pluck('muted_pubkey')->all())->toBe([$other])
        ->and(PubkeyModeration::query()->count())->toBe(0)
        ->and($annaChat->instance()->config()['labels']['mute'])->toBe('Mute :name for me')
        ->and($annaChat->html())->not->toContain('data-test="chat-moderation"');

    auth()->logout();
    $bertChat = Livewire::actingAs($bert)->test('game-channel', ['game' => 'chess'])->instance()->config();
    expect($bertChat['muted'])->toBe([])->and($bertChat['hidden'])->toBe([]);

    auth()->logout();
    $adminHtml = Livewire::actingAs(moderationAdmin())->test('game-channel', ['game' => 'chess'])->html();
    expect($adminHtml)->toContain('data-test="chat-moderation"')
        ->toContain('Mute for everyone')->toContain('Ban from the site')
        ->toContain('data-url="'.route('admin.moderation.store').'"');
});

test('the stream chat on /live has the admin items for admins only, and its personal mute says "for me"', function () {
    config([
        'twentyone.nostr.npub' => NostrKeys::hexToNpub((new TestSigner)->pubkey),
        'twentyone.stream.event.d' => 'twentyone-247',
        'twentyone.stream.relays' => ['wss://stream.example'],
    ]);
    $player = User::factory()->create();

    $playerPage = Livewire::actingAs($player)->test('pages::live');
    expect($playerPage->html())->not->toContain('data-test="chat-moderation"')
        ->and(StreamChat::current()?->config($player)['labels']['mute'])->toBe('Mute :name for me');

    auth()->logout();
    expect(Livewire::actingAs(moderationAdmin())->test('pages::live')->html())->toContain('data-test="chat-moderation"');
});

test('a hidden zapper shows no name or picture on the wall, the others still do', function () {
    $tournament = Tournament::factory()->create();
    $shown = (new TestSigner)->pubkey;
    $hidden = (new TestSigner)->pubkey;
    PubkeyModeration::factory()->create(['pubkey' => $hidden]);
    $zapper = fn (string $pubkey, int $sats): array => ['pubkey' => $pubkey, 'npub' => 'npub1'.substr($pubkey, 0, 20), 'sats' => $sats, 'zaps' => 1, 'first' => 1, 'user' => null];

    $html = view('pages.tournaments.partials.prize-pool', [
        'tournament' => $tournament,
        'pool' => ['sats' => 3000, 'base' => 0, 'zaps' => 3000, 'zappers' => [$zapper($hidden, 2000), $zapper($shown, 1000)], 'left' => 3000, 'mode' => 'winner', 'split' => [], 'sponsors' => []],
    ])->render();

    expect($html)->toContain('data-pubkey="'.$shown.'"')
        ->not->toContain($hidden);
});

test('admins and board keys, oneself, a bad key, a short reason and a second mute are refused', function () {
    $admin = moderationAdmin();
    $other = moderationAdmin();
    $board = NostrKeys::npubToHex(config('esports.board')[0]);
    $moderation = app(SiteModeration::class);
    $target = (new TestSigner)->pubkey;

    foreach ([[$admin->pubkey, 'admin'], [$other->pubkey, 'admin'], [$board, 'admin'], ['nope', 'key']] as [$key, $code]) {
        expect(fn () => $moderation->ban($admin, $key, 'a reason'))->toThrow(fn (SiteModerationRefused $refused) => expect($refused->reason)->toBe($code));
    }

    expect(fn () => $moderation->mute($admin, $target, 'x'))->toThrow(fn (SiteModerationRefused $refused) => expect($refused->reason)->toBe('reason'));

    $moderation->mute($admin, NostrKeys::hexToNpub($target), 'Spam again');
    expect(fn () => $moderation->mute($admin, $target, 'Spam again'))->toThrow(fn (SiteModerationRefused $refused) => expect($refused->reason)->toBe('already'))
        ->and(PubkeyModeration::query()->where('pubkey', $target)->count())->toBe(1);

    $this->actingAs($admin)->postJson(route('admin.moderation.store'), ['pubkey' => $target, 'action' => 'mute', 'reason' => 'Spam again'])
        ->assertStatus(422)->assertJson(['reason' => 'already']);
});

test('a banned key without an account is refused at its first login and gets none', function () {
    $signer = new TestSigner;
    Http::fake(fn () => Http::response([]));
    PubkeyModeration::factory()->ban()->create(['pubkey' => $signer->pubkey]);
    $challenge = $this->postJson(route('auth.nostr.challenge'))->assertOk()->json('challenge');

    $this->postJson(route('auth.nostr.login'), ['event' => $signer->loginEvent($challenge)])
        ->assertForbidden()->assertJson(['message' => 'This key cannot sign in here.']);

    $this->assertGuest();
    expect(User::query()->where('pubkey', $signer->pubkey)->exists())->toBeFalse();
});

test('a ban ends the open session, refuses the login, withdraws entries, queue places and challenges', function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    Http::fake(fn () => Http::response([]));
    $admin = moderationAdmin();
    [$banned, $signer] = keyedPlayer();
    $opponent = User::factory()->create();
    $tournament = openTournament(['capacity' => 8]);
    $entry = soloSignup($tournament, $banned, $signer);
    ChessQueueEntry::query()->create(['user_id' => $banned->id, 'mode' => 'blitz', 'rated' => false, 'rating' => 1000, 'joined_at' => now()]);
    $challenge = ChessChallenge::query()->create(['challenger_id' => $banned->id, 'challenged_id' => $opponent->id, 'mode' => 'correspondence', 'color' => 'random', 'status' => ChessInviteStatus::Pending, 'expires_at' => now()->addDay()]);

    $this->actingAs($banned)->get(route('gaming.edit'))->assertOk();

    app(SiteModeration::class)->ban($admin, $banned->pubkey, 'Spam and abuse');

    // The open session: logged out on the next request, sent to the login.
    $this->get(route('gaming.edit'))->assertRedirect(route('login'));
    $this->assertGuest();

    // The login: refused with a neutral sentence, the account stays.
    $challengeText = $this->postJson(route('auth.nostr.challenge'))->json('challenge');
    $this->postJson(route('auth.nostr.login'), ['event' => $signer->loginEvent($challengeText)])->assertForbidden();
    $this->assertGuest();

    $entry->refresh();
    expect($entry->removed_at)->not->toBeNull()
        ->and($entry->removal_reason)->toBe(SiteModeration::ENTRY_REASON)
        ->and($entry->removed_by_id)->toBe($admin->id)
        ->and(TournamentModerationEntry::query()->where('tournament_id', $tournament->id)->where('action', 'removed')->value('user_id'))->toBe($admin->id)
        ->and(ChessQueueEntry::query()->where('user_id', $banned->id)->exists())->toBeFalse()
        ->and($challenge->refresh()->status)->toBe(ChessInviteStatus::Withdrawn);
});

test('a banned player cannot be challenged, sign up or be suggested to invite; admins still find them', function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    $admin = moderationAdmin();
    [$banned, $signer] = keyedPlayer();
    $banned->forceFill(['name' => 'spammerxyz'])->save();
    $player = User::factory()->create();
    $tournament = openTournament(['capacity' => 8]);
    app(SiteModeration::class)->ban($admin, $banned->pubkey, 'Spam and abuse');

    expect(fn () => app(DailyChallenges::class)->challenge($player, $banned))->toThrow(fn (ChessRuleViolation $violation) => expect($violation->reason)->toBe('not_available'))
        ->and(fn () => app(TournamentSignups::class)->prepareSolo($tournament, $banned))->toThrow(fn (TournamentRuleViolation $violation) => expect($violation->reason)->toBe('not_available'));

    $this->actingAs($player)->getJson(route('players.search', ['q' => 'spammerxyz']))->assertOk()->assertExactJson([]);
    $this->actingAs($player)->getJson(route('players.search', ['q' => $banned->npub, 'keys' => 1]))->assertOk()->assertExactJson([]);
    expect($this->actingAs($admin)->getJson(route('players.search', ['q' => 'spammerxyz']))->assertOk()->json('0.id'))->toBe($banned->id);
});

test('a lineup keeps its entry without the banned player while it still fields a team', function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    $admin = moderationAdmin();
    [$lineup, $captain, $signer] = keyedLineup(1);
    $tournament = openTournament(['capacity' => 8], rocketLeague: true);
    $members = array_map(fn ($seat) => $seat->user_id, $lineup->activeSeats());
    $entry = lineupSignup($tournament, $lineup, $captain, $signer, $members);
    $bannedId = collect($members)->first(fn (int $id): bool => $id !== $captain->id);

    app(SiteModeration::class)->ban($admin, User::query()->findOrFail($bannedId)->pubkey, 'Abuse');

    $entry->refresh();
    expect($entry->removed_at)->toBeNull()
        ->and($entry->members)->not->toContain($bannedId)
        ->and(count($entry->members))->toBe(count($members) - 1);
});

test('a game server finish of a banned player is refused', function () {
    ScoreDemoOn::play();
    $admin = moderationAdmin();
    $banned = User::factory()->create();
    ScoreAccountClaim::query()->create(['game' => 'score-demo', 'account_id' => 'acct-banned', 'user_id' => $banned->id, 'confirmed_by_id' => $admin->id]);
    ['token' => $token] = ScoreServers::issue('box', 'score-demo');
    $finish = fn (string $id) => $this->postJson(route('scores.ingest'), ['events' => [['id' => $id, 'mode' => 'time-trial', 'course' => 'demo-1', 'account' => 'acct-banned', 'value' => 50_000, 'achieved_at' => now()->getTimestamp()]]], ['Authorization' => 'Bearer '.$token])->assertOk()->json();

    expect($finish('f1')['accepted'])->toBe(1);

    app(SiteModeration::class)->ban($admin, $banned->pubkey, 'Cheating');

    expect($finish('f2'))->toMatchArray(['accepted' => 0, 'refused' => [0 => 'account']]);
});

test('undo restores: the list, the login and the push; undoing a ban keeps a mute that stays', function () {
    Event::fake([SiteModerationChanged::class]);
    Http::fake(fn () => Http::response([]));
    $admin = moderationAdmin();
    [$player, $signer] = keyedPlayer();
    $moderation = app(SiteModeration::class);
    $mute = $moderation->mute($admin, $player->pubkey, 'Spam');
    $ban = $moderation->ban($admin, $player->pubkey, 'Spam again');

    $page = Livewire::actingAs($admin)->test('pages::admin.moderation')
        ->assertSee('Spam again')->assertSee($admin->displayName())->assertSeeHtml('data-test="moderation-undo"');
    $page->call('lift', $ban->id)->assertSet('notice', 'Ban undone.');

    expect($ban->refresh()->lifted_by_pubkey)->toBe($admin->pubkey)
        ->and(SiteModeration::isBanned($player->pubkey))->toBeFalse()
        ->and(SiteModeration::isHidden($player->pubkey))->toBeTrue();
    Event::assertDispatched(SiteModerationChanged::class, fn (SiteModerationChanged $event): bool => $event->hidden === true && $ban->lifted_at !== null);

    $page->call('lift', $mute->id)->assertSet('notice', 'Mute undone.');
    expect(SiteModeration::hiddenPubkeys())->toBe([]);
    Event::assertDispatched(SiteModerationChanged::class, fn (SiteModerationChanged $event): bool => $event->pubkey === $player->pubkey && $event->hidden === false);

    auth()->logout();
    $challenge = $this->postJson(route('auth.nostr.challenge'))->json('challenge');
    $this->postJson(route('auth.nostr.login'), ['event' => $signer->loginEvent($challenge)])->assertOk();
    $this->assertAuthenticatedAs($player);

    // Both steps stay in the record, with who undid them.
    expect(PubkeyModeration::query()->whereNotNull('lifted_at')->count())->toBe(2);
});

test('the admin page bans a pasted npub and lists it; nobody else sees a list or a label', function () {
    $admin = moderationAdmin();
    $target = new TestSigner;

    Livewire::actingAs($admin)->test('pages::admin.moderation')
        ->set('key', $target->pubkey)->set('action', 'ban')->set('reason', 'Ban evasion')
        ->call('add')->assertHasNoErrors()->assertSet('notice', 'Banned from the site.')
        ->assertSee('Ban evasion');

    expect(SiteModeration::isBanned($target->pubkey))->toBeTrue();

    auth()->logout();
    $this->get(route('home'))->assertOk()->assertDontSee('Ban evasion')->assertDontSee('moderation-row');
});

test('an unreadable cache still refuses a banned key: the table answers', function () {
    $banned = (new TestSigner)->pubkey;
    PubkeyModeration::factory()->ban()->create(['pubkey' => $banned]);
    Cache::partialMock()->shouldReceive('rememberForever')->andThrow(new RuntimeException('cache down'));

    expect(SiteModeration::isBanned($banned))->toBeTrue()
        ->and(SiteModeration::isHidden($banned))->toBeTrue()
        ->and(SiteModeration::isBanned((new TestSigner)->pubkey))->toBeFalse();
});
