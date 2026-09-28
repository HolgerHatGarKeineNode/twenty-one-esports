<?php

use App\Models\BotPost;
use App\Models\ChessGame;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use App\Support\StreamBot\PrideNotes;
use App\Support\StreamBot\StreamBotPublisher;
use App\Support\TwentyOne\PublishResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Support\TestSigner;

/*
 * The stream bot's pride notes: the latest win as a note with its slide,
 * the winner tagged and named, once a day in its slot, never again for
 * the same win, a failed send retried with the same event, nothing
 * outside a slot or with the flag off. The relays are stood in for.
 */

beforeEach(function () {
    $this->botKey = new TestSigner;
    $this->dir = storage_path('framework/testing/pride-'.bin2hex(random_bytes(4)));

    config([
        'esports.league.nsec' => (new TestSigner)->secret,
        'esports.stream_bot.enabled' => true,
        'esports.stream_bot.nsec' => $this->botKey->secret,
        'esports.stream_bot.pride_notes.image_dir' => $this->dir,
        'twentyone.stream.relays' => ['wss://one.test', 'wss://two.test'],
    ]);

    $this->published = [];
    $this->accepts = true;
    $this->app->instance(StreamBotPublisher::class, new class($this) extends StreamBotPublisher
    {
        public function __construct(private $test) {}

        public function publish(array $event, array $relays): array
        {
            $this->test->published[] = SignedEvent::fromInput($event);

            return collect($relays)->mapWithKeys(fn (string $relay): array => [$relay => new PublishResult($relay, $this->test->accepts, $this->test->accepts ? '' : 'blocked: no')])->all();
        }
    });

    $this->winner = User::factory()->create(['name' => 'Mx12art']);
    $this->loser = User::factory()->create(['name' => 'LightningInTheAlps']);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

test('the latest win goes out once in its slot, with the slide, the winner tagged and the loser only named', function () {
    // Sat 2026-09-26 17:30 UTC = 19:30 in Berlin: the win slot (19:00, 3 h).
    $this->travelTo(Carbon::parse('2026-09-26 17:30:00', 'UTC'));
    $game = ChessGame::factory()->finished('1-0')->create(['white_id' => $this->winner, 'black_id' => $this->loser, 'ended_at' => now()->subHour()]);

    $log = app(PrideNotes::class)->run(now()->toImmutable());
    $again = app(PrideNotes::class)->run(now()->addMinutes(5)->toImmutable());
    $note = $this->published[0];
    $imeta = collect($note->tags)->firstWhere(0, 'imeta');
    $url = substr($imeta[1], 4);
    $hash = substr($imeta[4], 2);

    expect($log)->toContain('win: posted id='.$note->id.' to 2/2 relays')
        ->and($again)->not->toContain('win:')
        ->and($this->published)->toHaveCount(1)
        ->and($note->pubkey)->toBe($this->botKey->pubkey)
        ->and($note->content)->toContain('nostr:'.NostrKeys::hexToNpub($this->winner->pubkey), 'LightningInTheAlps', route('games.show', $game), "\n\n".$url)
        ->and($note->content)->not->toContain('nostr:'.NostrKeys::hexToNpub($this->loser->pubkey), '#')
        ->and(collect($note->tags)->where(0, 'p')->pluck(1)->all())->toBe([$this->winner->pubkey])
        ->and($url)->toBe(route('stream.pride-image', ['hash' => $hash]))
        ->and(hash_file('sha256', PrideNotes::imagePath($hash)))->toBe($hash)
        ->and(BotPost::query()->where(['subject_type' => PrideNotes::SUBJECT, 'subject_id' => 1, 'slot' => '2026-09-26'])->value('published_at'))->not->toBeNull();

    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');

    // The next day, same slot, the same win: no news, no note.
    $this->travelTo(Carbon::parse('2026-09-27 17:30:00', 'UTC'));
    expect(app(PrideNotes::class)->run(now()->toImmutable()))->toContain('win: unchanged since its last note')
        ->and($this->published)->toHaveCount(1);
});

test('a note no relay took is sent again later as the same event', function () {
    $this->travelTo(Carbon::parse('2026-09-26 17:30:00', 'UTC'));
    ChessGame::factory()->finished('0-1')->create(['white_id' => $this->loser, 'black_id' => $this->winner, 'ended_at' => now()->subHour()]);
    $this->accepts = false;

    expect(app(PrideNotes::class)->run(now()->toImmutable()))->toContain('win: not accepted, retried later');

    $this->accepts = true;
    $this->travel(11)->minutes();

    expect(app(PrideNotes::class)->run(now()->toImmutable()))->toContain('win: posted')
        ->and(array_unique(array_map(fn (SignedEvent $event): string => $event->id, $this->published)))->toHaveCount(1);
});

test('outside every slot, or with the flag off, nothing is posted; a dry run posts nothing either', function () {
    ChessGame::factory()->finished('1-0')->create(['white_id' => $this->winner, 'black_id' => $this->loser, 'ended_at' => now()]);

    // 09:00 UTC: 11:00 in Berlin, 05:00 in New York, before every slot.
    $this->travelTo(Carbon::parse('2026-09-26 09:00:00', 'UTC'));
    expect(app(PrideNotes::class)->run(now()->toImmutable()))->toBe('no pride notes: no slot open');

    $this->travelTo(Carbon::parse('2026-09-26 17:30:00', 'UTC'));
    Artisan::call('twentyone:stream-bot:pride', ['--dry-run' => true]);
    expect(Artisan::output())->toContain('--- win, tags: 1', 'open slots now: win, signups', 'Nothing was signed, sent or stored');

    config(['esports.stream_bot.pride_notes.enabled' => false]);
    expect(app(PrideNotes::class)->run(now()->toImmutable()))->toContain('pride_notes.enabled is off')
        ->and($this->published)->toBe([])
        ->and(BotPost::query()->count())->toBe(0);
});

test('the biggest pot\'s prizes go out in the US evening slot with the tournament\'s own link and no player tagged', function () {
    // Sat 2026-09-26 23:30 UTC = 19:30 in New York: the prizes slot (19:00, 3 h).
    $this->travelTo(Carbon::parse('2026-09-26 23:30:00', 'UTC'));
    $pot = openTournament(['name' => 'Sats Cup', 'starts_at' => now()->addDays(3), 'signup_closes_at' => now()->addDays(2)]);
    $pot->forceFill(['pot_source' => Tournament::POT_WALLET, 'prize_target_sats' => 21000, 'prize_split' => [50, 30, 20]])->save();

    $log = app(PrideNotes::class)->run(now()->toImmutable());

    expect($log)->toContain('prizes: posted')
        ->and($this->published)->toHaveCount(1)
        ->and($this->published[0]->content)->toContain('21,000', 'Sats Cup', '10,395 / 6,237 / 4,158 sats', route('tournaments.show', $pot))
        ->and(collect($this->published[0]->tags)->where(0, 'p')->all())->toBe([]);
});
