<?php

use App\Enums\LineupRole;
use App\Games\GameRegistry;
use App\Models\ChatMute;
use App\Models\Lineup;
use App\Models\LineupSeat;
use App\Models\Rating;
use App\Models\User;
use App\Support\GameChat\GameChannels;
use App\Support\GameNames;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RelayReader;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\LeagueKey;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\Support\BlockfillOn;
use Tests\Support\CheckersGame;
use Tests\Support\HyperOn;
use Tests\Support\NineMensMorrisOn;
use Tests\Support\PongOn;
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

test('the component stays cheap under a flood: no redraw for votes nobody counts, polls shown only once their author counts, lookups by priority and pace', function () {
    $run = Process::path(base_path())->timeout(90)->run(['node', '--test', 'tests/js/gameChannel.test.mjs']);

    expect($run->successful())->toBeTrue($run->output().$run->errorOutput())
        ->and($run->output())->toContain('ℹ pass 7')->toContain('ℹ skipped 0');
});

test('each game has its own channel, fixed by the creator, and the league key signs exactly that id', function () {
    $league = new TestSigner;
    config(['esports.league.nsec' => $league->secret, 'esports.game_chat.creator' => null]);

    $ids = array_map(fn (string $game): ?string => GameChannels::channelId($game), array_keys(GameChannels::GAMES));
    $signed = SignedEvent::fromInput($league->sign(40, [], GameChannels::createContent('rocket-league'), GameChannels::CREATED_AT));

    expect(array_keys(GameChannels::GAMES))->toBe(['chess', 'rocket-league', 'ea-sports-fc-26', 'ea-sports-fc-27', 'nine-mens-morris', 'checkers', 'age-of-empires-2', 'tmnf', 'blockfill', 'blockli', 'hyperbitcoinization', 'proof-of-pong'])
        ->and(array_unique($ids))->toHaveCount(12)
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

test('the board games add two channels and change none of the four: every id of a fixed creator, switched on or off', function () {
    config(['esports.game_chat.creator' => str_repeat('a', 64)]);
    // Rev. 9.3's four, computed on 2026-09-30 before the board games were added; a changed id would orphan the chat
    // already written into it. The two of rev. 9.15 computed after, Age of Empires II (rev. 9.16) on 2026-09-30;
    // all seven equal nostr-tools' getEventHash.
    $frozen = [
        'chess' => 'a7ad3cc08cb547a696b717dc05784bab2935184c43dc3214298151e9687cc3ba',
        'rocket-league' => 'acbb95525755a5a247d5ae29a2cc6da3dcf7bdbaafdfeb6af9ed942cab7ede3a',
        'ea-sports-fc-26' => '23dcfbae270a842ba81e8db3041a68c7aa95718a6827fad7cdf0cdd99ef6c339',
        'ea-sports-fc-27' => '670278f922ed81489f1848a13303b17eeb0c94fca56b351d3efdc36399736506',
        'nine-mens-morris' => '136e4c7ed8a34dad000eb89ce942a8e2b34ed522ef6038b14475a71bfa3c8f68',
        'checkers' => '6e4221b5f8548e475e956cd73d43ef1a64b199c87abe2dd0b29162b70d093abd',
        'age-of-empires-2' => '7400cae2664605d1d134e74b9ba88f2c8743b8fa10b72f2d1615a3a05212d496',
        // TMNF and Blockfill (2026-10-03), each equal to nostr-tools' getEventHash.
        'tmnf' => '7b93615bc0584b65c91ad7a6f43045378397b94df0f5a905f1ccdac3ac1611a3',
        'blockfill' => '2eb506f4d95521318d9d03e071ce7f9bf50abd1ceb92d136d9c842ce4a51b5ca',
        'blockli' => '8aa1cd62b3934c12a38d9290add22b4e686dcd8c1c4704b762d67af26b71069d',
        // Hyperbitcoinization and Proof of Pong (2026-10-09, plan "Proof of Pong", P5), each equal to nostr-tools' getEventHash.
        'hyperbitcoinization' => 'f19ac294a4d935b12feee8567ef63c32e77b341b5e7eb406b6a6d837e9848257',
        'proof-of-pong' => 'baef46e9f787ba9457b4c3611c45d992c47367712a5bbce4210b9faa3400c98c',
    ];
    $ids = fn (): array => array_combine(array_keys(GameChannels::GAMES), array_map(GameChannels::channelId(...), array_keys(GameChannels::GAMES)));

    // The test app boots with the board games off: the ids are fixed all the same.
    expect(GameChannels::has('nine-mens-morris'))->toBeFalse()
        ->and($ids())->toBe($frozen)
        ->and(GameChannels::createEvent('nine-mens-morris'))->toBe([
            'id' => $frozen['nine-mens-morris'],
            'pubkey' => str_repeat('a', 64),
            'created_at' => 1790553600,
            'kind' => 40,
            'tags' => [],
            'content' => '{"name":"TWENTY ONE esports · Nine Men\'s Morris","about":"The global chat of Nine Men\'s Morris in the TWENTY ONE esports league: talk and vote."}',
        ])
        ->and(GameChannels::createEvent('checkers')['content'] ?? null)
        ->toBe('{"name":"TWENTY ONE esports · Checkers","about":"The global chat of Checkers in the TWENTY ONE esports league: talk and vote."}');

    NineMensMorrisOn::play();
    CheckersGame::play();
    expect(GameChannels::has('nine-mens-morris'))->toBeTrue()->and($ids())->toBe($frozen);
});

test('a board game\'s channel is open only while the board game is switched on, globally and on its own', function () {
    config(['esports.game_chat.creator' => (new TestSigner)->pubkey, 'esports.chat.relays' => ['ws://127.0.0.1:7777']]);
    $series = ['chess', 'rocket-league', 'ea-sports-fc-26', 'ea-sports-fc-27'];

    // Age of Empires II (rev. 9.16) is appended to GAMES and open like the other series games.
    expect(GameChannels::open())->toBe([...$series, 'age-of-empires-2'])
        ->and(GameChannels::config('nine-mens-morris', null))->toBeNull()
        ->and(GameChannels::config('checkers', null))->toBeNull();

    NineMensMorrisOn::play();
    expect(GameChannels::open())->toBe([...$series, 'nine-mens-morris', 'age-of-empires-2'])
        ->and(GameChannels::config('nine-mens-morris', null)['channel'] ?? null)->toBe(GameChannels::channelId('nine-mens-morris'))
        ->and(GameChannels::config('checkers', null))->toBeNull();

    CheckersGame::play();
    expect(GameChannels::open())->toBe([...$series, 'nine-mens-morris', 'checkers', 'age-of-empires-2']);

    // The global switch off takes both, whatever their own switches say.
    config(['esports.board_games.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);
    expect(GameChannels::open())->toBe([...$series, 'age-of-empires-2'])
        ->and(GameChannels::config('checkers', null))->toBeNull()
        ->and(Livewire::test('game-channel', ['game' => 'checkers'])->html())->toContain('data-test="game-chat-off"')->not->toContain('x-data="gameChannel');
});

test('a board game\'s result counts for polls and votes like any other game\'s', function () {
    NineMensMorrisOn::play();
    config(['esports.game_chat.creator' => (new TestSigner)->pubkey]);
    [$player, $fresh] = User::factory()->count(2)->create();
    Rating::query()->create(['pool' => 'casual', 'season' => '', 'game' => 'nine-mens-morris', 'mode' => 'blitz', 'subject' => 'user:'.$player->id, 'user_id' => $player->id, 'rating' => 1016, 'results' => 1, 'wins' => 1, 'draws' => 0, 'losses' => 0]);

    expect(GameChannels::config('nine-mens-morris', $player)['meCounts'])->toBeTrue()
        ->and(GameChannels::config('nine-mens-morris', $fresh)['meCounts'])->toBeFalse();
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

test('an account counts for polls and votes only as a member or with a result in the league, never for merely existing', function () {
    [$fresh, $member, $player, $seated, $emptyRating, $invited] = User::factory()->count(6)->create();
    $member->forceFill(['is_member' => true])->save();
    Rating::query()->create(['pool' => 'casual', 'season' => '', 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$player->id, 'user_id' => $player->id, 'rating' => 1016, 'results' => 1, 'wins' => 1, 'draws' => 0, 'losses' => 0]);
    Rating::query()->create(['pool' => 'casual', 'season' => '', 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$emptyRating->id, 'user_id' => $emptyRating->id, 'rating' => 1000, 'results' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0]);
    $lineup = Lineup::factory()->ready()->create();
    LineupSeat::query()->create(['lineup_id' => $lineup->id, 'user_id' => $seated->id, 'role' => LineupRole::Player, 'accepted_at' => now()]);
    // Invited, not accepted: the lineup's results are not theirs.
    LineupSeat::query()->create(['lineup_id' => $lineup->id, 'user_id' => $invited->id, 'role' => LineupRole::Player, 'accepted_at' => null]);
    Rating::query()->create(['pool' => 'casual', 'season' => '', 'game' => 'rocket-league', 'mode' => '2v2', 'subject' => 'lineup:'.$lineup->id, 'lineup_id' => $lineup->id, 'rating' => 1016, 'results' => 2, 'wins' => 2, 'draws' => 0, 'losses' => 0]);

    $found = GameChannels::players([$fresh->pubkey, $member->pubkey, $player->pubkey, $seated->pubkey, $emptyRating->pubkey, $invited->pubkey]);

    $counts = array_map(fn (array $row): bool => $row['counts'], $found);
    $expected = [
        $fresh->pubkey => false,
        $member->pubkey => true,
        $player->pubkey => true,
        $seated->pubkey => true,
        $emptyRating->pubkey => false,
        $invited->pubkey => false,
    ];
    ksort($counts);
    ksort($expected);

    expect($counts)->toBe($expected)
        // The viewer's own weight comes with the page: no lookup, and no poll form when it does not count.
        ->and(GameChannels::config('chess', $player)['meCounts'] ?? null)->toBeNull()
        ->and((function () use ($player, $fresh) {
            config(['esports.game_chat.creator' => (new TestSigner)->pubkey]);

            return [GameChannels::config('chess', $player)['meCounts'], GameChannels::config('chess', $fresh)['meCounts'], GameChannels::config('chess', null)['meCounts']];
        })())->toBe([true, false, false]);
});

test('a board game chat costs the same queries for 1, 5 and 25 message authors, on the page and in the lookup', function () {
    NineMensMorrisOn::play();
    config(['esports.game_chat.creator' => (new TestSigner)->pubkey, 'esports.chat.relays' => ['ws://127.0.0.1:7777']]);
    $viewer = User::factory()->create();
    $counts = [];

    foreach ([1, 5, 25] as $n) {
        // Messages live on the relays; what grows with them on the server is the lookup of their authors: league
        // players with a board game result, one of them seated in a lineup with results.
        $authors = User::factory()->count($n)->create();
        foreach ($authors as $author) {
            Rating::query()->create(['pool' => 'casual', 'season' => '', 'game' => 'nine-mens-morris', 'mode' => 'blitz', 'subject' => 'user:'.$author->id, 'user_id' => $author->id, 'rating' => 1016, 'results' => 1, 'wins' => 1, 'draws' => 0, 'losses' => 0]);
        }
        $lineup = Lineup::factory()->ready()->create();
        LineupSeat::query()->create(['lineup_id' => $lineup->id, 'user_id' => $authors[0]->id, 'role' => LineupRole::Player, 'accepted_at' => now()]);
        Rating::query()->create(['pool' => 'casual', 'season' => '', 'game' => 'rocket-league', 'mode' => '2v2', 'subject' => 'lineup:'.$lineup->id, 'lineup_id' => $lineup->id, 'rating' => 1016, 'results' => 2, 'wins' => 2, 'draws' => 0, 'losses' => 0]);
        $pubkeys = $authors->pluck('pubkey')->all();

        $component = Livewire::actingAs($viewer)->test('game-channel', ['game' => 'nine-mens-morris']);
        // Once before measuring: the first page view of a run fills caches the later ones read.
        $this->actingAs($viewer)->get('/games/nine-mens-morris')->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $found = $component->call('players', $pubkeys)->effects['returns'][0] ?? [];
        $lookup = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->actingAs($viewer)->get('/games/nine-mens-morris')->assertOk();
        $page = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($found)->toHaveCount($n)
            ->and(collect($found)->every(fn (array $row): bool => $row['counts']))->toBeTrue();
        $counts[$n] = ['lookup' => $lookup, 'page' => $page];
        Rating::query()->delete();
    }

    expect(array_unique(array_column($counts, 'lookup')))->toHaveCount(1, json_encode($counts))
        ->and(array_unique(array_column($counts, 'page')))->toHaveCount(1, json_encode($counts));
});

test('the component mutes for the viewer only, never oneself, and a guest mutes nothing on the server', function () {
    config(['esports.game_chat.creator' => (new TestSigner)->pubkey]);
    $viewer = User::factory()->create();
    $other = (new TestSigner)->pubkey;

    $component = Livewire::actingAs($viewer)->test('game-channel', ['game' => 'chess']);
    $component->call('setMuted', $other, true)->assertReturned(true);
    $component->call('setMuted', $viewer->pubkey, true)->assertReturned(false);
    $component->call('setMuted', 'not-a-key', true)->assertReturned(false);
    $component->call('players', [$viewer->pubkey, $other])->assertReturned([$viewer->pubkey => ['name' => $viewer->displayName(), 'avatar' => $viewer->avatarUrl() ?? route('avatars.generated', ['pubkey' => $viewer->pubkey, 'v' => 1]), 'counts' => false]]);

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
    'aoe2' => ['/games/age-of-empires-2', 'age-of-empires-2'],
]);

test('each board game lobby carries its own channel while switched on: a guest reads and is asked to log in, a player writes and polls', function (string $game) {
    NineMensMorrisOn::play();
    CheckersGame::play();
    config(['esports.game_chat.creator' => (new TestSigner)->pubkey, 'esports.chat.relays' => ['ws://127.0.0.1:7777']]);

    $this->get('/games/'.$game)->assertOk()
        ->assertSee('data-channel="'.GameChannels::channelId($game).'"', false)
        ->assertSee('x-data="gameChannel(', false)
        ->assertSee('data-test="game-chat-guest"', false)
        ->assertSee('Reading is open to everyone.')
        ->assertDontSee('data-test="game-chat-form"', false);

    $this->actingAs(User::factory()->create())->get('/games/'.$game)->assertOk()
        ->assertSee('data-channel="'.GameChannels::channelId($game).'"', false)
        ->assertSee('data-test="game-chat-form"', false)
        ->assertSee('data-test="game-chat-poll-form"', false)
        ->assertSee('Public on Nostr, visible in every client.');
})->with(['nine-mens-morris', 'checkers']);

test('without a creator the page says the chat is not set up, and nothing else breaks', function () {
    config(['esports.game_chat.creator' => null, 'esports.league.nsec' => null]);

    $this->get('/chess')->assertOk()->assertSee('data-test="game-chat-off"', false)->assertDontSee('x-data="gameChannel', false);
});

test('the command signs the open channels and their metadata with the league key and publishes them', function () {
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

        // The open channels only: the board games are off in the test app, so their two are not on the relay.
        expect($creates->pluck('id')->sort()->values()->all())->toBe(collect(GameChannels::open())->map(fn ($game) => GameChannels::channelId($game))->sort()->values()->all())
            ->and($creates)->toHaveCount(5)
            ->and(json_decode($meta->content, true)['relays'])->toBe(['ws://127.0.0.1:'.$port])
            ->and($meta->tags[0][0])->toBe('e')
            ->and($meta->tags[0][3])->toBe('root');

        // The daily run again: the same ids, nothing new on the relay (kind 41 keeps its created_at while it is unchanged).
        $this->artisan('esports:game-channels')->assertSuccessful()->expectsOutputToContain('duplicate');
        $again = app(RelayReader::class)->fetch([['kinds' => [40, 41], 'authors' => [$league->pubkey]]], ['ws://127.0.0.1:'.$port], perAuthor: 20);
        expect(collect($again)->pluck('id')->sort()->values()->all())->toBe(collect($stored)->pluck('id')->sort()->values()->all());

        // A new relay list is a new kind 41, once.
        config(['esports.chat.relays' => ['ws://127.0.0.1:'.$port, 'wss://relay.example']]);
        $this->artisan('esports:game-channels', ['--relays' => 'ws://127.0.0.1:'.$port]);
        $changed = collect(app(RelayReader::class)->fetch([['kinds' => [41], 'authors' => [$league->pubkey]]], ['ws://127.0.0.1:'.$port], perAuthor: 20));
        expect($changed)->toHaveCount(10);

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

test('the command runs daily, once, never twice at a time', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'esports:game-channels'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('21 3 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
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

/**
 * A dry run of the command: its output and the events it prints, decoded.
 *
 * @return array{0: list<array<string, mixed>>, 1: string}
 */
function gameChannelsDryRun(): array
{
    Artisan::call('esports:game-channels', ['--dry-run' => true]);
    $output = Artisan::output();
    preg_match_all('/^\{\n.*?\n\}$/ms', $output, $blocks);

    return [array_map(fn (string $block): array => json_decode($block, true, flags: JSON_THROW_ON_ERROR), $blocks[0]), $output];
}

test('the command signs a board game\'s channel only while the board game is switched on, and never another id', function () {
    $league = new TestSigner;
    config(['esports.league.nsec' => $league->secret, 'esports.game_chat.creator' => null]);
    $creates = fn (array $events): array => array_values(array_map(fn (array $event): string => $event['id'], array_filter($events, fn (array $event): bool => $event['kind'] === 40)));
    $series = array_map(GameChannels::channelId(...), ['chess', 'rocket-league', 'ea-sports-fc-26', 'ea-sports-fc-27']);
    $aoe = GameChannels::channelId('age-of-empires-2');

    // Off (as the test app boots): the four and Age of Empires II (rev. 9.16), and a line that says the board games were skipped.
    [$events, $output] = gameChannelsDryRun();
    expect($creates($events))->toBe([...$series, $aoe])
        ->and($events)->toHaveCount(10)
        ->and($output)->toContain('nine-mens-morris: switched off, nothing signed')->toContain('checkers: switched off, nothing signed')
        ->and($output)->not->toContain((string) GameChannels::channelId('nine-mens-morris'))->not->toContain((string) GameChannels::channelId('checkers'));

    // Nine men's morris on, checkers off: one more channel, with its metadata pointing at it.
    NineMensMorrisOn::play();
    [$events, $output] = gameChannelsDryRun();
    $morris = collect($events)->firstWhere('id', GameChannels::channelId('nine-mens-morris'));
    $morrisMeta = collect($events)->first(fn (array $event): bool => $event['kind'] === 41 && $event['tags'][0][1] === GameChannels::channelId('nine-mens-morris'));
    expect($creates($events))->toBe([...$series, GameChannels::channelId('nine-mens-morris'), $aoe])
        ->and($events)->toHaveCount(12)
        ->and(SignedEvent::fromInput($morris)?->hasValidSignature())->toBeTrue()
        ->and($morris['pubkey'])->toBe($league->pubkey)
        ->and($morris['created_at'])->toBe(GameChannels::CREATED_AT)
        ->and($morris['tags'])->toBe([])
        ->and($morris['content'])->toBe(GameChannels::createContent('nine-mens-morris'))
        ->and(json_decode($morrisMeta['content'], true)['name'])->toBe("TWENTY ONE esports · Nine Men's Morris")
        ->and($output)->toContain('checkers: switched off, nothing signed')->not->toContain((string) GameChannels::channelId('checkers'));

    // Both on: seven channels, each signed with exactly its id (TMNF and Blockfill stay off here).
    CheckersGame::play();
    [$events, $output] = gameChannelsDryRun();
    expect($creates($events))->toBe(array_map(GameChannels::channelId(...), array_values(array_diff(array_keys(GameChannels::GAMES), ['tmnf', 'blockfill', 'blockli', 'hyperbitcoinization', 'proof-of-pong']))))
        ->and($events)->toHaveCount(14)
        ->and(collect($events)->every(fn (array $event): bool => SignedEvent::fromInput($event)?->hasValidSignature() === true))->toBeTrue()
        ->and($output)->not->toContain('nine-mens-morris: switched off')->not->toContain('checkers: switched off');
});

test('TMNF and Blockfill have their own channels, fixed like the others, open and published only while their switch is on', function () {
    $league = new TestSigner;
    config(['esports.league.nsec' => $league->secret, 'esports.game_chat.creator' => null]);
    $tmnf = GameChannels::channelId('tmnf');
    $blockfill = GameChannels::channelId('blockfill');

    // Off (as the test app boots): the ids are fixed all the same, the channels closed and not signed.
    expect($tmnf)->toMatch('/^[0-9a-f]{64}$/')->and($blockfill)->toMatch('/^[0-9a-f]{64}$/')
        ->and(GameChannels::has('tmnf'))->toBeFalse()
        ->and(GameChannels::has('blockfill'))->toBeFalse();
    [, $output] = gameChannelsDryRun();
    expect($output)->toContain('tmnf: switched off, nothing signed')->toContain('blockfill: switched off, nothing signed')
        ->and($output)->not->toContain((string) $tmnf)->not->toContain((string) $blockfill);

    tmnfOn();
    BlockfillOn::play();
    [$events] = gameChannelsDryRun();
    $signed = collect($events)->where('kind', 40)->keyBy('id');

    expect(GameChannels::open())->toContain('tmnf', 'blockfill')
        ->and($signed->get($tmnf)['content'] ?? null)->toBe('{"name":"TWENTY ONE esports · TrackMania Nations Forever","about":"The global chat of TrackMania Nations Forever in the TWENTY ONE esports league: talk and vote."}')
        ->and($signed->get($blockfill)['content'] ?? null)->toBe('{"name":"TWENTY ONE esports · Blockfill","about":"The global chat of Blockfill in the TWENTY ONE esports league: talk and vote."}')
        ->and(SignedEvent::fromInput($signed->get($tmnf))?->hasValidSignature())->toBeTrue()
        ->and(SignedEvent::fromInput($signed->get($blockfill))?->hasValidSignature())->toBeTrue()
        ->and(collect($events)->where('kind', 41)->map(fn (array $event): string => $event['tags'][0][1])->values()->all())->toContain($tmnf, $blockfill);

    // Frozen on 2026-10-03 for a fixed creator, equal to nostr-tools' getEventHash: a changed field is another channel.
    config(['esports.league.nsec' => null, 'esports.game_chat.creator' => str_repeat('a', 64)]);
    expect(GameChannels::channelId('tmnf'))->toBe('7b93615bc0584b65c91ad7a6f43045378397b94df0f5a905f1ccdac3ac1611a3')
        ->and(GameChannels::channelId('blockfill'))->toBe('2eb506f4d95521318d9d03e071ce7f9bf50abd1ceb92d136d9c842ce4a51b5ca');
});

test('Hyperbitcoinization and Proof of Pong have their own channels, open and published only while their switch is on', function () {
    $league = new TestSigner;
    config(['esports.league.nsec' => $league->secret, 'esports.game_chat.creator' => null, 'esports.chat.relays' => ['ws://127.0.0.1:7777']]);
    $hyper = GameChannels::channelId('hyperbitcoinization');
    $pong = GameChannels::channelId('proof-of-pong');

    // Off (as the test app boots): the ids are fixed all the same, the channels closed, not signed and not on a page.
    expect($hyper)->toMatch('/^[0-9a-f]{64}$/')->and($pong)->toMatch('/^[0-9a-f]{64}$/')->and($hyper)->not->toBe($pong)
        ->and(GameChannels::has('hyperbitcoinization'))->toBeFalse()
        ->and(GameChannels::has('proof-of-pong'))->toBeFalse()
        ->and(GameChannels::config('proof-of-pong', null))->toBeNull()
        // A match's table chat is another channel than the game's.
        ->and(GameChannels::matchChannelId('01JABCDEFGHJKMNPQRSTVWXYZ0'))->not->toBe($hyper);
    [, $output] = gameChannelsDryRun();
    expect($output)->toContain('hyperbitcoinization: switched off, nothing signed')->toContain('proof-of-pong: switched off, nothing signed')
        ->and($output)->not->toContain((string) $hyper)->not->toContain((string) $pong);

    HyperOn::play();
    PongOn::play();
    [$events] = gameChannelsDryRun();
    $signed = collect($events)->where('kind', 40)->keyBy('id');

    expect(GameChannels::open())->toContain('hyperbitcoinization', 'proof-of-pong')
        ->and(GameChannels::config('proof-of-pong', null)['channel'] ?? null)->toBe($pong)
        ->and($signed->get($hyper)['content'] ?? null)->toBe('{"name":"TWENTY ONE esports · Hyperbitcoinization","about":"The global chat of Hyperbitcoinization in the TWENTY ONE esports league: talk and vote."}')
        ->and($signed->get($pong)['content'] ?? null)->toBe('{"name":"TWENTY ONE esports · Proof of Pong","about":"The global chat of Proof of Pong in the TWENTY ONE esports league: talk and vote."}')
        ->and(SignedEvent::fromInput($signed->get($hyper))?->hasValidSignature())->toBeTrue()
        ->and(SignedEvent::fromInput($signed->get($pong))?->hasValidSignature())->toBeTrue()
        ->and(collect($events)->where('kind', 41)->map(fn (array $event): string => $event['tags'][0][1])->values()->all())->toContain($hyper, $pong);

    // On their pages the chat follows the hero with the way to play, as on every game page (a bar below xl, the side column from xl).
    $this->get('/hyperbitcoinization')->assertOk()->assertSeeInOrder(['id="hyper-h"', 'data-test="hyper-lobby"', 'class="chat-rail"', 'data-test="game-chat" data-game="hyperbitcoinization" data-channel="'.$hyper.'"'], false);
    // Since P9 both ways to play (the live 1v1, the bot) are in the play section ahead of the chat.
    $this->get('/proof-of-pong')->assertOk()->assertSeeInOrder(['id="pong-h"', 'data-test="pong-lobby"', 'data-test="pong-play-bot"', 'class="chat-rail"', 'data-test="game-chat" data-game="proof-of-pong" data-channel="'.$pong.'"'], false);
});

test('every game in the registry has its chat on its page, every switch on', function () {
    NineMensMorrisOn::play();
    CheckersGame::play();
    tmnfOn();
    BlockfillOn::play();
    HyperOn::play();
    PongOn::play();
    config(['esports.game_chat.creator' => (new TestSigner)->pubkey, 'esports.chat.relays' => ['ws://127.0.0.1:7777']]);
    $games = array_keys(app(GameRegistry::class)->all());

    // Every kind of game is in the run: chess, a series game, a board game, both score games and the league's own two.
    expect($games)->toContain('chess', 'rocket-league', 'nine-mens-morris', 'checkers', 'tmnf', 'blockfill', 'hyperbitcoinization', 'proof-of-pong');

    foreach ($games as $game) {
        $page = GameNames::page($game);
        $html = (string) $this->get($page)->assertOk()->getContent();

        expect(GameChannels::has($game))->toBeTrue("{$game} has no open channel")
            ->and(str_contains($html, 'data-test="game-chat" data-game="'.$game.'" data-channel="'.GameChannels::channelId($game).'"'))->toBeTrue("{$page} has no chat of {$game}")
            ->and($html)->toContain('x-data="gameChannel(');
    }
});
