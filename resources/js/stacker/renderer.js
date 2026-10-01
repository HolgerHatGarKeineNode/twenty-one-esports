/**
 * Blockfill drawing: the well, the stack, the ghost, the falling piece, the
 * flash of a mined block, and the small hold/next previews, onto a 2D canvas
 * context the page hands in. Reads the game, never changes it.
 *
 * The well shows the 20 rows of the field plus the two lowest hidden rows on
 * top, where a piece appears, a little darker: a new piece is visible from
 * its first tick on.
 */

import { HEIGHT, WIDTH, activeCells, dropY } from './engine.js';
import { SHAPES } from './pieces.js';
import { FEE_COLORS, MINED, MINED_EDGE, WELL, WELL_LINE } from './palette.js';

/** Rows drawn: the visible 20 and the last two hidden ones. */
export const SHOWN_ROWS = 22;
const FIRST_ROW = HEIGHT - SHOWN_ROWS;
const SPAWN_ZONE = '#0A0A0C';

function cellRect(ctx, x, y, size, color) {
    const inset = Math.max(1, Math.round(size / 14));
    ctx.fillStyle = color;
    ctx.fillRect(x + inset, y + inset, size - 2 * inset, size - 2 * inset);
    const bevel = Math.max(1, Math.round(size / 9));
    ctx.fillStyle = 'rgba(255,255,255,0.28)';
    ctx.fillRect(x + inset, y + inset, size - 2 * inset, bevel);
    ctx.fillStyle = 'rgba(0,0,0,0.28)';
    ctx.fillRect(x + inset, y + size - inset - bevel, size - 2 * inset, bevel);
}

function ghostRect(ctx, x, y, size, color) {
    const inset = Math.max(1, Math.round(size / 14));
    const line = Math.max(1, Math.round(size / 14));
    ctx.globalAlpha = 0.16;
    ctx.fillStyle = color;
    ctx.fillRect(x + inset, y + inset, size - 2 * inset, size - 2 * inset);
    ctx.globalAlpha = 1;
    ctx.strokeStyle = color;
    ctx.lineWidth = line;
    ctx.strokeRect(x + inset + line / 2, y + inset + line / 2, size - 2 * inset - line, size - 2 * inset - line);
}

/**
 * Draws the well. `flash` (0..1) lights the `mined` lowest rows as freshly
 * mined blocks; 0 draws none.
 *
 * @param {CanvasRenderingContext2D} ctx in CSS pixels (the page scales for the pixel ratio)
 * @param {object} game
 * @param {{cell: number, mined?: number, flash?: number}} options
 */
export function drawWell(ctx, game, { cell, mined = 0, flash = 0 }) {
    const width = cell * WIDTH;
    const height = cell * SHOWN_ROWS;
    ctx.fillStyle = WELL;
    ctx.fillRect(0, 0, width, height);
    ctx.fillStyle = SPAWN_ZONE;
    ctx.fillRect(0, 0, width, cell * 2);
    ctx.fillStyle = WELL_LINE;
    for (let row = 1; row < SHOWN_ROWS; row++) {
        ctx.fillRect(0, row * cell - 1, width, 1);
    }

    for (let y = FIRST_ROW; y < HEIGHT; y++) {
        for (let x = 0; x < WIDTH; x++) {
            const value = game.board[y * WIDTH + x];
            if (value !== 0) {
                cellRect(ctx, x * cell, (y - FIRST_ROW) * cell, cell, FEE_COLORS[value - 1]);
            }
        }
    }

    const c = game.current;
    if (c !== null && !game.finished && !game.toppedOut) {
        const color = FEE_COLORS[c.piece];
        const ghostTop = dropY(game);
        if (ghostTop !== c.y) {
            for (const [dx, dy] of SHAPES[c.piece][c.rot]) {
                const y = ghostTop + dy;
                if (y >= FIRST_ROW) {
                    ghostRect(ctx, (c.x + dx) * cell, (y - FIRST_ROW) * cell, cell, color);
                }
            }
        }
        for (const [x, y] of activeCells(game)) {
            if (y >= FIRST_ROW) {
                cellRect(ctx, x * cell, (y - FIRST_ROW) * cell, cell, color);
            }
        }
    }

    if (flash > 0 && mined > 0) {
        ctx.globalAlpha = Math.min(1, flash);
        for (let row = SHOWN_ROWS - mined; row < SHOWN_ROWS; row++) {
            ctx.fillStyle = MINED;
            ctx.fillRect(0, row * cell, width, cell);
            ctx.fillStyle = MINED_EDGE;
            ctx.fillRect(0, row * cell + cell - Math.max(2, Math.round(cell / 9)), width, Math.max(2, Math.round(cell / 9)));
            ctx.fillStyle = '#FFFFFF';
            ctx.fillRect(0, row * cell, width, Math.max(2, Math.round(cell / 8)));
        }
        ctx.globalAlpha = 1;
    }
}

/**
 * Draws one piece centred in a preview box of `width` x `height` CSS pixels.
 *
 * @param {CanvasRenderingContext2D} ctx
 * @param {number} piece index into PIECE_CODES, or -1 for none
 */
export function drawPreview(ctx, piece, { width, height, cell }) {
    ctx.clearRect(0, 0, width, height);
    if (piece < 0) {
        return;
    }
    const cells = SHAPES[piece][0];
    const xs = cells.map(([x]) => x);
    const ys = cells.map(([, y]) => y);
    const minX = Math.min(...xs);
    const minY = Math.min(...ys);
    const w = (Math.max(...xs) - minX + 1) * cell;
    const h = (Math.max(...ys) - minY + 1) * cell;
    const left = Math.round((width - w) / 2);
    const top = Math.round((height - h) / 2);
    for (const [x, y] of cells) {
        cellRect(ctx, left + (x - minX) * cell, top + (y - minY) * cell, cell, FEE_COLORS[piece]);
    }
}
