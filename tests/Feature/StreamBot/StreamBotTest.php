<?php

use App\Models\RankBadge;
use App\Models\RankBadgeVersion;
use App\Models\StreamBotPost;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RelayReader;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\LeagueKey;
use App\Support\StreamBot\StreamBot;
use App\Support\StreamBot\StreamBotBuilders;
use App\Support\StreamBot\StreamBotChat;
use App\Support\StreamBot\StreamBotCopy;
use App\Support\StreamBot\StreamBotPublisher;
use App\Support\StreamBot\StreamCoordinates;
use App\Support\TwentyOne\PublishResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use swentel\nostr\Key\Key;
use Tests\Support\TestSigner;

/*
 * The stream chat bot's cadence and posting (P22): fail closed, only while
 * the stream is live, an interval, never two posts in a row without a human
 * in the chat (or 45 minutes), a daily cap, quiet hours, and no builder or
 * fact again too soon. The relays are stood in for; tests/Integration
 * covers the real relay.
 */

beforeEach(function () {
    $this->dir = storage_path('framework/testing/stream-bot-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($this->dir.'/hls');
    $this->streamKey = new TestSigner;
    $this->botKey = new TestSigner;

    config([
        'esports.stream_bot.enabled' => true,
        'esports.stream_bot.nsec' => $this->botKey->secret,
        'esports.stream_bot.jitter_minutes' => 0,
        'esports.stream_bot.chat_relays' => ['wss://chat.test'],
        'esports.stream_bot.quiet_hours' => null,
        'twentyone.nostr.npub' => NostrKeys::hexToNpub($this->streamKey->pubkey),
        'twentyone.nostr.nsec' => null,
        'twentyone.stream.relays' => ['wss://one.test', 'wss://two.test'],
        'twentyone.stream.session_file' => $this->dir.'/session.json',
        'twentyone.stream.hls_dir' => $this->dir.'/hls',
        'twentyone.stream.public_url' => 'https://esports.test/live/stream.m3u8',
    ]);

    // The relays: every event is kept, each relay answers as $this->accepts says.
    $this->published = [];
    $this->accepts = true;
    $this->app->instance(StreamBotPublisher::class, new class($this) extends StreamBotPublisher
    {
        public function __construct(private $test) {}

        public function publish(array $event, array $relays): array
        {
            $this->test->published[] = ['event' => $event, 'relays' => $relays];

            return collect($relays)->mapWithKeys(fn (string $relay): array => [$relay => new PublishResult($relay, $this->test->accepts, $this->test->accepts ? '' : 'blocked: no')])->all();
        }
    });

    // The chat: $this->humans are the pubkeys that wrote since the bot's last post.
    $this->humans = [];
    $this->chatReads = 0;
    $this->app->instance(StreamBotChat::class, new class($this) extends StreamBotChat
    {
        public function __construct(private $test) {}

        public function humansSince(StreamCoordinates $stream, string $botPubkey, int $since): array
        {
            $this->test->chatReads++;

            return $this->test->humans;
        }
    });

    // Sat 2026-09-26 12:00 UTC, 14:00 in Berlin.
    $this->travelTo(Carbon::parse('2026-09-26 12:00:00', 'UTC'));
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

/** The stream daemon's two files: an accepted `live` just now, a fresh playlist. */
function streamIsLive(): void
{
    file_put_contents(test()->dir.'/session.json', json_encode(['starts' => now()->getTimestamp() - 3600, 'lastLiveAt' => now()->getTimestamp()]));
    touch(test()->dir.'/hls/stream.m3u8', now()->getTimestamp());
}

function botTick(): string
{
    return app(StreamBot::class)->run(CarbonImmutable::now());
}

/** Travel on and keep the stream live, as the daemon would. */
function botLater(int $minutes): void
{
    test()->travel($minutes)->minutes();
    streamIsLive();
}

test('a post is a kind-1311 live chat message under the stream, signed by the bot key, sent to the stream relays', function () {
    streamIsLive();

    expect(botTick())->toStartWith('posted ');

    $sent = $this->published[0];
    $event = SignedEvent::fromInput($sent['event']);
    $post = StreamBotPost::query()->sole();

    expect($event)->not->toBeNull()
        ->and($event->kind)->toBe(1311)
        ->and($event->pubkey)->toBe($this->botKey->pubkey)
        ->and($event->hasValidSignature())->toBeTrue()
        ->and($event->tags)->toBe([['a', '30311:'.$this->streamKey->pubkey.':twentyone-247', 'wss://one.test', 'root']])
        ->and($event->createdAt)->toBe(now()->getTimestamp())
        ->and(StreamBotCopy::violations($event->content, $event->tags))->toBe([])
        ->and($sent['relays'])->toBe(['wss://one.test', 'wss://two.test'])
        ->and($post->event_id)->toBe($event->id)
        ->and($post->content)->toBe($event->content)
        ->and($post->relays_accepted)->toBe(2)
        ->and($post->next_due_at->getTimestamp())->toBe(now()->addMinutes(20)->getTimestamp());
});

test('a message that names a player for an achievement goes out with their p tag after the stream\'s a tag', function () {
    $alice = User::factory()->create(['name' => 'Alice']);
    $badge = RankBadge::query()->create(['user_id' => $alice->id, 'pubkey' => $alice->pubkey, 'game' => 'chess', 'mode' => 'blitz',
        'd' => 'rank/chess-blitz/'.$alice->pubkey, 'badge_pubkey' => str_repeat('b', 64), 'tier' => 'gold-2', 'season' => '']);
    RankBadgeVersion::query()->create(['rank_badge_id' => $badge->id, 'tier' => 'gold-2', 'previous_tier' => 'silver-1', 'season' => '', 'rating' => 1612, 'signed_at' => time()]);
    $message = app(StreamBotBuilders::class)->build('rank_up', CarbonImmutable::now())[0];

    $post = app(StreamBot::class)->post(LeagueKey::streamBot(), StreamCoordinates::fromConfig(), $message, CarbonImmutable::now());
    $event = SignedEvent::fromInput($this->published[0]['event']);

    expect($event->tags)->toBe([['a', '30311:'.$this->streamKey->pubkey.':twentyone-247', 'wss://one.test', 'root'], ['p', $alice->pubkey]])
        ->and($event->content)->toContain('nostr:'.NostrKeys::hexToNpub($alice->pubkey).' ')
        ->and($event->hasValidSignature())->toBeTrue()
        ->and(StreamBotCopy::violations($event->content, $event->tags))->toBe([])
        ->and($post->event_id)->toBe($event->id);
});

test('the stream key comes from the stream nsec when no npub is configured', function () {
    config(['twentyone.nostr.npub' => null, 'twentyone.nostr.nsec' => (new Key)->convertPrivateKeyToBech32($this->streamKey->secret)]);

    expect(StreamCoordinates::fromConfig()?->address())->toBe('30311:'.$this->streamKey->pubkey.':twentyone-247');
});

test('fail closed: nothing is signed or sent with the flag off, without a key or without a stream', function (array $config, string $reason) {
    streamIsLive();
    config($config);

    expect(botTick())->toBe('no post: '.$reason)
        ->and($this->published)->toBe([])
        ->and(StreamBotPost::query()->count())->toBe(0);
})->with([
    'flag off' => [['esports.stream_bot.enabled' => false], 'ESPORTS_STREAM_BOT_ENABLED is off'],
    'no key' => [['esports.stream_bot.nsec' => null], 'ESPORTS_STREAM_BOT_NSEC is not set or not a valid secret key'],
    'not a key' => [['esports.stream_bot.nsec' => 'nsec1nope'], 'ESPORTS_STREAM_BOT_NSEC is not set or not a valid secret key'],
    'no stream relays' => [['twentyone.stream.relays' => []], 'the stream has no key, d tag or relay (twentyone.nostr, twentyone.stream)'],
    'no stream key' => [['twentyone.nostr.npub' => null], 'the stream has no key, d tag or relay (twentyone.nostr, twentyone.stream)'],
]);

test('only while the stream is live: no session, an old live event or a stale playlist keep it quiet', function () {
    expect(botTick())->toBe('no post: the stream is not live (no live session)');

    streamIsLive();
    $this->travel(31)->minutes();
    touch($this->dir.'/hls/stream.m3u8', now()->getTimestamp());
    expect(botTick())->toBe('no post: the stream is not live (the last live 30311 is 31 min old)');

    streamIsLive();
    touch($this->dir.'/hls/stream.m3u8', now()->getTimestamp() - 61);
    expect(botTick())->toBe('no post: the stream is not live (the stream playlist is not fresh)');

    streamIsLive();
    expect(botTick())->toStartWith('posted ')
        ->and($this->published)->toHaveCount(1);
});

test('the interval: nothing before the next post is due, even with a busy chat', function () {
    streamIsLive();
    botTick();
    $this->humans = [(new TestSigner)->pubkey];

    botLater(19);
    expect(botTick())->toBe('no post: the next post is due at 14:20');

    botLater(1);
    expect(botTick())->toStartWith('posted ')
        ->and($this->published)->toHaveCount(2);
});

test('the jitter keeps every interval within 20 ± 5 minutes', function () {
    config(['esports.stream_bot.jitter_minutes' => 5, 'esports.stream_bot.daily_cap' => 100, 'esports.stream_bot.repeat_hours' => 0]);
    streamIsLive();

    for ($i = 0; $i < 12; $i++) {
        botTick();
        botLater(50);
    }

    $gaps = StreamBotPost::query()->get()->map(fn (StreamBotPost $post): int => $post->next_due_at->getTimestamp() - $post->posted_at->getTimestamp());

    expect($gaps)->toHaveCount(12)
        ->and($gaps->min())->toBeGreaterThanOrEqual(15 * 60)
        ->and($gaps->max())->toBeLessThanOrEqual(25 * 60)
        ->and($gaps->unique()->count())->toBeGreaterThan(1);
});

test('never two bot posts in a row: a human in the chat, or 45 minutes', function () {
    streamIsLive();
    botTick();
    $readsAfterFirst = $this->chatReads;

    botLater(20);
    expect(botTick())->toBe('no post: no human wrote since the last post, and 45 min have not passed')
        ->and($this->chatReads)->toBe($readsAfterFirst + 1);

    $this->humans = [(new TestSigner)->pubkey];
    expect(botTick())->toStartWith('posted ');

    // Nobody since that second post: 44 minutes are not enough, 45 are.
    $this->humans = [];
    botLater(44);
    expect(botTick())->toStartWith('no post: no human wrote');

    botLater(1);
    $reads = $this->chatReads;
    expect(botTick())->toStartWith('posted ')
        ->and($this->chatReads)->toBe($reads)
        ->and($this->published)->toHaveCount(3);
});

test('the chat is read only when everything else allows a post', function () {
    streamIsLive();
    botTick();
    botLater(5);

    expect(botTick())->toStartWith('no post: the next post is due')
        ->and($this->chatReads)->toBe(0);
});

test('the daily cap counts delivered posts of the Berlin day', function () {
    config(['esports.stream_bot.daily_cap' => 2]);
    // 23:00 Berlin: the cap resets at 00:00 Berlin, 22:00 UTC.
    $this->travelTo(Carbon::parse('2026-09-26 19:00:00', 'UTC'));
    streamIsLive();

    botTick();
    botLater(45);
    botTick();
    botLater(45);
    expect(botTick())->toBe('no post: the daily cap of 2 posts is reached')
        ->and($this->published)->toHaveCount(2);

    // 20:30 UTC + 90 min = 22:00 UTC = midnight in Berlin.
    botLater(90);
    expect(botTick())->toStartWith('posted ');
});

test('quiet hours in Berlin time, across midnight; an unreadable setting counts as quiet', function () {
    config(['esports.stream_bot.quiet_hours' => '23-07']);
    // 21:30 UTC = 23:30 Berlin.
    $this->travelTo(Carbon::parse('2026-09-26 21:30:00', 'UTC'));
    streamIsLive();
    expect(botTick())->toBe('no post: quiet hours');

    // 05:00 UTC = 07:00 Berlin: over.
    $this->travelTo(Carbon::parse('2026-09-27 05:00:00', 'UTC'));
    streamIsLive();
    expect(botTick())->toStartWith('posted ');

    config(['esports.stream_bot.quiet_hours' => '7 to 23']);
    botLater(60);
    expect(botTick())->toBe('no post: ESPORTS_STREAM_BOT_QUIET_HOURS is unreadable (treated as quiet)');

    config(['esports.stream_bot.quiet_hours' => '22:30-23:15']);
    expect(app(StreamBot::class)->isQuiet(CarbonImmutable::parse('2026-09-27 20:45:00', 'UTC')))->toBeTrue()
        ->and(app(StreamBot::class)->isQuiet(CarbonImmutable::parse('2026-09-27 21:15:00', 'UTC')))->toBeFalse();
});

test('a post no relay accepted is logged, counts for nothing and is retried after 5 minutes', function () {
    streamIsLive();
    $this->accepts = false;

    expect(botTick())->toContain('to 0/2 relays');
    $failed = StreamBotPost::query()->sole();

    expect($failed->relays_accepted)->toBe(0)
        ->and($failed->next_due_at->getTimestamp())->toBe(now()->addMinutes(5)->getTimestamp())
        ->and(app(StreamBot::class)->postsToday(CarbonImmutable::now()))->toBe(0)
        ->and(app(StreamBot::class)->recentFacts(CarbonImmutable::now()))->toBe([]);

    $this->accepts = true;
    botLater(5);
    // No delivered post before it: the human rule does not hold it back.
    expect(botTick())->toStartWith('posted ');
});

test('rotation: no builder within the last 4 posts, no fact twice within 12 hours', function () {
    config(['esports.stream_bot.daily_cap' => 100]);
    streamIsLive();

    for ($i = 0; $i < 20; $i++) {
        botTick();
        botLater(45);
    }

    $posts = StreamBotPost::query()->orderBy('id')->get()->values();

    expect($posts->count())->toBeGreaterThan(8);

    foreach ($posts as $index => $post) {
        $earlier = $posts->slice(max(0, $index - 4), min(4, $index))->pluck('builder')->all();
        expect($earlier)->not->toContain($post->builder);

        $sameFact = $posts->slice(0, $index)->where('fact_key', $post->fact_key)
            ->filter(fn (StreamBotPost $other): bool => $post->posted_at->getTimestamp() - $other->posted_at->getTimestamp() < 12 * 3600);
        expect($sameFact)->toBeEmpty();
    }
});

test('with every fact and tip used up, the bot says nothing', function () {
    streamIsLive();
    $bot = app(StreamBot::class);
    $facts = collect($bot->preview(CarbonImmutable::now(), 100))->pluck('factKey')->all();

    expect($bot->next(CarbonImmutable::now(), [], $facts))->toBeNull();
});

test('a message that breaks the copy rules is never picked', function () {
    $bot = app(StreamBot::class);
    // Every fact and tip but one is used up; that one would come out with a hashtag.
    $facts = collect($bot->preview(CarbonImmutable::now(), 100))->pluck('factKey')->reject(fn (string $key): bool => $key === 'feature:login')->values()->all();
    expect($bot->next(CarbonImmutable::now(), [], $facts)?->factKey)->toBe('feature:login');

    // A link with a fragment would carry a `#`, which chat clients render as a hashtag.
    URL::forceRootUrl('https://esports.test/#stream');
    expect($bot->next(CarbonImmutable::now(), [], $facts))->toBeNull();
});

test('the chat check asks the chat relays for the stream\'s 1311s since the last post, and the bot does not count', function () {
    $reader = new class extends RelayReader
    {
        public array $asked = [];

        public array $events = [];

        public function fetch(array $filters, ?array $relays = null, array $known = [], int $perAuthor = 1): array
        {
            $this->asked[] = ['filters' => $filters, 'relays' => $relays];

            return $this->events;
        }
    };
    $chat = new StreamBotChat($reader);
    $stream = StreamCoordinates::fromConfig();
    $human = new TestSigner;
    $message = fn (TestSigner $key): SignedEvent => SignedEvent::fromInput($key->sign(1311, [['a', $stream->address(), '', 'root']], 'gm', now()->getTimestamp()));

    $reader->events = [$message($this->botKey)];
    expect($chat->humansSince($stream, $this->botKey->pubkey, 1000))->toBe([])
        ->and($reader->asked[0])->toBe(['filters' => [['kinds' => [1311], '#a' => [$stream->address()], 'since' => 1000, 'limit' => 20]], 'relays' => ['wss://chat.test']]);

    $reader->events = [$message($this->botKey), $message($human)];
    expect($chat->humansSince($stream, $this->botKey->pubkey, 1000))->toBe([$human->pubkey]);

    config(['esports.stream_bot.chat_relays' => []]);
    expect($chat->humansSince($stream, $this->botKey->pubkey, 1000))->toBe([])
        ->and($reader->asked)->toHaveCount(2);
});
