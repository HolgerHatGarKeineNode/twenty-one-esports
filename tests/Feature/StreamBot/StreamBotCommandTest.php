<?php

use App\Models\StreamBotPost;
use App\Models\Tournament;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use App\Support\StreamBot\StreamBotPublisher;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\TestSigner;

/*
 * `twentyone:stream-bot` (P22): the dry run prints what the bot would post
 * and touches nothing; `--profile` makes the bot's kind 0 with `bot: true`.
 */

beforeEach(function () {
    $this->botKey = new TestSigner;
    config([
        'esports.stream_bot.nsec' => $this->botKey->secret,
        'twentyone.nostr.npub' => NostrKeys::hexToNpub((new TestSigner)->pubkey),
        'twentyone.stream.relays' => ['wss://one.test'],
        'twentyone.stream.session_file' => storage_path('framework/testing/stream-bot-none/session.json'),
    ]);
    $this->published = [];
    $this->app->instance(StreamBotPublisher::class, new class($this) extends StreamBotPublisher
    {
        public function __construct(private $test) {}

        public function publish(array $event, array $relays): array
        {
            $this->test->published[] = $event;

            return [];
        }
    });
});

test('the dry run prints the verdict and the next messages, and signs, sends and stores nothing, flag off or on', function (bool $enabled) {
    config(['esports.stream_bot.enabled' => $enabled]);
    Tournament::factory()->signup()->create(['name' => 'Friday Blitz Cup', 'signup_closes_at' => now()->addDay()]);

    $exit = Artisan::call('twentyone:stream-bot', ['--dry-run' => true, '--count' => 5]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Now: would not post, '.($enabled ? 'the stream is not live' : 'ESPORTS_STREAM_BOT_ENABLED is off'))
        ->and($output)->toContain('Bot key: '.NostrKeys::hexToNpub($this->botKey->pubkey))
        ->and(substr_count($output, "\n--- "))->toBe(5)
        ->and($output)->toContain('Dry run: 5 message(s), nothing was signed, sent or stored.')
        ->and($output)->not->toContain('#')
        ->and($this->published)->toBe([])
        ->and(StreamBotPost::query()->count())->toBe(0);
})->with(['flag off' => false, 'flag on' => true]);

test('the scheduled run with the flag off does nothing', function () {
    config(['esports.stream_bot.enabled' => false]);

    $this->artisan('twentyone:stream-bot')->expectsOutput('no post: ESPORTS_STREAM_BOT_ENABLED is off')->assertExitCode(0);

    expect($this->published)->toBe([]);
});

test('the bot profile is a kind 0 with bot: true, signed by the bot key', function () {
    Artisan::call('twentyone:stream-bot', ['--profile' => true]);

    $profile = SignedEvent::fromInput($this->published[0]);
    $content = json_decode($profile->content, true);

    expect($profile->kind)->toBe(0)
        ->and($profile->pubkey)->toBe($this->botKey->pubkey)
        ->and($profile->hasValidSignature())->toBeTrue()
        ->and($content)->toMatchArray(['name' => 'TWENTY ONE Bot', 'bot' => true])
        ->and($content['picture'])->toStartWith('https://');
});

test('without a bot key there is no profile', function () {
    config(['esports.stream_bot.nsec' => null]);

    $this->artisan('twentyone:stream-bot', ['--profile' => true])
        ->expectsOutput('ESPORTS_STREAM_BOT_NSEC is not set or not a valid secret key.')
        ->assertExitCode(1);

    expect($this->published)->toBe([]);
});
