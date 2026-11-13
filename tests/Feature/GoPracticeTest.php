<?php

use App\Games\GameRegistry;
use App\Support\Board\GoPractice;
use App\Support\Board\GoRules;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;
use Tests\Support\GoOn;

/*
|--------------------------------------------------------------------------
| Go practice against bots (DerCaddy, 2026-10-27)
|--------------------------------------------------------------------------
|
| A guest finds eight lessons and three bots on 9 x 9 behind each Go lobby's "Learn Go and practice". The game stays in
| the browser (resources/js/go/practice.js); the page only judges each move it is sent by GoRules, as a live game's move
| is judged, and stores nothing (App\Support\Board\GoPractice). Every lesson's position holds what its words promise,
| played to its goal here with White's answers as the page gives them. The bots and the tips are tested in node, and
| the engine they run on against the server's rules (tests/Unit/Board/GoPracticeScriptsTest.php).
|
*/

beforeEach(function () {
    GoOn::play();
});

/** The page's answer to one call, as the browser gets it. */
function goPracticeCall(string $method, mixed ...$arguments): ?array
{
    return Livewire::test('pages::board.go-practice')->call($method, ...$arguments)->effects['returns'][0] ?? null;
}

const GO_START = '9/9/9/9/9/9/9/9/9 x - 0';

test('a guest finds one practice page for every Go lobby and gets the lessons and the bots', function () {
    // One address for all sizes: the same page under three would be three copies of it.
    expect(route('board.go-practice', absolute: false))->toBe('/games/go/practice');

    foreach (['go-9', 'go-13', 'go-19'] as $slug) {
        $this->get(route('board.lobby', $slug))->assertOk()->assertSee(route('board.go-practice'));
    }

    $this->get(route('board.go-practice'))->assertOk()
        ->assertSee('Go · Practice')->assertSee('goPractice(', false)->assertSee('boardGame(boardConfig())', false)
        ->assertSee('Liberties and capturing')->assertSee('The end and the count')->assertSee('Hodler')->assertSee('Miner')->assertSee('Whale')
        // The context bar of the smallest size on.
        ->assertSee('data-test="context-bar" data-game="go-9"', false);

    expect(fn () => goPracticeCall('config'))->toThrow(MethodNotFoundException::class);
});

test('the practice belongs to the smallest Go size on, is not found while Go is off, and is not Blockli\'s', function () {
    config(['esports.board_games.games.go-9.enabled' => false, 'esports.board_games.games.go-13.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);

    $this->get('/games/go/practice')->assertOk()->assertSee('data-test="context-bar" data-game="go-19"', false);
    $this->get('/games/go-9/practice')->assertNotFound();

    config(['esports.board_games.games.go-19.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);
    $this->get('/games/go/practice')->assertNotFound();
});

test('the page judges a move in the position the browser holds and answers what the board needs', function () {
    $answer = goPracticeCall('play', GO_START, 'e5', [GO_START]);

    expect($answer)->toMatchArray(['ok' => true, 'position' => '9/9/9/9/4x4/9/9/9/9 o - 0', 'turn' => 'b', 'outcome' => null])
        ->and($answer['move'])->toBe(['move' => 'e5', 'notation' => 'E5', 'path' => ['e5']])
        ->and($answer['pieces'])->toBe(['e5' => ['side' => 'w', 'kind' => 'black']])
        ->and($answer['legal'])->toHaveCount(81)
        ->and($answer['count'])->toMatchArray(['margin' => 81 - 7.5, 'lead' => 'w']);

    $pass = goPracticeCall('play', '9/9/9/9/4x4/9/9/9/9 o - 0', 'pass', [GO_START, '9/9/9/9/4x4/9/9/9/9 o - 0']);
    expect($pass)->toMatchArray(['ok' => true, 'position' => '9/9/9/9/4x4/9/9/9/9 x - 1'])
        ->and($pass['move'])->toBe(['move' => 'pass', 'notation' => 'Pass', 'path' => []]);
});

test('the page refuses what a live game would refuse, and text that is no position', function () {
    expect(goPracticeCall('play', GO_START, 'i5', [GO_START]))->toBe(['ok' => false, 'error' => 'illegal_move'])
        ->and(goPracticeCall('play', '9/9/9/9/4x4/9/9/9/9 o - 0', 'e5', []))->toBe(['ok' => false, 'error' => 'illegal_move'])
        ->and(goPracticeCall('play', GO_START, ['e5'], []))->toBe(['ok' => false, 'error' => 'illegal_move'])
        // A stone without a liberty, a 13 x 13 board, nonsense: no position of the practice.
        ->and(goPracticeCall('play', 'xo7/o8/9/9/9/9/9/9/9 x - 0', 'e5', []))->toBe(['ok' => false, 'error' => 'no_position'])
        ->and(goPracticeCall('play', '13/13/13/13/13/13/13/13/13/13/13/13/13 x - 0', 'e5', []))->toBe(['ok' => false, 'error' => 'no_position'])
        ->and(goPracticeCall('play', 'nonsense', 'e5', []))->toBe(['ok' => false, 'error' => 'no_position'])
        ->and(goPracticeCall('play', GO_START, 'e5', 'not a list'))->toBe(['ok' => false, 'error' => 'no_position'])
        ->and(goPracticeCall('play', GO_START, 'e5', array_fill(0, GoPractice::HISTORY_LIMIT + 1, GO_START)))->toBe(['ok' => false, 'error' => 'no_position'])
        // Both passed: the game is over.
        ->and(goPracticeCall('play', '9/9/9/9/4x4/9/9/9/9 x - 2', 'e4', []))->toBe(['ok' => false, 'error' => 'game_over']);
});

test('a game ends as a live one does: counted after two passes, drawn by the same stones a third time', function () {
    $end = goPracticeCall('play', '4xo3/4xo3/4xo3/4xo3/4xo3/4xo3/4xo3/4xo3/4xo3 x - 1', 'pass', []);
    expect($end)->toMatchArray(['ok' => true, 'outcome' => ['result' => '1-0', 'reason' => 'score'], 'legal' => []]);

    $before = '9/9/9/9/4x4/9/9/9/9 o - 0';
    $after = '9/9/9/9/4x4/9/9/9/9 x - 1';
    $repeat = goPracticeCall('play', $before, 'pass', [$after, '9/9/9/9/4x4/9/9/9/9 x d4 0', $before]);
    expect($repeat)->toMatchArray(['ok' => true, 'outcome' => ['result' => '1/2-1/2', 'reason' => 'repetition']])
        ->and(goPracticeCall('play', $before, 'pass', [$before])['outcome'])->toBeNull();
});

test('a position the browser starts from opens with its pieces, moves and count', function () {
    expect(goPracticeCall('open', GO_START))->toMatchArray(['ok' => true, 'position' => GO_START, 'turn' => 'w', 'outcome' => null])
        ->and(goPracticeCall('open', GO_START)['legal'])->toHaveCount(82)
        ->and(goPracticeCall('open', '9/9'))->toBe(['ok' => false, 'error' => 'no_position']);
});

test('every lesson holds what its words promise, played to its goal with White\'s answers', function () {
    $rules = new GoRules(GoPractice::SIZE);
    $lessons = collect(GoPractice::LESSONS)->keyBy('id');
    $stones = fn (array $position, string $colour): int => substr_count($position['board'], $colour);
    // White's answer as the page gives it (resources/js/go/practice.js lessonReply): a pass, or the runner of a ladder.
    $reply = function (array $position, ?string $kind) use ($rules): string {
        if ($kind !== 'runner') {
            return 'pass';
        }

        foreach ($rules->legalMoves($position) as $move) {
            if ($move === 'pass') {
                continue;
            }

            // The white stones in atari run onto their last liberty: the move that leaves them more than one.
            $after = $rules->apply($position, $move);
            $libertiesBefore = goLiberties($rules, $position, 'o');
            if ($libertiesBefore === 1 && goLiberties($rules, $after, 'o') > 1) {
                return $move;
            }
        }

        return 'pass';
    };
    // Plays the player's moves, White answering where the lesson has an answer; returns the positions after each.
    $play = function (string $id, array $moves) use ($rules, $lessons, $reply): array {
        $position = $rules->deserialize($lessons[$id]['position']);
        $after = [];

        foreach ($moves as $move) {
            expect($rules->legalMoves($position))->toContain($move);
            $position = $rules->apply($position, $move);
            $after[] = $position;

            if ($lessons[$id]['reply'] !== null && $position['passes'] < 2) {
                $position = $rules->apply($position, $reply($position, $lessons[$id]['reply']));
            }
        }

        return [$position, $after];
    };

    expect($lessons->keys()->all())->toBe(['capture', 'group', 'escape', 'forbidden', 'ko', 'eyes', 'ladder', 'count']);

    foreach (GoPractice::LESSONS as $lesson) {
        // Every lesson is a position of the practice board with Black, the player, to move.
        expect($rules->serialize($rules->deserialize($lesson['position'])))->toBe($lesson['position'])
            ->and($rules->turn($rules->deserialize($lesson['position'])))->toBe('w');
    }

    // Liberties: one move takes the white stone, a move elsewhere takes nothing.
    $start = $rules->deserialize($lessons['capture']['position']);
    expect($stones($rules->apply($start, 'e4'), 'o'))->toBe(0)
        ->and($stones($rules->apply($start, 'a1'), 'o'))->toBe(1);

    // Groups: the last liberty of the three takes all three.
    expect($stones($rules->apply($rules->deserialize($lessons['group']['position']), 'f4'), 'o'))->toBe(0);

    // Out of atari: running onto e4 gives three liberties.
    expect(goLiberties($rules, $rules->apply($rules->deserialize($lessons['escape']['position']), 'e4'), 'x'))->toBe(3);

    // Forbidden points: j9 is suicide; a1 has no liberty of its own but takes two stones.
    $forbidden = $rules->deserialize($lessons['forbidden']['position']);
    expect($rules->legalMoves($forbidden))->not->toContain('j9')->toContain('a1')
        ->and($stones($rules->apply($forbidden, 'a1'), 'o'))->toBe(2);

    // Ko: d5 is refused at once; after a move elsewhere and White's pass it takes the stone on e5.
    expect($rules->legalMoves($rules->deserialize($lessons['ko']['position'])))->not->toContain('d5');
    [$ko] = $play('ko', ['h8']);
    expect($rules->legalMoves($ko))->toContain('d5')
        ->and($stones($ko, 'o') - $stones($rules->apply($ko, 'd5'), 'o'))->toBe(1);

    // Two eyes: after c1 White may play into neither (each would be suicide); after b1 White's c1 leaves one eye, which
    // White fills in turn.
    $eyes = $rules->deserialize($lessons['eyes']['position']);
    expect($lessons['eyes']['points'])->toBe(['c1'])
        ->and($rules->legalMoves($rules->apply($eyes, 'c1')))->not->toContain('b1')->not->toContain('d1')
        ->and($rules->legalMoves($rules->apply($eyes, 'b1')))->toContain('c1');

    // The ladder: d3, then always the atari that leaves the runner two liberties, until it is taken at the edge.
    [$ladder] = $play('ladder', ['d3', 'c1', 'b1', 'a3', 'a1']);
    expect($stones($ladder, 'o'))->toBe(0);
    // An atari from the wrong side lets White run free.
    [$escaped] = $play('ladder', ['c2', 'd4']);
    expect(goLiberties($rules, $escaped, 'o'))->toBeGreaterThanOrEqual(3);

    // The count: passing at once loses (the dead stone counts, the area beside it is nobody's); taking it first wins by 1.5.
    $count = $rules->deserialize($lessons['count']['position']);
    expect($rules->outcome($rules->apply($count, 'pass'), []))->toBe(['result' => '0-1', 'reason' => 'score']);
    [$won] = $play('count', ['a8', 'pass']);
    expect($rules->outcome($won, []))->toBe(['result' => '1-0', 'reason' => 'score'])
        ->and($rules->areaCount($won)['margin'])->toBe(1.5);
});

/** The fewest liberties of the groups of this colour on the board (0 without a stone of it). */
function goLiberties(GoRules $rules, array $position, string $colour): int
{
    $fewest = null;

    foreach (str_split($position['board']) as $point => $stone) {
        if ($stone !== $colour) {
            continue;
        }

        // The group's liberties: the empty points next to its stones, by a flood over the board text.
        $seen = [$point => true];
        $stack = [$point];
        $liberties = [];

        while ($stack !== []) {
            $at = array_pop($stack);

            foreach (goNeighbours($at, $rules->size()) as $next) {
                if ($position['board'][$next] === '.') {
                    $liberties[$next] = true;
                } elseif ($position['board'][$next] === $colour && ! isset($seen[$next])) {
                    $seen[$next] = true;
                    $stack[] = $next;
                }
            }
        }

        $fewest = min($fewest ?? PHP_INT_MAX, count($liberties));
    }

    return $fewest ?? 0;
}

/** @return list<int> */
function goNeighbours(int $point, int $size): array
{
    $row = intdiv($point, $size);
    $file = $point % $size;

    return array_values(array_filter([$row > 0 ? $point - $size : null, $file > 0 ? $point - 1 : null, $file < $size - 1 ? $point + 1 : null, $row < $size - 1 ? $point + $size : null], fn (?int $p): bool => $p !== null));
}

test('every word of the practice is there in German', function () {
    $german = json_decode((string) file_get_contents(lang_path('de.json')), true, flags: JSON_THROW_ON_ERROR);
    $keys = [];

    preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents(resource_path('views/pages/board/⚡go-practice.blade.php')), $found);
    array_push($keys, ...array_map(stripslashes(...), $found[1]));
    preg_match_all("/trans_choice\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents(resource_path('views/pages/board/⚡go-practice.blade.php')), $found);
    array_push($keys, ...array_map(stripslashes(...), $found[1]));

    foreach (GoPractice::LESSONS as $lesson) {
        array_push($keys, $lesson['title'], $lesson['text'], $lesson['task'], $lesson['done'], $lesson['retry'], ...(isset($lesson['closingNote']) ? [$lesson['closingNote']] : []));
    }

    expect(array_values(array_diff(array_unique($keys), array_keys($german))))->toBe([]);

    $this->withSession(['locale' => 'de'])->get(route('board.go-practice'))->assertOk()
        ->assertSee('Go · Üben')->assertSee('Freiheiten und Schlagen')->assertSee('Die Treppe')->assertSee('Gibt keinen Stein her')->assertSee('Wal');
});
