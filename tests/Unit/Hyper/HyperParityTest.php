<?php

/*
| The PHP rules core and the balance simulator (tools/hbsim) play the same games for the same seed:
| same winner, same rounds, same last board, same loot. The balance gate measured in Rust only holds
| for the game the server runs if this holds.
|
| The default suite replays the committed fixture (150 games, plus 40 team games of P4: 2v2 and 3v3 with
| and without limit 20, lines ending in `t2`; no cargo needed). The group
| `hbsim-parity` builds the simulator and compares 1,000 seeds per player count; it is excluded from
| the default run (phpunit.xml) and runs on demand:
|   vendor/bin/pest --group=hbsim-parity
*/

/**
 * @return list<string>
 */
function hyperReplay(string $output): array
{
    $expected = array_values(array_filter(explode("\n", $output), fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#')));

    return array_map(function (string $line): string {
        $fields = explode(' ', $line);
        [$seed, $players, $limit] = array_map('intval', $fields);

        return hyperParityLine($seed, $players, $limit, isset($fields[8]) ? (int) substr($fields[8], 1) : 0);
    }, $expected);
}

it('plays the simulator\'s fixture games move for move', function (): void {
    $fixture = (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/hyper/parity.txt');
    $expected = array_values(array_filter(explode("\n", $fixture), fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#')));

    expect($expected)->toHaveCount(190)
        ->and(hyperReplay($fixture))->toBe($expected);
});

it('plays 1,000 seeds per player count like the freshly built simulator', function (): void {
    $dir = dirname(__DIR__, 3).'/tools/hbsim';
    exec('cargo build --release -j 8 --manifest-path '.escapeshellarg("{$dir}/Cargo.toml").' 2>&1', $build, $code);

    expect($code)->toBe(0, "cargo build failed:\n".implode("\n", $build));

    foreach ([[0, 1000], [20, 200]] as [$limit, $count]) {
        foreach ([2, 3, 4, 5, 6] as $players) {
            $output = (string) shell_exec(escapeshellarg("{$dir}/target/release/hbsim")." parity 1 {$count} {$players} {$limit}");
            $expected = array_values(array_filter(explode("\n", $output)));

            expect($expected)->toHaveCount($count)
                ->and(hyperReplay($output))->toBe($expected, "{$players} players, limit {$limit}");
        }

        // Team games (P4): 2v2 and 3v3, seated alternately.
        foreach ([4, 6] as $players) {
            $output = (string) shell_exec(escapeshellarg("{$dir}/target/release/hbsim")." parity 1 {$count} {$players} {$limit} 2");
            $expected = array_values(array_filter(explode("\n", $output)));

            expect($expected)->toHaveCount($count)
                ->and(hyperReplay($output))->toBe($expected, "{$players} players in two teams, limit {$limit}");
        }
    }
})->group('hbsim-parity');
