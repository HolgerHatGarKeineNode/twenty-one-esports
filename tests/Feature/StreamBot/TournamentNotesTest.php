<?php

use App\Enums\TournamentStatus;
use App\Models\BotPost;
use App\Models\Tournament;
use App\Support\Nostr\SignedEvent;
use App\Support\StreamBot\StreamBotPublisher;
use App\Support\TwentyOne\PublishResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\TestSigner;

use function BitWasp\Bech32\convertBits;
use function BitWasp\Bech32\decodeRaw;

/*
 * The stream bot's notes on its own profile: one kind-1 note per published
 * tournament, the backlog a few per run, never twice (not across runs, not
 * with a rival run, not after a failed send), none for a tournament called
 * off first, nothing without the flag, the key or a relay. The relays are
 * stood in for.
 */

beforeEach(function () {
    $this->league = new TestSigner;
    $this->botKey = new TestSigner;

    config([
        'esports.league.nsec' => $this->league->secret,
        'esports.stream_bot.enabled' => true,
        'esports.stream_bot.nsec' => $this->botKey->secret,
        'esports.stream_bot.tournament_notes.per_run' => 3,
        'esports.stream_bot.tournament_notes.retry_minutes' => 10,
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

    // Sat 2026-09-26 12:00 UTC.
    $this->travelTo(Carbon::parse('2026-09-26 12:00:00', 'UTC'));
});

/**
 * NIP-19 naddr TLVs: d (0), relay (1), author (2, hex), kind (3, int).
 *
 * @return array{d: string, relay: string|null, author: string, kind: int}
 */
function decodeNoteNaddr(string $naddr): array
{
    // NIP-19 strings exceed the 90 characters BIP-173 allows, so without that length check.
    [$hrp, $data] = decodeRaw($naddr);
    expect($hrp)->toBe('naddr');
    $bytes = implode('', array_map('chr', convertBits($data, count($data), 5, 8, false)));
    $out = ['d' => '', 'relay' => null, 'author' => '', 'kind' => 0];

    for ($i = 0; $i < strlen($bytes);) {
        $type = ord($bytes[$i]);
        $length = ord($bytes[$i + 1]);
        $value = substr($bytes, $i + 2, $length);
        $i += 2 + $length;

        match ($type) {
            0 => $out['d'] = $value,
            1 => $out['relay'] = $value,
            2 => $out['author'] = bin2hex($value),
            3 => $out['kind'] = unpack('N', $value)[1],
        };
    }

    return $out;
}

/** @return list<int> the tournament ids the notes so far point to, in order */
function notedTournaments(array $events): array
{
    return array_map(function (SignedEvent $event): int {
        $slug = explode(':', $event->tag('q'), 3)[2];

        return Tournament::query()->where('slug', $slug)->value('id');
    }, $events);
}

test('every published tournament gets one note, the backlog a few per run, oldest first, never twice', function () {
    $tournaments = collect(range(1, 4))->map(function (int $i): Tournament {
        $this->travel(1)->minutes();

        return openTournament(['name' => 'Backlog Cup '.$i]);
    });
    Tournament::factory()->create(['name' => 'Still a draft']);

    Artisan::call('twentyone:stream-bot:tournaments');
    expect(notedTournaments($this->published))->toBe($tournaments->take(3)->pluck('id')->all());

    Artisan::call('twentyone:stream-bot:tournaments');
    Artisan::call('twentyone:stream-bot:tournaments');

    expect(notedTournaments($this->published))->toBe($tournaments->pluck('id')->all())
        ->and(collect($this->published)->every(fn (SignedEvent $event): bool => $event->kind === 1 && $event->pubkey === $this->botKey->pubkey && $event->hasValidSignature()))->toBeTrue()
        ->and(BotPost::query()->whereNotNull('published_at')->count())->toBe(4);
});

test('the note names the tournament and carries its calendar event as nostr:naddr with a q tag, no hashtag', function () {
    $tournament = openTournament(['name' => 'Friday #Blitz Cup']);
    $tournament->forceFill(['pot_source' => Tournament::POT_WALLET, 'prize_target_sats' => 21000])->save();
    config(['esports.relays' => ['wss://league.test', 'wss://other.test']]);

    Artisan::call('twentyone:stream-bot:tournaments');

    $note = $this->published[0];
    $address = '31923:'.$this->league->pubkey.':'.$tournament->slug;
    preg_match('/nostr:(naddr1[02-9ac-hj-np-z]+)$/', $note->content, $match);

    expect($note->content)->toStartWith("🏆 New tournament: Friday Blitz Cup\n🎮 Chess Blitz 5+3 · starts Sat, 3 Oct 2026, 9:00 PM CEST\n💰 21,000 sats in the pot\n👉 Sign up: ".route('tournaments.show', $tournament)."\n\nnostr:naddr1")
        ->and($note->content)->not->toContain('#')
        ->and(decodeNoteNaddr($match[1]))->toBe(['d' => $tournament->slug, 'relay' => 'wss://league.test', 'author' => $this->league->pubkey, 'kind' => 31923])
        ->and($note->tags)->toBe([['q', $address, 'wss://league.test']]);
});

test('a rival run that claimed the tournament first keeps it; a failed send is retried later with the same event', function () {
    $tournament = openTournament();
    $rivalRan = false;

    // The rival claims the row between this run's query and its claim.
    Tournament::retrieved(function (Tournament $retrieved) use (&$rivalRan): void {
        if (! $rivalRan && $retrieved->status === TournamentStatus::Signup) {
            $rivalRan = true;
            BotPost::query()->insert(['subject_type' => BotPost::SUBJECT_TOURNAMENT, 'subject_id' => $retrieved->id, 'kind' => 1, 'attempted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    });

    Artisan::call('twentyone:stream-bot:tournaments');
    expect($rivalRan)->toBeTrue()->and($this->published)->toBe([]);

    // The rival crashed: its claim expires, this run takes over, but no relay accepts.
    $this->travel(11)->minutes();
    $this->accepts = false;
    Artisan::call('twentyone:stream-bot:tournaments');
    Artisan::call('twentyone:stream-bot:tournaments');
    expect($this->published)->toHaveCount(1);

    $this->travel(11)->minutes();
    $this->accepts = true;
    Artisan::call('twentyone:stream-bot:tournaments');
    Artisan::call('twentyone:stream-bot:tournaments');

    expect($this->published)->toHaveCount(2)
        ->and($this->published[1]->id)->toBe($this->published[0]->id)
        ->and(BotPost::query()->sole()->published_at)->not->toBeNull()
        ->and(BotPost::query()->sole()->subject_id)->toBe($tournament->id);
});

test('a tournament called off before its note gets none, one called off after keeps its single note', function () {
    $noted = openTournament(['name' => 'Noted Cup']);
    Artisan::call('twentyone:stream-bot:tournaments');

    $calledOff = openTournament(['name' => 'Called Off Cup']);
    $calledOff->forceFill(['status' => TournamentStatus::Cancelled])->save();
    $noted->forceFill(['status' => TournamentStatus::Cancelled])->save();
    Artisan::call('twentyone:stream-bot:tournaments');

    expect(notedTournaments($this->published))->toBe([$noted->id]);
});

test('nothing is claimed, signed or sent without the flag, the key or a relay', function (array $settings) {
    openTournament();
    config($settings);

    Artisan::call('twentyone:stream-bot:tournaments');

    expect(Artisan::output())->toStartWith('no notes:')
        ->and($this->published)->toBe([])
        ->and(BotPost::query()->count())->toBe(0);
})->with([
    'flag off' => [['esports.stream_bot.enabled' => false]],
    'no key' => [['esports.stream_bot.nsec' => null]],
    'no relay' => [['twentyone.stream.relays' => []]],
]);
