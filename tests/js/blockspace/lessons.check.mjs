/**
 * Holds every lesson of the Blockspace tutorial to its goal: run by tests/Feature/BlockspacePracticeTest.php with the
 * lessons as the page gets them (a JSON file), because the positions are the server's and the verdict the browser's.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { ORI, applyMove, clone, key, moves, moveText, rng, rot, stateOf, think, winner } from '../../../resources/js/blockspace/engine.js';
import { lessonVerdict } from '../../../resources/js/blockspace/lessons.js';

const lessons = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const textOf = (move) => moveText(move.i, move.cells);

/** The verdict when the player plays `list`, one move after the other. */
function verdict(lesson, list) {
    const g = stateOf(lesson.position);
    let result = 'going';
    list.forEach((move, index) => {
        const before = clone(g);
        assert.ok(applyMove(g, move), lesson.id + ': ' + move + ' is not legal');
        result = lessonVerdict(lesson, before, g, move, index + 1);
    });

    return result;
}

for (const lesson of lessons) {
    const g = stateOf(lesson.position);
    assert.equal(g.side, 0, lesson.id + ': the player moves first');
    assert.ok(!g.over, lesson.id + ': the game runs');
    const options = moves(g, 0);

    if (lesson.goal === 'pass') {
        assert.equal(verdict(lesson, ['pass']), 'done', lesson.id);
        assert.ok(options.length > 0, lesson.id + ': passing is a choice here');
        assert.ok(options.every((move) => move.i !== 0), lesson.id + ': the long one on top fits nowhere for the player');
    } else if (lesson.goal === 'win') {
        let won = 0;
        for (let seed = 1; seed <= 20; seed++) {
            const game = stateOf(lesson.position);
            const r = rng(seed);
            while (!game.over) {
                const move = think(game, game.side === 0 ? 'mittel' : 'leicht', r, 1);
                applyMove(game, move ? textOf(move) : 'pass');
            }
            if (winner(game) === 0) won++;
        }
        assert.ok(won >= 16, lesson.id + ': a sensible player wins it, won ' + won + ' of 20');
    } else {
        assert.ok(options.length > 0, lesson.id + ': there is a move');
        const good = options.filter((move) => {
            const after = clone(g);
            applyMove(after, textOf(move));
            const result = lessonVerdict(lesson, g, after, textOf(move), lesson.count ?? 1);

            return result === 'done';
        });
        assert.ok(good.length > 0, lesson.id + ': some move reaches the goal');
        if (['touch', 'fee', 'slot'].includes(lesson.goal)) assert.ok(good.length < options.length, lesson.id + ': not every move does');
    }
    if (lesson.id === 'turn') {
        // Turned alone the form fits nowhere: every way to set it is a mirrored one.
        const flat = ORI[g.pool[0].f].reduce((best, shape, index, all) => (Math.max(...shape.map((p) => p[0])) < Math.max(...all[best].map((p) => p[0])) ? index : best), 0);
        const turned = new Set();
        let shape = ORI[g.pool[0].f][flat];
        for (let i = 0; i < 4; i++) {
            turned.add(key(shape));
            shape = rot(shape);
        }
        const shapeOf = (cells) => {
            const top = Math.min(...cells.map((c) => (c / 9) | 0));
            const left = Math.min(...cells.map((c) => c % 9));

            return key(cells.map((c) => [((c / 9) | 0) - top, (c % 9) - left]).sort((a, b) => a[0] - b[0] || a[1] - b[1]));
        };
        assert.ok(options.every((move) => !turned.has(shapeOf(move.cells))), 'turn: only mirrored ways fit');
    }
    if (lesson.goal === 'place' && lesson.count === 2) {
        const first = options[0];
        const next = clone(g);
        applyMove(next, textOf(first));
        assert.equal(next.side, 0, lesson.id + ': the player moves again');
        assert.equal(verdict(lesson, [textOf(first)]), 'going', lesson.id);
        assert.equal(verdict(lesson, [textOf(first), textOf(moves(next, 0)[0])]), 'done', lesson.id);
    }
    if (lesson.id === 'fuse') {
        const wrong = options.find((move) => move.i !== 0);
        const after = clone(g);
        applyMove(after, textOf(wrong));
        assert.ok(!after.pool.some((tx) => tx.f === g.pool[0].f), 'fuse: the oldest has burned');
    }
    if (lesson.id === 'corner') {
        const edge = stateOf(lesson.position);
        assert.ok(!applyMove(edge, '0:g3'), 'corner: a stone along the edge is refused');
    }
}
console.log('lessons ok: ' + lessons.length);
