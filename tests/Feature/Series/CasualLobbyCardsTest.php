<?php

use App\Enums\SeriesStatus;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Series\CasualMatches;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

/*
 * Lobby and account cards of a casual 1v1 (P23, slice S2; NIP "Lobby and
 * account cards"): the two content-free flags, the host swap, the room's
 * configuration for the chat (host, flags, A + D for the NIP-40
 * expiration, the player's own EA ID) and its casual steps. The cards
 * themselves never reach the server; their rules live in
 * resources/js/lobbyCards.js, run here under Node.
 */

beforeEach(fn () => $this->freezeTime());

test('the card rules hold in the client: build, parse, validation, newest wins, expiration, cache stubs', function () {
    $run = Process::path(base_path())->timeout(60)->run(['node', '--test', 'tests/js/lobbyCards.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 11')->toContain('ℹ skipped 0');
});

test('the room chat and the game chat keep separate caches, and neither holds a card or breaks on the other\'s entries', function () {
    $run = Process::path(base_path())->timeout(60)->run(['node', '--test', 'tests/js/chatCache.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 6')->toContain('ℹ skipped 0');
});

test('the guest\'s "seen" needs the host\'s "shared" first, and only a player of the match sets either', function () {
    [$match, $host, $guest] = casualStarted('rocket-league');
    $matches = app(CasualMatches::class);

    expect(casualRefusal(fn () => $matches->seeLobby($match, $guest)))->toBe('no_lobby_yet')
        ->and(casualRefusal(fn () => $matches->seeLobby($match, $host)))->toBe('not_guest')
        ->and(casualRefusal(fn () => $matches->seeLobby($match, User::factory()->create())))->toBe('not_player')
        ->and(casualRefusal(fn () => $matches->shareLobby($match, $guest)))->toBe('not_host');

    $this->travel(2)->minutes();
    $matches->shareLobby($match, $host);
    $this->travel(20)->seconds();
    $match = $matches->seeLobby($match, $guest);
    $seenAt = $match->lobby_seen_at;

    $this->travel(1)->minutes();
    $matches->seeLobby($match, $guest);

    expect($seenAt?->getTimestamp())->toBe(now()->subMinute()->getTimestamp())
        ->and($match->refresh()->lobby_seen_at?->getTimestamp())->toBe($seenAt?->getTimestamp())
        ->and($match->lobby_shared_at?->lt($match->lobby_seen_at))->toBeTrue();
});

test('no flag is set before the match started', function () {
    [$match, $anna, $bert] = casualPairing('rocket-league');
    $matches = app(CasualMatches::class);

    expect(casualRefusal(fn () => $matches->shareLobby($match, $anna)))->toBe('not_running')
        ->and(casualRefusal(fn () => $matches->seeLobby($match, $bert)))->toBe('not_running')
        ->and(casualRefusal(fn () => $matches->swapHost($match, $anna)))->toBe('not_running');
});

test('the host swaps once, before sharing and before the lobby deadline, and the new host gets the full time', function () {
    [$match, $host, $guest] = casualStarted('rocket-league');
    $matches = app(CasualMatches::class);
    $hostSide = $match->host_side;

    expect(casualRefusal(fn () => $matches->swapHost($match, $guest)))->toBe('not_host');

    $this->travel(4)->minutes();
    $match = $matches->swapHost($match, $host);

    expect($match->host_side)->toBe(SeriesMatch::otherSide((string) $hostSide))
        ->and($match->host_swapped_at?->getTimestamp())->toBe(now()->getTimestamp())
        ->and($match->casualNextDeadline())->toMatchArray(['kind' => 'lobby', 'side' => $match->host_side])
        ->and($match->casualLobbyDueAt()?->getTimestamp())->toBe(now()->addMinutes(5)->getTimestamp())
        // Once per match: the new host cannot hand it back.
        ->and(casualRefusal(fn () => $matches->swapHost($match, $guest)))->toBe('swapped_once')
        ->and(casualRefusal(fn () => $matches->shareLobby($match, $host)))->toBe('not_host');

    // The old host, now the guest, may claim a no-show only after the new deadline.
    $this->travel(4)->minutes();
    expect(casualRefusal(fn () => $matches->claimNoShow($match, $host)))->toBe('noshow_early');
    $this->travel(1)->minutes();
    expect($matches->claimNoShow($match, $host)->noshow_side)->toBe($hostSide);
});

test('no swap after the lobby was shared, after its deadline, or with a no-show claim pending', function () {
    $matches = app(CasualMatches::class);

    [$shared, $host] = casualStarted('rocket-league');
    $matches->shareLobby($shared, $host);
    expect(casualRefusal(fn () => $matches->swapHost($shared, $host)))->toBe('lobby_shared');

    [$late, $lateHost] = casualStarted('rocket-league');
    $this->travel(5)->minutes();
    expect(casualRefusal(fn () => $matches->swapHost($late, $lateHost)))->toBe('swap_late');

    [$claimed, $claimedHost, $claimer] = casualStarted('rocket-league');
    $this->travel(5)->minutes();
    $matches->claimNoShow($claimed, $claimer);
    $claimed->forceFill(['start_at' => now()->subMinute()])->save();

    expect(casualRefusal(fn () => $matches->swapHost($claimed, $claimedHost)))->toBe('noshow_pending')
        ->and($claimed->refresh()->host_swapped_at)->toBeNull();
});

test('a swap forgets the old host\'s "seen": it was about a card the new host never sent', function () {
    [$match, $host] = casualStarted('rocket-league');
    // A seen flag without a shared one is not reachable through seeLobby(); set it to prove the swap clears it.
    $match->forceFill(['lobby_seen_at' => now()])->save();

    expect(app(CasualMatches::class)->swapHost($match, $host)->lobby_seen_at)->toBeNull();
});

test('A + D of the expiration is the ready check plus lobby, join, report and confirm, fixed at the pairing', function () {
    [$match, $anna, $bert] = casualPairing('rocket-league');
    $expected = $match->ready_by?->copy()->addMinutes(5 + 10 + 60 + 30)->getTimestamp();

    config(['esports.casual.report_minutes' => 90]);
    $matches = app(CasualMatches::class);
    $matches->ready($match, $anna);
    $match = $matches->ready($match, $bert);
    $matches->swapHost($match, $match->host_side === 'challenger' ? $anna : $bert);

    expect($match->refresh()->casualChatExpiresFrom()?->getTimestamp())->toBe($expected)
        ->and(SeriesMatch::factory()->accepted()->create()->casualChatExpiresFrom())->toBeNull();
});

test('the room hands the chat the host, the flags, A + D and the player\'s own EA ID, and nothing to a lineup series', function () {
    [$match, $host, $guest] = casualStarted('ea-sports-fc-26');
    $host->forceFill(['gamer_tags' => ['ea' => 'Host_EA', 'epic' => 'hostepic']])->save();
    $guest->forceFill(['gamer_tags' => ['ea' => 'Guest_EA']])->save();

    $hostRoom = Livewire::actingAs($host)->test('pages::matches.room', ['match' => $match]);
    $config = $hostRoom->instance()->chatConfig();

    expect($config['casual'])->toBe([
        'game' => 'ea-sports-fc-26',
        'isHost' => true,
        'hostPubkey' => $host->pubkey,
        'started' => true,
        'open' => true,
        'shared' => false,
        'seen' => false,
        'expiresFrom' => $match->casualChatExpiresFrom()?->getTimestamp(),
        'lobbyName' => 'e21-'.$match->number,
        'eaId' => 'Host_EA',
    ])
        ->and(array_column($config['members'], 'pubkey'))->toEqualCanonicalizing([$host->pubkey, $guest->pubkey]);

    $guestConfig = Livewire::actingAs($guest)->test('pages::matches.room', ['match' => $match])->instance()->chatConfig();
    expect($guestConfig['casual']['isHost'])->toBeFalse()
        ->and($guestConfig['casual']['eaId'])->toBe('Guest_EA');

    $series = SeriesMatch::factory()->accepted()->create();
    expect(Livewire::actingAs($series->challengerLineup->clan->owner)->test('pages::matches.room', ['match' => $series])->instance()->chatConfig()['casual'])->toBeNull();
});

test('the chat\'s flags go through the room without arguments, and a refusal is answered to the chat only', function () {
    [$match, $host, $guest] = casualStarted('rocket-league');

    $guestRoom = Livewire::actingAs($guest)->test('pages::matches.room', ['match' => $match]);
    $guestRoom->call('casualLobbySeen')->assertReturned(['ok' => false, 'reason' => 'no_lobby_yet', 'message' => 'The host has not shared the lobby yet.'])
        ->assertSet('error', '');
    $guestRoom->call('casualLobbyShared')->assertReturned(['ok' => false, 'reason' => 'not_host', 'message' => 'Only the host shares the lobby.']);

    $hostRoom = Livewire::actingAs($host)->test('pages::matches.room', ['match' => $match]);
    $hostRoom->call('casualLobbyShared')->assertReturned(['ok' => true])
        ->assertDispatched('casual-room', fn (string $name, array $params) => $params['state']['shared'] === true && $params['state']['seen'] === false && $params['state']['isHost'] === true);

    $this->travel(10)->seconds();
    Livewire::actingAs($guest)->test('pages::matches.room', ['match' => $match])->call('casualLobbySeen')->assertReturned(['ok' => true])
        ->assertDispatched('casual-room', fn (string $name, array $params) => $params['state']['seen'] === true && $params['state']['isHost'] === false);

    $match->refresh();
    expect($match->lobby_shared_at?->lt($match->lobby_seen_at))->toBeTrue();
});

test('the steps of a casual room: Ready, swap, join and no-show go through CasualMatches, with their refusals on the error line', function () {
    [$match, $anna, $bert] = casualPairing('rocket-league');

    Livewire::actingAs($anna)->test('pages::matches.room', ['match' => $match])
        ->assertSee('Match steps')->assertSee('Both press Ready by')->assertSeeHtml('data-test="casual-ready"')
        ->assertDontSeeHtml('data-test="lobby-name-input"')
        ->call('casualReady')->assertSet('error', '')
        ->assertSee('You are ready. Waiting for');

    $room = Livewire::actingAs($bert)->test('pages::matches.room', ['match' => $match])->call('casualReady');
    $match->refresh();
    [$host, $guest] = $match->host_side === casualSideOf($match, $anna) ? [$anna, $bert] : [$bert, $anna];

    $guestRoom = Livewire::actingAs($guest)->test('pages::matches.room', ['match' => $match])
        ->assertSee('to share the lobby in the chat')->assertDontSeeHtml('data-test="casual-swap"')
        ->call('casualSwapHost')->assertSet('error', 'Only the host shares the lobby.')
        ->call('casualJoined')->assertSet('error', 'The host has not shared the lobby yet.');

    Livewire::actingAs($host)->test('pages::matches.room', ['match' => $match])
        ->assertSee('Create a private match in Rocket League')->assertSeeHtml('data-test="casual-swap"')
        ->call('casualSwapHost')->assertSet('error', '');

    expect($match->refresh()->host_side)->toBe(casualSideOf($match, $guest))
        ->and($room->instance())->not->toBeNull()
        ->and($guestRoom->instance())->not->toBeNull();

    // The old host now joins: after the new host shared, "I am in the lobby" sets `joined_at`.
    app(CasualMatches::class)->shareLobby($match, $guest);
    Livewire::actingAs($host)->test('pages::matches.room', ['match' => $match])
        ->assertSeeHtml('data-test="casual-joined"')
        ->call('casualJoined')->assertSet('error', '');

    expect($match->refresh()->joined_at)->not->toBeNull()
        ->and($match->status)->toBe(SeriesStatus::Accepted);
});

test('the chat hint is the same sentence for every chat, and the admin never gets an excerpt', function () {
    [$match, $host] = casualStarted('rocket-league');
    $hint = 'End-to-end encrypted over Nostr: the league server never receives or stores these messages.';

    $this->actingAs($host)->get(route('matches.room', $match))->assertOk()->assertSee($hint)
        ->assertDontSee('The chat is end-to-end encrypted with your Nostr key.');

    expect(file_get_contents(resource_path('views/pages/games/partials/chat-body.blade.php')))->toContain($hint)
        ->and(file_get_contents(resource_path('views/pages/admin/⚡dispute.blade.php')))->toContain('the league server never receives or stores it, so there is no excerpt here')
        ->and(json_decode((string) file_get_contents(lang_path('de.json')), true))->toHaveKey($hint);
});

test('the EA ID is a gamer tag of the profile, kept private', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::settings.gaming')
        ->set('gamerTags.ea', ' Satoshi_21 ')
        ->call('save')->assertHasNoErrors();

    expect($user->refresh()->gamer_tags)->toBe(['ea' => 'Satoshi_21']);
    $this->get(route('players.show', $user->npub))->assertOk()->assertDontSee('Satoshi_21');
});
