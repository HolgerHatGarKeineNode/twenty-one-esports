<?php

use App\Models\StreamBotPost;
use App\Support\StreamBot\StreamBot;
use App\Support\TwentyOne\EventBuilder;
use App\Support\TwentyOne\RelayPublisher;
use App\Support\TwentyOne\TwentyOneSigner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use swentel\nostr\Key\Key;
use Tests\Integration\Support\RelayCheck;
use Tests\Integration\Support\Stack;
use Tests\Support\TestSigner;

pest()->group('integration');

/*
|--------------------------------------------------------------------------
| P22: the stream chat bot against a real relay
|--------------------------------------------------------------------------
|
| The local `nak serve` of Stack is the stream relay and the chat relay at
| once; every key is thrown away after the run. A fake 30311 from a fake
| stream key, the daemon's session file and playlist in a temp directory,
| human kind-1311 messages published by other throwaway keys. The bot posts
| exactly when its rules allow, and what it posted is read back from the
| relay (kind, `a` tag, signature), not from its own log.
|
*/

test('the bot posts when the rules allow, waits for a human in between, and its 1311 is on the relay under the stream', function () {
    $stack = Stack::instance();
    $dir = storage_path('framework/testing/stream-bot-integration-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($dir.'/hls');

    $streamKey = new TestSigner;
    $botKey = new TestSigner;
    $stream = TwentyOneSigner::fromNsec((new Key)->convertPrivateKeyToBech32($streamKey->secret));

    config([
        'twentyone.nostr.nsec' => (new Key)->convertPrivateKeyToBech32($streamKey->secret),
        'twentyone.nostr.npub' => null,
        'twentyone.stream.relays' => [$stack->relayUrl],
        'twentyone.stream.session_file' => $dir.'/session.json',
        'twentyone.stream.hls_dir' => $dir.'/hls',
        'twentyone.stream.public_url' => 'https://esports.test/live/stream.m3u8',
        'esports.stream_bot.enabled' => true,
        'esports.stream_bot.nsec' => $botKey->secret,
        'esports.stream_bot.chat_relays' => [$stack->relayUrl],
        'esports.stream_bot.jitter_minutes' => 0,
        'esports.stream_bot.quiet_hours' => null,
    ]);
    StreamBotPost::query()->delete();

    // The stream's own 30311, as the daemon publishes it, and the daemon's two files.
    $live = $stream->sign((new EventBuilder)->liveActivity(config('twentyone.stream.event'), 'https://esports.test/live/stream.m3u8', $stream->pubkey, 'live', time() - 600));
    expect((new RelayPublisher)->publish($live, [$stack->relayUrl], 5)[$stack->relayUrl]->accepted)->toBeTrue();
    file_put_contents($dir.'/session.json', json_encode(['starts' => time() - 600, 'lastLiveAt' => time()]));
    touch($dir.'/hls/stream.m3u8');

    $address = '30311:'.$stream->pubkey.':'.config('twentyone.stream.event.d');
    $relay = new RelayCheck($stack->relayUrl);
    $bot = app(StreamBot::class);
    $human = new TestSigner;
    $say = fn (TestSigner $key, string $a, string $text, int $secondsAgo = 0) => expect((new RelayPublisher)->publish($key->sign(1311, [['a', $a, '', 'root']], $text, time() - $secondsAgo), [$stack->relayUrl], 5)[$stack->relayUrl]->accepted)->toBeTrue();
    // The bot's last post, moved back as if it went out `$minutes` ago (relay events keep the real clock).
    $age = fn (int $minutes) => StreamBotPost::query()->latest('id')->first()->forceFill([
        'posted_at' => now()->subMinutes($minutes), 'next_due_at' => now()->subMinutes($minutes)->addMinutes(20),
    ])->save();
    // Only the interval of the last post is over; it still went out just now.
    $due = fn () => StreamBotPost::query()->latest('id')->first()->forceFill(['next_due_at' => now()])->save();

    try {
        // 1. First post: nothing holds it back.
        expect($bot->run(CarbonImmutable::now()))->toStartWith('posted ');

        // 2. Twenty minutes later nobody wrote: the bot's own message and a
        //    message under ANOTHER stream do not count.
        $age(20);
        $say($human, '30311:'.$human->pubkey.':another-stream', 'gm elsewhere');
        expect($bot->run(CarbonImmutable::now()))->toBe('no post: no human wrote since the last post, and 45 min have not passed');

        // 3. A human writes in this chat: the bot may post again.
        $say($human, $address, 'gm stream', 2);
        expect($bot->run(CarbonImmutable::now()))->toStartWith('posted ');

        // 4. That human message is older than the new post: it does not count twice.
        $due();
        expect($bot->run(CarbonImmutable::now()))->toStartWith('no post: no human wrote');

        // 5. The interval still holds right after a post, humans or not.
        $age(10);
        $say($human, $address, 'still here');
        expect($bot->run(CarbonImmutable::now()))->toStartWith('no post: the next post is due');

        $posted = $relay->events('-k 1311 -a '.$botKey->pubkey);
        $logged = StreamBotPost::query()->orderBy('id')->pluck('event_id')->all();

        expect($posted)->toHaveCount(2)
            ->and(collect($posted)->pluck('id')->sort()->values()->all())->toBe(collect($logged)->sort()->values()->all());

        foreach ($posted as $event) {
            expect($event->kind)->toBe(1311)
                ->and($event->tags)->toBe([['a', $address, $stack->relayUrl, 'root']])
                ->and($event->content)->not->toContain('#')
                ->and($event->content)->toMatch('~https?://\S+~');
        }

        // The relay files them under the stream's coordinate, as a chat client asks for them.
        expect($relay->events('-k 1311 -t a='.escapeshellarg($address).' -a '.$botKey->pubkey))->toHaveCount(2);
    } finally {
        File::deleteDirectory($dir);
    }
});
