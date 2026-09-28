<?php

use App\Jobs\SendNostrDm;
use App\Models\NostrEvent;
use App\Models\RelayDelivery;
use App\Models\User;
use App\Support\Nostr\NostrKeys;
use App\Support\Nostr\RelayReader;
use App\Support\Nostr\SignedEvent;
use App\Support\Notifications\DmRelays;
use App\Support\Notifications\NotificationDm;
use App\Support\Wallet\NwcCipher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use swentel\nostr\Encryption\Nip44;
use Tests\Support\TestSigner;

/*
 * P45: where a notification DM goes and in which format. The player's
 * DM relay list (10050) and NIP-65 list (10002) are looked up on the
 * configured relays (a fake RelayReader here): with a 10050 the DM is a
 * NIP-17 gift wrap to those relays, with none a NIP-04 kind 4 to the
 * player's inbox, and a lookup that no relay answered stays NIP-17.
 *
 * Relays are closed local ports (every connection refused at once), so each
 * RelayDelivery row shows which relays were tried without any network.
 */

const DM_CHAT_RELAY = 'ws://127.0.0.1:9';
const DM_PLAYER_RELAY = 'ws://127.0.0.1:11';
const DM_INBOX_RELAY = 'ws://127.0.0.1:13';

/**
 * A reader that answers from `$events`, `answered` relays reaching EOSE, and
 * counts how often it was asked.
 */
function fakeDmLookup(array $events, ?int $answered = null): RelayReader
{
    $reader = new class extends RelayReader
    {
        public array $events = [];

        /** Relays that answered to EOSE; null: every relay asked. */
        public ?int $answered = null;

        public int $asked = 0;

        public function fetchCounted(array $filters, ?array $relays = null, array $known = [], int $perAuthor = 1, ?float $until = null): array
        {
            $this->asked++;

            return ['events' => $this->events, 'answered' => $this->answered ?? count($relays ?? [])];
        }
    };

    $reader->events = array_map(fn (array $event) => SignedEvent::fromInput($event), $events);
    $reader->answered = $answered;
    app()->instance(RelayReader::class, $reader);

    return $reader;
}

/**
 * @return array<string, string> relay => message of every delivery of this event
 */
function deliveriesOf(NostrEvent $event): array
{
    return RelayDelivery::query()->where('nostr_event_id', $event->id)->pluck('message', 'relay')->all();
}

beforeEach(function () {
    $this->notificationKey = bin2hex(random_bytes(32));
    config([
        'esports.notifications.nsec' => $this->notificationKey,
        'esports.chat.relays' => [DM_CHAT_RELAY],
        'esports.profile_relays' => ['ws://127.0.0.1:15'],
        'esports.relay_timeout_seconds' => 1,
        // The DM relays of these tests are local ports; RelayGuard reaches only these as they are.
        'esports.wallet.nwc_insecure_relays' => ['127.0.0.1:11', '127.0.0.1:13'],
    ]);
    $this->player = new TestSigner;
    $this->user = User::factory()->withPubkey($this->player->pubkey)->create();
});

test('a player without a DM relay list gets a NIP-04 DM on their inbox relays, readable with their key', function () {
    fakeDmLookup([$this->player->sign(10002, [['r', DM_INBOX_RELAY, 'read'], ['r', 'ws://127.0.0.1:17', 'write']])]);

    $delivery = NotificationDm::fromConfig()->deliver($this->user, "Your move in daily chess #12\nhttps://esports.test/games/12", 12);
    $event = SignedEvent::fromInput($delivery->event->payload());

    expect($delivery->format)->toBe('nip04')
        ->and($event->kind)->toBe(4)
        ->and($event->pubkey)->toBe(NotificationDm::fromConfig()->pubkey())
        ->and($event->tags)->toBe([['p', $this->player->pubkey], ['match', '12']])
        ->and($event->hasValidSignature())->toBeTrue()
        ->and(NwcCipher::decrypt(NwcCipher::NIP04, $event->content, $this->player->secret, $event->pubkey))->toBe("Your move in daily chess #12\nhttps://esports.test/games/12")
        // The inbox (read) relay and the chat relay; never the write-only relay.
        ->and(array_keys(deliveriesOf($delivery->event)))->toEqualCanonicalizing([DM_CHAT_RELAY, DM_INBOX_RELAY]);
});

test('a player with a DM relay list gets a NIP-17 gift wrap on those relays', function () {
    fakeDmLookup([
        $this->player->sign(10050, [['relay', DM_PLAYER_RELAY]]),
        $this->player->sign(10002, [['r', DM_INBOX_RELAY]]),
    ]);

    $delivery = NotificationDm::fromConfig()->deliver($this->user, 'A challenge', 3);
    $wrap = SignedEvent::fromInput($delivery->event->payload());
    $seal = json_decode(Nip44::decrypt($wrap->content, Nip44::getConversationKey($this->player->secret, $wrap->pubkey)), true);

    expect($delivery->format)->toBe('nip17')
        ->and($wrap->kind)->toBe(1059)
        ->and($seal['pubkey'])->toBe(NotificationDm::fromConfig()->pubkey())
        ->and(array_keys(deliveriesOf($delivery->event)))->toEqualCanonicalizing([DM_CHAT_RELAY, DM_PLAYER_RELAY]);
});

test('a lookup no relay answered is no missing list: the DM stays NIP-17 on the chat relays', function () {
    fakeDmLookup([], answered: 0);

    $delivery = NotificationDm::fromConfig()->deliver($this->user, 'A challenge');

    expect($delivery->format)->toBe('nip17')
        ->and($delivery->event->kind)->toBe(1059)
        ->and(array_keys(deliveriesOf($delivery->event)))->toBe([DM_CHAT_RELAY]);
});

test('audit F3: one of two lookup relays silent and no list seen: NIP-17, not NIP-04, and nothing is remembered', function () {
    $reader = fakeDmLookup([$this->player->sign(10002, [['r', DM_INBOX_RELAY, 'read']])], answered: 1);

    $route = app(DmRelays::class)->for($this->player->pubkey);
    $delivery = NotificationDm::fromConfig()->deliver($this->user, 'A challenge');

    expect($route->known)->toBeFalse()
        ->and($route->format())->toBe('nip17')
        ->and($delivery->format)->toBe('nip17')
        ->and($delivery->event->kind)->toBe(1059)
        ->and($reader->asked)->toBe(2);
});

test('audit F3: a DM relay list found in a partial answer is used', function () {
    fakeDmLookup([$this->player->sign(10050, [['relay', DM_PLAYER_RELAY]])], answered: 1);

    $delivery = NotificationDm::fromConfig()->deliver($this->user, 'A challenge');

    expect($delivery->format)->toBe('nip17')
        ->and(array_keys(deliveriesOf($delivery->event)))->toEqualCanonicalizing([DM_CHAT_RELAY, DM_PLAYER_RELAY]);
});

test('the newest DM relay list counts, even when an older one had relays', function () {
    fakeDmLookup([
        $this->player->sign(10050, [['relay', DM_PLAYER_RELAY]], createdAt: now()->getTimestamp() - 60),
        $this->player->sign(10050, [], createdAt: now()->getTimestamp()),
    ]);

    expect(app(DmRelays::class)->for($this->player->pubkey)->format())->toBe('nip04');
});

test('a relay the player names that is not public is never contacted', function () {
    fakeDmLookup([$this->player->sign(10050, [['relay', 'ws://10.0.0.5:6379'], ['relay', DM_PLAYER_RELAY]])]);

    $delivery = NotificationDm::fromConfig()->deliver($this->user, 'A challenge');
    $messages = deliveriesOf($delivery->event);

    expect($messages['ws://10.0.0.5:6379'])->toBe('error: relay not allowed')
        ->and($messages[DM_PLAYER_RELAY])->not->toBe('error: relay not allowed')->toStartWith('error: ');
});

test('the lookup is remembered for a while, and the test DM asks afresh', function () {
    $reader = fakeDmLookup([$this->player->sign(10050, [['relay', DM_PLAYER_RELAY]])]);

    app(DmRelays::class)->for($this->player->pubkey);
    app(DmRelays::class)->for($this->player->pubkey);
    expect($reader->asked)->toBe(1);

    app(DmRelays::class)->for($this->player->pubkey, fresh: true);
    expect($reader->asked)->toBe(2);

    // An unanswered lookup is not remembered.
    $other = new TestSigner;
    $reader->answered = 0;
    app(DmRelays::class)->for($other->pubkey);
    app(DmRelays::class)->for($other->pubkey);
    expect($reader->asked)->toBe(4);
});

test('"Send a test DM" in the settings queues a test DM, and the page shows how it went', function () {
    Queue::fake([SendNostrDm::class]);

    $page = Livewire::actingAs($this->user)->test('pages::settings.notifications')
        ->assertSeeHtml('data-test="send-test-dm"')
        ->call('sendTestDm')
        ->assertSeeHtml('data-state="pending"');

    $job = Queue::pushed(SendNostrDm::class)->first();
    expect($job->test)->toBeTrue()->and($job->user->is($this->user))->toBeTrue()
        ->and($job->text)->toContain('Test DM from TWENTY ONE esports')->toContain('/notifications/dm/'.$this->user->id);

    // No relay list: NIP-04, and the page says so.
    fakeDmLookup([]);
    $job->handle();

    // The relays here refuse every connection: the page says nobody took it.
    $page->call('$refresh')->assertOk()->assertSeeHtml('data-state="done" data-format="nip04"')
        ->assertSee('No relay took it.');

    // Taken by one relay of two, as NIP-04 and as NIP-17.
    Cache::put(SendNostrDm::testResultKey($this->user), ['state' => 'done', 'format' => 'nip04', 'accepted' => 1, 'asked' => 2, 'at' => now()->getTimestamp()]);
    $page->call('$refresh')->assertSee('because you have no DM relay list (kind 10050): 1 of 2 relays took it');
    Cache::put(SendNostrDm::testResultKey($this->user), ['state' => 'done', 'format' => 'nip17', 'accepted' => 2, 'asked' => 2, 'at' => now()->getTimestamp()]);
    $page->call('$refresh')->assertSee('Sent as a NIP-17 DM to your DM relays: 2 of 2 relays took it');

    // One a minute.
    $page->call('sendTestDm')->assertHasErrors('testDm');
    expect(Queue::pushed(SendNostrDm::class))->toHaveCount(1);
});

test('the test DM button is not offered without a notification key', function () {
    config(['esports.notifications.nsec' => null]);

    Livewire::actingAs($this->user)->test('pages::settings.notifications')
        ->assertDontSeeHtml('data-test="send-test-dm"');
});

test('the notification DM of a match still carries NIP-17 match tags through the new path', function () {
    fakeDmLookup([$this->player->sign(10050, [['relay', DM_PLAYER_RELAY]])]);

    $wrap = NotificationDm::fromConfig()->send($this->user, 'x', 7);

    expect($wrap)->toBeInstanceOf(NostrEvent::class)->and($wrap->kind)->toBe(1059)
        ->and(NostrKeys::isHexPubkey(SignedEvent::fromInput($wrap->payload())->pubkey))->toBeTrue();
});
