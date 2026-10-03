<?php

use App\Enums\TournamentFormat;
use App\Models\BotPost;
use App\Models\StreamBotPost;
use App\Models\Tournament;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use App\Support\StreamBot\ChampionChat;
use App\Support\StreamBot\StreamBotChat;
use App\Support\StreamBot\StreamBotCopy;
use App\Support\StreamBot\StreamBotPublisher;
use App\Support\StreamBot\StreamCoordinates;
use App\Support\Tournaments\TournamentChampion;
use App\Support\TwentyOne\PublishResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\Support\TestSigner;

/*
 * The GG in the stream chat (user, 2026-10-03): the moment a tournament is
 * decided the bot says so in the 24/7 stream's chat, once, within a minute
 * or two: "🏆 GG! <mention> wins <tournament> 🎉 <url>". It skips the
 * rotation's interval and its never-twice rule, but not the switch, the key,
 * a live stream or the quiet hours. The relays and the chat are stood in for.
 */

beforeEach(function () {
    $this->dir = storage_path('framework/testing/gg-'.bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($this->dir.'/hls');
    $this->streamKey = new TestSigner;
    $this->botKey = new TestSigner;

    config([
        'esports.league.nsec' => (new TestSigner)->secret,
        'esports.stream_bot.enabled' => true,
        'esports.stream_bot.nsec' => $this->botKey->secret,
        'esports.stream_bot.quiet_hours' => null,
        'esports.stream_bot.chat_relays' => ['wss://chat.test'],
        'twentyone.nostr.npub' => NostrKeys::hexToNpub($this->streamKey->pubkey),
        'twentyone.nostr.nsec' => null,
        'twentyone.stream.relays' => ['wss://one.test', 'wss://two.test'],
        'twentyone.stream.session_file' => $this->dir.'/session.json',
        'twentyone.stream.hls_dir' => $this->dir.'/hls',
        'twentyone.stream.public_url' => 'https://esports.test/live/stream.m3u8',
    ]);

    $this->published = [];
    $this->accepts = true;
    $this->app->instance(StreamBotPublisher::class, new class($this) extends StreamBotPublisher
    {
        public function __construct(private $test) {}

        public function publish(array $event, array $relays): array
        {
            $this->test->published[] = ['event' => SignedEvent::fromInput($event), 'relays' => $relays];

            return collect($relays)->mapWithKeys(fn (string $relay): array => [$relay => new PublishResult($relay, $this->test->accepts, $this->test->accepts ? '' : 'blocked: no')])->all();
        }
    });
    // Nobody wrote in the chat: the rotation's never-twice rule would hold its own next post back.
    $this->app->instance(StreamBotChat::class, new class extends StreamBotChat
    {
        public function __construct() {}

        public function humansSince(StreamCoordinates $stream, string $botPubkey, int $since): array
        {
            return [];
        }
    });

    // Sat 2026-09-26 12:00 UTC, 14:00 in Berlin.
    $this->travelTo(Carbon::parse('2026-09-26 12:00:00', 'UTC'));
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

function ggStreamIsLive(): void
{
    file_put_contents(test()->dir.'/session.json', json_encode(['starts' => now()->getTimestamp() - 3600, 'lastLiveAt' => now()->getTimestamp()]));
    touch(test()->dir.'/hls/stream.m3u8', now()->getTimestamp());
}

/** A chess knockout of four, just played out by the better seeds: "Player 1" wins. */
function ggTournament(): Tournament
{
    $tournament = runningChess(TournamentFormat::SingleElimination, 4);
    playOutAsDirector($tournament);
    $tournament->forceFill(['name' => 'Friday #Blitz Cup', 'published_at' => now()])->save();

    return $tournament->refresh();
}

function ggTick(): string
{
    return app(ChampionChat::class)->run(CarbonImmutable::now());
}

test('a decided tournament gets one GG in the stream chat right away, past the rotation\'s interval and never-twice rule', function () {
    ggStreamIsLive();
    // The rotation posted a minute ago: its next post is not due, and no human wrote since.
    StreamBotPost::query()->create(['builder' => 'stats', 'fact_key' => 'stats', 'event_id' => str_repeat('e', 64), 'content' => 'x https://esports.test',
        'relays_accepted' => 2, 'relays_total' => 2, 'posted_at' => now()->subMinute(), 'next_due_at' => now()->addMinutes(19)]);
    $tournament = ggTournament();
    $winner = app(TournamentChampion::class)->of($tournament)->user;

    $this->travel(1)->minutes();
    ggStreamIsLive();
    $log = ggTick();
    $this->travel(1)->minutes();
    ggStreamIsLive();
    $again = ggTick();

    expect($this->published)->toHaveCount(1);
    $event = $this->published[0]['event'];

    expect($log)->toStartWith('tournament '.$tournament->id.': GG posted')
        ->and($again)->toBe('no GG: no newly decided tournament without its GG')
        ->and($event->kind)->toBe(1311)
        ->and($event->pubkey)->toBe($this->botKey->pubkey)
        ->and($event->hasValidSignature())->toBeTrue()
        ->and($event->content)->toBe('🏆 GG! nostr:'.NostrKeys::hexToNpub($winner->pubkey).' wins Friday Blitz Cup 🎉 '.route('tournaments.show', $tournament))
        ->and($event->tags)->toBe([['a', '30311:'.$this->streamKey->pubkey.':twentyone-247', 'wss://one.test', 'root'], ['p', $winner->pubkey]])
        ->and(StreamBotCopy::violations($event->content, $event->tags))->toBe([])
        ->and($this->published[0]['relays'])->toBe(['wss://one.test', 'wss://two.test'])
        ->and(BotPost::query()->where(['subject_type' => ChampionChat::SUBJECT, 'subject_id' => $tournament->id, 'kind' => 1311])->sole()->published_at)->not->toBeNull()
        // In the chat log too, so the rotation does not name the same winner again within its repeat hours.
        ->and(StreamBotPost::query()->where('fact_key', 'tournament-winner:'.$tournament->id)->sole()->event_id)->toBe($event->id);
});

test('a GG no relay took is sent again later as the same event', function () {
    ggStreamIsLive();
    $tournament = ggTournament();
    $this->accepts = false;

    expect(ggTick())->toContain('not accepted');

    $this->accepts = true;
    $this->travel(1)->minutes();
    ggStreamIsLive();
    // Within the retry time nobody takes it again.
    expect(ggTick())->toBe('no GG: no newly decided tournament without its GG');

    $this->travel(2)->minutes();
    ggStreamIsLive();

    expect(ggTick())->toContain('GG posted')
        ->and($this->published)->toHaveCount(2)
        ->and($this->published[1]['event']->id)->toBe($this->published[0]['event']->id)
        ->and(BotPost::query()->where('subject_type', ChampionChat::SUBJECT)->sole()->attempts)->toBe(2);
});

test('no GG in the quiet hours, off air, switched off, without a key, or for a tournament decided long ago', function () {
    ggStreamIsLive();
    ggTournament();

    config(['esports.stream_bot.quiet_hours' => '13-15']);
    expect(ggTick())->toBe('no GG: quiet hours');

    config(['esports.stream_bot.quiet_hours' => null, 'esports.stream_bot.enabled' => false]);
    expect(ggTick())->toBe('no GG: ESPORTS_STREAM_BOT_ENABLED is off');

    config(['esports.stream_bot.enabled' => true, 'esports.stream_bot.nsec' => null]);
    expect(ggTick())->toBe('no GG: ESPORTS_STREAM_BOT_NSEC is not set or not a valid secret key');

    config(['esports.stream_bot.nsec' => $this->botKey->secret]);
    $this->travel(31)->minutes();
    expect(ggTick())->toStartWith('no GG: the stream is not live');

    // Live again, but the finish is past the GG window: a late GG is no GG.
    ggStreamIsLive();
    expect(ggTick())->toBe('no GG: no newly decided tournament without its GG')
        ->and($this->published)->toBe([])
        ->and(BotPost::query()->count())->toBe(0);
});

test('the control: the same tournament inside the window outside the quiet hours gets its GG', function () {
    ggStreamIsLive();
    ggTournament();
    config(['esports.stream_bot.quiet_hours' => '15-17']);

    expect(ggTick())->toContain('GG posted')->and($this->published)->toHaveCount(1);
});
