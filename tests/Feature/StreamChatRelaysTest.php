<?php

use App\Support\Caster\ChatReactions;
use App\Support\Nostr\RelayReader;
use App\Support\Nostr\SignedEvent;
use App\Support\StreamChat\StreamChat;
use App\Support\TwentyOne\EventBuilder;
use App\Support\TwentyOne\TwentyOneSigner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/*
 * One relay set for the stream chat (2026-10-12): the /live page reads and
 * posts on it, the caster's ChatReactions reads it, and the kind 30311
 * announces it as its NIP-53 `relays` tag. Measured that day: a kind 1311 on
 * nos.lol and damus only (where zap.stream posts for a user with its default
 * relays) never showed on /live, which read just the six stream relays.
 * relay.damus.io died by 2026-11-20 and gave way to relay.ditto.pub.
 */

beforeEach(function () {
    twentyOneStreamUp($this);
    config([
        'twentyone.stream.relays' => ['wss://stream.example', 'wss://second.example'],
        'esports.stream_chat.relays' => null,
        'esports.stream_chat.public_relays' => ['wss://public.example', 'wss://Stream.example/'],
        'esports.stream_bot.chat_relays' => ['wss://chat.example', 'https://not-a-relay.example', 'wss://public.example/'],
    ]);
});

afterEach(fn () => twentyOneStreamDown($this));

test('the chat relays are the stream relays, then the public chat relays, then the bot chat relays, each relay once', function () {
    expect(StreamChat::current()->relays)->toBe(['wss://stream.example', 'wss://second.example', 'wss://public.example', 'wss://chat.example'])
        ->and(StreamChat::current()->config(null)['relays'])->toBe(StreamChat::current()->relays)
        ->and(StreamChat::current()->config(null)['relayHint'])->toBe('wss://stream.example');

    // A set list still replaces all of them; an empty one switches the chat off.
    config(['esports.stream_chat.relays' => ['wss://own.example', 'wss://own.example/']]);
    expect(StreamChat::current()->relays)->toBe(['wss://own.example']);

    config(['esports.stream_chat.relays' => []]);
    expect(StreamChat::current()->relays)->toBe([]);
});

test('more than ten relays are cut at ten, the stream relays first', function () {
    config(['esports.stream_chat.public_relays' => array_map(fn (int $i): string => "wss://public-{$i}.example", range(1, 12))]);

    expect(StreamChat::current()->relays)->toHaveCount(StreamChat::MAX_RELAYS)
        ->and(array_slice(StreamChat::current()->relays, 0, 2))->toBe(['wss://stream.example', 'wss://second.example']);
});

test('production reads and posts on nos.lol and relay.ditto.pub besides the stream relays', function () {
    $saved = [$_SERVER['APP_ENV'] ?? null, $_ENV['APP_ENV'] ?? null, getenv('APP_ENV')];
    $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'production';
    putenv('APP_ENV=production');

    try {
        $publicRelays = (require config_path('esports.php'))['stream_chat']['public_relays'];
    } finally {
        [$_SERVER['APP_ENV'], $_ENV['APP_ENV']] = [$saved[0], $saved[1]];
        putenv('APP_ENV='.$saved[2]);
    }

    config([
        'twentyone.stream.relays' => (require config_path('twentyone.php'))['stream']['relays'],
        'esports.stream_chat.public_relays' => $publicRelays,
        'esports.stream_bot.chat_relays' => [],
    ]);

    expect($publicRelays)->toContain('wss://nos.lol', 'wss://relay.ditto.pub')
        ->and(StreamChat::current()->relays)->toContain('wss://nos.lol', 'wss://relay.ditto.pub', 'wss://relay.primal.net', 'wss://nostr.mom');
});

test('the caster reads the chat on exactly the relays the /live page reads', function () {
    config(['tv.commentary.enabled' => true, 'tv.commentary.chat.reactions' => true]);
    $reader = new class extends RelayReader
    {
        /** @var list<list<string>|null> */
        public array $relays = [];

        public function fetch(array $filters, ?array $relays = null, array $known = [], int $perAuthor = 1): array
        {
            $this->relays[] = $relays;

            return [];
        }
    };
    $this->app->instance(RelayReader::class, $reader);

    app(ChatReactions::class)->poll(CarbonImmutable::now());

    expect($reader->relays)->toBe([StreamChat::current()->relays]);

    // The chat switched off is off for the caster too.
    config(['esports.stream_chat.relays' => []]);
    $reader->relays = [];
    app(ChatReactions::class)->poll(CarbonImmutable::now());
    expect($reader->relays)->toBe([]);
});

test('the live and the ended 30311 announce exactly the chat relays as their relays tag, and none without a chat', function () {
    File::put(config('twentyone.stream.prepared'), 'fake');
    fakeEncoder($this->dir);

    [, $live] = streamRunRecorded($this->dir, 1);
    $announced = array_slice(collect($live['tags'])->firstWhere(0, 'relays') ?? [], 1);

    // The daemon's own relay (--relays) is its stream relay; then what the page reads besides it.
    expect($live['kind'])->toBe(30311)
        ->and(eventTag($live, 'status'))->toBe('live')
        ->and($announced)->toHaveCount(4)
        ->and($announced[0])->toStartWith('ws://127.0.0.1:')
        ->and($announced)->toBe(StreamChat::compose([$announced[0]]))
        ->and(array_slice($announced, 1))->toBe(['wss://public.example', 'wss://Stream.example/', 'wss://chat.example']);

    File::put($this->dir.'/session.json', json_encode(['starts' => 1790000000, 'lastLiveAt' => time()]));
    [$relay, $url] = recordingRelay($this->dir.'/ended.json');
    Artisan::call('twentyone:stream:end', ['--relays' => $url]);
    proc_terminate($relay);
    proc_close($relay);
    $ended = json_decode((string) file_get_contents($this->dir.'/ended.json'), true)[1];

    expect(eventTag($ended, 'status'))->toBe('ended')
        ->and(array_slice(collect($ended['tags'])->firstWhere(0, 'relays') ?? [], 1))->toBe([$url, 'wss://public.example', 'wss://Stream.example/', 'wss://chat.example']);

    // No chat, no relays tag: the 30311 keeps the shape the clients already read.
    config(['esports.stream_chat.relays' => []]);
    $plain = SignedEvent::fromInput(TwentyOneSigner::fromNsec($this->nsec)->sign((new EventBuilder)->liveActivity(
        config('twentyone.stream.event'), 'https://esports.example/live/stream.m3u8', $this->key->pubkey, 'live', 1790000000,
        relays: StreamChat::compose(['wss://stream.example']),
    )));
    expect(collect($plain->tags)->firstWhere(0, 'relays'))->toBeNull();
});
