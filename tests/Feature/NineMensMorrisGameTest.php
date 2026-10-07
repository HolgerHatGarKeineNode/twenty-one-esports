<?php

/*
| Nine men's morris on the board game core (plan "Mühle und Dame", P3): the
| game registered only behind both switches, a whole game through
| BoardGameService with a mill and a removal to a win by blocking, the
| page, and the rules page section. The rules themselves are unit-tested
| in tests/Unit/NineMensMorrisRulesTest.php.
*/

use App\Enums\BoardGameStatus;
use App\Games\GameKind;
use App\Games\GameRegistry;
use App\Games\NineMensMorris;
use App\Models\BoardGame;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardRuleViolation;
use App\Support\Board\NineMensMorrisRules;
use Tests\Support\NineMensMorrisOn;

beforeEach(fn () => $this->freezeTime());

/**
 * @param  list<string>  $moves
 */
function playMorris(BoardGame $game, array $moves): BoardGame
{
    foreach ($moves as $move) {
        $game->refresh();
        $game = app(BoardGameService::class)->move($game, $game->player($game->turn), $move, $game->ply + 1);
    }

    return $game->refresh();
}

function morrisViolation(Closure $action): ?string
{
    try {
        $action();
    } catch (BoardRuleViolation $violation) {
        return $violation->reason;
    }

    return null;
}

test('nine men\'s morris is registered only with both board game switches on, as a correspondence-only board game named Mühle in German', function () {
    expect(app(GameRegistry::class)->find(NineMensMorris::SLUG))->toBeNull();

    $registry = NineMensMorrisOn::play();
    $game = $registry->get(NineMensMorris::SLUG);

    expect($game)->toBeInstanceOf(NineMensMorris::class)
        ->and($game->kind())->toBe(GameKind::Board)
        ->and($game->rules())->toBeInstanceOf(NineMensMorrisRules::class)
        ->and(array_keys($game->modes()))->toBe(['correspondence'])
        ->and($game->mode('correspondence')?->timeControl)->toBe('1/86400')
        ->and(array_keys($registry->boards()))->toBe([NineMensMorris::SLUG])
        ->and($registry->isSeries(NineMensMorris::SLUG))->toBeFalse()
        ->and($game->name())->toBe("Nine Men's Morris")
        ->and(trans("Nine Men's Morris", [], 'de'))->toBe('Mühle');

    config(['esports.board_games.games.nine-mens-morris.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);

    expect(app(GameRegistry::class)->find(NineMensMorris::SLUG))->toBeNull();
});

test('a whole game through the core: a mill takes a man, a mill without its removal is refused, and blocking Black wins', function () {
    NineMensMorrisOn::play();
    [$white, $black] = User::factory()->count(2)->create();
    $games = app(BoardGameService::class);
    $game = $games->start(NineMensMorris::SLUG, $white, $black);

    expect($game->position)->toBe('........................ w 9 9 0')
        // Correspondence, the only mode since 2026-10-07: one move a day, no increment.
        ->and($game->mode)->toBe('correspondence')
        ->and($game->initial_ms)->toBe(86_400_000)
        ->and($game->increment_ms)->toBe(0);

    $game = playMorris($game, array_slice(NineMensMorrisOn::BLOCKING_GAME, 0, 16));

    // f6 closes the mill: without a removal it is no move, nor is taking a man that is not there.
    expect(morrisViolation(fn () => $games->move($game, $white, 'f6', 17)))->toBe('illegal_move')
        ->and(morrisViolation(fn () => $games->move($game, $white, 'f6xa1', 17)))->toBe('illegal_move')
        ->and(morrisViolation(fn () => $games->move($game, $white, 'd2', 17)))->toBe('illegal_move')
        ->and($game->refresh()->ply)->toBe(16);

    $game = playMorris($game, array_slice(NineMensMorrisOn::BLOCKING_GAME, 16, 2));

    // After all eighteen men are placed a man only steps along a line.
    expect(morrisViolation(fn () => $games->move($game, $white, 'a4', 19)))->toBe('illegal_move')
        ->and(morrisViolation(fn () => $games->move($game, $white, 'g7-a7', 19)))->toBe('illegal_move');

    $game = playMorris($game, array_slice(NineMensMorrisOn::BLOCKING_GAME, 18));

    expect($game->status)->toBe(BoardGameStatus::Finished)
        ->and($game->result)->toBe('1-0')
        ->and($game->end_reason)->toBe('blocked')
        ->and($game->ply)->toBe(19)
        ->and($game->moves()->pluck('notation')->all())->toBe(NineMensMorrisOn::BLOCKING_GAME)
        ->and($game->moves()->where('ply', 17)->value('position'))->toBe('...w.ww.wwbbbw.bwbbbw..w b 0 1 0');
});

test('the page draws the 24 points and names the game, the result and its reason, in German too', function () {
    NineMensMorrisOn::play();
    [$white, $black] = User::factory()->count(2)->create();
    $game = playMorris(app(BoardGameService::class)->start(NineMensMorris::SLUG, $white, $black), NineMensMorrisOn::BLOCKING_GAME);
    $layout = app(BoardGameService::class)->layout($game);

    expect($layout['points'])->toHaveCount(24)
        ->and($layout['lines'])->toHaveCount(16);

    $this->get(route('board.show', $game))->assertOk()
        ->assertSee('<title>Nine Men&#039;s Morris: ', false)
        ->assertSee('No move left');

    $this->actingAs($white)->get(route('board.show', [$game, 'lang' => 'de']))->assertOk()
        // The title and the heading name the game in German.
        ->assertSee('<title>Mühle: ', false)
        ->assertSee('lg:text-[28px]">Mühle</h1>', false)
        ->assertSee('Kein Zug mehr möglich');
});

test('the rules page explains nine men\'s morris only while it is switched on, with the numbers of the rules', function () {
    $this->get(route('rules'))->assertOk()->assertDontSee('id="nine-mens-morris"', false);

    NineMensMorrisOn::play();

    $this->get(route('rules'))->assertOk()
        ->assertSee('id="nine-mens-morris"', false)
        ->assertSee("Nine men's morris, one move a day.")
        ->assertSee('Closing two mills at once removes one man.')
        ->assertSee('50 moves each without a mill');
});
