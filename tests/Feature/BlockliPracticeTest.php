<?php

use App\Games\GameRegistry;
use App\Support\Board\BlockliPractice;
use App\Support\Board\BlockliRules;
use Livewire\Livewire;
use Tests\Support\BlockliOn;

/*
|--------------------------------------------------------------------------
| Blockli practice against bots (DerCaddy, 2026-10-23)
|--------------------------------------------------------------------------
|
| A guest finds six lessons and three bots behind the lobby's "Practice against bots". The game stays in the browser
| (resources/js/blockli/practice.js); the page only judges each move it is sent by BlockliRules, as a live game's move
| is judged, and stores nothing (App\Support\Board\BlockliPractice). Every lesson's position holds what its words
| promise. The bots and the tips are tested in node, and the engine they run on against the server's rules
| (tests/Unit/Board/BlockliPracticeScriptsTest.php).
|
*/

beforeEach(function () {
    BlockliOn::play();
});

/** The page's answer to one call, as the browser gets it. */
function practiceCall(string $method, mixed ...$arguments): ?array
{
    return Livewire::test('pages::board.practice', ['board' => 'blockli'])->call($method, ...$arguments)->effects['returns'][0] ?? null;
}

const PRACTICE_START = 'e1 e9 10 10 w - 0';

test('a guest finds the practice in the lobby and gets the lessons and the bots', function () {
    $this->get(route('board.lobby', 'blockli'))->assertOk()
        ->assertSee(route('board.practice', 'blockli'))->assertSee('Practice against bots');

    $this->get(route('board.practice', 'blockli'))->assertOk()
        ->assertSee('Blockli · Practice')->assertSee('blockliPractice(', false)->assertSee('boardGame(boardConfig())', false)
        ->assertSee('Move your pawn')->assertSee('Count the race')->assertSee('Hodler')->assertSee('Miner')->assertSee('Whale');
});

test('only Blockli has bots: another game\'s practice and a Blockli switched off are not found', function () {
    $this->get('/games/nine-mens-morris/practice')->assertNotFound();
    $this->get('/games/chess/practice')->assertNotFound();

    config(['esports.board_games.games.blockli.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);
    $this->get('/games/blockli/practice')->assertNotFound();
    $this->get(route('board.lobby', 'blockli'))->assertNotFound();
});

test('the page judges a move in the position the browser holds and answers what the board needs', function () {
    $answer = practiceCall('play', PRACTICE_START, 'e1-e2', [PRACTICE_START]);

    expect($answer)->toMatchArray(['ok' => true, 'position' => 'e2 e9 10 10 b - 1', 'turn' => 'b', 'outcome' => null])
        ->and($answer['move'])->toBe(['move' => 'e1-e2', 'notation' => 'e2', 'path' => ['e1', 'e2']])
        ->and($answer['pieces']['e2'])->toBe(['side' => 'w', 'kind' => 'pawn'])
        ->and($answer['legal'])->toHaveCount(131)
        ->and($answer['legal'])->toContain(['move' => 'e9-e8', 'path' => ['e9', 'e8']], ['move' => 'a1h', 'path' => ['a1/b2', 'h']])
        ->and($answer['standing']['w'])->toMatchArray(['steps' => 7, 'blocks' => 10])
        ->and($answer['standing']['b'])->toMatchArray(['steps' => 8, 'blocks' => 10]);

    $block = practiceCall('play', 'e2 e9 10 10 b - 1', 'e8h', [PRACTICE_START, 'e2 e9 10 10 b - 1']);
    expect($block)->toMatchArray(['ok' => true, 'position' => 'e2 e9 10 9 w e8hb 0'])
        ->and($block['move'])->toBe(['move' => 'e8h', 'notation' => 'e8h', 'path' => ['e8/f9', 'h']])
        ->and($block['pieces']['e8/f9'])->toBe(['side' => 'b', 'kind' => 'block-h']);
});

test('the page refuses what a live game would refuse, and text that is no position', function () {
    expect(practiceCall('play', PRACTICE_START, 'e1-e3', [PRACTICE_START]))->toBe(['ok' => false, 'error' => 'illegal_move'])
        ->and(practiceCall('play', PRACTICE_START, 'e9-e8', []))->toBe(['ok' => false, 'error' => 'illegal_move'])
        ->and(practiceCall('play', PRACTICE_START, ['e1-e2'], []))->toBe(['ok' => false, 'error' => 'illegal_move'])
        // Overlapping blocks, eleven blocks a side, a pawn without a way: no position of the game.
        ->and(practiceCall('play', 'e1 e9 10 8 w e5hb,e5vb 0', 'e1-e2', []))->toBe(['ok' => false, 'error' => 'no_position'])
        ->and(practiceCall('play', 'e1 e9 10 10 w e5hb 0', 'e1-e2', []))->toBe(['ok' => false, 'error' => 'no_position'])
        ->and(practiceCall('play', 'nonsense', 'e1-e2', []))->toBe(['ok' => false, 'error' => 'no_position'])
        ->and(practiceCall('play', PRACTICE_START, 'e1-e2', 'not a list'))->toBe(['ok' => false, 'error' => 'no_position'])
        ->and(practiceCall('play', PRACTICE_START, 'e1-e2', array_fill(0, BlockliPractice::HISTORY_LIMIT + 1, PRACTICE_START)))->toBe(['ok' => false, 'error' => 'no_position'])
        // White on rank 9 has won: nothing more is played.
        ->and(practiceCall('play', 'e9 e5 10 10 b - 0', 'e5-e4', []))->toBe(['ok' => false, 'error' => 'game_over']);
});

test('a game ends as a live one does: on the far side, and drawn by the same position too often', function () {
    $goal = practiceCall('play', 'e8 a5 10 10 w - 0', 'e8-e9', ['e8 a5 10 10 w - 0']);
    expect($goal)->toMatchArray(['ok' => true, 'outcome' => ['result' => '1-0', 'reason' => 'goal'], 'legal' => []]);

    // The position after the move stood there twenty times before: the twenty-first draws (BlockliRules::REPETITIONS).
    $before = 'e1 e8 10 10 w - 3';
    $history = [...array_fill(0, BlockliRules::REPETITIONS - 1, 'e2 e8 10 10 b - 0'), $before];
    $repeat = practiceCall('play', $before, 'e1-e2', $history);
    expect($repeat)->toMatchArray(['ok' => true, 'outcome' => ['result' => '1/2-1/2', 'reason' => 'repetition'], 'legal' => []]);
    expect(practiceCall('play', $before, 'e1-e2', array_slice($history, 1))['outcome'])->toBeNull();
});

test('a position the browser starts from opens with its pieces, moves and standing', function () {
    expect(practiceCall('open', PRACTICE_START))->toMatchArray(['ok' => true, 'position' => PRACTICE_START, 'turn' => 'w', 'outcome' => null])
        ->and(practiceCall('open', PRACTICE_START)['legal'])->toHaveCount(131)
        ->and(practiceCall('open', 'e1 e9'))->toBe(['ok' => false, 'error' => 'no_position']);
});

test('every lesson holds what its words promise', function () {
    $rules = new BlockliRules;
    $lessons = collect(BlockliPractice::LESSONS)->keyBy('id');
    $at = fn (string $id): array => $rules->deserialize($lessons[$id]['position']);
    $way = fn (array $position, string $side): ?int => $rules->distance($position, $side);
    // The blocks of White's that make Black's way at least `steps` longer.
    $longer = function (array $position, int $steps) use ($rules, $way): array {
        $blocks = array_filter($rules->legalMoves($position), fn (string $move): bool => ! str_contains($move, '-') && $way($rules->apply($position, $move), 'b') - $way($position, 'b') >= $steps);
        sort($blocks);

        return array_values($blocks);
    };

    expect($lessons->keys()->all())->toBe(['move', 'around', 'block', 'jump', 'fence', 'race']);
    foreach (BlockliPractice::LESSONS as $lesson) {
        // Every lesson is a position of the game with White, the player, to move.
        expect($rules->serialize($rules->deserialize($lesson['position'])))->toBe($lesson['position'])
            ->and($rules->turn($rules->deserialize($lesson['position'])))->toBe('w');
    }

    // Two steps to the top row; Black far from his.
    expect([$way($at('move'), 'w'), $way($at('move'), 'b')])->toBe([2, 8]);

    // Around the left end six steps; with the left end closed too, the right one is seven.
    expect($way($at('around'), 'w'))->toBe(6)
        ->and($way($rules->deserialize('e6 i9 10 6 w a6hb,c6hb,e6hb,g6hb 0'), 'w'))->toBe(7);

    // Black is one step from his goal, and only the two blocks right under his pawn make his way longer.
    expect($way($at('block'), 'b'))->toBe(1)
        ->and($longer($at('block'), 1))->toBe(['d1h', 'e1h']);

    // Face to face: the jump is on.
    expect($rules->legalMoves($at('jump')))->toContain('e4-e6');

    // The fence: the block under h8 and i8 is refused only because it would lock Black in; across his way further
    // down the i file a block costs him two steps.
    expect($rules->legalMoves($at('fence')))->not->toContain($lessons['fence']['closing'])
        ->and($rules->legalMoves($rules->deserialize('e3 a9 9 10 w h8vw 0')))->toContain($lessons['fence']['closing'])
        ->and($longer($at('fence'), 2))->toBe(['h1h', 'h2h', 'h3h', 'h4h', 'h5h', 'h6h']);

    // The race: five steps against four with Black moving next is lost by running; a block of two steps or more turns it.
    $race = $at('race');
    expect([$way($race, 'w'), $way($race, 'b')])->toBe([5, 4])
        ->and($longer($race, 2))->toContain('h3h')->toContain('h4h')
        ->and($way($rules->apply($race, 'h3h'), 'b'))->toBe(7)
        ->and($way($rules->apply($race, 'g4h'), 'b'))->toBe(5);
});

test('every word of the practice is there in German', function () {
    $german = json_decode((string) file_get_contents(lang_path('de.json')), true, flags: JSON_THROW_ON_ERROR);
    $keys = [];

    foreach ([resource_path('views/pages/board/⚡practice.blade.php'), resource_path('views/pages/board/partials/lobby-play.blade.php')] as $file) {
        preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file), $found);
        array_push($keys, ...array_map(stripslashes(...), $found[1]));
    }
    foreach (BlockliPractice::LESSONS as $lesson) {
        array_push($keys, $lesson['title'], $lesson['text'], $lesson['task'], $lesson['done'], $lesson['retry']);
    }

    expect(array_values(array_diff(array_unique($keys), array_keys($german))))->toBe([]);

    app()->setLocale('de');
    $this->get(route('board.practice', 'blockli'))->assertOk()
        ->assertSee('Blockli · Üben')->assertSee('Figur ziehen')->assertSee('Nie ganz einsperren')->assertSee('Gibt keinen Block her. Er läuft nur.')->assertSee('Wal');
});
