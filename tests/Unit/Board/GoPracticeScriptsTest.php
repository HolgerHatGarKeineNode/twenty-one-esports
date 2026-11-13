<?php

use App\Support\Board\GoRules;
use Illuminate\Process\Factory;

/*
 * Go practice (DerCaddy, 2026-10-27) plays its bots and words its tips in the browser, on its own go engine
 * (resources/js/go), while the server judges every move by GoRules. So the engine has to know the server's rules
 * exactly: the second case holds both to the same legal moves, the same count and the same position after a move in a
 * thousand positions of random games on all three boards, ko and captures among them. The cases of the scripts
 * themselves are tests/js/go/*.test.mjs under node:test, 22 of them: 8 for the engine (engine.test.mjs), 6 for the
 * bots (bots.test.mjs), 8 for the tips (tips.test.mjs).
 */
test('the go practice engine, bots and tips keep their cases', function () {
    nodeTest('tests/js/go/*.test.mjs', 22, 180);
});

test('the engine of the go practice knows the server\'s rules: the same legal moves, count and next position in a thousand positions', function () {
    mt_srand(27);
    $cases = [];

    // Random games, nearly always a stone, so the boards fill up and stones get captured; a position now and then.
    foreach ([9 => 700, 13 => 200, 19 => 100] as $size => $wanted) {
        $rules = new GoRules($size);
        $taken = 0;

        while ($taken < $wanted) {
            $position = $rules->start();

            for ($ply = 0; $ply < $rules->moveLimit() && $taken < $wanted && $position['passes'] < 2; $ply++) {
                $legal = $rules->legalMoves($position);
                $stones = array_values(array_diff($legal, ['pass']));
                $move = $stones !== [] && mt_rand(0, 99) < 96 ? $stones[mt_rand(0, count($stones) - 1)] : 'pass';

                if (mt_rand(0, 9) === 0) {
                    $count = $rules->areaCount($position);
                    sort($legal, SORT_STRING);
                    $cases[] = [
                        'text' => $rules->serialize($position),
                        'move' => $move,
                        'legal' => $legal,
                        'black' => $count['w']['score'],
                        'white' => $count['b']['score'],
                        'next' => $rules->serialize($rules->apply($position, $move)),
                        'ko' => $position['ko'] !== null,
                    ];
                    $taken++;
                }

                $position = $rules->apply($position, $move);
            }
        }
    }

    $script = 'import("./resources/js/go/engine.js").then(({ GoBoard }) => { let input = ""; process.stdin.on("data", (chunk) => (input += chunk));'
        .' process.stdin.on("end", () => console.log(JSON.stringify(JSON.parse(input).map((c) => { const b = GoBoard.fromText(c.text); const legal = b.legalIds().sort(); const count = b.count();'
        .' const text = b.toText(); b.playId(c.move); return { text, legal, black: count.black, white: count.white, next: b.toText() }; })))); })';
    $run = (new Factory)->path(dirname(__DIR__, 3))->timeout(120)->input((string) json_encode($cases))->run(['node', '-e', $script]);

    expect($run->successful())->toBeTrue($run->errorOutput());

    $engine = json_decode($run->output(), true);
    $server = array_map(fn (array $case): array => ['text' => $case['text'], 'legal' => $case['legal'], 'black' => $case['black'], 'white' => $case['white'], 'next' => $case['next']], $cases);

    expect($engine)->toHaveCount(1000)
        ->and(array_keys(array_filter($engine, fn (array $answer, int $i): bool => $answer != $server[$i], ARRAY_FILTER_USE_BOTH)))->toBe([])
        // Ko and captures are in there.
        ->and(count(array_filter($cases, fn (array $case): bool => $case['ko'])))->toBeGreaterThan(3)
        ->and(count(array_filter($cases, fn (array $case): bool => strlen($case['next']) < strlen($case['text']) - 1)))->toBeGreaterThan(20);
});
