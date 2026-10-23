<?php

use App\Support\Board\BlockliRules;
use Illuminate\Process\Factory;

/*
 * Blockli practice (DerCaddy, 2026-10-23) plays its bots and words its tips in the browser, on the engine of DerCaddy's
 * prototype (resources/js/blockli), while the server judges every move by BlockliRules. So the engine has to know the
 * server's rules exactly: the second case holds both to the same legal moves and ways in a thousand positions of random
 * games. The cases of the scripts themselves are tests/js/blockli/*.test.mjs under node:test, 17 of them: 10 for the
 * bots and the bridge between the two notations (bots.test.mjs), 7 for the tips (tips.test.mjs).
 */
test('the practice bots and tips keep their cases', function () {
    nodeTest('tests/js/blockli/*.test.mjs', 17, 120);
});

test('the engine of the practice bots knows the server\'s rules: the same legal moves and ways in a thousand positions', function () {
    $rules = new BlockliRules;
    mt_srand(21);
    $positions = [];

    // Random games, a little more often a pawn move than a block, so the boards fill up and the pawns meet.
    while (count($positions) < 1000) {
        $position = $rules->start();

        for ($ply = 0; $ply < 120 && count($positions) < 1000; $ply++) {
            $legal = $rules->legalMoves($position);
            $positions[] = $position;
            $pawns = array_values(array_filter($legal, fn (string $move): bool => str_contains($move, '-')));
            $move = mt_rand(0, 99) < 55 ? $pawns[mt_rand(0, count($pawns) - 1)] : $legal[mt_rand(0, count($legal) - 1)];
            $position = $rules->apply($position, $move);

            if ($rules->outcome($position, []) !== null) {
                break;
            }
        }
    }

    $texts = array_map($rules->serialize(...), $positions);
    $script = 'import("./resources/js/blockli/bots.js").then((b) => { let input = ""; process.stdin.on("data", (chunk) => (input += chunk));'
        .' process.stdin.on("end", () => console.log(JSON.stringify(JSON.parse(input).map((text) => { const s = b.stateOf(text); return { legal: b.legalServerMoves(s), ways: b.distances(s) }; })))); })';
    $run = (new Factory)->path(dirname(__DIR__, 3))->timeout(120)->input((string) json_encode($texts))->run(['node', '-e', $script]);

    expect($run->successful())->toBeTrue($run->errorOutput());

    $engine = json_decode($run->output(), true);
    $server = array_map(function (array $position) use ($rules): array {
        $legal = $rules->legalMoves($position);
        sort($legal, SORT_STRING);

        return ['legal' => $legal, 'ways' => [$rules->distance($position, 'w'), $rules->distance($position, 'b')]];
    }, $positions);

    expect($engine)->toHaveCount(1000)
        ->and(array_keys(array_filter($engine, fn (array $answer, int $i): bool => $answer !== $server[$i], ARRAY_FILTER_USE_BOTH)))->toBe([])
        // Blocks and meetings are in there: the pawns faced each other and boards held many blocks.
        ->and(count(array_filter($positions, fn (array $p): bool => count($p['blocks']) >= 8)))->toBeGreaterThan(100);
});
