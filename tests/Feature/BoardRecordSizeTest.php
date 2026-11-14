<?php

use App\Models\BoardGame;
use App\Models\BoardMove;
use App\Models\User;
use App\Support\Board\BlockliRules;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardThrottle;
use App\Support\Board\GoRules;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
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

/**
 * One Livewire request of a guest (no session of its own) that replays a page's snapshot with `calls`, on as many
 * copies of the page as `components` says: the batch a script sends.
 *
 * @param  list<array{0: string, 1?: list<mixed>}>  $calls
 */
function boardBatch(BoardGame $game, array $calls, int $components = 1): TestResponse
{
    $snapshot = json_encode(Livewire::test('pages::board.show', ['boardGame' => $game])->snapshot);
    $component = ['snapshot' => $snapshot, 'updates' => [], 'calls' => array_map(fn (array $call): array => ['method' => $call[0], 'params' => $call[1] ?? [], 'metadata' => []], $calls)];

    return test()->withHeaders(['X-Livewire' => '1', 'Sec-Fetch-Site' => 'same-origin'])
        ->postJson(app('livewire')->getUpdateUri(), ['components' => array_fill(0, $components, $component)]);
}

test('what the board script needs is not an action of the page: a guest batch of 50 config() calls yields no record (security N2)', function () {
    $response = boardBatch(goLongGame(20), array_fill(0, 50, ['config']));

    expect($response->isSuccessful())->toBeFalse()
        ->and(substr_count((string) $response->getContent(), '"moves"'))->toBe(0);
});

/**
 * The dear position (security N1): a legal go game of 559 plies on 19 x 19. Black fills every point but those of an
 * odd row and an odd file while White passes: one group of 280 stones around 81 empty points with no empty
 * neighbour, where every fill per stone or per neighbour walked the whole group.
 */
function goDearGame(): BoardGame
{
    $game = app(BoardGameService::class)->start('go-19', User::factory()->create(), User::factory()->create(), 'correspondence');
    $rules = new GoRules(19);
    $position = $rules->start();
    $rows = [];

    for ($point = 0; $point < 361; $point++) {
        if (intdiv($point, 19) % 2 === 1 && $point % 19 % 2 === 1) {
            continue;
        }

        foreach ($rows === [] ? [$rules->id($point)] : [GoRules::PASS, $rules->id($point)] as $move) {
            $notation = $rules->notation($position, $move);
            $position = $rules->apply($position, $move);
            $rows[] = ['board_game_id' => $game->id, 'ply' => count($rows) + 1, 'move' => $move, 'notation' => $notation, 'position' => $rules->serialize($position), 'spent_ms' => 1000, 'clock_ms' => 0, 'created_at' => now()];
        }
    }

    foreach (array_chunk($rows, 100) as $chunk) {
        BoardMove::query()->insert($chunk);
    }

    $game->forceFill(['position' => $rules->serialize($position), 'turn' => $rules->turn($position), 'ply' => count($rows), 'version' => count($rows) + 1])->save();

    return $game->refresh();
}

test('a guest batch of 50 clock checks on the dear position of 559 plies is refused whole, and one request carries 10 calls on 50 pages at the most (security N1)', function () {
    $game = goDearGame();
    $rules = new GoRules(19);

    // The position is the one measured: 280 stones in one group, and nothing left to the side to move but the pass.
    expect($game->ply)->toBe(559)
        ->and(substr_count($rules->stones($game->position), 'x'))->toBe(280)
        ->and($rules->legalMoves($rules->deserialize($game->position)))->toBe([GoRules::PASS]);

    $batch = boardBatch($game, array_fill(0, 50, ['checkClock']));

    expect($batch->isSuccessful())->toBeFalse()
        ->and(substr_count((string) $batch->getContent(), '"version"'))->toBe(0)
        ->and(config('livewire.payload.max_calls'))->toBe(10)
        ->and(config('livewire.payload.max_components'))->toBe(50)
        ->and(boardBatch($game, array_fill(0, 11, ['fetchState', [$game->version]]))->isSuccessful())->toBeFalse()
        ->and(boardBatch($game, [['fetchState', [$game->version]]], components: 51)->isSuccessful())->toBeFalse()
        // What the pages send has room: 10 calls on each of 50 components.
        ->and(boardBatch($game, array_fill(0, 10, ['fetchState', [$game->version]]), components: 50)->assertOk()->json('components'))->toHaveCount(50);
});

test('the short answers have a ceiling a minute too: the clock check, the state without the record and a refused action (security N1)', function () {
    $game = goDearGame();
    $guest = Livewire::test('pages::board.show', ['boardGame' => $game]);
    $checked = 0;

    for ($call = 0; $call < BoardThrottle::CLOCK_CHECKS_PER_MINUTE + 5; $call++) {
        $checked += $guest->call('checkClock')->effects['returns'][0] === null ? 0 : 1;
    }

    $states = 0;

    for ($call = 0; $call < BoardThrottle::STATES_PER_MINUTE + 5; $call++) {
        $states += $guest->call('fetchState', $game->version)->effects['returns'][0] === null ? 0 : 1;
    }

    // A guest's action is refused without the state: nothing of the position is read for it.
    expect($checked)->toBe(BoardThrottle::CLOCK_CHECKS_PER_MINUTE)
        ->and($states)->toBe(BoardThrottle::STATES_PER_MINUTE)
        ->and($guest->call('resign')->effects['returns'][0])->toBe(['ok' => false, 'error' => 'not_a_player', 'state' => null]);

    // A player's refused move brings the state a few times a minute, then the reason alone.
    $player = Livewire::actingAs($game->player($game->turn))->test('pages::board.show', ['boardGame' => $game]);
    $withState = 0;

    for ($call = 0; $call < BoardThrottle::REFUSALS_PER_MINUTE + 5; $call++) {
        $refused = $player->call('move', 'a19', 560)->effects['returns'][0];
        $withState += $refused['state'] === null ? 0 : 1;

        expect($refused)->toMatchArray(['ok' => false, 'error' => 'illegal_move']);
    }

    expect($withState)->toBe(BoardThrottle::REFUSALS_PER_MINUTE)
        // The pass, the one legal move, is answered as ever.
        ->and($player->call('move', GoRules::PASS, 560)->effects['returns'][0])->toMatchArray(['ok' => true, 'error' => null]);
});

test('a script behind a shared address does not lock the players there out: a player is counted on his own, a refused call not on the address (security N3)', function () {
    // A guest with a session of its own, as each browser behind the address has.
    $guest = function (): void {
        auth()->logout();
        request()->setLaravelSession(session()->driver());
        session()->setId(Str::random(40));
    };
    $allowed = function (string $what, int $calls): int {
        $passed = 0;

        for ($call = 0; $call < $calls; $call++) {
            $passed += BoardThrottle::allows($what, 3) ? 1 : 0;
        }

        return $passed;
    };

    // One guest far over its own room: three calls pass, and the 40 refused ones take nothing of the address's room.
    $guest();
    expect($allowed('hammered', 43))->toBe(3);
    $guest();
    expect($allowed('hammered', 1))->toBe(1);

    // Ten guests use up the address (ten times a visitor's room): the next guest there is refused, a signed-in player is not.
    for ($visitor = 0; $visitor < 10; $visitor++) {
        $guest();
        expect($allowed('shared', 3))->toBe(3);
    }

    $guest();
    expect($allowed('shared', 1))->toBe(0);

    $this->actingAs(User::factory()->create());
    expect($allowed('shared', 5))->toBe(3);
});
