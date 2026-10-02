<?php

/*
 * Variety and pacing of the stream bot's profile notes (ProfileNotes): every
 * note type rotates its wordings so the same wording never follows itself,
 * each type keeps its cooldown and daily cap (Berlin day), a burst of first
 * places becomes one summary note naming everyone who held the top, and
 * TMNF's first places stay off the profile (they go to the stream chat).
 */

use App\Enums\StackerRunStatus;
use App\Games\Blockfill;
use App\Games\TrackmaniaNationsForever;
use App\Jobs\VerifyStackerRun;
use App\Models\BotPost;
use App\Models\StackerRun;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\Verifier;
use App\Support\StreamBot\BlockfillNotes;
use App\Support\StreamBot\StreamBotCopy;
use App\Support\StreamBot\StreamBotPublisher;
use App\Support\StreamBot\TmnfNotes;
use App\Support\Tournaments\TournamentPublisher;
use App\Support\TwentyOne\PublishResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\Support\BlockfillOn;
use Tests\Support\FakeStackerVerifier;
use Tests\Support\TestSigner;

beforeEach(function () {
    Cache::flush();
    config([
        'esports.league.nsec' => (new TestSigner)->secret,
        'esports.stream_bot.enabled' => true,
        'esports.stream_bot.nsec' => (new TestSigner)->secret,
        'twentyone.stream.relays' => ['wss://one.test'],
    ]);

    $this->sent = [];
    $this->app->instance(StreamBotPublisher::class, new class($this) extends StreamBotPublisher
    {
        public function __construct(private $test) {}

        public function publish(array $event, array $relays): array
        {
            $this->test->sent[] = SignedEvent::fromInput($event);

            return array_map(fn (string $relay): PublishResult => new PublishResult($relay, true, ''), $relays);
        }
    });

    // Sat 2026-09-26 06:00 UTC, 08:00 in Berlin.
    $this->travelTo(CarbonImmutable::parse('2026-09-26 06:00:00'));
    // The admins approved the weeks around now (the approval itself: LeagueWeekApprovalTest).
    leagueWeeksApproved(Blockfill::SLUG);
    leagueWeeksApproved(TrackmaniaNationsForever::SLUG);
});

/**
 * The notes of one note type sent so far, in order, found by the bot_posts rows that hold them.
 *
 * @return list<SignedEvent>
 */
function varietyNotes(array $sent, string $subject, ?string $slotLike = null): array
{
    $ids = BotPost::query()->where('subject_type', $subject)->when($slotLike !== null, fn ($query) => $query->where('slot', 'like', $slotLike))
        ->whereNotNull('event_id')->pluck('event_id')->all();

    return array_values(array_filter($sent, fn (SignedEvent $event): bool => $event->kind === 1 && in_array($event->id, $ids, true)));
}

/** A note's first line without names, links and numbers: what is left is its wording. */
function varietyWording(SignedEvent $note): string
{
    $line = explode("\n", $note->content)[0];

    return trim((string) preg_replace(['~nostr:\S+~', '~https?://\S+~', '~[0-9][0-9:.,]*~', '~Rotation Cup \S+~'], '', $line));
}

function varietyTick(string $command, string $at): void
{
    test()->travelTo(CarbonImmutable::parse($at));
    Artisan::call($command);
}

test('tournament notes rotate their wording: no two notes in a row read alike, and the rotation is deterministic', function () {
    foreach (range(1, 4) as $i) {
        openTournament(['name' => 'Rotation Cup '.$i]);
    }

    foreach (['06:00', '07:01', '08:02', '09:03'] as $time) {
        varietyTick('twentyone:stream-bot:tournaments', '2026-09-26 '.$time.':00');
    }

    $notes = varietyNotes($this->sent, BotPost::SUBJECT_TOURNAMENT);
    $variants = BotPost::query()->where('subject_type', BotPost::SUBJECT_TOURNAMENT)->orderBy('id')->pluck('variant')->all();
    $count = StreamBotCopy::variants('tournament_note_open');

    expect($notes)->toHaveCount(4)
        ->and($count)->toBeGreaterThanOrEqual(3)
        ->and($variants)->toBe([0, 1, 2, 3 % $count]);

    foreach (array_slice($notes, 1) as $index => $note) {
        expect(varietyWording($note))->not->toBe(varietyWording($notes[$index]));

        // Every wording keeps the copy rules and still names the start (a moved start is found by it).
        [$text] = explode("\n\nnostr:", $note->content, 2);
        expect(StreamBotCopy::violations($text, $note->tags))->toBe([])
            ->and($text)->toContain('starts');
    }
});

test('tournament notes keep their cooldown: a second published tournament waits an hour after the last note', function () {
    openTournament(['name' => 'First Cup']);
    openTournament(['name' => 'Second Cup']);

    varietyTick('twentyone:stream-bot:tournaments', '2026-09-26 06:00:00');
    varietyTick('twentyone:stream-bot:tournaments', '2026-09-26 06:05:00');
    varietyTick('twentyone:stream-bot:tournaments', '2026-09-26 06:30:00');

    expect(varietyNotes($this->sent, BotPost::SUBJECT_TOURNAMENT))->toHaveCount(1);

    varietyTick('twentyone:stream-bot:tournaments', '2026-09-26 07:01:00');

    expect(varietyNotes($this->sent, BotPost::SUBJECT_TOURNAMENT))->toHaveCount(2);
});

test('tournament notes stop at the daily cap of the Berlin day; the rest go out the next day', function () {
    foreach (range(1, 8) as $i) {
        openTournament(['name' => 'Capped Cup '.$i]);
    }

    // Hourly from 08:00 to 19:00 in Berlin.
    foreach (range(6, 17) as $hour) {
        varietyTick('twentyone:stream-bot:tournaments', sprintf('2026-09-26 %02d:00:00', $hour));
    }

    expect(varietyNotes($this->sent, BotPost::SUBJECT_TOURNAMENT))->toHaveCount(6);

    // 00:05 in Berlin: a new day.
    varietyTick('twentyone:stream-bot:tournaments', '2026-09-26 22:05:00');
    varietyTick('twentyone:stream-bot:tournaments', '2026-09-26 23:06:00');

    expect(varietyNotes($this->sent, BotPost::SUBJECT_TOURNAMENT))->toHaveCount(8);
});

test('free-places reminders keep their cooldown and rotate their wording', function () {
    config(['esports.stream_bot.free_places' => [
        'special_slots_hours' => [168, 72, 24, 3], 'cup_slots_hours' => [24, 3], 'stop_before_close_minutes' => 60, 'per_run' => 3, 'retry_minutes' => 10,
    ]]);

    // Published at 08:00 in Berlin, sign-up closes 25 h later: the 24 h slot opens at 09:00, for all three at once.
    foreach (range(1, 3) as $i) {
        $tournament = Tournament::factory()->create(['name' => 'Places Cup '.$i, 'created_by_id' => organizer()->id, 'capacity' => 8, 'starts_at' => now()->addHours(26)]);
        app(TournamentPublisher::class)->publish($tournament, $tournament->creator, CarbonImmutable::now()->addHours(25));
    }

    varietyTick('twentyone:stream-bot:free-places', '2026-09-26 07:00:00');
    varietyTick('twentyone:stream-bot:free-places', '2026-09-26 07:30:00');

    expect(varietyNotes($this->sent, BotPost::SUBJECT_FREE_PLACES))->toHaveCount(1);

    varietyTick('twentyone:stream-bot:free-places', '2026-09-26 08:01:00');
    $notes = varietyNotes($this->sent, BotPost::SUBJECT_FREE_PLACES);

    expect($notes)->toHaveCount(2)
        ->and(varietyWording($notes[1]))->not->toBe(varietyWording($notes[0]));
});

/** Blockfill on, week 41 open (Monday 2026-10-05), Wednesday noon of it. */
function varietyBlockfillWeek(): void
{
    app()->instance(Verifier::class, new FakeStackerVerifier);
    BlockfillOn::play();
    test()->travelTo(CarbonImmutable::parse('2026-10-05 08:00:00'));
    Artisan::call('blockfill:weeks');
    Artisan::call('twentyone:stream-bot:blockfill');
    test()->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00'));
}

/** A verified Blockfill run of the player called `$name`, handed in at `$at`. */
function varietyRun(string $name, int $ticks, string $at): StackerRun
{
    test()->travelTo(CarbonImmutable::parse($at));
    $user = User::query()->firstWhere('name', $name) ?? User::factory()->create(['name' => $name]);
    $now = CarbonImmutable::now();
    $run = StackerRun::factory()->for($user)->create([
        'status' => StackerRunStatus::Verifying, 'issued_at' => $now, 'started_at' => $now, 'submitted_at' => $now,
        'ticks' => $ticks, 'state_hash' => '00000000', 'replay' => 'AAAA',
    ]);
    VerifyStackerRun::dispatchSync($run->id);

    return $run->refresh();
}

function varietyNpub(string $name): string
{
    return 'nostr:'.NostrKeys::hexToNpub(User::query()->where('name', $name)->sole()->pubkey);
}

test('a burst of first places becomes one summary note that counts them and tags everyone who held the top', function () {
    varietyBlockfillWeek();
    varietyRun('Ada', 3100, '2026-10-07 12:00:00');
    varietyTick('twentyone:stream-bot:blockfill', '2026-10-07 12:00:00');

    // Three new first places within the hour after Ada's note: Ben, Cy, then Ben again.
    varietyRun('Ben', 3000, '2026-10-07 12:10:00');
    varietyTick('twentyone:stream-bot:blockfill', '2026-10-07 12:10:00');
    varietyRun('Cy', 2900, '2026-10-07 12:20:00');
    varietyTick('twentyone:stream-bot:blockfill', '2026-10-07 12:20:00');
    varietyRun('Ben', 2800, '2026-10-07 12:30:00');
    varietyTick('twentyone:stream-bot:blockfill', '2026-10-07 12:30:00');
    varietyTick('twentyone:stream-bot:blockfill', '2026-10-07 13:01:00');
    varietyTick('twentyone:stream-bot:blockfill', '2026-10-07 13:06:00');

    $notes = varietyNotes($this->sent, BlockfillNotes::SUBJECT, 'top-%');
    [$text] = explode("\n\nnostr:", $notes[1]->content ?? '', 2);
    $ben = User::query()->where('name', 'Ben')->sole()->pubkey;
    $cy = User::query()->where('name', 'Cy')->sole()->pubkey;

    expect($notes)->toHaveCount(2)
        ->and($text)->toMatch('/\b3 (new first places|times)\b/')
        ->and($text)->toContain(varietyNpub('Ben'))->toContain(varietyNpub('Cy'))->not->toContain(varietyNpub('Ada'))
        ->and($text)->toContain('0:46.666')
        ->and($notes[1]->tagsNamed('p'))->toBe([[$ben], [$cy]])
        ->and(StreamBotCopy::violations($text, $notes[1]->tags))->toBe([])
        ->and(varietyWording($notes[1]))->not->toBe(varietyWording($notes[0]));
});

test('first-place notes stop at their daily cap, however often the top changes', function () {
    varietyBlockfillWeek();

    // A new first place every 90 minutes, from 08:00 to 15:30 in Berlin.
    foreach (['06:00' => 3600, '07:30' => 3500, '09:00' => 3400, '10:30' => 3300, '12:00' => 3200, '13:30' => 3100] as $time => $ticks) {
        varietyRun('Ada', $ticks, '2026-10-07 '.$time.':00');
        varietyTick('twentyone:stream-bot:blockfill', '2026-10-07 '.$time.':00');
    }

    expect(varietyNotes($this->sent, BlockfillNotes::SUBJECT, 'top-%'))->toHaveCount(3);
});

test('TMNF first places stay off the profile; its week note goes out', function () {
    tmnfOn();
    config(['esports.tmnf.server.address' => 'tmnf.example.org:2350']);
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00'));
    tmnfPlayer('ada_drives', linked: true, attributes: ['name' => 'Ada']);
    tmnfFinish('ada_drives', 25_100);

    varietyTick('twentyone:stream-bot:tmnf', '2026-10-07 12:00:00');
    tmnfFinish('ada_drives', 24_900);
    varietyTick('twentyone:stream-bot:tmnf', '2026-10-07 13:05:00');
    varietyTick('twentyone:stream-bot:tmnf', '2026-10-07 14:10:00');

    expect(varietyNotes($this->sent, TmnfNotes::SUBJECT, 'open'))->toHaveCount(1)
        ->and(BotPost::query()->where('subject_type', TmnfNotes::SUBJECT)->where('slot', 'like', 'top-%')->exists())->toBeFalse();
});

test('the same wording never follows itself for a week note either: Blockfill week 41, then week 42', function () {
    varietyBlockfillWeek();
    varietyTick('blockfill:weeks', '2026-10-12 08:00:00');
    varietyTick('twentyone:stream-bot:blockfill', '2026-10-12 08:00:00');

    $opens = varietyNotes($this->sent, BlockfillNotes::SUBJECT, 'open');

    expect($opens)->toHaveCount(2)
        ->and(varietyWording($opens[1]))->not->toBe(varietyWording($opens[0]))
        ->and(app(BlockfillWeeks::class)->current()->title())->toContain('42');
});
