<?php

use App\Models\Clan;
use App\Models\NostrEvent;
use App\Models\RelayDelivery;
use App\Models\User;
use App\Support\Clans\ClanDraft;
use App\Support\Clans\ClanService;
use App\Support\Nostr\EsportsEventRules;
use App\Support\Nostr\RelayPublisher;
use App\Support\Nostr\SignedEvent;
use Tests\Support\TestSigner;
use WebSocket\Client;
use WebSocket\Message\Text;

/**
 * Read one event back from a relay by id (REQ ... EOSE).
 *
 * @return array<string, mixed>|null
 */
function readBack(string $relay, string $id): ?array
{
    $client = new Client($relay);
    $client->setTimeout(5);
    $client->text(json_encode(['REQ', 'readback', ['ids' => [$id]]]));

    try {
        while (true) {
            $frame = $client->receive();
            $message = $frame instanceof Text ? json_decode($frame->getContent(), true) : null;

            if (($message[0] ?? null) === 'EVENT') {
                return $message[2];
            }

            if (($message[0] ?? null) === 'EOSE') {
                return null;
            }
        }
    } finally {
        $client->disconnect();
    }
}

test('founding a clan publishes a valid clan event to the ndak relays, and each relay returns it', function () {
    $relays = ['ws://127.0.0.1:7777', 'ws://127.0.0.1:7780', 'ws://127.0.0.1:7782'];
    config(['esports.relays' => $relays, 'queue.default' => 'sync']);

    $signer = new TestSigner;
    $owner = User::factory()->withPubkey($signer->pubkey)->create();
    $draft = new ClanDraft('Relay Probe '.substr($signer->pubkey, 0, 6), 'R'.strtoupper(substr($signer->pubkey, 0, 3)), 'P4 relay test');
    $service = app(ClanService::class);

    $service->create($owner, $draft, $signer->signTemplates($service->prepareCreate($owner, $draft)));

    $stored = NostrEvent::query()->where('kind', Clan::KIND)->sole();

    expect(RelayDelivery::query()->where('nostr_event_id', $stored->id)->pluck('accepted', 'relay')->all())
        ->toBe(array_fill_keys($relays, true));

    foreach ($relays as $relay) {
        $event = SignedEvent::fromInput(readBack($relay, $stored->event_id));

        expect($event)->not->toBeNull("{$relay} did not return the event")
            ->and($event->hasValidSignature())->toBeTrue()
            ->and(app(EsportsEventRules::class)->check($event))->toBeNull()
            ->and($event->toJson())->toBe($stored->raw);
    }

    // A relay that refuses is recorded as refusing: same id, changed content, so the id no longer matches.
    $forged = NostrEvent::query()->create([...$stored->only(['pubkey', 'kind', 'd', 'signed_at']),
        'event_id' => $stored->event_id.'-forged',
        'raw' => str_replace('"content":"P4 relay test"', '"content":"forged"', $stored->raw),
    ]);
    $forged->event_id = $stored->event_id;

    $results = app(RelayPublisher::class)->publish($forged);

    expect(array_column($results, 'accepted'))->toBe([false, false, false])
        ->and(RelayDelivery::query()->where('nostr_event_id', $forged->id)->where('accepted', false)->count())->toBe(3);
});
