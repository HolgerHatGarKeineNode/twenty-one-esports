<?php

use App\Enums\TournamentStatus;
use App\Models\BotPost;
use App\Models\Tournament;
use App\Support\LeagueTime;
use App\Support\Nostr\SignedEvent;
use App\Support\StreamBot\StreamBotCopy;
use App\Support\StreamBot\StreamBotPublisher;
use App\Support\StreamBot\TournamentNotes;
use App\Support\Tournaments\CasualCups;
use App\Support\TwentyOne\PublishResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TestSigner;

use function BitWasp\Bech32\convertBits;
use function BitWasp\Bech32\decodeRaw;

/*
 * The stream bot's notes on its own profile: one kind-1 note per published
 * tournament, the backlog a few per run, never twice (not across runs, not
 * with a rival run, not after a failed send), none for a tournament called
 * off first, nothing without the flag, the key or a relay; a note whose
 * start changed is deleted (NIP-09) and posted anew by the scheduled run:
 * once per note with a rival run, the same deletion again to a relay that
 * refused it, never a note signed by another key. The relays are stood in
 * for: each keeps the notes it took and drops the ones a deletion names.
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
    // Relay => how many more events it refuses; relay => the ids of the notes it holds.
    $this->refusals = [];
    $this->held = [];
    // A seam: called with each event before the relays answer (it disarms itself).
    $this->onPublish = null;
    $this->app->instance(StreamBotPublisher::class, new class($this) extends StreamBotPublisher
    {
        public function __construct(private $test) {}

        public function publish(array $event, array $relays): array
        {
            $signed = SignedEvent::fromInput($event);
            $this->test->published[] = $signed;

            if ($this->test->onPublish !== null) {
                ($this->test->onPublish)($signed);
            }

            return collect($relays)->mapWithKeys(function (string $relay) use ($signed): array {
                $ok = $this->test->accepts && ($this->test->refusals[$relay] ?? 0) === 0;

                if (! $ok && ($this->test->refusals[$relay] ?? 0) > 0) {
                    $this->test->refusals[$relay]--;
                }

                if ($ok && $signed->kind === 1) {
                    $this->test->held[$relay][$signed->id] = true;
                }

                if ($ok && $signed->kind === 5) {
                    foreach ($signed->tagsNamed('e') as $tag) {
                        unset($this->test->held[$relay][$tag[0]]);
                    }
                }

                return [$relay => new PublishResult($relay, $ok, $ok ? '' : 'blocked: no')];
            })->all();
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

/** The tournament note rows (kind 1), not the deletions next to them. */
function notePost(): BotPost
{
    return BotPost::query()->where('kind', 1)->sole();
}

test('a start change after the note went out: a NIP-09 deletion of the old note, then a fresh note with the new start in its row', function () {
    $tournament = openTournament(['name' => 'Moving Cup']);
    Artisan::call('twentyone:stream-bot:tournaments');
    $old = $this->published[0];

    $tournament->forceFill(['starts_at' => $tournament->starts_at->addDays(2)->setTime(19, 30)])->save();
    Artisan::call('twentyone:stream-bot:tournaments');
    [$deletion, $note] = [$this->published[1], $this->published[2]];
    $body = explode("\n\nnostr:", $note->content, 2)[0];

    expect($this->published)->toHaveCount(3)
        ->and($old->content)->toContain('starts Sat, 3 Oct 2026, 9:00 PM CEST')
        ->and($deletion->kind)->toBe(5)
        ->and($deletion->pubkey)->toBe($this->botKey->pubkey)
        ->and($deletion->hasValidSignature())->toBeTrue()
        ->and($deletion->tags)->toBe([['e', $old->id], ['k', '1']])
        ->and($note->kind)->toBe(1)
        ->and($note->hasValidSignature())->toBeTrue()
        ->and($note->content)->toContain('starts Mon, 5 Oct 2026, 9:30 PM CEST')->not->toContain('3 Oct')->not->toContain('#')
        ->and($note->tags)->toBe($old->tags)
        ->and(StreamBotCopy::violations($body, $note->tags))->toBe([])
        ->and(notePost()->event_id)->toBe($note->id)
        ->and(notePost()->published_at)->not->toBeNull()
        ->and(BotPost::query()->where('kind', 5)->sole()->event_id)->toBe($deletion->id)
        ->and($this->held)->toBe(['wss://one.test' => [$note->id => true], 'wss://two.test' => [$note->id => true]]);

    // The corrected note names the current start: later runs leave it alone.
    $this->travel(11)->minutes();
    Artisan::call('twentyone:stream-bot:tournaments');
    expect($this->published)->toHaveCount(3);
});

test('no relay takes the deletion: the old note stays in its row and a later run sends the same deletion again', function () {
    $tournament = openTournament();
    Artisan::call('twentyone:stream-bot:tournaments');
    $old = $this->published[0];
    $tournament->forceFill(['starts_at' => $tournament->starts_at->addDay()])->save();

    $this->accepts = false;
    Artisan::call('twentyone:stream-bot:tournaments');
    expect(notePost()->event_id)->toBe($old->id)
        ->and(collect($this->published)->pluck('kind')->all())->toBe([1, 5]);

    $this->accepts = true;
    $this->travel(11)->minutes();
    Artisan::call('twentyone:stream-bot:tournaments');
    expect(collect($this->published)->pluck('kind')->all())->toBe([1, 5, 5, 1])
        ->and($this->published[2]->id)->toBe($this->published[1]->id)
        ->and(notePost()->event_id)->toBe($this->published[3]->id);
});

test('a relay that refused the deletion gets the same deletion on the next run, then both relays hold only the new note', function () {
    $tournament = openTournament();
    Artisan::call('twentyone:stream-bot:tournaments');
    $old = $this->published[0];
    $tournament->forceFill(['starts_at' => $tournament->starts_at->addDay()])->save();

    // two.test refuses the deletion once (the new note after it goes through).
    $this->refusals = ['wss://two.test' => 1];
    Artisan::call('twentyone:stream-bot:tournaments');
    [$deletion, $note] = [$this->published[1], $this->published[2]];
    expect($this->held['wss://two.test'])->toBe([$old->id => true, $note->id => true])
        ->and(BotPost::query()->where('kind', 5)->sole()->published_at)->toBeNull();

    $this->travel(11)->minutes();
    Artisan::call('twentyone:stream-bot:tournaments');

    expect(collect($this->published)->pluck('kind')->all())->toBe([1, 5, 1, 5])
        ->and($this->published[3]->id)->toBe($deletion->id)
        ->and($this->held)->toBe(['wss://one.test' => [$note->id => true], 'wss://two.test' => [$note->id => true]])
        ->and(BotPost::query()->where('kind', 5)->sole()->published_at)->not->toBeNull();

    // Done: nothing more goes out.
    $this->travel(11)->minutes();
    Artisan::call('twentyone:stream-bot:tournaments');
    expect($this->published)->toHaveCount(4);
});

test('a deletion a relay never takes is given up after its attempts, with a warning', function () {
    $tournament = openTournament();
    Artisan::call('twentyone:stream-bot:tournaments');
    $tournament->forceFill(['starts_at' => $tournament->starts_at->addDay()])->save();
    $this->refusals = ['wss://two.test' => 1000];
    Log::spy();

    foreach (range(1, 14) as $run) {
        Artisan::call('twentyone:stream-bot:tournaments');
        $this->travel(11)->minutes();
    }

    // One send in the correction and eleven resends: twelve attempts, then no more.
    expect(collect($this->published)->where('kind', 5)->count())->toBe(12)
        ->and(BotPost::query()->where('kind', 5)->sole()->attempts)->toBe(12);
    Log::shouldHaveReceived('warning')->with('Stream bot deletion given up for the relays that did not take it', Mockery::type('array'))->once();
});

test('a rival run that corrected the note after this run found it stale: this run deletes nothing (not the new note)', function () {
    $tournament = openTournament();
    Artisan::call('twentyone:stream-bot:tournaments');
    $tournament->forceFill(['starts_at' => $tournament->starts_at->addDay()])->save();

    // Seam: the rival runs in full while this run reads the stale tournaments, after it read their notes.
    $armed = true;
    Tournament::retrieved(function () use (&$armed): void {
        if ($armed) {
            $armed = false;
            app(TournamentNotes::class)->run(CarbonImmutable::now());
        }
    });
    app(TournamentNotes::class)->run(CarbonImmutable::now());
    $new = notePost()->event_id;

    expect(collect($this->published)->pluck('kind')->all())->toBe([1, 5, 1])
        ->and(collect($this->published)->where('kind', 5)->flatMap(fn (SignedEvent $deletion): array => array_column($deletion->tagsNamed('e'), 0))->all())->not->toContain($new)
        ->and($this->held['wss://one.test'])->toBe([$new => true]);
});

test('a rival run while this run sends the deletion: the claim holds, one deletion and one new note', function () {
    $tournament = openTournament();
    Artisan::call('twentyone:stream-bot:tournaments');
    $tournament->forceFill(['starts_at' => $tournament->starts_at->addDay()])->save();

    // Seam: the rival runs in full while this run's deletion is on its way to the relays.
    $this->onPublish = function (SignedEvent $event): void {
        if ($event->kind === 5) {
            $this->onPublish = null;
            app(TournamentNotes::class)->run(CarbonImmutable::now());
        }
    };
    Artisan::call('twentyone:stream-bot:tournaments');

    expect(collect($this->published)->pluck('kind')->all())->toBe([1, 5, 1])
        ->and($this->held['wss://two.test'])->toBe([notePost()->event_id => true]);
});

test('a stale note signed by another key than the bot key is neither deleted nor renewed, with a warning naming both keys', function () {
    $tournament = openTournament();
    Artisan::call('twentyone:stream-bot:tournaments');
    $old = $this->published[0];
    $tournament->forceFill(['starts_at' => $tournament->starts_at->addDay()])->save();
    $other = new TestSigner;
    config(['esports.stream_bot.nsec' => $other->secret]);
    Log::spy();

    Artisan::call('twentyone:stream-bot:tournaments');

    expect($this->published)->toHaveCount(1)
        ->and(notePost()->event_id)->toBe($old->id)
        ->and(BotPost::query()->where('kind', 5)->count())->toBe(0);
    Log::shouldHaveReceived('warning')->with('Stream bot note announces an old start but is signed by another key, not deleted or renewed', [
        'tournament' => $tournament->id, 'note' => $old->id, 'note_pubkey' => $this->botKey->pubkey, 'bot_pubkey' => $other->pubkey,
    ]);
});

test('the scheduled run corrects the note of a cup moved to its game slot and leaves a current note alone, no DM', function () {
    Queue::fake();
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-30 21:00', 'UTC'));
    config(['esports.casual_cups.enabled' => ['ea-sports-fc-26', 'chess']]);
    // The FC cup opened on the old Saturday slot; chess stays on its slot.
    config(['esports.casual_cups.games.ea-sports-fc-26.slot' => ['weekday' => 'saturday', 'time' => '20:00']]);
    $moved = app(CasualCups::class)->ensure('ea-sports-fc-26', 'eu');
    $kept = app(CasualCups::class)->ensure('chess', 'eu');
    Artisan::call('twentyone:stream-bot:tournaments');
    $notes = collect($this->published)->keyBy(fn (SignedEvent $note): int => notedTournaments([$note])[0]);

    config(['esports.casual_cups.games.ea-sports-fc-26.slot' => ['weekday' => 'friday', 'time' => '18:00']]);
    expect(app(CasualCups::class)->moveToGameSlots())->toHaveCount(1)
        ->and(app(TournamentNotes::class)->stale())->toHaveCount(1);

    Artisan::call('twentyone:stream-bot:tournaments');
    [$deletion, $note] = array_slice($this->published, 2);

    expect($this->published)->toHaveCount(4)
        ->and($notes[$moved->id]->content)->toContain('starts Sat, 3 Oct 2026, 8:00 PM CEST')
        ->and($deletion->tags)->toBe([['e', $notes[$moved->id]->id], ['k', '1']])
        ->and(notedTournaments([$note]))->toBe([$moved->id])
        ->and($note->content)->toContain('starts '.LeagueTime::stamp($moved->refresh()->starts_at, 'Europe/Berlin', 'en'))->toContain('Fri, 9 Oct 2026, 6:00 PM CEST')
        ->and(StreamBotCopy::violations(explode("\n\nnostr:", $note->content, 2)[0], $note->tags))->toBe([])
        ->and(BotPost::query()->where(['subject_id' => $moved->id, 'kind' => 1])->value('event_id'))->toBe($note->id)
        ->and(BotPost::query()->where(['subject_id' => $kept->id, 'kind' => 1])->value('event_id'))->toBe($notes[$kept->id]->id)
        ->and(app(TournamentNotes::class)->stale())->toBe([]);

    Notification::assertNothingSent();
});
