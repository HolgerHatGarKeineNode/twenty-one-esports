<?php

use App\Jobs\PublishNostrEvent;
use App\Models\ChessGame;
use App\Models\NostrEvent;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessPgn;
use App\Support\Chess\GameRecords;
use App\Support\Chess\PresenceLookup;
use App\Support\Nostr\EsportsEventRules;
use App\Support\Nostr\RelayReader;
use App\Support\Nostr\SignedEvent;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\Support\TestSigner;

beforeEach(function () {
    $this->freezeTime();
    Bus::fake([PublishNostrEvent::class]);
});

/**
 * The league key of these tests, as `esports.league.nsec`.
 */
function recordLeague(): TestSigner
{
    $league = new TestSigner;
    config(['esports.league.nsec' => $league->secret]);

    return $league;
}

/** The ladder a rated test game is pinned to. */
function recordLadder(string $mode): string
{
    return '32152:'.str_repeat('8', 64).':chess/'.$mode.'/season-1';
}

/**
 * Fool's mate: Black mates on move 2. Rated by default (pinned to a ladder,
 * as the rated queue does), casual on request.
 *
 * @return array{0: ChessGame, 1: User, 2: TestSigner, 3: User, 4: TestSigner}
 */
function foolsMate(string $mode = 'blitz', bool $rated = true): array
{
    $whiteKey = new TestSigner;
    $blackKey = new TestSigner;
    $white = User::factory()->withPubkey($whiteKey->pubkey)->create(['name' => 'anna']);
    $black = User::factory()->withPubkey($blackKey->pubkey)->create(['name' => 'bert']);
    $games = app(ChessGameService::class);
    $game = $games->start($white, $black, $mode);

    if ($rated) {
        $game->forceFill(['rated' => true, 'ladder_address' => recordLadder($mode), 'pgn_headers' => null])->save();
        $game->forceFill(['pgn_headers' => ChessPgn::headersFor($game)])->save();
    }

    foreach (['f2f3', 'e7e5', 'g2g4', 'd8h4'] as $uci) {
        $game = $games->move($game->refresh(), $game->turn() === 'w' ? $white : $black, $uci);
    }

    return [$game->refresh(), $white, $whiteKey, $black, $blackKey];
}

test('a finished rated game gets exactly one NIP-64 record, signed by the league when it ends; no player signs anything', function (string $mode, string $event, string $timeControl) {
    $league = recordLeague();
    [$game, $white, , $black] = foolsMate($mode);
    $stored = $game->recordEvent;
    $record = SignedEvent::fromInput($stored->payload());

    // NIP-64: kind 64, PGN in export format (Seven Tag Roster first, in order), result as terminator.
    expect($record->kind)->toBe(64)
        ->and($record->pubkey)->toBe($league->pubkey)
        ->and($record->hasValidSignature())->toBeTrue()
        ->and($record->tagsNamed('p'))->toBe([[$white->pubkey, '', 'white'], [$black->pubkey, '', 'black']])
        ->and($record->tagsNamed('e'))->toBe([])
        ->and($record->tagsNamed('a'))->toBe([[recordLadder($mode), '']])
        ->and($record->tagsNamed('t'))->toBe([])
        ->and($record->tag('alt'))->toContain('anna vs bert, 0-1')
        ->and(array_slice(explode("\n", $record->content), 0, 7))->toBe([
            '[Event "'.$event.'"]',
            '[Site "'.route('games.show', $game).'"]',
            '[Date "'.now()->utc()->format('Y.m.d').'"]',
            '[Round "-"]',
            '[White "anna"]',
            '[Black "bert"]',
            '[Result "0-1"]',
        ])
        ->and($record->content)->toContain('[TimeControl "'.$timeControl.'"]')->toContain('[Termination "normal"]')->toEndWith("1. f3 e5 2. g4 Qh4# 0-1\n")
        ->and(NostrEvent::query()->where('kind', 64)->pluck('pubkey')->all())->toBe([$league->pubkey]);

    Bus::assertDispatched(PublishNostrEvent::class, fn (PublishNostrEvent $job) => $job->event->is($stored));
    Bus::assertDispatchedTimes(PublishNostrEvent::class, 1);

    // Idempotent: the game has its record; every later call and the clock sweep add none.
    expect(app(GameRecords::class)->recordFinished($game))->toBeNull()
        ->and(app(ChessGameService::class)->checkClock($game)->record_event_id)->toBe($stored->id);
    $this->artisan('chess:check-clocks')->assertSuccessful();

    expect(NostrEvent::query()->where('kind', 64)->count())->toBe(1);
})->with([
    'blitz' => ['blitz', 'TWENTY ONE esports, rated blitz', '300+3'],
    'daily' => [ChessGame::CORRESPONDENCE, 'TWENTY ONE esports, rated daily chess', '1/86400'],
]);

test('a casual game gets no league record, in either mode: the league\'s profile carries rated games only', function (string $mode) {
    recordLeague();
    [$game] = foolsMate($mode, rated: false);

    expect($game->result)->toBe('0-1')
        ->and($game->record_event_id)->toBeNull()
        ->and(NostrEvent::query()->count())->toBe(0);

    Bus::assertNotDispatched(PublishNostrEvent::class);
})->with(['blitz' => 'blitz', 'daily' => ChessGame::CORRESPONDENCE]);

test('a rated resignation, flag and agreed draw are recorded; an abort and a game without a move are not; without a league key the result stands unrecorded', function () {
    $league = recordLeague();
    $games = app(ChessGameService::class);

    $resigned = ChessGame::factory()->create(['rated' => true]);
    $games->move($resigned, $resigned->white, 'e2e4');
    $games->resign($resigned->refresh(), $resigned->white);

    $drawn = ChessGame::factory()->daily()->create(['rated' => true]);
    $games->move($drawn, $drawn->white, 'e2e4');
    $games->offerDraw($drawn->refresh(), $drawn->black);
    $games->acceptDraw($drawn->refresh(), $drawn->white);

    $flagged = ChessGame::factory()->daily()->create(['rated' => true]);
    $games->move($flagged, $flagged->white, 'e2e4');
    $games->move($flagged->refresh(), $flagged->black, 'e7e5');
    $this->travel(86_401)->seconds();
    $this->artisan('chess:check-clocks')->assertSuccessful();

    $aborted = ChessGame::factory()->create(['rated' => true]);
    $games->abort($aborted, $aborted->white);

    expect($resigned->refresh()->recordEvent?->pubkey)->toBe($league->pubkey)
        ->and($resigned->recordEvent->payload()['content'])->toContain('[Result "0-1"]')->toEndWith("1. e4 0-1\n")
        ->and($drawn->refresh()->recordEvent?->payload()['content'])->toContain('[Result "1/2-1/2"]')
        ->and($flagged->refresh()->recordEvent?->payload()['content'])->toContain('[Termination "time forfeit"]')->toEndWith("1. e4 e5 0-1\n")
        ->and($aborted->refresh()->record_event_id)->toBeNull();

    config(['esports.league.nsec' => null]);
    $unkeyed = ChessGame::factory()->create(['rated' => true]);
    $games->move($unkeyed, $unkeyed->white, 'e2e4');
    $games->resign($unkeyed->refresh(), $unkeyed->black);

    expect($unkeyed->refresh()->result)->toBe('1-0')
        ->and($unkeyed->record_event_id)->toBeNull()
        ->and(NostrEvent::query()->where('kind', 64)->count())->toBe(3);
});

test('a player posts the game to their profile only by button: the preview is the exact note, signed by them, once', function () {
    $league = recordLeague();
    [$game, $white, $whiteKey, $black, $blackKey] = foolsMate(ChessGame::CORRESPONDENCE);
    $spectator = User::factory()->create();
    $record = $game->recordEvent;

    // Never automatic: the game is over and not one player-signed note exists.
    expect(NostrEvent::query()->where('kind', 64)->where('pubkey', '!=', $league->pubkey)->count())->toBe(0);

    Livewire::actingAs($spectator)->test('pages::games.show', ['game' => $game])
        ->call('prepareGamePost')->assertReturned(['ok' => false, 'error' => 'not_a_player', 'template' => null]);

    $page = Livewire::actingAs($black)->test('pages::games.show', ['game' => $game]);
    $template = $page->call('prepareGamePost')->effects['returns'][0]['template'];

    expect($template['kind'])->toBe(64)
        ->and($template['content'])->toBe($record->payload()['content'])
        ->and($template['tags'])->toBe([
            ['p', $white->pubkey, '', 'white'],
            ['p', $black->pubkey, '', 'black'],
            ['q', $record->event_id, '', $league->pubkey],
            ['alt', 'Chess game '.$game->number().' (daily): anna vs bert, 0-1 (NIP-64 PGN)'],
        ]);

    // A doctored note, or one signed by the other player, posts nothing.
    [$doctored] = $blackKey->signTemplates([[...$template, 'content' => str_replace('0-1', '1-0', $template['content'])]]);
    [$foreign] = $whiteKey->signTemplates([$template]);
    $page->call('submitGamePost', json_encode($doctored))->assertReturned(fn (array $r) => $r['error'] === 'signature_rejected');
    $page->call('submitGamePost', json_encode($foreign))->assertReturned(fn (array $r) => $r['error'] === 'signature_rejected');

    expect($game->refresh()->black_post_event_id)->toBeNull();

    [$signed] = $blackKey->signTemplates([$template]);
    $page->call('submitGamePost', json_encode($signed))->assertReturned(fn (array $r) => $r['ok'] === true && $r['state']['posted'] === ['w' => false, 'b' => true]);

    $post = NostrEvent::query()->findOrFail($game->refresh()->black_post_event_id);

    expect($post->pubkey)->toBe($black->pubkey)
        ->and($post->kind)->toBe(64)
        ->and(app(EsportsEventRules::class)->check(SignedEvent::fromInput($post->payload())))->toBeNull()
        ->and($game->white_post_event_id)->toBeNull()
        ->and($game->record_event_id)->toBe($record->id);

    Bus::assertDispatched(PublishNostrEvent::class, fn (PublishNostrEvent $job) => $job->event->is($post));

    // Once per player: a second preview and a second post are refused.
    [$again] = $blackKey->signTemplates([[...$template, 'created_at' => $template['created_at'] + 1]]);
    $page->call('prepareGamePost')->assertReturned(['ok' => false, 'error' => 'already_posted', 'template' => null]);
    $page->call('submitGamePost', json_encode($again))->assertReturned(fn (array $r) => $r['error'] === 'already_posted');

    expect(NostrEvent::query()->where('kind', 64)->where('pubkey', $black->pubkey)->count())->toBe(1);
});

test('a casual game\'s post stands alone: the same PGN, signed by the player, without a quote', function () {
    recordLeague();
    [$game, $white, $whiteKey] = foolsMate(ChessGame::CORRESPONDENCE, rated: false);
    $page = Livewire::actingAs($white)->test('pages::games.show', ['game' => $game]);
    $template = $page->call('prepareGamePost')->effects['returns'][0]['template'];

    expect($template['content'])->toBe(ChessPgn::of($game->load('moves')))
        ->and($template['content'])->toEndWith("1. f3 e5 2. g4 Qh4# 0-1\n")->toStartWith('[Event "TWENTY ONE esports, casual daily chess"]')
        ->and(array_column($template['tags'], 0))->toBe(['p', 'p', 'alt']);

    [$signed] = $whiteKey->signTemplates([$template]);
    $page->call('submitGamePost', json_encode($signed))->assertReturned(fn (array $r) => $r['ok'] === true);

    $post = SignedEvent::fromInput(NostrEvent::query()->findOrFail($game->refresh()->white_post_event_id)->payload());

    expect($post->pubkey)->toBe($white->pubkey)
        ->and(app(EsportsEventRules::class)->check($post))->toBeNull()
        ->and($game->record_event_id)->toBeNull()
        ->and(NostrEvent::query()->where('kind', 64)->count())->toBe(1);

    Livewire::actingAs($white)->test('pages::games.show', ['game' => $game])
        ->assertSee(__('Casual games get no record from the league. The result is saved on the server, and you can post the game yourself.'));
});

test('a running or aborted game cannot be posted, and the finished page offers the post only to its players', function () {
    recordLeague();
    $running = ChessGame::factory()->daily()->create();
    app(ChessGameService::class)->move($running, $running->white, 'e2e4');
    $aborted = ChessGame::factory()->create();
    app(ChessGameService::class)->abort($aborted, $aborted->white);
    [$finished, $white] = foolsMate();

    Livewire::actingAs($running->white)->test('pages::games.show', ['game' => $running])
        ->call('prepareGamePost')->assertReturned(fn (array $r) => $r['error'] === 'not_finished');
    Livewire::actingAs($aborted->white)->test('pages::games.show', ['game' => $aborted])
        ->call('prepareGamePost')->assertReturned(fn (array $r) => $r['error'] === 'not_finished')
        ->assertDontSeeHtml('data-test="game-post"');

    Livewire::actingAs($white)->test('pages::games.show', ['game' => $finished])
        ->assertSeeHtml('data-test="game-post"')
        ->assertSee(__('Post this game to my profile'))
        ->assertDontSeeHtml('chessPublishRecord');
    Livewire::actingAs(User::factory()->create())->test('pages::games.show', ['game' => $finished])
        ->assertDontSeeHtml('data-test="game-post"');

    // A page opened before the change asks for a record to sign at the end: there is none.
    Livewire::actingAs($white)->test('pages::games.show', ['game' => $finished])->call('recordTemplate')->assertReturned(null);
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

test('the current version of an addressable event of any age reaches a relay that never took it; older versions stay home', function () {
    [$old, $new] = ['ws://127.0.0.1:9', 'ws://127.0.0.1:19'];
    $signer = new TestSigner;
    $first = NostrEvent::fromSigned(SignedEvent::fromInput($signer->sign(31923, [['d', 'cup-1']], 'v1')));
    $first->forceFill(['queued_at' => now()])->save();
    $this->travel(1)->seconds();
    $latest = NostrEvent::fromSigned(SignedEvent::fromInput($signer->sign(31923, [['d', 'cup-1']], 'v2')));
    $latest->forceFill(['queued_at' => now()])->save();
    $latest->deliveries()->create(['relay' => $old, 'accepted' => true, 'message' => '', 'attempted_at' => now()]);

    // Signed while the relay list was thin; a week later a second relay is configured.
    $this->travel(7)->days();
    config(['esports.relays' => [$old, $new]]);
    $this->artisan('nostr:republish')->expectsOutput('Republished 1 event(s).')->assertSuccessful();

    expect($latest->deliveries()->where('relay', $new)->exists())->toBeTrue()
        ->and($first->deliveries()->count())->toBe(0);
});

test('the kind-64 filters of the NIP\'s query table find the league\'s record and the player\'s post, and nothing else', function () {
    $league = recordLeague();
    [$game, $white, , $black, $blackKey] = foolsMate(ChessGame::CORRESPONDENCE);
    $records = app(GameRecords::class);
    [$signed] = $blackKey->signTemplates([$records->postTemplate($game, $black)]);
    $records->submitPost($game, $black, json_encode($signed));
    $record = SignedEvent::fromInput($game->refresh()->recordEvent->payload());
    $post = SignedEvent::fromInput(NostrEvent::query()->findOrFail($game->black_post_event_id)->payload());

    // A move chain signed before rev. 9.4: the second note points at the first.
    $whiteKey = new TestSigner;
    $first = SignedEvent::fromInput($whiteKey->sign(64, [['p', $white->pubkey, '', 'white'], ['alt', 'move 1']], '[Event "?"]'));
    $second = SignedEvent::fromInput($blackKey->sign(64, [['e', $first->id], ['alt', 'move 2']], '[Event "?"]'));

    $nip = (string) file_get_contents(base_path('docs/nips/esports.md'));
    $table = str($nip)->after("\n## Queries\n")->before("\n## ")->toString();
    $filters = [];
    foreach (explode("\n", $table) as $line) {
        if (str_contains($line, '"kinds":[64]') && preg_match('/^\| (.+?) \| `(\{.+?\})`/', $line, $row) === 1) {
            $filters[$row[1]] = $row[2];
        }
    }

    expect(array_keys($filters))->toBe([
        'the game records of a chess match (all boards)',
        'a player\'s chess game records (rev. 9.4)',
        'the posts of one game record by its players (rev. 9.4)',
        'the moves of a correspondence game before rev. 9.4',
    ])->and($table)->not->toContain('the next move of a correspondence game');

    $fill = fn (string $filter, array $values): array => json_decode(strtr($filter, $values), true, flags: JSON_THROW_ON_ERROR);
    $matches = new ReflectionMethod(RelayReader::class, 'matches');
    $found = fn (array $filter) => array_values(array_map(
        fn (SignedEvent $event) => $event->id,
        array_filter([$record, $post, $first, $second], fn (SignedEvent $event) => $matches->invoke(null, $event, $filter)),
    ));

    expect($found($fill($filters['a player\'s chess game records (rev. 9.4)'], ['<league>' => $league->pubkey, '<player>' => $black->pubkey])))->toBe([$record->id])
        ->and($found($fill($filters['the posts of one game record by its players (rev. 9.4)'], ['<record id>' => $record->id])))->toBe([$post->id])
        ->and($found($fill($filters['the moves of a correspondence game before rev. 9.4'], ['<id of a move note>' => $first->id])))->toBe([$second->id])
        // A casual game has no challenge: the match filter finds nothing of it, and never a post.
        ->and($found($fill($filters['the game records of a chess match (all boards)'], ['<league>' => $league->pubkey, '<challenge id>' => str_repeat('a', 64)])))->toBe([]);
});
