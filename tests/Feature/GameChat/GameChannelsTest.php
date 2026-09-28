<?php

use App\Models\ChatMute;
use App\Models\User;
use App\Support\GameChat\GameChannels;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RelayReader;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\LeagueKey;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\Support\TestSigner;
use Tests\Support\WaitForPort;

/*
 * The global chat of each game (P21, NIP "Game channels"): one NIP-28
 * channel per game whose id follows from the creator's pubkey alone, the
 * config the page hands the browser, the league-player lookup, the mutes,
 * the placement on the overview pages and the command that publishes the
 * channels. The chat's rules run under Node (tests/js/channelChat.test.mjs).
 */

test('the chat rules hold in the client: messages, polls, one vote per pubkey, closed polls, moderation, bounds', function () {
    $run = Process::path(base_path())->timeout(60)->run(['node', '--test', 'tests/js/channelChat.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 10')->toContain('ℹ skipped 0');
});

test('each game has its own channel, fixed by the creator, and the league key signs exactly that id', function () {
    $league = new TestSigner;
    config(['esports.league.nsec' => $league->secret, 'esports.game_chat.creator' => null]);

    $ids = array_map(fn (string $game): ?string => GameChannels::channelId($game), array_keys(GameChannels::GAMES));
    $signed = SignedEvent::fromInput($league->sign(40, [], GameChannels::createContent('rocket-league'), GameChannels::CREATED_AT));

    expect(array_keys(GameChannels::GAMES))->toBe(['chess', 'rocket-league', 'ea-sports-fc-26', 'ea-sports-fc-27'])
        ->and(array_unique($ids))->toHaveCount(4)
        ->and($ids)->each->toMatch('/^[0-9a-f]{64}$/')
        ->and(GameChannels::channelId('rocket-league'))->toBe($signed?->id)
        ->and($signed?->hasValidSignature())->toBeTrue()
        // The same creator given as npub: the same channels, and no secret needed.
        ->and((function () use ($league, $ids) {
            config(['esports.league.nsec' => null, 'esports.game_chat.creator' => NostrKeys::hexToNpub($league->pubkey)]);

            return array_map(fn (string $game): ?string => GameChannels::channelId($game), array_keys(GameChannels::GAMES)) === $ids;
        })())->toBeTrue()
        ->and(GameChannels::channelId('chess-960'))->toBeNull();

    // Another creator is another set of channels; none without one.
    config(['esports.game_chat.creator' => (new TestSigner)->pubkey]);
    expect(GameChannels::channelId('chess'))->not->toBe($ids[0]);
    config(['esports.game_chat.creator' => null]);
    expect(GameChannels::channelId('chess'))->toBeNull()
        ->and(GameChannels::config('chess', null))->toBeNull();
});

test('a channel id never changes: the chess channel of a fixed creator, checked against nostr-tools\' getEventHash', function () {
    config(['esports.game_chat.creator' => str_repeat('a', 64)]);

    // Computed once and read back with nostr-tools (getEventHash) on 2026-09-28: a changed field is another channel.
    expect(GameChannels::channelId('chess'))->toBe('a7ad3cc08cb547a696b717dc05784bab2935184c43dc3214298151e9687cc3ba');
});

test('the browser gets the channel, the chat relays, the viewer and nothing about other players', function () {
    $creator = new TestSigner;
    config(['esports.game_chat.creator' => $creator->pubkey, 'esports.chat.relays' => ['wss://relay.one', 'not a relay', 'ws://127.0.0.1:7777']]);
    $viewer = User::factory()->create();
    $muted = User::factory()->create();
    ChatMute::query()->create(['user_id' => $viewer->id, 'muted_pubkey' => $muted->pubkey]);

    $config = GameChannels::config('ea-sports-fc-27', $viewer);
    $guest = GameChannels::config('ea-sports-fc-27', null);

    expect($config)->toMatchArray([
        'game' => 'ea-sports-fc-27',
        'channel' => GameChannels::channelId('ea-sports-fc-27'),
        'creator' => $creator->pubkey,
        'relays' => ['wss://relay.one', 'ws://127.0.0.1:7777'],
        'relayHint' => 'wss://relay.one',
        'me' => $viewer->pubkey,
        'muted' => [$muted->pubkey],
        'maxLength' => 280,
    ])
        ->and($config['poll'])->toBe(['questionMax' => 140, 'optionMax' => 60, 'maxOptions' => 4, 'durations' => [3600, 86400, 259200, 604800]])
        ->and($guest['me'])->toBeNull()
        ->and($guest['muted'])->toBe([]);
});

test('the lookup names league players only, at most 100 at a time, hex keys only', function () {
    $players = User::factory()->count(2)->create();
    $stranger = (new TestSigner)->pubkey;

    $found = GameChannels::players([$players[0]->pubkey, $players[1]->pubkey, $stranger, 'nope', 42, strtoupper($players[0]->pubkey)]);

    expect(array_keys($found))->toEqualCanonicalizing([$players[0]->pubkey, $players[1]->pubkey])
        ->and($found[$players[0]->pubkey]['name'])->toBe($players[0]->displayName())
        ->and($found[$players[0]->pubkey]['avatar'])->toStartWith('http')
        ->and(GameChannels::players([...array_fill(0, 100, $stranger), $players[0]->pubkey]))->toBe([]);
});

test('the component mutes for the viewer only, never oneself, and a guest mutes nothing on the server', function () {
    config(['esports.game_chat.creator' => (new TestSigner)->pubkey]);
    $viewer = User::factory()->create();
    $other = (new TestSigner)->pubkey;

    $component = Livewire::actingAs($viewer)->test('game-channel', ['game' => 'chess']);
    $component->call('setMuted', $other, true)->assertReturned(true);
    $component->call('setMuted', $viewer->pubkey, true)->assertReturned(false);
    $component->call('setMuted', 'not-a-key', true)->assertReturned(false);
    $component->call('players', [$viewer->pubkey, $other])->assertReturned([$viewer->pubkey => ['name' => $viewer->displayName(), 'avatar' => $viewer->avatarUrl() ?? route('avatars.generated', ['pubkey' => $viewer->pubkey, 'v' => 1])]]);

    expect(ChatMute::query()->where('user_id', $viewer->id)->pluck('muted_pubkey')->all())->toBe([$other]);

    $component->call('setMuted', $other, false)->assertReturned(true);
    expect(ChatMute::query()->count())->toBe(0);

    auth()->logout();
    Livewire::test('game-channel', ['game' => 'chess'])->call('setMuted', $other, true)->assertReturned(false);
    expect(ChatMute::query()->count())->toBe(0);
});

test('every game overview page carries its channel: /chess and each series game, a guest reads and is asked to log in', function (string $url, string $game) {
    config(['esports.game_chat.creator' => (new TestSigner)->pubkey, 'esports.chat.relays' => ['ws://127.0.0.1:7777']]);

    $this->get($url)->assertOk()
        ->assertSee('data-channel="'.GameChannels::channelId($game).'"', false)
        ->assertSee('data-test="game-chat-guest"', false)
        ->assertDontSee('data-test="game-chat-form"', false);

    $this->actingAs(User::factory()->create())->get($url)->assertOk()
        ->assertSee('data-test="game-chat-form"', false)
        ->assertSee('data-test="game-chat-poll-form"', false)
        ->assertSee('Public on Nostr, visible in every client.');
})->with([
    'chess' => ['/chess', 'chess'],
    'rocket league' => ['/games/rocket-league', 'rocket-league'],
    'fc 26' => ['/games/ea-sports-fc-26', 'ea-sports-fc-26'],
    'fc 27' => ['/games/ea-sports-fc-27', 'ea-sports-fc-27'],
]);

test('without a creator the page says the chat is not set up, and nothing else breaks', function () {
    config(['esports.game_chat.creator' => null, 'esports.league.nsec' => null]);

    $this->get('/chess')->assertOk()->assertSee('data-test="game-chat-off"', false)->assertDontSee('x-data="gameChannel', false);
});

test('the command signs the four channels and their metadata with the league key and publishes them', function () {
    $league = new TestSigner;
    config(['esports.league.nsec' => $league->secret, 'esports.game_chat.creator' => null]);
    $port = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $relay = Process::path(base_path())->start(['php', 'tests/Support/mini-relay.php', (string) $port]);

    try {
        WaitForPort::open('127.0.0.1', $port);
        config(['esports.chat.relays' => ['ws://127.0.0.1:'.$port]]);

        $this->artisan('esports:game-channels')->assertSuccessful()
            ->expectsOutputToContain('chess: channel '.GameChannels::channelId('chess'))
            ->expectsOutputToContain('kind 40 ws://127.0.0.1:'.$port.' ok')
            ->expectsOutputToContain('kind 41 ws://127.0.0.1:'.$port.' ok');

        $stored = app(RelayReader::class)->fetch([['kinds' => [40, 41], 'authors' => [$league->pubkey]]], ['ws://127.0.0.1:'.$port], perAuthor: 20);
        $creates = collect($stored)->where('kind', 40);
        $meta = collect($stored)->firstWhere('kind', 41);

        expect($creates->pluck('id')->sort()->values()->all())->toBe(collect(array_keys(GameChannels::GAMES))->map(fn ($game) => GameChannels::channelId($game))->sort()->values()->all())
            ->and(json_decode($meta->content, true)['relays'])->toBe(['ws://127.0.0.1:'.$port])
            ->and($meta->tags[0][0])->toBe('e')
            ->and($meta->tags[0][3])->toBe('root');

        // The league's moderation: a mute by npub, a hide by id.
        $spammer = new TestSigner;
        $this->artisan('esports:game-channels', ['--mute' => NostrKeys::hexToNpub($spammer->pubkey), '--hide' => str_repeat('a', 64)])->assertSuccessful();
        $moderation = collect(app(RelayReader::class)->fetch([['kinds' => [43, 44], 'authors' => [$league->pubkey]]], ['ws://127.0.0.1:'.$port], perAuthor: 5));
        expect($moderation->firstWhere('kind', 44)?->tags)->toBe([['p', $spammer->pubkey]])
            ->and($moderation->firstWhere('kind', 43)?->tags)->toBe([['e', str_repeat('a', 64)]]);
    } finally {
        $relay->stop(1);
    }
});

test('the command refuses without the league key, for another creator, and for a malformed target', function () {
    config(['esports.league.nsec' => null]);
    $this->artisan('esports:game-channels', ['--dry-run' => true])->assertFailed();

    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.game_chat.creator' => (new TestSigner)->pubkey]);
    $this->artisan('esports:game-channels', ['--dry-run' => true])->assertFailed()->expectsOutputToContain('another key than the league key');

    config(['esports.game_chat.creator' => null]);
    $this->artisan('esports:game-channels', ['--mute' => 'npub1nothing', '--dry-run' => true])->assertFailed();
    $this->artisan('esports:game-channels', ['--hide' => 'xyz', '--dry-run' => true])->assertFailed();
    $this->artisan('esports:game-channels', ['--dry-run' => true])->assertSuccessful()->expectsOutputToContain('Dry run: nothing was sent.');

    expect(LeagueKey::fromConfig())->not->toBeNull();
});
