<?php

use App\Enums\TournamentFormat;
use App\Models\BotPost;
use App\Models\Tournament;
use App\Support\Nostr\SignedEvent;
use App\Support\StreamBot\StreamBotPublisher;
use App\Support\StreamBot\TournamentNotes;
use App\Support\Tournaments\TournamentPublisher;
use App\Support\TwentyOne\PublishResult;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestSigner;

/*
 * The stream bot's free-places reminders (P49): a kind-1 note per
 * tournament and slot while sign-up is open and places are left, special
 * tournaments at 7 d, 3 d, 24 h, 3 h before the close, casual cups at 24 h
 * and 3 h, nothing within an hour of the close, a passed slot skipped
 * rather than posted late, never twice (not across runs, not with a rival
 * run), a few per run. The relays are stood in for.
 */

beforeEach(function () {
    $this->league = new TestSigner;
    $this->botKey = new TestSigner;

    config([
        'esports.league.nsec' => $this->league->secret,
        'esports.stream_bot.enabled' => true,
        'esports.stream_bot.nsec' => $this->botKey->secret,
        'esports.stream_bot.free_places' => [
            'special_slots_hours' => [168, 72, 24, 3],
            'cup_slots_hours' => [24, 3],
            'stop_before_close_minutes' => 60,
            'per_run' => 3,
            'retry_minutes' => 10,
        ],
        // The pacing of the profile notes (ProfileVarietyTest) is not what this file tests.
        'esports.stream_bot.profile_limits' => [],
        'esports.stream_bot.profile_gap_minutes' => 0,
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

/** A special tournament published now whose sign-up closes at `$closes` and which starts an hour later. */
function placesTournament(CarbonImmutable $closes, array $attributes = []): Tournament
{
    $tournament = Tournament::factory()->create(['created_by_id' => organizer()->id, 'capacity' => 8, 'starts_at' => $closes->addHour(), ...$attributes]);

    return app(TournamentPublisher::class)->publish($tournament, $tournament->creator, $closes);
}

/** A casual cup of `$series` published now, sign-up closing (and the cup starting) at `$closes`. */
function placesCup(string $series, CarbonImmutable $closes, int $capacity = 4): Tournament
{
    $cup = Tournament::factory()->create([
        'name' => 'Chess Casual Cup #1', 'format' => TournamentFormat::DoubleElimination, 'capacity' => $capacity, 'starts_at' => $closes,
        'created_by_id' => null, 'cup_series' => $series, 'cup_number' => 1, 'cup_open_series' => $series,
    ]);

    return app(TournamentPublisher::class)->openSignup($cup, $closes);
}

function signUpPlayers(Tournament $tournament, int $count): void
{
    for ($index = 0; $index < $count; $index++) {
        [$player, $signer] = keyedPlayer();
        soloSignup($tournament->refresh(), $player, $signer);
    }
}

function runFreePlaces(): string
{
    Artisan::call('twentyone:stream-bot:free-places');

    return trim(Artisan::output());
}

/** @return list<string> "<tournament id>:<slot>" of every delivered free-places note, oldest first */
function postedSlots(): array
{
    return BotPost::query()->where('subject_type', BotPost::SUBJECT_FREE_PLACES)->whereNotNull('published_at')
        ->orderBy('published_at')->orderBy('id')->get()->map(fn (BotPost $post): string => $post->subject_id.':'.$post->slot)->all();
}

test('a special tournament is reminded at 7 d, 3 d, 24 h and 3 h before its close, each slot once', function () {
    $closes = CarbonImmutable::now()->addDays(8);
    $tournament = placesTournament($closes);
    $id = $tournament->id;

    // Not due yet: more than 7 days to go.
    $this->travelTo($closes->subHours(168)->subMinute());
    expect(runFreePlaces())->toStartWith('no free-places notes');

    foreach ([168, 72, 24, 3] as $hours) {
        $this->travelTo($closes->subHours($hours));
        runFreePlaces();
        // Five and ten minutes later the slot is still due but delivered: nothing more.
        $this->travel(5)->minutes();
        runFreePlaces();
        $this->travel(5)->minutes();
        runFreePlaces();
    }

    expect(postedSlots())->toBe(["{$id}:168h", "{$id}:72h", "{$id}:24h", "{$id}:3h"])
        ->and($this->published)->toHaveCount(4)
        ->and(collect($this->published)->every(fn (SignedEvent $event): bool => $event->kind === 1 && $event->pubkey === $this->botKey->pubkey && $event->hasValidSignature()))->toBeTrue();
});

test('nothing is posted within an hour of the close, even for a slot never posted', function () {
    config(['esports.stream_bot.free_places.special_slots_hours' => [3]]);
    $closes = CarbonImmutable::now()->addDays(2);
    $late = placesTournament($closes);
    // Its 3 h slot begins a minute after the first one's stop.
    $other = placesTournament($closes->addHours(2)->addMinute());

    // The scheduler was down through the first one's whole 3 h window.
    $this->travelTo($closes->subMinutes(60));
    expect(runFreePlaces())->toStartWith('no free-places notes');

    $this->travelTo($closes->subMinutes(59));
    runFreePlaces();
    $this->travelTo($closes->subMinutes(1));
    runFreePlaces();

    expect(postedSlots())->toBe(["{$other->id}:3h"])
        ->and(BotPost::query()->where('subject_id', $late->id)->exists())->toBeFalse();
});

test('a full tournament gets no reminder, one with a place left does', function () {
    $closes = CarbonImmutable::now()->addDays(2);
    $full = placesTournament($closes, ['capacity' => 2, 'name' => 'Full Cup']);
    $open = placesTournament($closes, ['capacity' => 3, 'name' => 'Open Cup']);
    signUpPlayers($full, 2);
    signUpPlayers($open, 2);

    $this->travelTo($closes->subHours(24));
    runFreePlaces();

    expect(postedSlots())->toBe(["{$open->id}:24h"])
        ->and($this->published[0]->content)->toStartWith('🪑 1 of 3 places left: Open Cup');
});

test('casual cups get only the 24 h and 3 h slots, with their current capacity and start on their region\'s clock', function () {
    $closes = CarbonImmutable::parse('2026-10-04 00:00:00', 'UTC'); // Sat 3 Oct, 8:00 PM EDT
    $cup = placesCup('chess-us', $closes);
    $cup->forceFill(['capacity' => 8])->save(); // It grew.
    signUpPlayers($cup, 2);

    foreach ([168, 72] as $hours) {
        $this->travelTo($closes->subHours($hours));
        runFreePlaces();
    }

    expect($this->published)->toBe([]);

    foreach ([24, 3] as $hours) {
        $this->travelTo($closes->subHours($hours));
        runFreePlaces();
    }

    expect(postedSlots())->toBe(["{$cup->id}:24h", "{$cup->id}:3h"])
        ->and($this->published[0]->content)->toStartWith("🪑 6 of 8 places left: Chess Casual Cup 1\n🎮 Chess Blitz 5+3 · starts Sat, 3 Oct 2026, 8:00 PM EDT\n⏳ Sign-up closes in 1 day\n");
});

test('a slot whose window passed is skipped, not posted late; only the latest due slot goes out', function () {
    $closes = CarbonImmutable::now()->addDays(8);
    $tournament = placesTournament($closes);

    // The scheduler was down from before the 7 d slot until 2 days before the close.
    $this->travelTo($closes->subDays(2));
    runFreePlaces();
    runFreePlaces();
    $this->travelTo($closes->subHours(20));
    runFreePlaces();

    expect(postedSlots())->toBe(["{$tournament->id}:72h", "{$tournament->id}:24h"])
        ->and(BotPost::query()->where('slot', '168h')->exists())->toBeFalse();
});

test('a slot whose moment lies before the tournament was published is left to the tournament\'s own note', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-26 12:00:00', 'UTC'));
    $tournament = placesTournament(CarbonImmutable::now()->addDays(5));

    // Published 5 days before its close: the 7 d slot's window is still open, but it began before publishing.
    runFreePlaces();
    expect($this->published)->toBe([]);

    $this->travelTo($tournament->signup_closes_at->toImmutable()->subHours(72));
    runFreePlaces();
    expect(postedSlots())->toBe(["{$tournament->id}:72h"]);
});

test('at most per_run notes per run across all tournaments, the soonest close first', function () {
    $tournaments = collect([5, 3, 4, 1, 2])->map(fn (int $hours): Tournament => placesTournament(CarbonImmutable::now()->addHours(24 + $hours)));

    $this->travelTo(CarbonImmutable::now()->addHours(7));
    runFreePlaces();
    $soonest = $tournaments->sortBy('signup_closes_at')->pluck('id');
    expect(postedSlots())->toBe($soonest->take(3)->map(fn (int $id): string => "{$id}:24h")->all());

    runFreePlaces();
    expect(postedSlots())->toBe($soonest->map(fn (int $id): string => "{$id}:24h")->all())
        ->and($this->published)->toHaveCount(5);
});

test('a rival run that claimed the slot first keeps it; a failed send is retried with the same event while the slot is due', function () {
    $closes = CarbonImmutable::now()->addDays(2);
    $tournament = placesTournament($closes);
    $this->travelTo($closes->subHours(24));
    $rivalRan = false;

    // The rival claims the row after this run found the slot free and before its own claim.
    DB::listen(function (QueryExecuted $query) use (&$rivalRan, $tournament): void {
        if (! $rivalRan && str_starts_with($query->sql, 'select exists') && str_contains($query->sql, 'bot_posts')) {
            $rivalRan = true;
            BotPost::query()->insert(['subject_type' => BotPost::SUBJECT_FREE_PLACES, 'subject_id' => $tournament->id, 'kind' => 1, 'slot' => '24h', 'attempted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    });

    expect(runFreePlaces())->toContain('taken by another run')
        ->and($rivalRan)->toBeTrue()
        ->and($this->published)->toBe([]);

    // The rival crashed: its claim expires, this run takes over, but no relay accepts.
    $this->travel(11)->minutes();
    $this->accepts = false;
    runFreePlaces();
    runFreePlaces();
    expect($this->published)->toHaveCount(1);

    $this->travel(11)->minutes();
    $this->accepts = true;
    runFreePlaces();
    runFreePlaces();

    expect($this->published)->toHaveCount(2)
        ->and($this->published[1]->id)->toBe($this->published[0]->id)
        ->and(postedSlots())->toBe(["{$tournament->id}:24h"]);
});

test('an unsent note is not retried once its slot passed', function () {
    $closes = CarbonImmutable::now()->addDays(2);
    placesTournament($closes, ['name' => 'Unlucky Cup']);
    $this->travelTo($closes->subHours(3)->subMinutes(5));
    $this->accepts = false;
    runFreePlaces();
    expect($this->published)->toHaveCount(1);

    // The 24 h slot's window ended; the 3 h slot is new.
    $this->travelTo($closes->subHours(3));
    $this->accepts = true;
    runFreePlaces();
    $this->travel(11)->minutes();
    runFreePlaces();

    expect($this->published)->toHaveCount(2)
        ->and(BotPost::query()->where('slot', '24h')->sole()->published_at)->toBeNull()
        ->and(BotPost::query()->where('slot', '3h')->sole()->published_at)->not->toBeNull();
});

test('the note names the free places and carries the calendar event as nostr:naddr with a q tag, no hashtag or t tag', function () {
    config(['esports.relays' => ['wss://league.test']]);
    $closes = CarbonImmutable::parse('2026-10-03 18:00:00', 'UTC');
    $tournament = placesTournament($closes, ['name' => 'Friday #Blitz Cup']);
    signUpPlayers($tournament, 5);

    $this->travelTo($closes->subHours(72));
    runFreePlaces();

    $note = $this->published[0];
    $naddr = app(TournamentNotes::class)->naddr($tournament->refresh());

    expect($note->content)->toBe("🪑 3 of 8 places left: Friday Blitz Cup\n🎮 Chess Blitz 5+3 · starts Sat, 3 Oct 2026, 9:00 PM CEST\n⏳ Sign-up closes in 3 days\n👉 Grab a place: ".route('tournaments.show', $tournament)."\n\nnostr:".$naddr)
        ->and($note->content)->not->toContain('#')
        ->and($note->tags)->toBe([['q', '31923:'.$this->league->pubkey.':'.$tournament->slug, 'wss://league.test']]);
});

test('nothing is claimed, signed or sent without the flag, the key or a relay', function (array $settings) {
    $closes = CarbonImmutable::now()->addDays(2);
    placesTournament($closes);
    $this->travelTo($closes->subHours(24));
    config($settings);

    expect(runFreePlaces())->toStartWith('no notes:')
        ->and($this->published)->toBe([])
        ->and(BotPost::query()->count())->toBe(0);
})->with([
    'flag off' => [['esports.stream_bot.enabled' => false]],
    'no key' => [['esports.stream_bot.nsec' => null]],
    'no relay' => [['twentyone.stream.relays' => []]],
]);

test('the dry run prints the due notes and stores nothing', function () {
    $closes = CarbonImmutable::now()->addDays(2);
    $tournament = placesTournament($closes, ['name' => 'Dry Cup']);
    $this->travelTo($closes->subHours(24));

    Artisan::call('twentyone:stream-bot:free-places', ['--dry-run' => true]);

    expect(Artisan::output())->toContain("--- tournament {$tournament->id} slot 24h")
        ->toContain('🪑 8 of 8 places left: Dry Cup')
        ->toContain('Dry run: 1 note(s)')
        ->and($this->published)->toBe([])
        ->and(BotPost::query()->count())->toBe(0);
});
