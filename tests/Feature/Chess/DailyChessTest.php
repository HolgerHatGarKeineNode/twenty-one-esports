<?php

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Enums\ChessInviteStatus;
use App\Jobs\PublishNostrEvent;
use App\Models\ChessGame;
use App\Models\NostrEvent;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessInvites;
use App\Support\Chess\ChessQueue;
use App\Support\Chess\ChessRuleViolation;
use App\Support\Chess\DailyChallenges;
use App\Support\Nostr\RejectedEvent;
use App\Support\Nostr\SignedEvent;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\Support\TestSigner;

beforeEach(function () {
    $this->freezeTime();
    Bus::fake([PublishNostrEvent::class]);
});

/**
 * Two players with real keys, so their notes can be signed.
 *
 * @return array{0: User, 1: TestSigner, 2: User, 3: TestSigner}
 */
function dailyPlayers(): array
{
    $a = new TestSigner;
    $b = new TestSigner;

    return [User::factory()->withPubkey($a->pubkey)->create(), $a, User::factory()->withPubkey($b->pubkey)->create(), $b];
}

/**
 * One daily move the way the page plays it (NIP rev. 9.4): no note, no
 * signature, the server checks and stores it.
 *
 * @return array{ok: bool, error: string|null, state: array<string, mixed>}
 */
function playDaily(ChessGame $game, User $user, string $uci, ?int $ply = null): array
{
    return Livewire::actingAs($user)->test('pages::games.show', ['game' => $game])
        ->call('playMove', $uci, $ply ?? $game->refresh()->ply + 1)
        ->effects['returns'][0];
}

function dailyRefusal(Closure $action): ?string
{
    try {
        $action();
    } catch (ChessRuleViolation|RejectedEvent $refused) {
        return $refused->reason;
    }

    return null;
}

test('a daily challenge starts a casual daily game with the chosen colours, accepted only by the challenged player', function () {
    [$anna, , $bert] = dailyPlayers();
    $challenges = app(DailyChallenges::class);

    $challenge = $challenges->challenge($anna, $bert, 'black', 'gl hf');

    expect(dailyRefusal(fn () => $challenges->challenge($bert, $anna)))->toBe('challenge_open')
        ->and(dailyRefusal(fn () => $challenges->accept($challenge, $anna)))->toBe('challenge_closed')
        ->and($challenges->incoming($bert)->pluck('id')->all())->toBe([$challenge->id]);

    $game = $challenges->accept($challenge, $bert);

    expect($game->isCorrespondence())->toBeTrue()
        ->and($game->rated)->toBeFalse()
        ->and([$game->white_id, $game->black_id])->toBe([$bert->id, $anna->id])
        ->and($game->deadline_ms)->toBe((int) now()->getTimestampMs() + 86_400_000)
        ->and($game->pgn_headers['TimeControl'])->toBe('1/86400')
        ->and($challenge->refresh()->status)->toBe(ChessInviteStatus::Accepted);

    // A challenge that ran out (48 h) cannot be accepted any more.
    $carl = User::factory()->create();
    $late = $challenges->challenge($anna, $carl);
    $this->travel(48)->hours();

    expect(dailyRefusal(fn () => $challenges->accept($late, $carl)))->toBe('challenge_closed');
});

test('a daily move is played by the league server alone: no note, no signature, nothing for any relay', function () {
    [$white, , $black] = dailyPlayers();
    $game = ChessGame::factory()->daily()->create(['white_id' => $white->id, 'black_id' => $black->id]);

    // The double-check shows what the move is; nothing is signed for it.
    Livewire::actingAs($white)->test('pages::games.show', ['game' => $game])
        ->call('previewMove', 'e2e4', 1)
        ->assertReturned(fn (array $r) => $r === ['ok' => true, 'error' => null, 'move' => ['san' => 'e4', 'result' => '*', 'check' => false]]);

    expect(playDaily($game, $white, 'e2e4'))->toMatchArray(['ok' => true, 'error' => null])
        ->and(playDaily($game, $black, 'e7e5'))->toMatchArray(['ok' => true, 'error' => null]);

    $state = app(ChessGameService::class)->snapshot($game->refresh());

    expect($game->ply)->toBe(2)
        ->and($game->fen)->toBe('rnbqkbnr/pppp1ppp/8/4p3/4P3/8/PPPP1PPP/RNBQKBNR w KQkq e6 0 2')
        ->and($game->moves()->pluck('san')->all())->toBe(['e4', 'e5'])
        ->and($game->moves()->whereNotNull('nostr_event_id')->count())->toBe(0)
        ->and(NostrEvent::query()->count())->toBe(0)
        ->and($state['moves'][0])->not->toHaveKey('event')
        ->and($state['posted'])->toBe(['w' => false, 'b' => false]);

    Bus::assertNotDispatched(PublishNostrEvent::class);

    // A page opened before the change asks for a note to sign: it is told to reload, and nothing is played.
    Livewire::actingAs($white)->test('pages::games.show', ['game' => $game])
        ->call('prepareMove', 'g1f3', 3)
        ->assertReturned(['ok' => false, 'error' => 'reload', 'move' => null]);

    expect($game->refresh()->ply)->toBe(2);
});

test('the server refuses an illegal move, a move out of turn, a move sent twice and a spectator; nothing is stored', function () {
    [$white, , $black] = dailyPlayers();
    $spectator = User::factory()->create();
    $game = ChessGame::factory()->daily()->create(['white_id' => $white->id, 'black_id' => $black->id]);

    expect(playDaily($game, $white, 'e2e5')['error'])->toBe('illegal_move')
        ->and(playDaily($game, $black, 'e7e5')['error'])->toBe('not_your_turn')
        ->and(playDaily($game, $spectator, 'e2e4')['error'])->toBe('not_a_player')
        ->and(playDaily($game, $white, 'e2e4', 2)['error'])->toBe('out_of_sync')
        ->and($game->refresh()->ply)->toBe(0);

    // A retry of the same move (a second tab, a double click) is played once.
    expect(playDaily($game, $white, 'e2e4', 1)['ok'])->toBeTrue()
        ->and(playDaily($game, $white, 'e2e4', 1)['error'])->toBe('not_your_turn')
        ->and($game->refresh()->ply)->toBe(1)
        ->and($game->moves()->count())->toBe(1);

    // The preview checks the same things and plays nothing.
    Livewire::actingAs($black)->test('pages::games.show', ['game' => $game])
        ->call('previewMove', 'e7e4', 2)->assertReturned(fn (array $r) => $r['error'] === 'illegal_move')
        ->call('previewMove', 'e7e5', 5)->assertReturned(fn (array $r) => $r['error'] === 'out_of_sync');

    expect($game->refresh()->ply)->toBe(1);
});

test('a daily game in progress at the change continues server-side from its last move: the old notes stay, nothing is played twice', function () {
    [$white, $whiteKey, $black, $blackKey] = dailyPlayers();
    $games = app(ChessGameService::class);
    $game = $games->start($white, $black, ChessGame::CORRESPONDENCE);
    $games->move($game, $white, 'e2e4', 1);
    $games->move($game->refresh(), $black, 'e7e5', 2);

    // Before rev. 9.4 each of these moves was its mover's signed kind-64 note, chained by `e`.
    $first = NostrEvent::fromSigned(SignedEvent::fromInput($whiteKey->sign(64, [['p', $white->pubkey, '', 'white'], ['p', $black->pubkey, '', 'black'], ['alt', 'move 1']], '[Event "?"]')));
    $second = NostrEvent::fromSigned(SignedEvent::fromInput($blackKey->sign(64, [['p', $white->pubkey, '', 'white'], ['p', $black->pubkey, '', 'black'], ['e', $first->event_id], ['alt', 'move 2']], '[Event "?"]')));
    $game->moves()->where('ply', 1)->update(['nostr_event_id' => $first->id]);
    $game->moves()->where('ply', 2)->update(['nostr_event_id' => $second->id]);
    $before = NostrEvent::query()->orderBy('id')->pluck('raw', 'id')->all();

    // An open tab replays Black's old move, and White's next one: the first is refused, the second is played.
    expect(playDaily($game, $black, 'e7e5', 2)['error'])->toBe('not_your_turn')
        ->and(playDaily($game, $white, 'e7e5', 2)['error'])->toBe('out_of_sync')
        ->and(playDaily($game, $white, 'g1f3', 3)['ok'])->toBeTrue();

    $game->refresh();

    expect($game->ply)->toBe(3)
        ->and($game->moves()->orderBy('ply')->pluck('nostr_event_id')->all())->toBe([$first->id, $second->id, null])
        ->and(NostrEvent::query()->orderBy('id')->pluck('raw', 'id')->all())->toBe($before);

    // The running game names its history; the move form asks for no signer.
    Livewire::actingAs($white)->test('pages::games.show', ['game' => $game])
        ->assertSee(__('Earlier moves'))
        ->assertSee(trans_choice(':count move was published as its own note before moves stopped being posted; it stays as history.|:count moves were published as their own notes before moves stopped being posted; they stay as history.', 2))
        ->assertDontSee('each move is its own NIP-64 note');
});

test('a missed daily deadline ends the game: aborted before both first moves, lost on time after', function () {
    [$white, , $black] = dailyPlayers();
    $early = ChessGame::factory()->daily()->create(['white_id' => $white->id, 'black_id' => $black->id]);
    $late = ChessGame::factory()->daily()->create(['white_id' => $white->id, 'black_id' => $black->id]);

    playDaily($late, $white, 'e2e4');
    playDaily($late, $black, 'e7e5');

    // White has 24 h for the third move; one second before the end the game still runs.
    $this->travel(86_399)->seconds();
    $this->artisan('chess:check-clocks')->assertSuccessful();
    expect($late->refresh()->status)->toBe(ChessGameStatus::Active)
        ->and($early->refresh()->status)->toBe(ChessGameStatus::Active);

    $this->travel(1)->seconds();
    $this->artisan('chess:check-clocks')->assertSuccessful();

    expect($early->refresh()->status)->toBe(ChessGameStatus::Aborted)
        ->and($late->refresh()->status)->toBe(ChessGameStatus::Finished)
        ->and($late->result)->toBe('0-1')
        ->and($late->end_reason)->toBe(ChessEndReason::Timeout)
        ->and(playDaily($late, $white, 'g1f3', 3)['error'])->toBe('game_over')
        ->and($late->refresh()->ply)->toBe(2);
});

test('daily games never block live play', function () {
    [$anna, , $bert] = dailyPlayers();
    ChessGame::factory()->daily()->create(['white_id' => $anna->id, 'black_id' => $bert->id]);

    expect(app(ChessGameService::class)->activeGameOf($anna))->toBeNull()
        ->and(app(ChessQueue::class)->join($anna))->toBeNull();

    $blitz = app(ChessGameService::class)->start($anna, $bert);

    expect($blitz->mode)->toBe('blitz')
        ->and(app(ChessGameService::class)->activeGameOf($bert)?->id)->toBe($blitz->id);
});

test('daily games are exempt from one live game at a time: several at once, beside a live game, and no invite or queue entry is touched', function () {
    [$anna, , $bert] = dailyPlayers();
    $carl = User::factory()->create();
    $live = ChessGame::factory()->create(['white_id' => $anna->id]);
    $anna->forceFill(['looking_to_play' => 'chess/blitz'])->save();
    $invite = app(ChessInvites::class)->invite($carl, $anna);
    app(ChessQueue::class)->join($carl);
    $games = app(ChessGameService::class);

    $one = $games->start($anna, $bert, ChessGame::CORRESPONDENCE);
    $two = $games->start($carl, $anna, ChessGame::CORRESPONDENCE);

    expect(ChessGame::query()->daily()->playedBy($anna)->where('status', ChessGameStatus::Active)->pluck('id')->all())->toEqualCanonicalizing([$one->id, $two->id])
        ->and($games->activeGameOf($anna)?->id)->toBe($live->id)
        ->and($invite->refresh()->status)->toBe(ChessInviteStatus::Pending)
        ->and(app(ChessQueue::class)->entryOf($carl))->not->toBeNull();
});

test('the list of daily games names each game\'s latest move', function () {
    [$anna, , $bert] = dailyPlayers();
    $games = app(ChessGameService::class);
    $game = $games->start($anna, $bert, ChessGame::CORRESPONDENCE);
    $games->move($game, $anna, 'e2e4');
    $games->move($game->refresh(), $bert, 'e7e5');

    Livewire::actingAs($anna)->test('pages::me.correspondence')
        ->assertSeeHtml('<b>1… e5</b>')
        ->assertDontSeeHtml('<b>1. e4</b>');
});
