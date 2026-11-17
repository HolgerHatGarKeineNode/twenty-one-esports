<?php

/*
 * The browser engine of Blockspace against the fixture the server replays too (BlockspaceRulesTest): both are held to the
 * same games. The three cases are tests/js/blockspace/games.test.mjs; games.fixture.mjs writes the fixture.
 */
test('the browser engine replays the games of the fixture', function () {
    nodeTest('tests/js/blockspace/games.test.mjs', 3, 60);
});

test('the board styles share no class name with the site styles', function () {
    // The /matches block strip styles `.bs-note` in app.css; the board's notes took its nowrap and ellipsis and cut the
    // note on a turn sat out short (2026-11-08). So every class of the board carries a prefix of its own, and no site
    // stylesheet may use it.
    $classes = function (string $file): array {
        preg_match_all('/\.(-?[a-z][a-z0-9_-]*)/', (string) file_get_contents($file), $found);

        return array_values(array_unique($found[1]));
    };
    $root = dirname(__DIR__, 3);
    $board = array_values(array_filter($classes($root.'/resources/js/blockspace/board.css'), fn (string $name): bool => str_starts_with($name, 'bsp-')));
    $site = [];

    foreach (glob($root.'/resources/css/*.css') ?: [] as $file) {
        $site = [...$site, ...$classes($file)];
    }

    expect($board)->toContain('bsp-note')
        ->and($site)->toContain('bs-note')
        ->and(array_values(array_intersect($board, $site)))->toBe([])
        ->and(array_values(array_filter($site, fn (string $name): bool => str_starts_with($name, 'bsp-'))))->toBe([]);
});
