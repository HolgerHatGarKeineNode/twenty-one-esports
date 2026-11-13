<?php

use App\Models\BoardGame;
use App\Models\BoardMove;
use App\Models\User;
use App\Support\Board\BlockliRules;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardThrottle;
use App\Support\Board\GoRules;
use Livewire\Livewire;
use Tests\Support\BlockliOn;
use Tests\Support\GoOn;

/*
|--------------------------------------------------------------------------
| A move does not carry the whole game (security F1, 2026-11-13)
|--------------------------------------------------------------------------
|
| Measured on a go game of 250 moves on 19 x 19: every own move, every refused
| action and every fetchState() without `known` answered with 1.06 MB (the
| pieces after each of the 250 moves), and a guest who put 50 such calls into
| one Livewire request cost 3.5 s and 53 MB. Now an action answers without the
| record, the record of a board of stones names what each move took instead of
| the pieces after it, and a visitor gets the whole record a few times a
| minute (BoardThrottle). The practice pages' judge has the same ceiling.
|
*/

beforeEach(function () {
    GoOn::play();
});

/**
 * A running go game on 19 x 19 with `plies` moves on the record, written straight into the tables: the stones go on
 * every second point row by row, wherever the rules allow one.
 */
function goLongGame(int $plies = 250): BoardGame
{
    $game = app(BoardGameService::class)->start('go-19', User::factory()->create(), User::factory()->create(), 'correspondence');
    $rules = new GoRules(19);
    $position = $rules->start();
    $rows = [];

    for ($point = 0, $ply = 0; $ply < $plies; $point = ($point + 2) % 361) {
        $move = $rules->id($point);

        if (! in_array($move, $rules->legalMoves($position), true)) {
            continue;
        }

        $notation = $rules->notation($position, $move);
        $position = $rules->apply($position, $move);
        $rows[] = ['board_game_id' => $game->id, 'ply' => ++$ply, 'move' => $move, 'notation' => $notation, 'position' => $rules->serialize($position), 'spent_ms' => 1000, 'clock_ms' => 0, 'created_at' => now()];
    }

    BoardMove::query()->insert($rows);
    $game->forceFill(['position' => $rules->serialize($position), 'turn' => $rules->turn($position), 'ply' => $plies, 'version' => $plies + 1])->save();

    return $game->refresh();
}

test('an own move and a refused one answer without the record, even in a go game of 250 moves on 19 x 19', function () {
    $game = goLongGame();
    $page = Livewire::actingAs($game->player($game->turn))->test('pages::board.show', ['boardGame' => $game]);

    // A stone on a stone: refused.
    $rules = new GoRules(19);
    $refused = $page->call('move', $rules->id((int) strpos($rules->stones($game->position), 'x')), 251)->effects['returns'][0];
    $legal = $rules->legalMoves($rules->deserialize($game->position))[0];
    $moved = $page->call('move', $legal, 251)->effects['returns'][0];

    expect($refused['ok'])->toBeFalse()
        ->and($refused['state'])->not->toHaveKey('moves')
        ->and(strlen((string) json_encode($refused)))->toBeLessThan(30_000)
        ->and($moved['ok'])->toBeTrue()
        ->and($moved['state']['ply'])->toBe(251)
        ->and($moved['state'])->not->toHaveKey('moves')
        ->and($moved['state']['lastMove'])->toMatchArray(['move' => $legal, 'side' => $game->turn])->not->toHaveKey('pieces')
        ->and(strlen((string) json_encode($moved)))->toBeLessThan(30_000);
});

test('the whole record of that game stays under 150 kB, and its moves rebuild every position', function () {
    $game = goLongGame();
    $state = Livewire::test('pages::board.show', ['boardGame' => $game])->call('fetchState')->effects['returns'][0];
    $rules = new GoRules(19);
    $ids = array_column($rules->view($rules->start())['points'], 'id');
    $stones = str_repeat('.', 361);

    // As boardGame.js builds an earlier position: the stones a move took come off, its own goes on.
    foreach ($state['moves'] as $move) {
        foreach ($move['taken'] as $id) {
            $stones[array_search($id, $ids, true)] = '.';
        }

        $stones[array_search($move['path'][0], $ids, true)] = $move['side'] === 'w' ? 'x' : 'o';
    }

    expect($state['moves'])->toHaveCount(250)
        ->and($state['moves'])->each->not->toHaveKey('pieces')
        ->and($stones)->toBe($state['pieces'])
        ->and(strlen((string) json_encode($state)))->toBeLessThan(150_000);
});

test('a record names the stones a move took', function () {
    $games = app(BoardGameService::class);
    $game = $games->start('go-9', User::factory()->create(), User::factory()->create());

    foreach (['b1', 'a1', 'a2', 'pass'] as $ply => $move) {
        $game = $games->move($game->refresh(), $game->player($game->turn), $move, $ply + 1);
    }

    expect(array_map(fn (array $move): array => [$move['move'], $move['side'], $move['taken']], $games->snapshot($game)['moves']))
        ->toBe([['b1', 'w', []], ['a1', 'b', []], ['a2', 'w', ['a1']], ['pass', null, []]]);
});

test('a visitor gets the whole record a few times a minute: a batch of calls is cut off', function () {
    $game = goLongGame(20);
    $page = Livewire::test('pages::board.show', ['boardGame' => $game]);
    $withRecord = 0;

    for ($call = 0; $call < BoardThrottle::HISTORY_PER_MINUTE + 5; $call++) {
        $withRecord += array_key_exists('moves', $page->call('fetchState')->effects['returns'][0]) ? 1 : 0;
    }

    // A page that knows the state costs nothing of it.
    $known = $page->call('fetchState', $game->version)->effects['returns'][0];

    expect($withRecord)->toBe(BoardThrottle::HISTORY_PER_MINUTE)
        ->and($known)->not->toHaveKey('moves')
        ->and($known['version'])->toBe($game->version);
});

test('the practice pages\' judge has a ceiling a minute, for a guest too', function (string $page, array $parameters, string $start) {
    BlockliOn::play();
    $practice = Livewire::test($page, $parameters);

    expect($practice->call('open', $start)->effects['returns'][0]['ok'])->toBeTrue();

    // The rest of the minute's room, used up by this very visitor.
    $session = BoardThrottle::PRACTICE_PER_MINUTE - 1;
    while ($session-- > 0 && BoardThrottle::allows('practice', BoardThrottle::PRACTICE_PER_MINUTE));

    expect($practice->call('open', $start)->effects['returns'][0])->toBe(['ok' => false, 'error' => 'too_many'])
        ->and($practice->call('play', $start, 'pass', [])->effects['returns'][0])->toBe(['ok' => false, 'error' => 'too_many']);
})->with([
    'Go' => ['pages::board.go-practice', [], '9/9/9/9/9/9/9/9/9 x - 0'],
    'Blockli' => ['pages::board.practice', ['board' => 'blockli'], fn () => (new BlockliRules)->serialize((new BlockliRules)->start())],
]);
