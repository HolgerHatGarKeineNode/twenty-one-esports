/**
 * Writes tests/Fixtures/blockspace-games.json from the browser engine: 24 games with random and heuristic moves and
 * passes, with and without the fuse. The server replays them (tests/Unit/Board/BlockspaceRulesTest.php), the browser
 * engine as well (games.test.mjs). Run it when a rule changes, from the repository root: `node tests/js/blockspace/games.fixture.mjs`.
 */
import { createHash } from 'node:crypto';
import { writeFileSync } from 'node:fs';
import * as BS from '../../../resources/js/blockspace/engine.js';

const md5 = (text) => createHash('md5').update(text).digest('hex');
const games = [];
const r = BS.rng(77);

for (let s = 0; s < 24; s++) {
    const fuse = s % 2 === 1;
    const g = BS.newGame(1000 + s, fuse);
    const start = BS.positionOf(g);
    const steps = [];
    let n = 0;
    while (!g.over && n++ < 300) {
        const options = BS.moves(g, g.side);
        const legal = options.map((m) => BS.moveText(m.i, m.cells)).concat('pass').sort();
        let move;
        if (r() < 0.06 || !options.length) {
            BS.pass(g);
            move = 'pass';
        } else {
            const m = s % 3 === 0 ? options[Math.floor(r() * options.length)] : BS.think(g, 'mittel', r, 1);
            move = BS.moveText(m.i, m.cells);
            BS.play(g, m.i, m.cells);
        }
        steps.push({ legal: legal.length, sum: legal.join('|').length, move, after: md5(BS.positionOf(g)) });
    }
    games.push({ start, steps, winner: BS.winner(g) });
}

writeFileSync(new URL('../../Fixtures/blockspace-games.json', import.meta.url), JSON.stringify(games));
