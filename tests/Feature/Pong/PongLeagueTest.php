<?php

/*
|--------------------------------------------------------------------------
| Proof of Pong in the league (plan "Proof of Pong", P4)
|--------------------------------------------------------------------------
|
| One live game at a time, both ways: a running Proof of Pong match keeps a player out of a live board game, live
| chess and their queues, and a live board or chess game keeps them out of Proof of Pong. An invite reaches the
| invited player outside the lobby, in the match dock on every page, and so does the running match.
|
*/

use App\Enums\PongMatchStatus;
use App\Models\BoardGame;
use App\Models\BoardQueueEntry;
use App\Models\ChessGame;
use App\Models\PongMatch;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardQueue;
use App\Support\Board\BoardRuleViolation;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessRuleViolation;
use App\Support\Dock\OpenMatches;
use App\Support\Pong\PongInvites;
use App\Support\Pong\PongMatches;
use App\Support\Pong\PongRuleViolation;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FixtureBoardGame;
use Tests\Support\PongOn;

beforeEach(function () {
    $this->withoutVite();
    Queue::fake();
    PongOn::play();
});

function pongLeagueRefusal(Closure $action): ?string
{
    try {
        $action();
    } catch (BoardRuleViolation|ChessRuleViolation|PongRuleViolation $violation) {
        return $violation->reason;
    }

    return null;
}

test('a running Proof of Pong match keeps its players out of a live board game, its queue and live chess; once over they are free', function () {
    // A live board game is a blitz one (the test fixture's): the real board games are correspondence only and never live.
    FixtureBoardGame::play();
    [$a, $b, $c] = User::factory()->count(3)->create();
    $match = app(PongMatches::class)->create($a, $b);

    expect(pongLeagueRefusal(fn () => app(BoardGameService::class)->start(FixtureBoardGame::SLUG, $a, $c)))->toBe('playing_elsewhere')
        ->and(pongLeagueRefusal(fn () => app(BoardGameService::class)->start(FixtureBoardGame::SLUG, $c, $b)))->toBe('playing_elsewhere')
        ->and(pongLeagueRefusal(fn () => app(BoardQueue::class)->join($a, FixtureBoardGame::SLUG)))->toBe('playing_elsewhere')
        ->and(pongLeagueRefusal(fn () => app(ChessGameService::class)->start($a, $c)))->toBe('already_playing')
        ->and(BoardGame::query()->count())->toBe(0)
        ->and(BoardQueueEntry::query()->count())->toBe(0)
        ->and(ChessGame::query()->count())->toBe(0);

    // A game by correspondence is no live game: it still starts.
    expect(app(ChessGameService::class)->start($a, $c, ChessGame::CORRESPONDENCE)->isActive())->toBeTrue();

    // Switched off, Proof of Pong blocks nothing.
    config(['esports.pong.enabled' => false]);
    expect(PongMatches::runningMatchOf($a))->toBeNull();
    config(['esports.pong.enabled' => true]);

    $match->forceFill(['status' => PongMatchStatus::Aborted])->save();

    expect(app(BoardGameService::class)->start(FixtureBoardGame::SLUG, $a, $c)->status->value)->toBe('active');
});

test('a live board or chess game keeps its players out of Proof of Pong: no invite, no accept, no match', function () {
    FixtureBoardGame::play();
    [$board, $boardRival, $chess, $chessRival, $free] = User::factory()->count(5)->create();
    app(BoardGameService::class)->start(FixtureBoardGame::SLUG, $board, $boardRival);
    app(ChessGameService::class)->start($chess, $chessRival);
    $looking = fn (): User => User::factory()->create(['looking_to_play' => PongInvites::LOOKING]);

    expect(PongInvites::busy($board))->toBe('playing_elsewhere')
        ->and(PongInvites::busy($chess))->toBe('playing_elsewhere')
        ->and(PongInvites::busy($free))->toBeNull()
        ->and(pongLeagueRefusal(fn () => app(PongInvites::class)->invite($board, $looking())))->toBe('playing_elsewhere')
        ->and(pongLeagueRefusal(fn () => app(PongInvites::class)->invite($chess, $looking())))->toBe('playing_elsewhere');

    // An invite to a player who starts a live chess game before answering cannot be accepted.
    $invitee = $looking();
    $invite = app(PongInvites::class)->invite($free, $invitee);
    app(ChessGameService::class)->start($invitee, User::factory()->create());

    expect(pongLeagueRefusal(fn () => app(PongInvites::class)->accept($invite, $invitee)))->toBe('playing_elsewhere')
        ->and(PongMatch::query()->count())->toBe(0);
});

test('a Proof of Pong invite reaches the invitee in the match dock on any page, and the running match is a live tab for both', function () {
    $inviter = User::factory()->create();
    $invitee = User::factory()->create(['looking_to_play' => PongInvites::LOOKING]);
    $invite = app(PongInvites::class)->invite($inviter, $invitee);

    $theirs = app(OpenMatches::class)->for($invitee);
    $item = $theirs->firstWhere('key', 'pong-invite-'.$invite->id);

    expect($item)->not->toBeNull()
        ->and($item->kind)->toBe('pong_invite')
        ->and($item->needsYou)->toBeTrue()
        ->and($item->href)->toStartWith(route('pong.index'))
        ->and($item->boardIcon())->toBe('play')
        ->and($item->sentence)->toBe(__(':name invites you to a game of :game', ['name' => $inviter->displayName(), 'game' => 'Proof of Pong']))
        ->and(app(OpenMatches::class)->for($inviter)->pluck('key')->all())->not->toContain('pong-invite-'.$invite->id);

    // On a page far from the lobby: the dock shows it.
    Livewire::actingAs($invitee)->test('match-dock')->assertOk()->assertSee(__(':game invite', ['game' => 'Proof of Pong']));
    $this->actingAs($invitee)->get(route('matches.index'))->assertOk()->assertSee('pong-invite-'.$invite->id, false);

    $match = app(PongInvites::class)->accept($invite, $invitee);

    foreach ([$inviter, $invitee] as $player) {
        $tab = app(OpenMatches::class)->for($player)->firstWhere('key', 'pong-'.$match->id);

        expect($tab)->not->toBeNull()
            ->and($tab->group)->toBe('live')
            ->and($tab->href)->toBe(route('pong.match', $match));
    }

    expect(app(OpenMatches::class)->for($invitee)->pluck('key')->all())->not->toContain('pong-invite-'.$invite->id);

    // Over: no tab.
    $match->forceFill(['status' => PongMatchStatus::Finished])->save();
    expect(app(OpenMatches::class)->for($inviter)->pluck('key')->all())->not->toContain('pong-'.$match->id);
});
