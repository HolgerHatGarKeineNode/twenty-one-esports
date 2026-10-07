<?php

/*
| Checkers ("Dame") on the board game core (plan "Mühle und Dame", P4): the
| registration behind its switch, moves checked by the server against
| CheckersRules (the rules themselves: tests/Unit/Board/CheckersRulesTest.php),
| a game played to its end and the rules page section.
*/

use App\Enums\BoardGameStatus;
use App\Games\Checkers;
use App\Games\GameKind;
use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardRuleViolation;
use Tests\Support\CheckersGame;

beforeEach(fn () => $this->freezeTime());

function startCheckers(): BoardGame
{
    CheckersGame::play();

    return app(BoardGameService::class)->start('checkers', User::factory()->create(), User::factory()->create());
}

/**
 * @param  list<string>  $moves
 */
function playCheckers(BoardGame $game, array $moves): BoardGame
{
    foreach ($moves as $move) {
        $game->refresh();
        app(BoardGameService::class)->move($game, $game->player($game->turn), $move, $game->ply + 1);
    }

    return $game->refresh();
}

test('checkers is a board game registered only with the board game switch and its own entry on', function () {
    expect(app(GameRegistry::class)->find('checkers'))->toBeNull();

    $registry = CheckersGame::play();
    $checkers = $registry->find('checkers');

    expect($checkers)->toBeInstanceOf(Checkers::class)
        ->and($checkers->kind())->toBe(GameKind::Board)
        ->and($registry->isBoard('checkers'))->toBeTrue()
        ->and($checkers->name())->toBe('Checkers')
        ->and(__('Checkers', [], 'de'))->toBe('Dame')
        ->and(array_keys($checkers->modes()))->toBe(['correspondence'])
        ->and($checkers->mode('correspondence')?->timeControl)->toBe('1/86400')
        ->and($checkers->validateResult($checkers->mode('correspondence'), ['result' => '1/2-1/2']))->toBe([])
        ->and($checkers->validateResult($checkers->mode('correspondence'), ['result' => '2-0']))->toBe(['result']);

    config(['esports.board_games.games.checkers.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);

    expect(app(GameRegistry::class)->find('checkers'))->toBeNull();
});

test('the server plays only what the German rules allow: a step while a capture is on is refused', function () {
    $game = playCheckers(startCheckers(), ['c3-d4', 'f6-e5']);
    $white = $game->player('w');

    $refused = null;

    try {
        app(BoardGameService::class)->move($game, $white, 'a3-b4', 3);
    } catch (BoardRuleViolation $violation) {
        $refused = $violation->reason;
    }

    expect($refused)->toBe('illegal_move')
        ->and($game->refresh()->ply)->toBe(2)
        ->and(app(BoardGameService::class)->snapshot($game)['legal'])->toBe([['move' => 'd4xf6', 'path' => ['d4', 'f6']]]);

    $game = playCheckers($game, ['d4xf6']);
    $legal = array_column(app(BoardGameService::class)->snapshot($game)['legal'], 'move');
    sort($legal);

    // Black must take back, with either man.
    expect($legal)->toBe(['e7xg5', 'g7xe5']);

    $game = playCheckers($game, ['g7xe5']);

    expect($game->moves()->pluck('notation')->all())->toBe(['c3-d4', 'f6-e5', 'd4xf6', 'g7xe5'])
        // c3 and f6 gone, White's man from d4 taken on f6, Black's g7 now on e5.
        ->and($game->position)->toBe('wwwwwwwww.ww......b.bb.bbbb.bbbb w 0')
        ->and(app(BoardGameService::class)->snapshot($game)['pieces'])->toHaveCount(22);
});

test('a capture chain is one move, clicked square by square, and taking the last piece wins', function () {
    $game = CheckersGame::setUp(startCheckers(), ['a1' => 'w', 'b2' => 'b', 'd4' => 'b', 'h6' => 'W']);

    expect(app(BoardGameService::class)->snapshot($game)['legal'])->toBe([['move' => 'a1xc3xe5', 'path' => ['a1', 'c3', 'e5']]]);

    $game = playCheckers($game, ['a1xc3xe5']);

    expect($game->status)->toBe(BoardGameStatus::Finished)
        ->and($game->result)->toBe('1-0')
        ->and($game->end_reason)->toBe('no_pieces')
        ->and(app(BoardGameService::class)->snapshot($game)['pieces'])->toBe(['e5' => ['side' => 'w', 'kind' => 'man'], 'h6' => ['side' => 'w', 'kind' => 'king']]);
});

test('a side left without a move loses', function () {
    // White's b4 steps to a5. Black's b6 then has a5 and c5 in front (d4 behind c5, the edge behind a5), a7 behind it is Black's own.
    $game = CheckersGame::setUp(startCheckers(), ['b4' => 'w', 'c5' => 'w', 'd4' => 'w', 'b6' => 'b', 'a7' => 'b']);
    $game = playCheckers($game, ['b4-a5']);

    expect($game->status)->toBe(BoardGameStatus::Finished)
        ->and($game->result)->toBe('1-0')
        ->and($game->end_reason)->toBe('no_moves');
});

test('the rules page explains checkers once it is playable, in English and German', function () {
    $this->get(route('rules'))->assertOk()->assertDontSee('id="checkers"', false);

    CheckersGame::play();

    $this->get(route('rules'))->assertOk()
        ->assertSee('id="checkers"', false)
        ->assertSee('Capturing is compulsory.')
        ->assertSee('neither the most pieces nor a king comes first');
    $this->get(route('rules', ['lang' => 'de']))->assertOk()
        ->assertSee('Es herrscht Schlagzwang.')
        ->assertSee('weder die meisten Steine noch eine Dame haben Vorrang');
});

test('the game page shows a checkers game with its board and the German name', function () {
    $game = startCheckers();

    $this->get(route('board.show', $game))->assertOk()->assertSee('<title>Checkers: ', false)->assertSee('>Checkers</h1>', false);
    $this->get(route('board.show', ['boardGame' => $game, 'lang' => 'de']))->assertOk()->assertSee('<title>Dame: ', false)->assertSee('>Dame</h1>', false);
});
