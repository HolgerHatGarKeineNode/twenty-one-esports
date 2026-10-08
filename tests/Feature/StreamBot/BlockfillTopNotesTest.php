<?php

/*
| The stream bot's "new first place" note of a running Blockfill week
| (BlockfillNotes::SLOT_TOP): a verified run that takes the week's first
| place gets one kind-1 note, at most one per `top_minutes`, a burst in one
| note led by its latest, none in the week's last hour, none for runs without a verified
| time, none with a switch off, and never twice. The player is tagged as
| PrideNotes tags them (`nostr:npub1…` and `p`), a player without a Nostr key
| keeps the plain name.
*/

use App\Enums\StackerRunStatus;
use App\Games\Blockfill;
use App\Games\GameRegistry;
use App\Jobs\VerifyStackerRun;
use App\Models\BotPost;
use App\Models\StackerRun;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use App\Support\Stacker\BlockfillWeeks;
use App\Support\Stacker\Verifier;
use App\Support\StreamBot\BlockfillNotes;
use App\Support\StreamBot\StreamBotCopy;
use App\Support\StreamBot\StreamBotPublisher;
use App\Support\StreamBot\TournamentNotes;
use App\Support\TwentyOne\PublishResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\Support\BlockfillOn;
use Tests\Support\FakeStackerVerifier;
use Tests\Support\TestSigner;

beforeEach(function () {
    Cache::flush();
    $this->app->instance(Verifier::class, new FakeStackerVerifier);
    $this->relays = topNoteRelays();
    config(['esports.league.nsec' => (new TestSigner)->secret]);
    BlockfillOn::play();

    // Monday morning: week 41 (from Monday 2026-10-05 00:00 Berlin) opens with its 31923, and its week note goes out.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00:00'));
    // The admins approved the weeks around now (the approval itself: LeagueWeekApprovalTest).
    leagueWeeksApproved(Blockfill::SLUG);
    $this->artisan('blockfill:weeks')->assertSuccessful();
    $this->artisan('twentyone:stream-bot:blockfill')->assertSuccessful();
    $this->week = app(BlockfillWeeks::class)->current();

    // A Wednesday noon of that week.
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00'));
});

/** A stand-in for the stream relays that keeps every event sent; `$accept` false refuses them. */
function topNoteRelays(): object
{
    $relays = new class extends StreamBotPublisher
    {
        /** @var list<SignedEvent> */
        public array $sent = [];

        public bool $accept = true;

        public function __construct() {}

        public function publish(array $event, array $relays): array
        {
            $this->sent[] = SignedEvent::fromInput($event);

            return array_map(fn (string $relay): PublishResult => new PublishResult($relay, $this->accept, $this->accept ? '' : 'refused'), $relays);
        }
    };
    app()->instance(StreamBotPublisher::class, $relays);
    config([
        'esports.stream_bot.enabled' => true,
        'esports.stream_bot.nsec' => (new TestSigner)->secret,
        'twentyone.stream.relays' => ['wss://one.test'],
    ]);

    return $relays;
}

/** A run of the player named `$name` with `$ticks`, handed in now; verified through the real verdict job unless `$status` says otherwise. */
function topNoteRun(string $name, int $ticks, StackerRunStatus $status = StackerRunStatus::Verified): StackerRun
{
    $user = User::query()->firstWhere('name', $name) ?? User::factory()->create(['name' => $name]);
    $at = CarbonImmutable::now();
    $run = StackerRun::factory()->for($user)->create([
        'status' => $status === StackerRunStatus::Verified ? StackerRunStatus::Verifying : $status,
        'issued_at' => $at, 'started_at' => $at, 'submitted_at' => $at,
        'ticks' => $ticks, 'state_hash' => '00000000', 'replay' => 'AAAA',
    ]);

    if ($status === StackerRunStatus::Verified) {
        VerifyStackerRun::dispatchSync($run->id);
    }

    return $run->refresh();
}

/** How a note names the player called `$name`: their `nostr:npub1…`. */
function topNoteNpub(string $name): string
{
    return 'nostr:'.NostrKeys::hexToNpub(User::query()->where('name', $name)->sole()->pubkey);
}

/** One scheduler tick of the Blockfill notes at `$at`. */
function topNoteTick(string $at): void
{
    test()->travelTo(CarbonImmutable::parse($at));
    test()->artisan('twentyone:stream-bot:blockfill')->assertSuccessful();
}

/**
 * The "new first place" notes sent so far, found by their rows (their wording rotates, ProfileNotes).
 *
 * @return list<SignedEvent>
 */
function topNotes(object $relays): array
{
    $ids = BotPost::query()->where('subject_type', BlockfillNotes::SUBJECT)->where('slot', 'like', 'top-%')->whereNotNull('event_id')->pluck('event_id')->all();

    return array_values(array_filter($relays->sent, fn (SignedEvent $event): bool => in_array($event->id, $ids, true)));
}

test('a verified run that takes the week\'s first place gives exactly one note, however often the job runs', function () {
    topNoteRun('Ada', 3100);
    topNoteRun('Ben', 3200);

    topNoteTick('2026-10-07 12:00:00');
    topNoteTick('2026-10-07 12:05:00');
    topNoteTick('2026-10-07 13:30:00');

    $notes = topNotes($this->relays);

    expect($notes)->toHaveCount(1)
        ->and($notes[0]->content)->toContain(topNoteNpub('Ada'))->not->toContain(topNoteNpub('Ben'))
        // The week's first first place has nobody before it to beat.
        ->and($notes[0]->content)->not->toContain('faster')
        ->and(BotPost::query()->where('subject_type', BlockfillNotes::SUBJECT)->where('slot', 'like', 'top-%')->whereNotNull('published_at')->count())->toBe(1);
});

test('a second first place within the window waits until the window ends, and then one note leads with the latest and names the one between', function () {
    topNoteRun('Ada', 3100);
    topNoteTick('2026-10-07 12:00:00');

    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:10:00'));
    topNoteRun('Ben', 3000);
    topNoteTick('2026-10-07 12:10:00');

    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:20:00'));
    topNoteRun('Cy', 2900);
    topNoteTick('2026-10-07 12:20:00');
    topNoteTick('2026-10-07 12:59:00');

    expect(topNotes($this->relays))->toHaveCount(1);

    topNoteTick('2026-10-07 13:00:00');
    topNoteTick('2026-10-07 13:05:00');
    $notes = topNotes($this->relays);

    // The burst (Ben, then Cy) is one summary note (ProfileVarietyTest): Cy on top, Ben as the one who held it between.
    expect($notes)->toHaveCount(2)
        ->and(explode("\n", $notes[1]->content)[1])->toContain(topNoteNpub('Cy'))->not->toContain(topNoteNpub('Ben'))
        ->and(explode("\n", $notes[1]->content)[2])->toContain(topNoteNpub('Ben'))
        ->and($notes[1]->content)->not->toContain(topNoteNpub('Ada'))
        ->and(BotPost::query()->where('subject_type', BlockfillNotes::SUBJECT)->where('slot', 'like', 'top-%')->count())->toBe(2);
});

test('no note in the last hour before the week closes, while one an hour earlier went out', function () {
    // The week closes Monday 2026-10-12 00:00 Berlin, 2026-10-11 22:00 UTC.
    $this->travelTo(CarbonImmutable::parse('2026-10-11 20:50:00'));
    topNoteRun('Ada', 3100);
    topNoteTick('2026-10-11 20:50:00');

    expect(topNotes($this->relays))->toHaveCount(1);

    $this->travelTo(CarbonImmutable::parse('2026-10-11 21:01:00'));
    topNoteRun('Ben', 3000);

    foreach (['21:01', '21:30', '21:55', '21:59'] as $time) {
        topNoteTick('2026-10-11 '.$time.':00');
    }

    expect(topNotes($this->relays))->toHaveCount(1);
});

test('practice, waiting, rejected and unverified runs never make a note, however fast they claim to be', function () {
    topNoteRun('Ada', 3100);
    topNoteTick('2026-10-07 12:00:00');

    $this->travelTo(CarbonImmutable::parse('2026-10-07 13:05:00'));

    foreach ([StackerRunStatus::Practice, StackerRunStatus::Pending, StackerRunStatus::Rejected, StackerRunStatus::Verifying, StackerRunStatus::Issued] as $status) {
        topNoteRun('Ben', 2000, $status);
    }

    // The hourly job reads the week's runs again: nothing of them gets onto the board.
    $this->artisan('blockfill:weeks')->assertSuccessful();
    topNoteTick('2026-10-07 13:05:00');
    topNoteTick('2026-10-07 13:30:00');

    expect(topNotes($this->relays))->toHaveCount(1)
        ->and(topNotes($this->relays)[0]->content)->toContain(topNoteNpub('Ada'))->not->toContain(topNoteNpub('Ben'));
});

test('nothing with the bot switched off or Blockfill switched off; switched back on the first place goes out', function () {
    topNoteRun('Ada', 3100);

    config(['esports.stream_bot.enabled' => false]);
    topNoteTick('2026-10-07 12:00:00');

    config(['esports.stream_bot.enabled' => true, 'esports.blockfill.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);
    topNoteTick('2026-10-07 12:05:00');

    expect(topNotes($this->relays))->toBe([])
        ->and(BotPost::query()->where('slot', 'like', 'top-%')->exists())->toBeFalse();

    config(['esports.blockfill.enabled' => true]);
    app()->forgetInstance(GameRegistry::class);
    topNoteTick('2026-10-07 12:10:00');

    expect(topNotes($this->relays))->toHaveCount(1);
});

test('the note tags the player (nostr:npub1… and p), names the time, the gap to the first place before, the week and /blockfill; it passes the copy rules and carries the naddr and a q tag', function () {
    topNoteRun('Ada', 3100);
    topNoteTick('2026-10-07 12:00:00');

    $this->travelTo(CarbonImmutable::parse('2026-10-07 13:10:00'));
    topNoteRun('Ben', 3000);
    topNoteTick('2026-10-07 13:10:00');

    $week = $this->week->refresh();
    $note = topNotes($this->relays)[1];
    $naddr = app(TournamentNotes::class)->naddr($week);
    [$text] = explode("\n\nnostr:", $note->content, 2);

    // The week's second first-place note takes the type's second wording (ProfileNotes).
    expect($note->kind)->toBe(1)
        ->and($note->content)->toBe(
            'New name on top of Blockfill Week 41, 2026: '.topNoteNpub('Ben').".\n"
            ."⏱️ 0:50.000, 1.666 s faster than the first place before\n"
            .'👉 '.route('stacker.play')."\n\nnostr:".$naddr)
        ->and(route('stacker.play'))->toEndWith('/blockfill')
        ->and(StreamBotCopy::violations($text, $note->tags))->toBe([])
        ->and($note->tags)->toBe([['p', User::query()->where('name', 'Ben')->sole()->pubkey], ['q', $week->address(), app(TournamentNotes::class)->relayHint() ?? '']])
        ->and($note->content)->not->toContain(': Ben')
        ->and($note->tagsNamed('t'))->toBe([])
        // The wording only: a bech32 npub or naddr may spell "fee" or "face" by chance.
        ->and(mb_strtolower((string) preg_replace('~nostr:\S+~', '', $note->content)))->not->toContain('fee')->not->toContain('face')->not->toContain('#');
});

test('a failed send is retried with the same signed event after the retry time, and a delivered note is never sent again', function () {
    topNoteRun('Ada', 3100);
    $this->relays->accept = false;
    topNoteTick('2026-10-07 12:00:00');
    topNoteTick('2026-10-07 12:05:00');

    expect(topNotes($this->relays))->toHaveCount(1);

    $this->relays->accept = true;
    topNoteTick('2026-10-07 12:11:00');
    topNoteTick('2026-10-07 12:16:00');
    topNoteTick('2026-10-07 13:30:00');
    $notes = topNotes($this->relays);
    $post = BotPost::query()->where('subject_type', BlockfillNotes::SUBJECT)->where('slot', 'like', 'top-%')->sole();

    expect($notes)->toHaveCount(2)
        ->and($notes[1]->id)->toBe($notes[0]->id)
        ->and($post->attempts)->toBe(2)
        ->and($post->published_at)->not->toBeNull();
});

test('a rival run that holds the claim makes this run skip the note', function () {
    topNoteRun('Ada', 3100);
    $due = app(BlockfillNotes::class)->due(CarbonImmutable::now());

    expect($due)->toHaveCount(1)
        ->and($due[0]['week']->id)->toBe($this->week->id)
        ->and($due[0]['slot'])->toStartWith('top-');

    BotPost::query()->insert([
        'subject_type' => BlockfillNotes::SUBJECT, 'subject_id' => $this->week->id, 'kind' => TournamentNotes::KIND_NOTE, 'slot' => $due[0]['slot'],
        'attempted_at' => now(), 'attempts' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    topNoteTick('2026-10-07 12:00:00');

    expect(topNotes($this->relays))->toBe([]);
});

test('a first place that reached the board long before the window allowed a note is not posted late', function () {
    // Ada has been first since 09:00; the bot was off until noon.
    $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00:00'));
    topNoteRun('Ada', 3100);
    topNoteTick('2026-10-07 12:00:00');

    expect(topNotes($this->relays))->toBe([]);

    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:30:00'));
    topNoteRun('Ben', 3000);
    topNoteTick('2026-10-07 12:30:00');

    // Ada's old first place is no part of a burst either: the note is Ben's alone.
    expect(topNotes($this->relays))->toHaveCount(1)
        ->and(topNotes($this->relays)[0]->content)->toContain(topNoteNpub('Ben'))->not->toContain(topNoteNpub('Ada'));
});

test('a new week\'s own note goes first, and its first place waits the window after it', function () {
    // Monday 2026-10-12 00:10 Berlin: week 42 has just opened, its first run comes in.
    $this->travelTo(CarbonImmutable::parse('2026-10-11 22:10:00'));
    $this->artisan('blockfill:weeks')->assertSuccessful();
    topNoteRun('Ada', 3100);
    topNoteTick('2026-10-11 22:10:00');

    $opens = fn (): int => BotPost::query()->where(['subject_type' => BlockfillNotes::SUBJECT, 'slot' => BlockfillNotes::SLOT_OPEN])->whereNotNull('published_at')->count();

    expect($opens())->toBe(2)
        ->and(topNotes($this->relays))->toBe([]);

    topNoteTick('2026-10-11 22:15:00');

    expect(topNotes($this->relays))->toBe([]);

    topNoteTick('2026-10-11 23:10:00');

    expect(topNotes($this->relays))->toHaveCount(1)
        ->and(topNotes($this->relays)[0]->content)->toContain('Blockfill Week 42, 2026')->toContain(topNoteNpub('Ada'));
});

test('a player without a valid Nostr key is named plainly, with no p tag', function () {
    $run = topNoteRun('Ada', 3100);
    // A stand-in for an account without a usable key: the pubkey column cannot be empty.
    User::query()->whereKey($run->user_id)->update(['pubkey' => 'not-a-nostr-key']);
    topNoteTick('2026-10-07 12:00:00');

    $note = topNotes($this->relays)[0];
    [$text] = explode("\n\nnostr:", $note->content, 2);

    expect($text)->toStartWith('🥇 Ada leads Blockfill Week 41, 2026.')
        ->and($text)->not->toContain('nostr:')
        ->and($note->tagsNamed('p'))->toBe([])
        ->and(StreamBotCopy::violations($text, $note->tags))->toBe([]);
});
