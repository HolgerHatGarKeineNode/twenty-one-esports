<?php

use App\Jobs\PublishNostrEvent;
use App\Models\BotPost;
use App\Models\NostrEvent;
use App\Models\Tournament;
use App\Support\Nostr\RelayReader;
use App\Support\Nostr\SignedEvent;
use App\Support\StreamBot\StreamBotPublisher;
use App\Support\StreamBot\TournamentNotes;
use App\Support\TwentyOne\PublishResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Tests\Support\TestSigner;

/*
 * tournaments:heal-nostr after NIP-09 deletion requests signed with the
 * league or bot key (2026-09-27: three bot notes deleted from a client): a
 * deleted current 31923 gets a new version after the deletion, a missing one
 * is sent again unchanged, a deleted bot note is posted anew, nothing else;
 * a dry run changes nothing and a second run finds nothing to do. The relays
 * are stood in for.
 */

beforeEach(function () {
    $this->league = new TestSigner;
    $this->botKey = new TestSigner;

    config([
        'esports.league.nsec' => $this->league->secret,
        'esports.stream_bot.enabled' => true,
        'esports.stream_bot.nsec' => $this->botKey->secret,
        'esports.stream_bot.tournament_notes.per_run' => 10,
        // The pacing of the profile notes (ProfileVarietyTest) is not what this file tests.
        'esports.stream_bot.profile_limits' => [],
        'twentyone.stream.relays' => ['wss://stream.test'],
    ]);

    Bus::fake([PublishNostrEvent::class]);

    // What each relay returns, as raw events.
    $this->onRelay = [];
    $this->app->instance(RelayReader::class, new class($this) extends RelayReader
    {
        public function __construct(private $test) {}

        public function fetch(array $filters, ?array $relays = null, array $known = [], int $perAuthor = 1): array
        {
            $found = [];

            foreach ($relays ?? [] as $relay) {
                foreach ($this->test->onRelay[$relay] ?? [] as $raw) {
                    $event = SignedEvent::fromInput($raw);

                    foreach ($filters as $filter) {
                        if (in_array($event->kind, $filter['kinds'], true)
                            && (! isset($filter['authors']) || in_array($event->pubkey, $filter['authors'], true))
                            && (! isset($filter['#d']) || in_array($event->tag('d'), $filter['#d'], true))) {
                            $found[$event->id] = $event;
                        }
                    }
                }
            }

            return array_values($found);
        }
    });

    $this->notes = [];
    $this->app->instance(StreamBotPublisher::class, new class($this) extends StreamBotPublisher
    {
        public function __construct(private $test) {}

        public function publish(array $event, array $relays): array
        {
            $this->test->notes[] = SignedEvent::fromInput($event);

            return collect($relays)->mapWithKeys(fn (string $relay): array => [$relay => new PublishResult($relay, true, '')])->all();
        }
    });

    $this->travelTo(Carbon::parse('2026-09-26 12:00:00', 'UTC'));

    // Four published tournaments with their bot notes; the league relays hold every current
    // version except the fourth's on the second relay.
    $this->cups = collect(range(1, 4))->map(function (int $i): Tournament {
        $this->travel(1)->minutes();

        return openTournament(['name' => 'Heal Cup '.$i]);
    });
    Artisan::call('twentyone:stream-bot:tournaments');
    config(['esports.relays' => ['wss://one.test', 'wss://two.test']]);

    foreach ($this->cups as $i => $cup) {
        $raw = json_decode($cup->fresh('event')->event->raw, true);
        $this->onRelay['wss://one.test'][] = $raw;

        if ($i < 3) {
            $this->onRelay['wss://two.test'][] = $raw;
        }
    }

    $this->travel(10)->minutes();
    [$one, $two, $three, $four] = $this->cups->map(fn (Tournament $cup): Tournament => $cup->fresh('event'))->all();
    $this->noteOf = fn (Tournament $cup): string => BotPost::query()->where('subject_id', $cup->id)->value('event_id');
    $this->deletedNote = ($this->noteOf)($three);

    // The deletion requests, found only on the stream relay: an `a` of the first, an `e` of the
    // second's current version, an `e` of the third's note (from a client), an `a` of the fourth
    // older than its version, one of an event that is none of ours, and an `a` of the third signed
    // by the bot key, which is not the third's author (NIP-09: deletes nothing).
    $this->deletions = [
        'first' => $this->league->sign(5, [['a', $one->address()], ['k', '31923']]),
        'second' => $this->league->sign(5, [['e', $two->event->event_id], ['k', '31923']]),
        'note' => $this->botKey->sign(5, [['e', $this->deletedNote], ['p', $this->botKey->pubkey], ['k', '1'], ['client', 'Amethyst']]),
        'older' => $this->league->sign(5, [['a', $four->address()], ['k', '31923']], '', $four->event->signed_at - 1),
        'other' => $this->league->sign(5, [['e', str_repeat('ab', 32)], ['k', '1']]),
        'foreign' => $this->botKey->sign(5, [['a', $three->address()], ['k', '31923']]),
    ];
    $this->onRelay['wss://stream.test'] = array_values($this->deletions);

    $this->travel(1)->minutes();
});

test('the dry run names every deletion and what it would do, and changes nothing', function () {
    $events = NostrEvent::query()->count();
    $posts = BotPost::query()->orderBy('id')->pluck('event_id')->all();
    $queued = Bus::dispatched(PublishNostrEvent::class)->count();

    Artisan::call('tournaments:heal-nostr');
    $output = Artisan::output();
    [$one, $two, $three, $four] = $this->cups->all();

    expect($output)->toContain('Deletion requests (kind 5) by the league or bot key: 6')
        ->toContain($this->deletions['note']['id'].' at 2026-09-26 12:14:00 UTC by '.substr($this->botKey->pubkey, 0, 12).' via Amethyst: e=['.$this->deletedNote.'] a=[] k=[1]')
        ->toContain("tournament {$one->id} {$one->slug}: DELETED by {$this->deletions['first']['id']}, re-sign")
        ->toContain("tournament {$two->id} {$two->slug}: DELETED by {$this->deletions['second']['id']}, re-sign")
        ->toContain("tournament {$three->id} {$three->slug}: present on every league relay")
        ->toContain("tournament {$four->id} {$four->slug}: missing on wss://two.test, resend")
        ->toContain('Deleted bot notes (kind 1): 1')
        ->toContain("tournament {$three->id}: note {$this->deletedNote} deleted by {$this->deletions['note']['id']}, renew")
        // The renewed note in the wording the next note takes (ProfileNotes rotates them).
        ->toContain(explode("\n", app(TournamentNotes::class)->content($three))[0])->toContain('Heal Cup 3')
        ->toContain($this->league->pubkey.':'.str_repeat('ab', 32))
        ->toContain($this->botKey->pubkey.':'.$three->address())
        ->toContain('Dry run: nothing was signed, stored or sent.')
        ->and(NostrEvent::query()->count())->toBe($events)
        ->and(BotPost::query()->orderBy('id')->pluck('event_id')->all())->toBe($posts)
        ->and(Bus::dispatched(PublishNostrEvent::class)->count())->toBe($queued)
        ->and($this->notes)->toHaveCount(4);
});

test('publish re-signs the deleted versions after the deletion, resends the missing one, renews only the deleted note; a second run finds nothing', function () {
    [$one, $two, $three, $four] = $this->cups->map(fn (Tournament $cup): Tournament => $cup->fresh('event'))->all();
    $before = collect([$one, $two, $three, $four])->mapWithKeys(fn (Tournament $cup): array => [$cup->id => $cup->event->event_id])->all();
    $notesBefore = collect([$one, $two, $four])->map($this->noteOf)->all();

    expect(Artisan::call('tournaments:heal-nostr', ['--publish' => true]))->toBe(0);

    foreach (['first' => $one, 'second' => $two] as $deletion => $cup) {
        $version = $cup->fresh('event')->event;
        $signed = SignedEvent::fromInput(json_decode($version->raw, true));

        expect($version->event_id)->not->toBe($before[$cup->id])
            ->and($version->signed_at)->toBeGreaterThan($this->deletions[$deletion]['created_at'])
            ->and($signed->hasValidSignature())->toBeTrue()
            ->and($signed->pubkey)->toBe($this->league->pubkey)
            ->and($signed->tag('d'))->toBe($cup->slug);
        Bus::assertDispatched(PublishNostrEvent::class, fn (PublishNostrEvent $job): bool => $job->event->event_id === $version->event_id);
    }

    // Not deleted: the third keeps its version, the fourth's same event goes out again.
    expect($three->fresh('event')->event->event_id)->toBe($before[$three->id])
        ->and($four->fresh('event')->event->event_id)->toBe($before[$four->id]);
    Bus::assertDispatched(PublishNostrEvent::class, fn (PublishNostrEvent $job): bool => $job->event->event_id === $before[$four->id]);

    // One new note, for the third, stored in its row; the others untouched.
    $renewed = ($this->noteOf)($three);
    $note = collect($this->notes)->last();

    expect($this->notes)->toHaveCount(5)
        ->and($note->id)->toBe($renewed)
        ->and($renewed)->not->toBe($this->deletedNote)
        ->and($note->pubkey)->toBe($this->botKey->pubkey)
        ->and($note->tags)->toBe([['q', '31923:'.$this->league->pubkey.':'.$three->slug, 'wss://one.test']])
        ->and($note->content)->not->toContain('#')
        ->and(BotPost::query()->where('subject_id', $three->id)->value('published_at'))->not->toBeNull()
        ->and(collect([$one, $two, $four])->map($this->noteOf)->all())->toBe($notesBefore);

    // The relays now hold every current version: the next run has nothing to do.
    $this->onRelay['wss://one.test'] = $this->onRelay['wss://two.test'] = $this->cups
        ->map(fn (Tournament $cup): array => json_decode($cup->fresh('event')->event->raw, true))->all();
    $events = NostrEvent::query()->count();

    Artisan::call('tournaments:heal-nostr', ['--publish' => true]);

    expect(Artisan::output())->toContain('Deleted bot notes (kind 1): 0')->toContain('Nothing to do.')
        ->and(NostrEvent::query()->count())->toBe($events)
        ->and($this->notes)->toHaveCount(5);
});

test('a note is renewed once: a second renew of the same deleted id changes nothing', function () {
    $three = $this->cups[2];
    $notes = app(TournamentNotes::class);

    $first = $notes->renew($three, $this->deletedNote, now()->toImmutable());
    $second = $notes->renew($three, $this->deletedNote, now()->toImmutable());

    expect($first)->toContain('posted')
        ->and($second)->toBe("tournament {$three->id}: its note is not {$this->deletedNote} (renewed already)")
        ->and($this->notes)->toHaveCount(5);
});

test('a current version that still carries t tags is re-signed without them, once', function () {
    $three = $this->cups[2]->fresh('event');
    $old = $three->event->payload();

    // The format before 2026-09-28: the same tags plus hashtags, signed a second later, on every league relay.
    $hashtagged = NostrEvent::fromSigned(SignedEvent::fromInput($this->league->sign(31923, [...$old['tags'], ['t', 'esports'], ['t', 'chess']], $old['content'], $old['created_at'] + 1)));
    $three->forceFill(['event_id' => $hashtagged->id])->save();
    $this->onRelay['wss://one.test'][] = $this->onRelay['wss://two.test'][] = $hashtagged->payload();

    Artisan::call('tournaments:heal-nostr');
    expect(Artisan::output())->toContain("tournament {$three->id} {$three->slug}: carries t tags, re-sign {$hashtagged->event_id}");

    Artisan::call('tournaments:heal-nostr', ['--publish' => true]);
    $version = SignedEvent::fromInput($three->fresh('event')->event->payload());

    expect($version->id)->not->toBe($hashtagged->event_id)
        ->and($version->tagsNamed('t'))->toBe([])
        ->and($version->createdAt)->toBeGreaterThan($hashtagged->signed_at)
        ->and($version->tag('d'))->toBe($three->slug);

    $this->onRelay['wss://one.test'][] = $this->onRelay['wss://two.test'][] = $version->toArray();
    Artisan::call('tournaments:heal-nostr');

    expect(Artisan::output())->toContain("tournament {$three->id} {$three->slug}: present on every league relay {$version->id}");
});
