<?php

/*
 * The browser engine of Blockspace against the fixture the server replays too (BlockspaceRulesTest): both are held to the
 * same games. The two cases are tests/js/blockspace/games.test.mjs; games.fixture.mjs writes the fixture.
 */
test('the browser engine replays the games of the fixture', function () {
    nodeTest('tests/js/blockspace/games.test.mjs', 2, 60);
});
