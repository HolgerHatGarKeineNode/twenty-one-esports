<?php

use App\Models\ChessGame;
use App\Models\NostrEvent;
use App\Models\RelayDelivery;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\GameRecords;
use App\Support\Nostr\EsportsEventRules;
use App\Support\Nostr\SignedEvent;
use App\Support\Notifications\NotificationDm;
use Tests\Support\TestSigner;

/*
 * Against the local ndak relays (rnostr 7777, strfry 7780, khatru 7782);
 * excluded by default, run with `vendor/bin/pest --group=relay`. Never
 * against public relays. readBack() comes from tests/Relay/ClanEventRelayTest.php.
 */
const NDAK_RELAYS = ['ws://127.0.0.1:7777', 'ws://127.0.0.1:7780', 'ws://127.0.0.1:7782'];

beforeEach(fn () => config(['esports.relays' => NDAK_RELAYS, 'esports.chat.relays' => NDAK_RELAYS, 'queue.default' => 'sync']));

test('the NIP-64 records of a blitz game and of a daily game reach every ndak relay and come back signed and unchanged', function () {
    $whiteKey = new TestSigner;
    $blackKey = new TestSigner;
    $white = User::factory()->withPubkey($whiteKey->pubkey)->create();
    $black = User::factory()->withPubkey($blackKey->pubkey)->create();
    $games = app(ChessGameService::class);
    $records = app(GameRecords::class);

    // Blitz: fool's mate, then White's app signs the final record.
    $blitz = $games->start($white, $black);
    foreach (['f2f3', 'e7e5', 'g2g4', 'd8h4'] as $uci) {
        $games->move($blitz->refresh(), $blitz->turn() === 'w' ? $white : $black, $uci);
    }
    [$signed] = $whiteKey->signTemplates([$records->finalTemplate($blitz->refresh())]);
    $records->submitFinal($blitz, $white, json_encode($signed));

    // Daily: two moves, each its own note, the second chained to the first.
    $daily = $games->start($white, $black, ChessGame::CORRESPONDENCE);
    foreach ([[$white, $whiteKey, 'e2e4'], [$black, $blackKey, 'e7e5']] as [$player, $key, $uci]) {
        $ply = $daily->refresh()->ply + 1;
        [$note] = $key->signTemplates([$records->prepareMove($daily, $player, $uci, $ply)['template']]);
        $records->playSigned($daily, $player, $uci, $ply, json_encode($note));
    }

    $stored = NostrEvent::query()->where('kind', 64)->orderBy('id')->get();
    expect($stored)->toHaveCount(3);

    foreach ($stored as $event) {
        expect(RelayDelivery::query()->where('nostr_event_id', $event->id)->pluck('accepted', 'relay')->all())
            ->toBe(array_fill_keys(NDAK_RELAYS, true));

        foreach (NDAK_RELAYS as $relay) {
            $back = SignedEvent::fromInput(readBack($relay, $event->event_id));

            expect($back)->not->toBeNull("{$relay} did not return {$event->event_id}")
                ->and($back->hasValidSignature())->toBeTrue()
                ->and(app(EsportsEventRules::class)->check($back))->toBeNull()
                ->and($back->toJson())->toBe($event->raw);
        }
    }

    expect(SignedEvent::fromInput($stored[2]->payload())->tagsNamed('e'))->toBe([[$stored[1]->event_id]]);
});

test('a notification DM (gift wrap from the notification key) is accepted by the ndak relays', function () {
    config(['esports.notifications.nsec' => bin2hex(random_bytes(32))]);
    $player = User::factory()->withPubkey((new TestSigner)->pubkey)->create();

    $wrap = NotificationDm::fromConfig()->send($player, 'Your move in daily chess #1', 1);

    expect($wrap->kind)->toBe(1059)
        ->and(RelayDelivery::query()->where('nostr_event_id', $wrap->id)->pluck('accepted', 'relay')->all())
        ->toBe(array_fill_keys(NDAK_RELAYS, true));
});
