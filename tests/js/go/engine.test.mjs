/**
 * resources/js/go/engine.js: the rules of Go in the browser and the search of the practice bots (DerCaddy,
 * 2026-10-27). The rules are held to the server's in a thousand positions by tests/Unit/Board/GoPracticeScriptsTest.php;
 * these cases are the hand-made ones. Runnable alone with `node --test tests/js/go`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { BLACK, GoBoard, PASS, WHITE, rng, search } from '../../../resources/js/go/engine.js';

/** A position from rows drawn from the top (`x` black, `o` white, `.` empty), as the server writes it. */
function board(rows, turn = 'x', ko = '-', passes = 0) {
    const packed = rows.map((row) => row.replace(/ /g, '').replace(/\.+/g, (run) => String(run.length)));

    return GoBoard.fromText(packed.join('/') + ' ' + turn + ' ' + ko + ' ' + passes);
}

const EMPTY = Array(9).fill('. . . . . . . . .');

test('the text of a position reads and writes as the server writes it', () => {
    for (const text of ['9/9/9/9/9/9/9/9/9 x - 0', '9/9/9/4x4/3xox3/9/9/9/9 o - 1', '9/9/9/3ox4/2o1ox3/3ox4/9/9/9 x d5 0', '13/13/13/13/13/13/13/13/13/13/13/13/13 x - 0']) {
        assert.equal(GoBoard.fromText(text).toText(), text);
    }
    const b = GoBoard.fromText('9/9/9/9/9/9/9/9/9 x - 0');
    assert.equal(b.idOf(b.pointOf('a1')), 'a1');
    assert.equal(b.idOf(b.pointOf('j9')), 'j9');
    assert.equal(b.pointOf('i5'), -1);
    assert.equal(b.pointOf('k1'), -1);
    assert.equal(b.idOf(PASS), 'pass');
});

test('a stone without liberties comes off; a group shares its liberties', () => {
    const b = board(['.........', '.........', '.........', '...xxx...', '..xooox..', '...xx....', '.........', '.........', '.........']);
    assert.equal(b.playId('f4'), 3);
    assert.equal(b.toText(), '9/9/9/3xxx3/2x3x2/3xxx3/9/9/9 o - 0');
    assert.equal(b.toMove, WHITE);
});

test('suicide is not allowed unless the stone captures; the ko forbids taking back at once', () => {
    const corner = board(['.x.......', 'x........', '.........', '.........', '.........', '.........', '.........', 'xo.......', '.xo......'], 'o');
    assert.equal(corner.legal(corner.pointOf('a9')), false, 'a9 has no liberty and takes nothing');
    assert.equal(corner.legal(corner.pointOf('a1')), true, 'a1 takes the stone on b1');

    const ko = board(['.........', '.........', '.........', '...xo....', '..xo.o...', '...xo....', '.........', '.........', '.........']);
    assert.equal(ko.playId('e5'), 1);
    assert.equal(ko.ko, ko.pointOf('d5'));
    assert.equal(ko.legal(ko.pointOf('d5')), false);
    assert.ok(!ko.legalIds().includes('d5'));
    ko.playId('h8');
    ko.playId('h2');
    assert.equal(ko.legal(ko.pointOf('d5')), true, 'after a move elsewhere the ko may be taken back');
});

test('two passes end the game; the count is stones and the area that touches one colour, with 7.5 for White', () => {
    const b = GoBoard.fromText('9/9/9/9/9/9/9/9/9 x - 0');
    b.play(PASS);
    assert.equal(b.over, false);
    b.play(PASS);
    assert.equal(b.over, true);
    assert.deepEqual(b.legalIds(), []);
    assert.equal(b.count().margin, -7.5);
    assert.equal(b.winner(), WHITE);

    const walls = board(Array(9).fill('....xo...'), 'x', '-', 2);
    const count = walls.count();
    assert.equal(count.black, 45);
    assert.equal(count.white, 43.5);
    assert.equal(walls.winner(), BLACK);
    assert.equal(count.owners[walls.pointOf('a1')], BLACK);
    assert.equal(count.owners[walls.pointOf('j9')], WHITE);
});

test('an eye is a point of one colour all round, with at most one diagonal of the other (none on the edge)', () => {
    const b = board(['.........', '.........', '.........', '....x....', '...x.x...', '....x....', '.........', '.x.......', 'x.x......']);
    assert.equal(b.eyeOf(b.pointOf('e5'), BLACK), true);
    assert.equal(b.eyeOf(b.pointOf('e5'), WHITE), false);
    assert.equal(b.eyeOf(b.pointOf('b1'), BLACK), true);
    const false_eye = board(['.........', '.........', '.........', '...ox....', '...x.x...', '....xo...', '.........', '.........', '.........']);
    assert.equal(false_eye.eyeOf(false_eye.pointOf('e5'), BLACK), false, 'two white diagonals make it false');
});

test('random games never break the rules: every stone on the board has a liberty, the text reads back', () => {
    const random = rng(7);
    for (let game = 0; game < 30; game++) {
        const b = GoBoard.fromText('9/9/9/9/9/9/9/9/9 x - 0');
        while (!b.over && b.plies < 400) {
            b.play(b.randomMove(random));
            for (const p of b.points) if (b.color[p]) assert.ok(b.liberties(p).length > 0);
        }
        assert.equal(GoBoard.fromText(b.toText()).toText(), b.toText());
    }
});

test('the search answers a legal move and takes the stones it can take', () => {
    const b = board(['.........', '.........', '.........', '...xxx...', '..xooox..', '...xx....', '.........', '.........', '.........']);
    const result = search(b, { playouts: 1500, random: rng(1) });
    assert.equal(result.move, 'f4');
    assert.ok(result.winrate > 0.5);
    assert.ok(result.playouts === 1500);
    const empty = GoBoard.fromText('9/9/9/9/9/9/9/9/9 x - 0');
    const opening = search(empty, { playouts: 800, random: rng(2) });
    assert.ok(empty.legalIds().includes(opening.move));
    // Ownership runs from -1 (White's) to 1 (Black's).
    assert.ok(Object.values(opening.ownership).every((v) => v >= -1 && v <= 1));
});

test('the search knows the end: it passes when the other side passed and passing wins the count', () => {
    const b = board(Array(9).fill('....xo...'), 'x', '-', 1);
    assert.equal(search(b, { playouts: 600, random: rng(3) }).move, 'pass');
    const time = search(GoBoard.fromText(EMPTY.map((row) => row.replace(/ /g, '').replace(/\.+/g, (run) => String(run.length))).join('/') + ' x - 0'), { playouts: Infinity, time: 50, random: rng(4) });
    assert.ok(time.playouts > 0 && time.playouts < Infinity);
});
