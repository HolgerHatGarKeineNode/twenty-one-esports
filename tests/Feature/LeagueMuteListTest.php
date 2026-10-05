<?php

use App\Enums\ModerationAction;
use App\Jobs\PublishLeagueMuteList;
use App\Jobs\PublishNostrEvent;
use App\Models\Admin;
use App\Models\NostrEvent;
use App\Models\PubkeyModeration;
use App\Models\RelayDelivery;
use App\Models\User;
use App\Support\Moderation\LeagueMuteList;
use App\Support\Moderation\MuteListUnreadable;
use App\Support\Moderation\SiteModeration;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RelayReader;
use App\Support\Nostr\SignedEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
 * The league's public mute list (user, 2026-10-05): every key muted or
 * banned on the site goes out as a NIP-51 kind 10000 of the league key,
 * carried over onto the newest list a client wrote for that key (option 2):
 * its encrypted content and its foreign tags unchanged, the site's `p` tags
 * added, a lift removing only what the site added. No relay answering the
 * read → nothing is signed. Relays here are a fake reader, the in-memory
 * MiniRelay or unreachable ports; never a public relay.
 */

/** Stands in for the relays' answer to the read of the newest list. */
final class FakeMuteListRelays extends RelayReader
{
    /** @var list<SignedEvent> */
    public array $events = [];

    public int $answered = 1;

    public int $reads = 0;

    public function fetchCounted(array $filters, ?array $relays = null, array $known = [], int $perAuthor = 1, ?float $until = null): array
    {
        $this->reads++;

        return ['events' => $this->events, 'answered' => $this->answered];
    }
}

function muteListAdmin(): User
{
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

/** @return list<list<string>> */
function muteListTags(NostrEvent $event): array
{
    return $event->payload()['tags'];
}

/** The relays now answer with exactly this event (array or stored version). */
function relaysHold(FakeMuteListRelays $relays, NostrEvent|array|null $event): void
{
    $relays->events = $event === null ? [] : [SignedEvent::fromInput($event instanceof NostrEvent ? $event->payload() : $event)];
}

/**
 * A list as Amethyst writes it for the league key: private items as NIP-44
 * text in content, a foreign public `p` with a relay hint, `t`, `word`, `e`,
 * its `client` tag, and one exact duplicate.
 *
 * @return array<string, mixed>
 */
function amethystList(TestSigner $league, int $createdAt, string $foreign, string $content = 'AqrB+/9kZx0=?iv=not-really/ünï+cödé=='): array
{
    return $league->sign(10000, [
        ['p', $foreign, 'wss://relay.example.com/'],
        ['t', 'spam'],
        ['word', 'scam'],
        ['e', str_repeat('ab', 32)],
        ['client', 'Amethyst'],
        ['t', 'spam'],
    ], $content, $createdAt);
}

/** The Amethyst list's tags as the site carries them over: verbatim, the duplicate once. */
function amethystTags(string $foreign): array
{
    return [['p', $foreign, 'wss://relay.example.com/'], ['t', 'spam'], ['word', 'scam'], ['e', str_repeat('ab', 32)], ['client', 'Amethyst']];
}

beforeEach(function () {
    $this->league = new TestSigner;
    $this->relays = new FakeMuteListRelays;
    app()->instance(RelayReader::class, $this->relays);
    config(['esports.league.nsec' => $this->league->secret, 'esports.league.mute_list' => true, 'esports.relays' => ['ws://127.0.0.1:9']]);
});

test('a mute queues the list; the list carries the client\'s content and foreign tags over unchanged and adds the key', function () {
    Queue::fake();
    $foreign = (new TestSigner)->pubkey;
    $amethyst = amethystList($this->league, now()->getTimestamp() - 3600, $foreign);
    relaysHold($this->relays, $amethyst);
    $target = (new TestSigner)->pubkey;

    app(SiteModeration::class)->mute(muteListAdmin(), NostrKeys::hexToNpub($target), 'spam spam');

    Queue::assertPushed(PublishLeagueMuteList::class, 1);
    (new PublishLeagueMuteList)->handle(app(LeagueMuteList::class));

    $event = NostrEvent::query()->where('kind', 10000)->sole();
    $signed = SignedEvent::fromInput($event->payload());

    expect($signed?->hasValidSignature())->toBeTrue()
        ->and($event->pubkey)->toBe($this->league->pubkey)
        ->and($signed->content)->toBe($amethyst['content'])
        ->and(muteListTags($event))->toBe([...amethystTags($foreign), ['p', $target]])
        ->and($event->signed_at)->toBeGreaterThan($amethyst['created_at'])
        ->and($event->raw)->not->toContain('spam spam');

    Queue::assertPushed(PublishNostrEvent::class, fn (PublishNostrEvent $job): bool => $job->event->is($event));
});

test('a lift removes only the key the site added: foreign p, tags and content stay', function () {
    Queue::fake();
    Sleep::fake(syncWithCarbon: true);
    $foreign = (new TestSigner)->pubkey;
    $amethyst = amethystList($this->league, now()->getTimestamp() - 3600, $foreign);
    relaysHold($this->relays, $amethyst);
    $admin = muteListAdmin();
    [$first, $second] = [(new TestSigner)->pubkey, (new TestSigner)->pubkey];
    $moderation = app(SiteModeration::class);
    $list = app(LeagueMuteList::class);

    $muteFirst = $moderation->mute($admin, $first, 'spam spam');
    $banSecond = $moderation->ban($admin, $second, 'scam links');
    $both = $list->publish();
    $expected = [$first, $second];
    sort($expected);
    expect(muteListTags($both))->toBe([...amethystTags($foreign), ...array_map(fn (string $key): array => ['p', $key], $expected)]);
    relaysHold($this->relays, $both);

    $moderation->lift($admin, $muteFirst->id);
    $one = $list->publish();
    expect(muteListTags($one))->toBe([...amethystTags($foreign), ['p', $second]]);
    relaysHold($this->relays, $one);

    $moderation->lift($admin, $banSecond->id);
    $none = $list->publish();

    expect(muteListTags($none))->toBe(amethystTags($foreign))
        ->and($none->payload()['content'])->toBe($amethyst['content'])
        ->and(LeagueMuteList::newest($this->league->pubkey)?->is($none))->toBeTrue();
});

test('a client\'s public p for a key the site once moderated and lifted survives the site\'s next lift', function () {
    Queue::fake();
    Sleep::fake(syncWithCarbon: true);
    $admin = muteListAdmin();
    $moderation = app(SiteModeration::class);
    $foreign = (new TestSigner)->pubkey;

    // Long ago: the site muted and lifted the key; nothing listed it then.
    $moderation->lift($admin, $moderation->mute($admin, $foreign, 'old case')->id);
    $this->travel(1)->hours();

    // Then a client muted it publicly, and the site mutes another key.
    relaysHold($this->relays, amethystList($this->league, now()->getTimestamp() - 60, $foreign));
    $other = $moderation->mute($admin, (new TestSigner)->pubkey, 'spam spam');
    relaysHold($this->relays, app(LeagueMuteList::class)->publish());

    $moderation->lift($admin, $other->id);

    expect(muteListTags(app(LeagueMuteList::class)->publish()))->toBe(amethystTags($foreign));
});

test('no relay answers the read: nothing is signed, the job fails for a retry, the hourly run only warns', function () {
    Queue::fake();
    Log::spy();
    $this->relays->answered = 0;
    $target = (new TestSigner)->pubkey;

    app(SiteModeration::class)->mute(muteListAdmin(), $target, 'spam spam');

    expect(fn () => (new PublishLeagueMuteList)->handle(app(LeagueMuteList::class)))->toThrow(MuteListUnreadable::class);
    $this->artisan('esports:mute-list')->assertSuccessful();

    expect(NostrEvent::query()->count())->toBe(0)
        ->and(SiteModeration::isHidden($target))->toBeTrue();
    Queue::assertNotPushed(PublishNostrEvent::class);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'no relay answered'))->once();
});

test('a new version is dated after the newest relay version, also one of this second or one from the future', function () {
    Queue::fake();
    Sleep::fake(syncWithCarbon: true);
    $this->travelTo(now()->startOfSecond());
    $admin = muteListAdmin();
    $list = app(LeagueMuteList::class);
    $foreign = (new TestSigner)->pubkey;

    // Same second: the writer waits for the next one rather than signing ahead of the clock.
    $sameSecond = amethystList($this->league, now()->getTimestamp(), $foreign);
    relaysHold($this->relays, $sameSecond);
    app(SiteModeration::class)->mute($admin, (new TestSigner)->pubkey, 'spam spam');
    $first = $list->publish();

    expect($first->signed_at)->toBe($sameSecond['created_at'] + 1)
        ->and($first->signed_at)->toBeLessThanOrEqual(now()->getTimestamp());
    Sleep::assertSleptTimes(1);

    // A client with its clock ten minutes ahead: the new version still beats it.
    $future = amethystList($this->league, now()->getTimestamp() + 600, $foreign, 'future+content==');
    relaysHold($this->relays, $future);
    app(SiteModeration::class)->ban($admin, (new TestSigner)->pubkey, 'scam links');
    $second = $list->publish();

    expect($second->signed_at)->toBe($future['created_at'] + 1)
        ->and($second->payload()['content'])->toBe('future+content==');
    Sleep::assertSleptTimes(1);
});

test('a client edit that dropped the site\'s keys is repaired by the hourly run, with the client\'s new content and tags', function () {
    Queue::fake();
    Sleep::fake(syncWithCarbon: true);
    $foreign = (new TestSigner)->pubkey;
    relaysHold($this->relays, amethystList($this->league, now()->getTimestamp() - 3600, $foreign));
    $target = (new TestSigner)->pubkey;
    app(SiteModeration::class)->mute(muteListAdmin(), $target, 'spam spam');
    $ours = app(LeagueMuteList::class)->publish();
    relaysHold($this->relays, $ours);

    // The relays hold the list: the hourly run signs nothing.
    $this->artisan('esports:mute-list')->expectsOutputToContain('nothing was signed')->assertSuccessful();
    expect(NostrEvent::query()->where('kind', 10000)->count())->toBe(1);

    // Amethyst writes a new list from an old copy: new private part, a new tag, the site's key gone.
    $edited = $this->league->sign(10000, [['p', $foreign, 'wss://relay.example.com/'], ['word', 'nsfw'], ['client', 'Amethyst']], 'NEW+private/part==', now()->getTimestamp() + 60);
    $this->travel(2)->minutes();
    relaysHold($this->relays, $edited);

    $this->artisan('esports:mute-list')->expectsOutputToContain('Signed and queued')->assertSuccessful();
    $repaired = LeagueMuteList::newest($this->league->pubkey);

    expect(muteListTags($repaired))->toBe([['p', $foreign, 'wss://relay.example.com/'], ['word', 'nsfw'], ['client', 'Amethyst'], ['p', $target]])
        ->and($repaired->payload()['content'])->toBe('NEW+private/part==')
        ->and($repaired->signed_at)->toBeGreaterThan($edited['created_at']);
});

test('the site\'s newest version that the relays lack is queued again, not signed anew', function () {
    Queue::fake();
    $amethyst = amethystList($this->league, now()->getTimestamp() - 3600, (new TestSigner)->pubkey);
    relaysHold($this->relays, $amethyst);
    app(SiteModeration::class)->mute(muteListAdmin(), (new TestSigner)->pubkey, 'spam spam');
    $ours = app(LeagueMuteList::class)->publish();
    Queue::assertPushed(PublishNostrEvent::class, 1);

    // The relays still answer with the older client list (our version went nowhere).
    expect(app(LeagueMuteList::class)->publish())->toBeNull()
        ->and(NostrEvent::query()->where('kind', 10000)->count())->toBe(1);
    Queue::assertPushed(PublishNostrEvent::class, 2);
    Queue::assertPushed(PublishNostrEvent::class, fn (PublishNostrEvent $job): bool => $job->event->is($ours));
});

test('no list anywhere: the site\'s keys with empty content, and an unchanged list is not signed again', function () {
    Queue::fake();
    $target = (new TestSigner)->pubkey;
    app(SiteModeration::class)->mute(muteListAdmin(), $target, 'spam spam');

    $event = app(LeagueMuteList::class)->publish();
    expect(muteListTags($event))->toBe([['p', $target]])
        ->and($event->payload()['content'])->toBe('');

    relaysHold($this->relays, $event);
    expect(app(LeagueMuteList::class)->publish())->toBeNull()
        ->and(app(LeagueMuteList::class)->publish())->toBeNull()
        ->and(NostrEvent::query()->where('kind', 10000)->count())->toBe(1);
    Queue::assertPushed(PublishNostrEvent::class, 1);
});

test('mutes and bans go out as one deduplicated sorted list, without admins, board members and the league key', function () {
    Queue::fake();
    $admin = muteListAdmin();
    $keys = [(new TestSigner)->pubkey, (new TestSigner)->pubkey, (new TestSigner)->pubkey];
    $moderation = app(SiteModeration::class);

    $moderation->mute($admin, $keys[0], 'spam spam');
    $moderation->ban($admin, $keys[0], 'still spam');
    $moderation->ban($admin, $keys[1], 'scam links');
    $moderation->mute($admin, $keys[2], 'flooding');

    // Rows the service would refuse today: a key promoted to admin or board later, and the league key itself.
    $promoted = (new TestSigner)->pubkey;
    $board = (new TestSigner)->pubkey;
    foreach ([$promoted, $board, $this->league->pubkey] as $pubkey) {
        PubkeyModeration::query()->create(['pubkey' => $pubkey, 'action' => ModerationAction::Mute, 'reason' => 'old row', 'actor_pubkey' => $admin->pubkey]);
    }
    Admin::query()->create(['pubkey' => $promoted]);
    config(['esports.board' => [NostrKeys::hexToNpub($board)]]);

    $event = app(LeagueMuteList::class)->publish();
    sort($keys);

    expect(muteListTags($event))->toBe(array_map(fn (string $key): array => ['p', $key], $keys));
});

test('changes while a run is queued coalesce into one job', function () {
    Queue::fake();
    $admin = muteListAdmin();

    app(SiteModeration::class)->mute($admin, (new TestSigner)->pubkey, 'spam spam');
    app(SiteModeration::class)->ban($admin, (new TestSigner)->pubkey, 'scam links');

    Queue::assertPushed(PublishLeagueMuteList::class, 1);
});

test('switched off, without the league key or without relays nothing is read or signed, and the moderation still holds', function (array $config) {
    Queue::fake();
    config($config);
    $target = (new TestSigner)->pubkey;

    app(SiteModeration::class)->mute(muteListAdmin(), $target, 'spam spam');

    expect(app(LeagueMuteList::class)->publish())->toBeNull()
        ->and($this->relays->reads)->toBe(0)
        ->and(NostrEvent::query()->count())->toBe(0)
        ->and(SiteModeration::isHidden($target))->toBeTrue();
})->with([
    'switched off' => [['esports.league.mute_list' => false]],
    'no league key' => [['esports.league.nsec' => null]],
    'no relays' => [['esports.relays' => []]],
]);

test('on unless switched off, and the hourly reconcile runs only while it is on', function () {
    $saved = [getenv('ESPORTS_PUBLISH_MUTE_LIST'), $_ENV['ESPORTS_PUBLISH_MUTE_LIST'] ?? null, $_SERVER['ESPORTS_PUBLISH_MUTE_LIST'] ?? null];
    putenv('ESPORTS_PUBLISH_MUTE_LIST');
    unset($_ENV['ESPORTS_PUBLISH_MUTE_LIST'], $_SERVER['ESPORTS_PUBLISH_MUTE_LIST']);

    try {
        $default = (require config_path('esports.php'))['league']['mute_list'];
        putenv('ESPORTS_PUBLISH_MUTE_LIST=false');
        $killed = (require config_path('esports.php'))['league']['mute_list'];
    } finally {
        putenv($saved[0] === false ? 'ESPORTS_PUBLISH_MUTE_LIST' : 'ESPORTS_PUBLISH_MUTE_LIST='.$saved[0]);
    }

    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'esports:mute-list'));

    // Off by default until the relay-quorum fix (security gate 2026-10-05, F1): a read with the outbox relays down
    // could sign over an empty base and wipe the key's private list.
    config(['esports.league.mute_list' => true]);
    expect($default)->toBeFalse()
        ->and($killed)->toBeFalse()
        ->and($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->filtersPass(app()))->toBeTrue();

    config(['esports.league.mute_list' => false]);
    expect($event->filtersPass(app()))->toBeFalse();
});

test('over a real websocket: the client list is read from the relay and carried over, a dead relay fails softly, only dead relays sign nothing', function () {
    app()->forgetInstance(RelayReader::class);
    $foreign = (new TestSigner)->pubkey;
    $amethyst = amethystList($this->league, now()->getTimestamp() - 3600, $foreign);
    $port = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $seed = tempnam(sys_get_temp_dir(), 'mute-list-relay');
    file_put_contents($seed, json_encode([$amethyst]));
    $relay = Process::path(base_path())->start(['php', 'tests/Support/mini-relay.php', (string) $port, $seed]);
    $good = 'ws://127.0.0.1:'.$port;
    $dead = 'ws://127.0.0.1:9';

    try {
        for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $port); $i++) {
            usleep(100_000);
        }

        config(['esports.relays' => [$good, $dead], 'esports.relay_timeout_seconds' => 2]);
        $target = (new TestSigner)->pubkey;

        // Sync queue: the mute runs the list job, which reads the relay and runs the relay job.
        app(SiteModeration::class)->mute(muteListAdmin(), $target, 'spam spam');
        $event = NostrEvent::query()->where('kind', 10000)->sole();

        // The relay now answers with our version: the next run signs nothing.
        expect(app(LeagueMuteList::class)->publish())->toBeNull();
    } finally {
        $relay->stop();
        @unlink($seed);
    }

    $deliveries = RelayDelivery::query()->where('nostr_event_id', $event->id)->pluck('accepted', 'relay')->all();

    expect(muteListTags($event))->toBe([...amethystTags($foreign), ['p', $target]])
        ->and($event->payload()['content'])->toBe($amethyst['content'])
        ->and($deliveries)->toBe([$good => true, $dead => false]);

    config(['esports.relays' => [$dead]]);
    app(SiteModeration::class)->ban(muteListAdmin(), (new TestSigner)->pubkey, 'scam links');

    expect(NostrEvent::query()->where('kind', 10000)->count())->toBe(1);
});

test('the moderation page says the keys go out publicly as the league mute list, only while it does', function () {
    $note = "The keys in force go out publicly as the league's Nostr mute list, without reasons and without saying mute or ban.";
    $admin = muteListAdmin();

    Livewire::actingAs($admin)->test('pages::admin.moderation')
        ->assertSee($note)
        ->assertDontSee('Nobody but admins sees this list.');

    config(['esports.league.mute_list' => false]);
    Livewire::actingAs($admin)->test('pages::admin.moderation')->assertDontSee($note);

    app()->setLocale('de');
    expect(__($note))->toStartWith('Die geltenden Schlüssel');
});
