<?php

use App\Enums\BoardGameStatus;
use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Models\User;
use App\Support\Board\BlockspaceRules;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardQueue;
use App\Support\Board\BoardRuleViolation;
use App\Support\Cards\PageCard;
use App\Support\GameNames;
use App\Support\Tournaments\GameProfile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\BlockspaceOn;

/*
| Blockspace in the league: the queue pairs it like every board game, each game gets a deal of its own, the sides move
| White, Black, Black, White on the core built for strict turns, and a push stays under Reverb's 10 kB.
*/

beforeEach(function () {
    Queue::fake();
    $this->freezeTime();
    BlockspaceOn::play();
});

function blockspaceGame(string $mode = 'rapid'): BoardGame
{
    BlockspaceOn::play();

    return app(BoardGameService::class)->start('blockspace', User::factory()->create(), User::factory()->create(), $mode);
}

/** Plays `$count` moves, each the side's first legal one. */
function blockspacePlay(BoardGame $game, int $count): BoardGame
{
    $rules = new BlockspaceRules;

    foreach (range(1, $count) as $ply) {
        $game->refresh();
        $game = app(BoardGameService::class)->move($game, $game->player($game->turn), $rules->legalMoves($rules->deserialize($game->position))[0], $game->ply + 1);
    }

    return $game->refresh();
}

function blockspaceRefusal(Closure $action): ?string
{
    try {
        $action();
    } catch (BoardRuleViolation $violation) {
        return $violation->reason ?? $violation->getMessage();
    }

    return null;
}

test('two searching players are paired in every live mode', function (string $mode) {
    $queue = app(BoardQueue::class);
    $first = User::factory()->create();
    $second = User::factory()->create();

    expect($queue->join($first, 'blockspace', $mode))->toBeNull();

    $game = $queue->join($second, 'blockspace', $mode);

    expect($game)->toBeInstanceOf(BoardGame::class)
        ->and($game->game)->toBe('blockspace')
        ->and($game->mode)->toBe($mode)
        ->and($game->status)->toBe(BoardGameStatus::Active);
})->with(['blitz', 'rapid']);

test('the game is on the registry with its three modes and every mode can be planned as a tournament', function () {
    $games = app(GameRegistry::class);

    expect($games->isBoard('blockspace'))->toBeTrue()
        ->and(array_keys($games->get('blockspace')->modes()))->toBe(['blitz', 'rapid', 'correspondence']);

    foreach (['blitz', 'rapid', 'correspondence'] as $mode) {
        expect(GameProfile::for('blockspace', $mode)->mode)->toBe($mode);
    }
});

test('every game gets a deal of its own, blitz with the fuse', function () {
    $rules = new BlockspaceRules;
    $blitz = $rules->deserialize(blockspaceGame('blitz')->position);
    $rapid = $rules->deserialize(blockspaceGame('rapid')->position);

    expect($blitz['fuse'])->toBeTrue()
        ->and($rapid['fuse'])->toBeFalse()
        ->and($blitz['deck'])->not->toBe($rapid['deck']);
});

test('a game runs White, Black, Black, White with the right clock on the move', function () {
    $game = blockspaceGame();
    $turns = [];

    foreach (range(1, 8) as $ply) {
        $turns[] = $game->turn;
        $game = blockspacePlay($game, 1);
    }

    expect($turns)->toBe(['w', 'b', 'b', 'w', 'w', 'b', 'b', 'w']);
});

test('a move that is not legal is refused and changes nothing', function () {
    $game = blockspaceGame();
    $position = $game->position;

    expect(blockspaceRefusal(fn () => app(BoardGameService::class)->move($game, $game->player('w'), '0:a21', 1)))->not->toBeNull()
        ->and($game->refresh()->position)->toBe($position);
});

/** A running game whose queue is empty up to and including its last position: moves reveal nothing any more. */
function blockspaceWithoutQueue(BoardGame $game): BoardGame
{
    $rules = new BlockspaceRules;
    $empty = function (string $text) use ($rules): string {
        $position = $rules->deserialize($text);
        $position['queue'] = '';

        return $rules->serialize($position);
    };

    foreach ($game->moves as $move) {
        $move->forceFill(['position' => $empty($move->position)])->save();
    }

    $game->forceFill(['position' => $empty($game->position)])->save();

    return $game->refresh();
}

test('a move that showed the next transaction cannot be taken back', function () {
    $game = blockspacePlay(blockspaceGame(), 3);
    $games = app(BoardGameService::class);

    expect($games->snapshot($game)['takeBackUntil'])->toBeNull()
        ->and(blockspaceRefusal(fn () => $games->takeBack($game, $game->player('b'))))->not->toBeNull()
        ->and($game->refresh()->ply)->toBe(3);
});

test('with nothing left to come Black, to move again, takes its own move back, and White cannot', function () {
    $game = blockspaceWithoutQueue(blockspacePlay(blockspaceGame(), 1));
    $game = blockspacePlay($game, 1);
    $games = app(BoardGameService::class);

    // Ply 2 was Black's and Black is to move again: the side to move is the last mover.
    expect($game->turn)->toBe('b')
        ->and($games->snapshot($game)['takeBackBy'])->toBe('b')
        ->and(blockspaceRefusal(fn () => $games->takeBack($game, $game->player('w'))))->not->toBeNull()
        ->and($game->refresh()->ply)->toBe(2);

    $games->takeBack($game, $game->player('b'));

    expect($game->refresh()->ply)->toBe(1)->and($game->turn)->toBe('b');
});

test('a board shows the game\'s own start when stepped back', function () {
    $game = blockspaceGame();
    $games = app(BoardGameService::class);
    $rules = $games->rulesOf($game);
    $start = $rules->view($rules->deserialize($games->startOf($game, $rules)))['pieces'];

    expect($start['tx0']['kind'])->toBe(array_keys(BlockspaceRules::FORMS)[strpos('abcdefghijklmnopqrstu', $rules->deserialize($game->position)['pool'][0])]);
});

test('a push never reaches Reverb\'s 10 kB, even on the fullest board and with the wire\'s escaping', function () {
    $game = blockspaceGame();
    $rules = new BlockspaceRules;
    $position = $rules->deserialize($game->position);
    // More stones than any of 400 bot games has laid (139), with a move on the record.
    $game = blockspacePlay($game, 1);
    $position = $rules->deserialize($game->position);
    $position['board'] = substr(str_repeat('wb', 95), 0, 170).str_repeat('.', 19);
    $game->forceFill(['position' => $rules->serialize($position)])->save();

    $push = app(BoardGameService::class)->snapshot($game->refresh(), withMoves: false);
    $wire = json_encode(['name' => 'board.updated', 'data' => json_encode($push), 'channels' => ['private-board.1', 'board.1.watch']]);

    expect($push['legal'])->toBe([])
        ->and($push['lastMove'])->not->toHaveKey('pieces')
        ->and(strlen($wire))->toBeLessThan(10000);
});

test('a page load still carries the pieces of every move for stepping back', function () {
    $game = blockspacePlay(blockspaceGame(), 3);
    $state = app(BoardGameService::class)->snapshot($game);

    expect($state['moves'])->toHaveCount(3)
        ->and($state['moves'][0]['pieces'])->toHaveKey('state');
});

test('nothing of what comes next reaches a player', function () {
    $game = blockspacePlay(blockspaceGame(), 2);
    $position = (new BlockspaceRules)->deserialize($game->position);

    expect(json_encode(app(BoardGameService::class)->snapshot($game)))
        ->not->toContain($position['queue'])
        ->not->toContain($position['deck']);
});

test('a game plays to its end through the service and names a winner', function () {
    $game = blockspaceGame();
    $rules = new BlockspaceRules;
    $games = app(BoardGameService::class);
    $moves = 0;

    while ($game->refresh()->isActive() && $moves++ < 200) {
        $legal = array_values(array_filter($rules->legalMoves($rules->deserialize($game->position)), fn (string $move): bool => $move !== BlockspaceRules::PASS));
        $games->move($game, $game->player($game->turn), $legal[0] ?? BlockspaceRules::PASS, $game->ply + 1);
    }

    expect($game->status)->toBe(BoardGameStatus::Finished)
        ->and($game->end_reason)->toBeIn(['mempool_empty', 'block_full'])
        ->and($game->result)->toBeIn(['1-0', '0-1', '1/2-1/2']);
});

test('the rungs and the state are pieces no point draws, and the card takes the game', function () {
    $game = blockspacePlay(blockspaceGame(), 4);
    $rules = new BlockspaceRules;
    $view = $rules->view($rules->deserialize($game->position));

    // The rungs and the state are pieces no point draws: every drawer walks the points or skips what has none.
    expect(array_diff(array_keys($view['pieces']), array_column($view['points'], 'id')))->toContain('state', 'tx0');
    expect(PageCard::boardGame($game->refresh()))->toBeInstanceOf(PageCard::class);
});

test('the sides are called by the colours of their stones, in Blockspace only', function () {
    expect(GameNames::sides('blockspace'))->toBe(['w' => 'Orange', 'b' => 'Blue'])
        ->and(GameNames::sides('blockli'))->toBe(['w' => 'White', 'b' => 'Black']);

    app()->setLocale('de');

    expect(GameNames::sides('blockspace'))->toBe(['w' => 'Orange', 'b' => 'Blau']);
});

test('the correspondence page of the game opens', function () {
    $this->get(route('board.correspondence', 'blockspace'))->assertOk();
});

test('the lobby and a running game\'s page open', function () {
    $game = blockspaceGame();

    $this->get(route('board.lobby', 'blockspace'))->assertOk();
    Livewire::test('pages::board.show', ['boardGame' => $game])->assertOk();
});
