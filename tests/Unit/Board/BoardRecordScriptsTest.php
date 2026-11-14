<?php

/*
 * The record a board game's page holds (resources/js/boardRecord.js, used by boardGame.js follow()): a state without
 * the record is joined to it only where it is the very next change (security N4, 2026-11-14: a move taken back and
 * replaced while the page missed it stayed in the list). The four cases are tests/js/boardRecord.test.mjs.
 */
test('a state without the record joins the page\'s record only as its next change', function () {
    nodeTest('tests/js/boardRecord.test.mjs', 4);
});
