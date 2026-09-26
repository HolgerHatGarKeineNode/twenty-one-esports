/**
 * resources/js/sanNotation.js: German piece letters on display, both languages on input.
 * Run by tests/Feature/Chess/SanNotationTest.php; runnable alone with `node --test`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { displaySan, inputSan } from '../../resources/js/sanNotation.js';

test('German shows K D T L S, English and pawn moves stay as they are', () => {
    assert.equal(displaySan('Bg2', 'de'), 'Lg2');
    assert.equal(displaySan('Nxf3+', 'de'), 'Sxf3+');
    assert.equal(displaySan('Qh8#', 'de-DE'), 'Dh8#');
    assert.equal(displaySan('Rad1', 'de'), 'Tad1');
    assert.equal(displaySan('exd8=Q+', 'de'), 'exd8=D+');
    assert.equal(displaySan('b4', 'de'), 'b4');
    assert.equal(displaySan('O-O-O', 'de'), 'O-O-O');
    assert.equal(displaySan('Bg2', 'en'), 'Bg2');
});

test('a typed move is read in either language', () => {
    assert.equal(inputSan(' Lg2 '), 'Bg2');
    assert.equal(inputSan('Sf3'), 'Nf3');
    assert.equal(inputSan('Dxd7#'), 'Qxd7#');
    assert.equal(inputSan('e8=D'), 'e8=Q');
    assert.equal(inputSan('Bg2'), 'Bg2');
    assert.equal(inputSan('bxc3'), 'bxc3');
});
