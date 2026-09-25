<?php

use App\Models\Clan;
use App\Models\Lineup;
use App\Models\NostrEvent;
use App\Models\RelayDelivery;
use App\Models\User;
use App\Support\Nostr\EsportsEventRules;
use App\Support\Nostr\SignedEvent;
use App\Support\Series\ChallengeDraft;
use App\Support\Series\SeriesService;
use Tests\Support\TestSigner;
use WebSocket\Client;
use WebSocket\Message\Text;

/*
 * The rated series flow (2150, 2151, 2152, 2153) against the local ndak
 * relays (rnostr 7777, strfry 7780, khatru 7782); excluded by default, run
 * with `vendor/bin/pest --group=relay`. Never against public relays.
 *
 * Rated play is off before Block 0; the ladder seam is set here to a
 * throwaway league key so the path P7 switches on is proven on real relays.
 */

/**
 * @return array<string, mixed>|null
 */
function seriesReadBack(string $relay, string $id): ?array
{
    $client = new Client($relay);
    $client->setTimeout(5);
    $client->text(json_encode(['REQ', 'series-readback', ['ids' => [$id]]]));

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

test('a rated series publishes 2150 to 2153 to every ndak relay, each comes back signed, unchanged and without the lobby', function () {
    $relays = ['ws://127.0.0.1:7777', 'ws://127.0.0.1:7780', 'ws://127.0.0.1:7782'];
    config([
        'esports.relays' => $relays,
        'queue.default' => 'sync',
        'esports.ladder' => ['league_pubkey' => (new TestSigner)->pubkey, 'season' => 'relay-probe'],
    ]);
    $series = app(SeriesService::class);

    $side = function (): array {
        $signer = new TestSigner;
        $captain = User::factory()->withPubkey($signer->pubkey)->create();
        $lineup = Lineup::factory()->mode('2v2')->ready()->create(['clan_id' => Clan::factory()->create(['owner_id' => $captain->id])->id]);

        return [$lineup, $captain, $signer];
    };
    [$lineupA, $captainA, $signerA] = $side();
    [$lineupB, $captainB, $signerB] = $side();

    $start = now()->addSeconds(61)->getTimestamp();
    $draft = new ChallengeDraft($lineupA->id, $lineupB->id, 3, true, [$start], $start, 'P6a relay probe');
    $match = $series->challenge($captainA, $draft, $signerA->signTemplates($series->prepareChallenge($captainA, $draft)['templates']));
    $series->answer($match, $captainB, 'accepted', $start, $signerB->signTemplates($series->prepareAnswer($match, $captainB, 'accepted', $start)));

    // Past the start, still within the relays' window for future timestamps.
    $this->travelTo(now()->setTimestamp($start)->addSeconds(30));
    $series->setLobby($match, $captainA, 'e21-relay-probe', 'relay-secret-pw', 'EU');
    $series->saveLiveGame($match, $captainA, 0, 3, 1, null);
    $series->saveLiveGame($match, $captainA, 1, null, null, 'challenger');
    $series->report($match, $captainA, $signerA->signTemplates($series->prepareReport($match, $captainA)));
    $series->respond($match, $captainB, 'confirmed', '', $signerB->signTemplates($series->prepareResponse($match, $captainB, 'confirmed')));

    $stored = NostrEvent::query()->orderBy('id')->get();
    expect($stored->pluck('kind')->all())->toBe([2150, 2151, 2152, 2153]);

    foreach ($stored as $event) {
        expect(RelayDelivery::query()->where('nostr_event_id', $event->id)->pluck('accepted', 'relay')->all())
            ->toBe(array_fill_keys($relays, true));

        foreach ($relays as $relay) {
            $back = SignedEvent::fromInput(seriesReadBack($relay, $event->event_id));

            expect($back)->not->toBeNull("{$relay} did not return kind {$event->kind} {$event->event_id}")
                ->and($back->hasValidSignature())->toBeTrue()
                ->and(app(EsportsEventRules::class)->check($back))->toBeNull()
                ->and($back->toJson())->toBe($event->raw)
                ->and($back->toJson())->not->toContain('relay-secret-pw')
                ->and($back->toJson())->not->toContain('e21-relay-probe');
        }
    }
});
