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
use App\Support\Chess\GameRecords;
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
 * One daily move the way the page plays it: prepare, sign, play.
 */
function playDaily(ChessGame $game, User $user, TestSigner $signer, string $uci): ChessGame
{
    $records = app(GameRecords::class);
    $ply = $game->refresh()->ply + 1;
    [$signed] = $signer->signTemplates([$records->prepareMove($game, $user, $uci, $ply)['template']]);

    return $records->playSigned($game, $user, $uci, $ply, json_encode($signed));
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

test('a daily move is played only with the mover\'s signed NIP-64 note, chained to the previous move', function () {
    [$white, $whiteKey, $black, $blackKey] = dailyPlayers();
    $game = ChessGame::factory()->daily()->create(['white_id' => $white->id, 'black_id' => $black->id]);

    playDaily($game, $white, $whiteKey, 'e2e4');
    playDaily($game, $black, $blackKey, 'e7e5');

    $notes = NostrEvent::query()->where('kind', 64)->orderBy('id')->get();
    $first = SignedEvent::fromInput($notes[0]->payload());
    $second = SignedEvent::fromInput($notes[1]->payload());

    expect($game->refresh()->ply)->toBe(2)
        ->and($game->moves()->pluck('nostr_event_id')->all())->toBe($notes->pluck('id')->all())
        ->and($first->pubkey)->toBe($white->pubkey)
        ->and($first->hasValidSignature())->toBeTrue()
        ->and($first->tagsNamed('p'))->toBe([[$white->pubkey, '', 'white'], [$black->pubkey, '', 'black']])
        ->and($first->tagsNamed('e'))->toBe([])
        ->and($first->content)->toContain('[Result "*"]')->toContain("\n\n1. e4 *\n")
        ->and($second->pubkey)->toBe($black->pubkey)
        ->and($second->tagsNamed('e'))->toBe([[$first->id]])
        ->and($second->content)->toContain('1. e4 e5 *');

    Bus::assertDispatchedTimes(PublishNostrEvent::class, 2);

    // A note that is not the prepared one, or signed by the other player, plays nothing.
    $records = app(GameRecords::class);
    $template = $records->prepareMove($game, $white, 'g1f3', 3)['template'];
    [$doctored] = $whiteKey->signTemplates([[...$template, 'content' => str_replace('Nf3', 'Nc3', $template['content'])]]);
    [$foreign] = $blackKey->signTemplates([$template]);

    expect(dailyRefusal(fn () => $records->playSigned($game, $white, 'g1f3', 3, json_encode($doctored))))->toBe('not_the_prepared_event')
        ->and(dailyRefusal(fn () => $records->playSigned($game, $white, 'g1f3', 3, json_encode($foreign))))->toBe('foreign_author')
        ->and(dailyRefusal(fn () => $records->playSigned($game, $white, 'g1f3', 3, '{"id":"x"}')))->toBe('malformed')
        ->and($game->refresh()->ply)->toBe(2)
        ->and(NostrEvent::query()->count())->toBe(2);
});

test('a missed daily deadline ends the game: aborted before both first moves, lost on time after', function () {
    [$white, $whiteKey, $black, $blackKey] = dailyPlayers();
    $early = ChessGame::factory()->daily()->create(['white_id' => $white->id, 'black_id' => $black->id]);
    $late = ChessGame::factory()->daily()->create(['white_id' => $white->id, 'black_id' => $black->id]);

    playDaily($late, $white, $whiteKey, 'e2e4');
    playDaily($late, $black, $blackKey, 'e7e5');

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
        ->and(dailyRefusal(fn () => playDaily($late, $white, $whiteKey, 'g1f3')))->toBe('game_over');
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
