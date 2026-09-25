<?php

use App\Jobs\PublishNostrEvent;
use App\Models\ChessGame;
use App\Models\NostrEvent;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessRuleViolation;
use App\Support\Chess\GameRecords;
use App\Support\Chess\PresenceLookup;
use App\Support\Nostr\EsportsEventRules;
use App\Support\Nostr\SignedEvent;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\Support\TestSigner;

beforeEach(function () {
    $this->freezeTime();
    Bus::fake([PublishNostrEvent::class]);
});

test('a finished game gets exactly one NIP-64 record, signed by one of its players and published', function () {
    $whiteKey = new TestSigner;
    $blackKey = new TestSigner;
    $white = User::factory()->withPubkey($whiteKey->pubkey)->create(['name' => 'anna']);
    $black = User::factory()->withPubkey($blackKey->pubkey)->create(['name' => 'bert']);
    $games = app(ChessGameService::class);
    $records = app(GameRecords::class);

    $game = $games->start($white, $black);
    foreach (['f2f3', 'e7e5', 'g2g4', 'd8h4'] as $uci) {
        $game = $games->move($game->refresh(), $game->turn() === 'w' ? $white : $black, $uci);
    }

    $template = $records->finalTemplate($game->refresh());
    [$signed] = $blackKey->signTemplates([$template]);
    $stored = $records->submitFinal($game, $black, json_encode($signed));
    $event = SignedEvent::fromInput($stored->payload());

    // NIP-64: kind 64, PGN in export format (Seven Tag Roster first, in order), result as terminator.
    expect($event->kind)->toBe(64)
        ->and($event->pubkey)->toBe($black->pubkey)
        ->and($event->hasValidSignature())->toBeTrue()
        ->and(app(EsportsEventRules::class)->check($event))->toBeNull()
        ->and($event->tagsNamed('p'))->toBe([[$white->pubkey, '', 'white'], [$black->pubkey, '', 'black']])
        ->and($event->tagsNamed('e'))->toBe([])
        ->and($event->tagsNamed('a'))->toBe([])
        ->and($event->tag('alt'))->toContain('anna vs bert, 0-1')
        ->and(array_slice(explode("\n", $event->content), 0, 7))->toBe([
            '[Event "TWENTY ONE esports, casual blitz"]',
            '[Site "'.route('games.show', $game).'"]',
            '[Date "'.now()->utc()->format('Y.m.d').'"]',
            '[Round "-"]',
            '[White "anna"]',
            '[Black "bert"]',
            '[Result "0-1"]',
        ])
        ->and($event->content)->toContain('[TimeControl "300+3"]')->toContain('[Termination "normal"]')->toEndWith("1. f3 e5 2. g4 Qh4# 0-1\n")
        ->and($game->refresh()->record_event_id)->toBe($stored->id);

    Bus::assertDispatched(PublishNostrEvent::class, fn (PublishNostrEvent $job) => $job->event->is($stored));

    // The first valid record counts; the other player's later one is refused.
    [$late] = $whiteKey->signTemplates([$template]);
    expect(fn () => $records->submitFinal($game, $white, json_encode($late)))->toThrow(ChessRuleViolation::class)
        ->and(NostrEvent::query()->where('kind', 64)->count())->toBe(1)
        ->and($records->finalTemplate($game->refresh()))->toBeNull();
});

test('the page hands the record template only to players, and only once the game is over', function () {
    $game = ChessGame::factory()->create();
    $spectator = User::factory()->create();

    Livewire::actingAs($game->white)->test('pages::games.show', ['game' => $game])
        ->call('recordTemplate')->assertReturned(null);

    app(ChessGameService::class)->move($game, $game->white, 'e2e4');
    app(ChessGameService::class)->move($game->refresh(), $game->black, 'e7e5');
    app(ChessGameService::class)->resign($game->refresh(), $game->black);

    Livewire::actingAs($spectator)->test('pages::games.show', ['game' => $game])->call('recordTemplate')->assertReturned(null);
    Livewire::actingAs($game->white)->test('pages::games.show', ['game' => $game])
        ->call('recordTemplate')->assertReturned(fn (array $template) => $template['kind'] === 64 && str_contains($template['content'], '[Result "1-0"]'));
});

test('a disconnected opponent can be claimed against only after the timeout and only while gone', function () {
    config(['esports.chess.disconnect_claim_seconds' => 60]);
    $game = ChessGame::factory()->create();
    $games = app(ChessGameService::class);
    $games->move($game, $game->white, 'e2e4');
    $games->move($game->refresh(), $game->black, 'e7e5');

    $gone = true;
    $presence = Mockery::mock(PresenceLookup::class);
    $presence->shouldReceive('absent')->andReturnUsing(function () use (&$gone) {
        return $gone;
    });
    app()->instance(PresenceLookup::class, $presence);

    $claim = fn () => Livewire::actingAs($game->white)->test('pages::games.show', ['game' => $game])->call('claimWin');
    $claim()->assertReturned(fn (array $r) => $r['error'] === 'no_claim');

    Livewire::actingAs($game->white)->test('pages::games.show', ['game' => $game])->call('reportGone');
    $this->travel(59)->seconds();
    $claim()->assertReturned(fn (array $r) => $r['error'] === 'no_claim');

    // Back on the page: no claim, and the old mark is gone even once they leave again.
    $gone = false;
    $this->travel(5)->seconds();
    $claim()->assertReturned(fn (array $r) => $r['error'] === 'no_claim');
    Livewire::actingAs($game->black)->test('pages::games.show', ['game' => $game]);
    $gone = true;
    $claim()->assertReturned(fn (array $r) => $r['error'] === 'no_claim');

    // A new report starts the timer again.
    Livewire::actingAs($game->white)->test('pages::games.show', ['game' => $game])->call('reportGone');
    $this->travel(60)->seconds();

    $claim()->assertReturned(fn (array $r) => $r['ok'] === true && $r['state']['result'] === '1-0' && $r['state']['reason'] === 'abandoned');
});

test('a record a relay has not taken yet is sent again, to that relay only', function () {
    // Closed local ports: the relay "answers" at once with a refused connection.
    [$took, $missed] = ['ws://127.0.0.1:9', 'ws://127.0.0.1:19'];
    config(['esports.relays' => [$took, $missed]]);
    $event = NostrEvent::fromSigned(SignedEvent::fromInput((new TestSigner)->sign(64, [['alt', 'probe']], '[Event "?"]')));
    $event->deliveries()->create(['relay' => $took, 'accepted' => true, 'message' => '', 'attempted_at' => now()]);
    $event->deliveries()->create(['relay' => $missed, 'accepted' => false, 'message' => 'error: no OK before timeout', 'attempted_at' => now()]);

    $this->travel(2)->minutes();
    $this->artisan('nostr:republish')->expectsOutput('Republished 1 event(s).')->assertSuccessful();

    $attempts = $event->deliveries()->pluck('attempted_at', 'relay');

    expect($attempts[$took]->toDateTimeString())->toBe(now()->subMinutes(2)->toDateTimeString())
        ->and($attempts[$missed]->toDateTimeString())->toBe(now()->toDateTimeString());
});
