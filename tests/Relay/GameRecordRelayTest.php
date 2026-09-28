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

test('the league\'s NIP-64 records of a blitz and a daily game, and a player\'s post of one, reach every ndak relay and come back signed and unchanged', function () {
    $league = new TestSigner;
    config(['esports.league.nsec' => $league->secret]);
    $whiteKey = new TestSigner;
    $blackKey = new TestSigner;
    $white = User::factory()->withPubkey($whiteKey->pubkey)->create();
    $black = User::factory()->withPubkey($blackKey->pubkey)->create();
    $games = app(ChessGameService::class);
    $records = app(GameRecords::class);

    // Fool's mate twice, rated blitz and daily: the moves are no events, the league signs each rated game's record at the end.
    foreach (['blitz', ChessGame::CORRESPONDENCE] as $mode) {
        $game = $games->start($white, $black, $mode);
        $game->forceFill(['rated' => true, 'ladder_address' => '32152:'.$league->pubkey.':chess/'.$mode.'/season-1'])->save();
        foreach (['f2f3', 'e7e5', 'g2g4', 'd8h4'] as $uci) {
            $games->move($game->refresh(), $game->turn() === 'w' ? $white : $black, $uci);
        }
    }

    // Black posts the daily game to their profile (the button, NIP rev. 9.4).
    [$post] = $blackKey->signTemplates([$records->postTemplate($game->refresh(), $black)]);
    $records->submitPost($game, $black, json_encode($post));

    $stored = NostrEvent::query()->where('kind', 64)->orderBy('id')->get();
    expect($stored->pluck('pubkey')->all())->toBe([$league->pubkey, $league->pubkey, $black->pubkey]);

    foreach ($stored as $event) {
        expect(RelayDelivery::query()->where('nostr_event_id', $event->id)->pluck('accepted', 'relay')->all())
            ->toBe(array_fill_keys(NDAK_RELAYS, true));

        foreach (NDAK_RELAYS as $relay) {
            $back = SignedEvent::fromInput(readBack($relay, $event->event_id));

            expect($back)->not->toBeNull("{$relay} did not return {$event->event_id}")
                ->and($back->hasValidSignature())->toBeTrue()
                ->and($back->toJson())->toBe($event->raw);
        }
    }

    expect(app(EsportsEventRules::class)->check(SignedEvent::fromInput($stored[2]->payload())))->toBeNull()
        ->and(SignedEvent::fromInput($stored[2]->payload())->tagsNamed('q'))->toBe([[$stored[1]->event_id, '', $league->pubkey]]);
});

test('a notification DM (gift wrap from the notification key) is accepted by the ndak relays', function () {
    config(['esports.notifications.nsec' => bin2hex(random_bytes(32))]);
    $player = User::factory()->withPubkey((new TestSigner)->pubkey)->create();

    $wrap = NotificationDm::fromConfig()->send($player, 'Your move in daily chess #1', 1);

    expect($wrap->kind)->toBe(1059)
        ->and(RelayDelivery::query()->where('nostr_event_id', $wrap->id)->pluck('accepted', 'relay')->all())
        ->toBe(array_fill_keys(NDAK_RELAYS, true));
});
