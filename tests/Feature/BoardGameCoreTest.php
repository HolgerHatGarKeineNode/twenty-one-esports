<?php

/*
| The board game core next to chess (plan "Mühle und Dame", P2): games,
| moves, clock, resign, draw, abort and the game page, proven with the
| test-only three men's morris of FixtureBoardGame/FixtureBoardRules before
| nine men's morris (P3) and checkers (P4) exist.
*/

use App\Enums\BoardGameStatus;
use App\Events\BoardGameStarted;
use App\Events\BoardGameUpdated;
use App\Games\GameRegistry;
use App\Jobs\CheckBoardClock;
use App\Models\BoardGame;
use App\Models\BoardMove;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardRuleViolation;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Support\FixtureBoardGame;
use Tests\Support\FixtureBoardRules;

// Clocks are compared to the millisecond: time only moves when a test travels.
beforeEach(fn () => $this->freezeTime());

function startBoardGame(?User $white = null, ?User $black = null): BoardGame
{
    FixtureBoardGame::play();

    return app(BoardGameService::class)->start(FixtureBoardGame::SLUG, $white ?? User::factory()->create(), $black ?? User::factory()->create());
}

/**
 * Plays the given moves in turn, each by the player whose move it is.
 *
 * @param  list<string>  $moves
 */
function playBoard(BoardGame $game, array $moves): BoardGame
{
    foreach ($moves as $move) {
        $game->refresh();
        $game = app(BoardGameService::class)->move($game, $game->player($game->turn), $move, $game->ply + 1);
    }

    return $game->refresh();
}

function boardViolation(Closure $action): ?string
{
    try {
        $action();
    } catch (BoardRuleViolation $violation) {
        return $violation->reason;
    }

    return null;
}

/* ---------- Start ------------------------------------------------------------------------------------------- */

test('a game starts in the rules\' start position with White to move, blitz 5+3, and both players hear of it', function () {
    Event::fake([BoardGameStarted::class]);
    [$white, $black] = User::factory()->count(2)->create();

    $game = startBoardGame($white, $black);

    expect($game->refresh()->status)->toBe(BoardGameStatus::Active)
        ->and($game->game)->toBe(FixtureBoardGame::SLUG)
        ->and($game->position)->toBe('......... w')
        ->and($game->turn)->toBe('w')
        ->and([$game->initial_ms, $game->increment_ms, $game->white_ms, $game->black_ms])->toBe([300_000, 3_000, 300_000, 300_000])
        ->and($game->deadline_ms)->toBe((int) now()->getTimestampMs() + 30_000);

    Event::assertDispatched(BoardGameStarted::class, fn (BoardGameStarted $event) => $event->gameId === $game->id
        && $event->broadcastAs() === 'board.game-started'
        && $event->broadcastWith() === ['gameId' => $game->id, 'url' => route('board.show', $game)]
        && array_map(fn ($channel) => $channel->name, $event->broadcastOn()) === ['private-App.Models.User.'.$white->id, 'private-App.Models.User.'.$black->id]);
});

test('only a switched-on board game starts, never chess or a series, and nobody plays two live board games', function () {
    [$anna, $bert, $cleo] = User::factory()->count(3)->create();

    // The switch is off at boot: no board game in the registry.
    expect(boardViolation(fn () => app(BoardGameService::class)->start(FixtureBoardGame::SLUG, $anna, $bert)))->toBe('unknown_game');

    FixtureBoardGame::play();
    $service = app(BoardGameService::class);

    expect(boardViolation(fn () => $service->start('chess', $anna, $bert)))->toBe('unknown_game')
        ->and(boardViolation(fn () => $service->start('rocket-league', $anna, $bert)))->toBe('unknown_game')
        ->and(boardViolation(fn () => $service->start(FixtureBoardGame::SLUG, $anna, $bert, 'correspondence')))->toBe('unsupported_mode')
        ->and(boardViolation(fn () => $service->start(FixtureBoardGame::SLUG, $anna, $anna)))->toBe('same_player');

    $service->start(FixtureBoardGame::SLUG, $anna, $bert);

    expect(boardViolation(fn () => $service->start(FixtureBoardGame::SLUG, $cleo, $bert)))->toBe('already_playing')
        ->and(BoardGame::query()->count())->toBe(1);
});

/* ---------- Moves ------------------------------------------------------------------------------------------- */

test('the server accepts a legal move: it stores it, passes the turn and tells players and spectators', function () {
    $game = startBoardGame();
    Event::fake([BoardGameUpdated::class]);

    $game = app(BoardGameService::class)->move($game, $game->white, 'b2', 1);

    expect($game->refresh()->ply)->toBe(1)
        ->and($game->position)->toBe('....w.... b')
        ->and($game->turn)->toBe('b')
        ->and($game->version)->toBe(2)
        ->and($game->moves()->sole()->only(['ply', 'move', 'notation', 'position']))->toBe(['ply' => 1, 'move' => 'b2', 'notation' => 'b2', 'position' => '....w.... b']);

    Event::assertDispatched(BoardGameUpdated::class, function (BoardGameUpdated $event) use ($game) {
        expect($event->broadcastAs())->toBe('board.updated')
            ->and(array_map(fn ($channel) => $channel->name, $event->broadcastOn()))->toBe(['private-board.'.$game->id, 'board.'.$game->id.'.watch'])
            ->and($event->broadcastWith())->not->toHaveKey('moves')
            ->and($event->broadcastWith()['pieces'])->toBe(['b2' => ['side' => 'w', 'kind' => 'man']])
            ->and($event->broadcastWith()['lastMove']['path'])->toBe(['b2'])
            // Black's placements: every point but b2.
            ->and(array_column($event->broadcastWith()['legal'], 'move'))->toBe(['a1', 'b1', 'c1', 'a2', 'c2', 'a3', 'b3', 'c3']);

        return true;
    });
});

test('the server refuses an illegal move and stores nothing', function (array $before, string $move) {
    $game = playBoard(startBoardGame(), $before);
    $position = $game->position;

    expect(boardViolation(fn () => app(BoardGameService::class)->move($game, $game->player($game->turn), $move, $game->ply + 1)))->toBe('illegal_move')
        ->and($game->refresh()->ply)->toBe(count($before))
        ->and($game->position)->toBe($position)
        ->and($game->version)->toBe(count($before) + 1)
        ->and($game->moves()->count())->toBe(count($before));
})->with([
    'placing on an occupied point' => [['b2'], 'b2'],
    'a point off the board' => [[], 'd4'],
    'a step while men are still to place' => [['b2', 'a1'], 'b2-b3'],
    'a step to a point not joined to it' => [['a1', 'b1', 'c3', 'c1', 'c2', 'a2'], 'c3-a3'],
    'moving the other side\'s man' => [['a1', 'b1', 'c3', 'c1', 'c2', 'a2'], 'b1-b2'],
    'not a move at all' => [[], 'x9-'],
]);

test('a player cannot move out of turn, and a spectator or guest not at all', function () {
    $game = startBoardGame();
    $service = app(BoardGameService::class);

    expect(boardViolation(fn () => $service->move($game, $game->black, 'b2', 1)))->toBe('not_your_turn')
        ->and(boardViolation(fn () => $service->move($game, User::factory()->create(), 'b2', 1)))->toBe('not_a_player')
        ->and($game->refresh()->ply)->toBe(0);
});

test('a move sent twice or from a board that fell behind is played once, the rest refused', function () {
    $game = startBoardGame();
    $service = app(BoardGameService::class);
    // Two tabs (or a double click) hold the game as it was before the first move.
    $stale = BoardGame::query()->findOrFail($game->id);

    $service->move($game, $game->white, 'b2', 1);

    // The same move again, from the stale copy: the service reads the locked row, not the copy.
    expect(boardViolation(fn () => $service->move($stale, $stale->white, 'b2', 1)))->toBe('not_your_turn')
        ->and(boardViolation(fn () => $service->move($stale, $stale->white, 'a1', 1)))->toBe('not_your_turn');

    $game = playBoard($game, ['a1', 'c3']);

    // Black's board still shows ply 1 and proposes a move for ply 2 while it is ply 4.
    expect(boardViolation(fn () => $service->move($stale, $stale->black, 'c1', 2)))->toBe('out_of_sync')
        ->and($game->refresh()->ply)->toBe(3)
        ->and($game->moves()->pluck('move')->all())->toBe(['b2', 'a1', 'c3']);
});

test('the database refuses a second move for the same ply', function () {
    $game = playBoard(startBoardGame(), ['b2']);

    expect(fn () => BoardMove::query()->create(['board_game_id' => $game->id, 'ply' => 1, 'move' => 'a1', 'notation' => 'a1', 'position' => 'w...w.... w', 'spent_ms' => 0, 'clock_ms' => 0]))
        ->toThrow(QueryException::class);
});

test('the rules end the game: three in a row while placing and after stepping, a draw at the move limit', function () {
    $placed = playBoard(startBoardGame(), ['a1', 'b1', 'a2', 'b2', 'a3']);

    expect($placed->status)->toBe(BoardGameStatus::Finished)
        ->and($placed->result)->toBe('1-0')
        ->and($placed->end_reason)->toBe('three_in_a_row')
        ->and($placed->deadline_ms)->toBeNull()
        ->and(boardViolation(fn () => app(BoardGameService::class)->move($placed, $placed->black, 'c1', 6)))->toBe('game_over');

    // Placed: White a1 c2 b3, Black b1 c1 a2, no line. Then White steps b3-c3 and c2-b2: a1 b2 c3.
    $stepped = playBoard(startBoardGame(), ['a1', 'b1', 'c2', 'c1', 'b3', 'a2', 'b3-c3', 'a2-a3', 'c2-b2']);

    expect($stepped->status)->toBe(BoardGameStatus::Finished)
        ->and($stepped->result)->toBe('1-0')
        ->and($stepped->end_reason)->toBe('three_in_a_row')
        ->and($stepped->moves()->pluck('notation')->last())->toBe('c2-b2');

    // The same placing, then both sides step to and fro without a line until the move limit.
    $shuffle = ['b3-c3', 'a2-a3', 'c3-b3', 'a3-a2'];
    $moves = ['a1', 'b1', 'c2', 'c1', 'b3', 'a2', ...array_merge(...array_fill(0, 8, $shuffle)), 'b3-c3'];
    $drawn = playBoard(startBoardGame(), $moves);

    expect($drawn->ply)->toBe(FixtureBoardRules::MOVE_LIMIT - 1)
        ->and($drawn->status)->toBe(BoardGameStatus::Active);

    $drawn = playBoard($drawn, ['a2-a3']);

    expect($drawn->status)->toBe(BoardGameStatus::Finished)
        ->and($drawn->result)->toBe('1/2-1/2')
        ->and($drawn->end_reason)->toBe('move_limit');
});

/* ---------- Clock ------------------------------------------------------------------------------------------- */

test('no clock runs before both first moves; then thinking time is taken off and the increment added', function () {
    $game = startBoardGame();

    $this->travel(20)->seconds();
    $game = playBoard($game, ['b2']);
    $this->travel(20)->seconds();
    $game = playBoard($game, ['a1']);

    expect([$game->white_ms, $game->black_ms])->toBe([300_000, 300_000])
        ->and($game->deadline_ms)->toBe((int) now()->getTimestampMs() + 300_000);

    $this->travel(10)->seconds();
    $game = playBoard($game, ['c3']);

    expect($game->white_ms)->toBe(300_000 - 10_000 + 3_000)
        ->and($game->moves()->where('ply', 3)->sole()->only(['spent_ms', 'clock_ms']))->toBe(['spent_ms' => 10_000, 'clock_ms' => 293_000])
        ->and($game->deadline_ms)->toBe((int) now()->getTimestampMs() + 300_000)
        ->and(app(BoardGameService::class)->clocks($game, (int) now()->getTimestampMs() + 4_000))->toBe(['w' => 293_000, 'b' => 296_000]);
});

test('every change queues a clock check for its deadline', function () {
    Queue::fake();

    $game = startBoardGame();
    playBoard($game, ['b2', 'a1']);

    Queue::assertPushed(CheckBoardClock::class, 3);
    // After both first moves: White's full clock, rounded up, plus a second.
    Queue::assertPushed(CheckBoardClock::class, fn (CheckBoardClock $job) => $job->gameId === $game->id
        && $job->delay instanceof DateTimeInterface && $job->delay->getTimestamp() === now()->addSeconds(301)->getTimestamp());
});

test('a flag falls by the queued job: the side to move loses on time', function () {
    $game = playBoard(startBoardGame(), ['b2', 'a1']);

    $this->travel(299)->seconds();
    (new CheckBoardClock($game->id))->handle(app(BoardGameService::class));
    expect($game->refresh()->status)->toBe(BoardGameStatus::Active);

    $this->travel(1)->seconds();
    Event::fake([BoardGameUpdated::class]);
    (new CheckBoardClock($game->id))->handle(app(BoardGameService::class));

    expect($game->refresh()->status)->toBe(BoardGameStatus::Finished)
        ->and($game->result)->toBe('0-1')
        ->and($game->end_reason)->toBe('timeout')
        ->and($game->white_ms)->toBe(0)
        ->and($game->deadline_ms)->toBeNull();
    Event::assertDispatched(BoardGameUpdated::class, fn (BoardGameUpdated $event) => $event->state['reason'] === 'timeout');
});

test('a game that throws in the clock sweep is reported, and the other games still end on time', function () {
    $bad = playBoard(startBoardGame(), ['b2', 'a1', 'c3']);
    $good = playBoard(startBoardGame(), ['b2', 'a1', 'c3']);
    Exceptions::fake();
    BoardGame::saving(fn (BoardGame $game) => $game->id === $bad->id ? throw new RuntimeException('a broken game') : null);

    $this->travel(300)->seconds();
    $this->artisan('board:check-clocks')->expectsOutput('Checked 2 game(s).')->assertSuccessful();

    expect($good->refresh()->status)->toBe(BoardGameStatus::Finished)
        ->and($bad->refresh()->status)->toBe(BoardGameStatus::Active);
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'a broken game');
});

test('a flag falls by the scheduled sweep when no job ran; a missed first move aborts', function () {
    $flagged = playBoard(startBoardGame(), ['b2', 'a1', 'c3']);

    $this->travel(299)->seconds();
    $this->artisan('board:check-clocks')->expectsOutput('Checked 0 game(s).')->assertSuccessful();
    expect($flagged->refresh()->status)->toBe(BoardGameStatus::Active);

    // Black's clock: 300 s from White's third move.
    $this->travel(1)->seconds();
    $this->artisan('board:check-clocks')->expectsOutput('Checked 1 game(s).')->assertSuccessful();

    expect($flagged->refresh()->status)->toBe(BoardGameStatus::Finished)
        ->and($flagged->result)->toBe('1-0')
        ->and($flagged->end_reason)->toBe('timeout');

    $unmoved = startBoardGame();
    $this->travel(30)->seconds();
    $this->artisan('board:check-clocks')->expectsOutput('Checked 1 game(s).');

    expect($unmoved->refresh()->status)->toBe(BoardGameStatus::Aborted)
        ->and($unmoved->result)->toBeNull()
        ->and($unmoved->end_reason)->toBe('aborted');
});

test('a move that arrives after the flag fell is refused and the game ends on time', function () {
    $game = playBoard(startBoardGame(), ['b2', 'a1']);
    $this->travel(301)->seconds();

    expect(boardViolation(fn () => app(BoardGameService::class)->move($game, $game->white, 'c3', 3)))->toBe('game_over')
        ->and($game->refresh()->status)->toBe(BoardGameStatus::Finished)
        ->and($game->end_reason)->toBe('timeout')
        ->and($game->ply)->toBe(2);
});

test('the clock sweep runs every ten seconds', function () {
    $sweep = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'board:check-clocks'));

    expect($sweep)->not->toBeNull()
        ->and($sweep->getExpression())->toBe('* * * * *')
        ->and($sweep->repeatSeconds)->toBe(10)
        ->and($sweep->withoutOverlapping)->toBeTrue();
});

test('a game whose board game was switched off takes no moves, and its flag still falls', function () {
    $game = playBoard(startBoardGame(), ['b2', 'a1']);
    app()->instance(GameRegistry::class, new GameRegistry(array_values(array_filter(app(GameRegistry::class)->all(), fn ($entry) => $entry->slug() !== FixtureBoardGame::SLUG))));

    expect(boardViolation(fn () => app(BoardGameService::class)->move($game, $game->white, 'c3', 3)))->toBe('unknown_game');

    $this->travel(300)->seconds();
    $this->artisan('board:check-clocks')->assertSuccessful();

    expect($game->refresh()->end_reason)->toBe('timeout')->and($game->result)->toBe('0-1');
});

/* ---------- Resign, draw, abort ----------------------------------------------------------------------------- */

test('a player resigns: the other side wins', function () {
    $game = playBoard(startBoardGame(), ['b2']);

    $game = app(BoardGameService::class)->resign($game, $game->white);

    expect($game->status)->toBe(BoardGameStatus::Finished)
        ->and($game->result)->toBe('0-1')
        ->and($game->end_reason)->toBe('resignation')
        ->and(boardViolation(fn () => app(BoardGameService::class)->resign($game, $game->black)))->toBe('game_over')
        ->and(boardViolation(fn () => app(BoardGameService::class)->resign(startBoardGame(), User::factory()->create())))->toBe('not_a_player');
});

test('a draw is offered and accepted, declined, or lapses when the other side moves', function () {
    $service = app(BoardGameService::class);

    $game = playBoard(startBoardGame(), ['b2', 'a1']);
    expect(boardViolation(fn () => $service->acceptDraw($game, $game->black)))->toBe('no_draw_offer');

    $game = $service->offerDraw($game, $game->white);
    expect($game->draw_offer)->toBe('w')
        // The offer is made to Black; White cannot accept it.
        ->and(boardViolation(fn () => $service->acceptDraw($game, $game->white)))->toBe('no_draw_offer');

    $game = $service->declineDraw($game, $game->black);
    expect($game->draw_offer)->toBeNull()->and($game->status)->toBe(BoardGameStatus::Active);

    // Offered, then the offer stands through White's own move and lapses with Black's.
    $game = $service->offerDraw($game, $game->white);
    $game = playBoard($game, ['c3']);
    expect($game->draw_offer)->toBe('w');
    $game = playBoard($game, ['c1']);
    expect($game->draw_offer)->toBeNull();

    $game = $service->offerDraw($game, $game->black);
    $game = $service->acceptDraw($game, $game->white);

    expect($game->status)->toBe(BoardGameStatus::Finished)
        ->and($game->result)->toBe('1/2-1/2')
        ->and($game->end_reason)->toBe('agreement');

    // Two offers meet: the second accepts the first.
    $crossed = playBoard(startBoardGame(), ['b2', 'a1']);
    $service->offerDraw($crossed, $crossed->black);
    $crossed = $service->offerDraw($crossed, $crossed->white);

    expect($crossed->result)->toBe('1/2-1/2')->and($crossed->end_reason)->toBe('agreement');
});

test('either player may abort until both have moved, not after', function () {
    $service = app(BoardGameService::class);

    $game = playBoard(startBoardGame(), ['b2']);
    $game = $service->abort($game, $game->black);

    expect($game->status)->toBe(BoardGameStatus::Aborted)
        ->and($game->result)->toBeNull()
        ->and($game->end_reason)->toBe('aborted');

    $late = playBoard(startBoardGame(), ['b2', 'a1']);

    expect(boardViolation(fn () => $service->abort($late, $late->white)))->toBe('too_late_to_abort')
        ->and($late->refresh()->status)->toBe(BoardGameStatus::Active);
});

/* ---------- The game page ----------------------------------------------------------------------------------- */

test('the board game route exists only when the switch was on at boot', function () {
    expect(config('esports.board_games.enabled'))->toBeFalse()
        ->and(Route::has('board.show'))->toBeFalse();
    $this->get('/board/1')->assertNotFound();

    // routes/web.php loads the board routes behind the switch, and only there.
    expect(file_get_contents(base_path('routes/web.php')))->toContain("if (config('esports.board_games.enabled')) {\n    require __DIR__.'/board.php';\n}");
});

test('guests and players see the board; a game whose board game is off is not found', function () {
    $game = playBoard(startBoardGame(), ['b2']);

    $this->get(route('board.show', $game))->assertOk()
        ->assertSee('data-test="board-game"', false)
        ->assertSee('Fixture Board');
    $this->actingAs($game->black)->get(route('board.show', $game))->assertOk();

    app()->instance(GameRegistry::class, new GameRegistry(array_values(array_filter(app(GameRegistry::class)->all(), fn ($entry) => $entry->slug() !== FixtureBoardGame::SLUG))));
    $this->get(route('board.show', $game))->assertNotFound();
});

test('the page plays a move for the player to move and refuses everyone else, with the server\'s state', function () {
    $game = startBoardGame();

    $page = Livewire::actingAs($game->white)->test('pages::board.show', ['boardGame' => $game])->call('move', 'b2', 1);
    expect($page->effects['returns'][0])->toMatchArray(['ok' => true, 'error' => null])
        ->and($page->effects['returns'][0]['state']['ply'])->toBe(1)
        ->and($page->effects['returns'][0]['state']['pieces'])->toBe(['b2' => ['side' => 'w', 'kind' => 'man']]);
    // A plain roundtrip of the page keeps answering (a 500 there shows in no console).
    $page->call('$refresh')->assertOk();

    $refused = Livewire::actingAs($game->white)->test('pages::board.show', ['boardGame' => $game])->call('move', 'a1', 2);
    expect($refused->effects['returns'][0])->toMatchArray(['ok' => false, 'error' => 'not_your_turn']);

    auth()->logout();
    $guest = Livewire::test('pages::board.show', ['boardGame' => $game])->call('resign');
    expect($guest->effects['returns'][0])->toMatchArray(['ok' => false, 'error' => 'not_a_player'])
        ->and($game->refresh()->status)->toBe(BoardGameStatus::Active);
});

test('only the two players may listen on a board game\'s private channel', function () {
    $game = startBoardGame();
    $channels = app(Broadcaster::class)->getChannels();
    $authorize = $channels['board.{boardGame}'];

    expect($authorize($game->white, $game))->toBeTrue()
        ->and($authorize($game->black, $game))->toBeTrue()
        ->and($authorize(User::factory()->create(), $game))->toBeFalse();
});
