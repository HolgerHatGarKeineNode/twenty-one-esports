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
use App\Support\Moderation\SiteModeration;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\SignedEvent;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
 * The league's public mute list (user, 2026-10-05): every key muted or
 * banned on the site goes out as a NIP-51 kind 10000 of the league key, one
 * public `p` each, no reasons, no mute/ban distinction, republished in full
 * after every change and skipped when nothing changed. Relays only in-memory
 * (tests/Support/MiniRelay) or unreachable ports, never a public relay.
 */

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

beforeEach(function () {
    $this->league = new TestSigner;
    config(['esports.league.nsec' => $this->league->secret, 'esports.league.mute_list' => true, 'esports.relays' => []]);
});

test('a mute queues the list, and the list is a kind 10000 signed by the league that names the key', function () {
    Queue::fake();
    $target = (new TestSigner)->pubkey;

    app(SiteModeration::class)->mute(muteListAdmin(), NostrKeys::hexToNpub($target), 'spam spam');

    Queue::assertPushed(PublishLeagueMuteList::class, 1);
    (new PublishLeagueMuteList)->handle(app(LeagueMuteList::class));

    $event = NostrEvent::query()->where('kind', 10000)->sole();
    $signed = SignedEvent::fromInput($event->payload());

    expect($signed)->not->toBeNull()
        ->and($signed->hasValidSignature())->toBeTrue()
        ->and($event->pubkey)->toBe($this->league->pubkey)
        ->and($event->payload()['content'])->toBe('')
        ->and(muteListTags($event))->toBe([['p', $target]])
        ->and($event->raw)->not->toContain('spam');

    Queue::assertPushed(PublishNostrEvent::class, fn (PublishNostrEvent $job): bool => $job->event->is($event));
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
    $expected = $keys;
    sort($expected);

    expect($event)->not->toBeNull()
        ->and(muteListTags($event))->toBe(array_map(fn (string $key): array => ['p', $key], $expected));
});

test('a lift publishes the list without the key, and lifting the last one publishes an empty list', function () {
    Queue::fake();
    Sleep::fake(syncWithCarbon: true);
    $admin = muteListAdmin();
    [$first, $second] = [(new TestSigner)->pubkey, (new TestSigner)->pubkey];
    $moderation = app(SiteModeration::class);
    $list = app(LeagueMuteList::class);

    $muteFirst = $moderation->mute($admin, $first, 'spam spam');
    $banSecond = $moderation->ban($admin, $second, 'scam links');
    expect(LeagueMuteList::listed($list->publish()))->toEqualCanonicalizing([$first, $second]);

    $moderation->lift($admin, $muteFirst->id);
    expect(muteListTags($list->publish()))->toBe([['p', $second]]);

    $moderation->lift($admin, $banSecond->id);
    $empty = $list->publish();

    expect($empty)->not->toBeNull()
        ->and(muteListTags($empty))->toBe([])
        ->and($empty->payload()['content'])->toBe('')
        ->and(LeagueMuteList::newest($this->league->pubkey)?->is($empty))->toBeTrue()
        ->and(NostrEvent::query()->where('kind', 10000)->count())->toBe(3);
});

test('an unchanged list is not signed again, and a change in the same second is dated one second later, never ahead of the clock', function () {
    Queue::fake();
    Sleep::fake(syncWithCarbon: true);
    $this->travelTo(now()->startOfSecond());
    $admin = muteListAdmin();
    $list = app(LeagueMuteList::class);

    app(SiteModeration::class)->mute($admin, (new TestSigner)->pubkey, 'spam spam');
    $first = $list->publish();

    expect($list->publish())->toBeNull()
        ->and($list->publish())->toBeNull()
        ->and(NostrEvent::query()->where('kind', 10000)->count())->toBe(1);
    Queue::assertPushed(PublishNostrEvent::class, 1);

    app(SiteModeration::class)->ban($admin, (new TestSigner)->pubkey, 'scam links');
    $second = $list->publish();

    expect($second->signed_at)->toBe($first->signed_at + 1)
        ->and($second->signed_at)->toBeLessThanOrEqual(now()->getTimestamp())
        ->and(LeagueMuteList::newest($this->league->pubkey)?->is($second))->toBeTrue();
    Sleep::assertSleptTimes(1);
    Queue::assertPushed(PublishNostrEvent::class, 2);
});

test('changes while a run is queued coalesce into one job', function () {
    Queue::fake();
    $admin = muteListAdmin();

    app(SiteModeration::class)->mute($admin, (new TestSigner)->pubkey, 'spam spam');
    app(SiteModeration::class)->ban($admin, (new TestSigner)->pubkey, 'scam links');

    Queue::assertPushed(PublishLeagueMuteList::class, 1);
});

test('switched off or without the league key nothing is signed, and the moderation still holds', function (array $config) {
    Queue::fake();
    config($config);
    $target = (new TestSigner)->pubkey;

    app(SiteModeration::class)->mute(muteListAdmin(), $target, 'spam spam');

    expect(app(LeagueMuteList::class)->publish())->toBeNull()
        ->and(NostrEvent::query()->count())->toBe(0)
        ->and(SiteModeration::isHidden($target))->toBeTrue();
})->with([
    'switched off' => [['esports.league.mute_list' => false]],
    'no league key' => [['esports.league.nsec' => null]],
]);

test('the list reaches a relay, and a relay that refuses fails softly', function () {
    $port = (int) Process::run(['php', '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];'])->output();
    $relay = Process::path(base_path())->start(['php', 'tests/Support/mini-relay.php', (string) $port]);

    try {
        for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $port); $i++) {
            usleep(100_000);
        }

        $good = 'ws://127.0.0.1:'.$port;
        $dead = 'ws://127.0.0.1:9';
        config(['esports.relays' => [$good, $dead], 'esports.relay_timeout_seconds' => 2]);
        $target = (new TestSigner)->pubkey;

        // Sync queue: the mute runs the list job, which runs the relay job.
        app(SiteModeration::class)->mute(muteListAdmin(), $target, 'spam spam');
    } finally {
        $relay->stop();
    }

    $event = NostrEvent::query()->where('kind', 10000)->sole();
    $deliveries = RelayDelivery::query()->where('nostr_event_id', $event->id)->pluck('accepted', 'relay')->all();

    expect(muteListTags($event))->toBe([['p', $target]])
        ->and($deliveries)->toBe([$good => true, $dead => false])
        ->and(SiteModeration::isHidden($target))->toBeTrue();
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
    expect(__("The keys in force go out publicly as the league's Nostr mute list, without reasons and without saying mute or ban."))->toStartWith('Die geltenden Schlüssel');
});
