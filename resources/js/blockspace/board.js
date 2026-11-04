/*
 * The board of Blockspace (`input` = `pieces`, App\Support\Board\BlockspaceRules) inside the league's board
 * (boardGame.js): the block, the mempool ladder and the way a player takes a transaction, turns it and sets it. A mouse:
 * left click takes and sets, right click turns, a long right click mirrors. A finger: tap a transaction, tap or drag on
 * the block, tap the stone shown to set it. Keys: 1-5, R, F, the arrows, Enter.
 *
 * Everything is counted in one unit, the side of a cell (--u, board.css): the ladder runs in the block's rows, and a
 * form on the ladder has the size it has on the block.
 *
 * It shows no legal places, finding them is the game (user, 2026-11-04). A stone the rules refuse is refused here with
 * the reason, before anything is sent; the server judges what is sent.
 */
import './board.css';
import { setFocus } from '../focusMode.js';
import { BONUS, C, N, NAMES, ORDER, ORI, R, SIZE, START, cellName, cellOf, flip, key, moveText, readPieces, refuse, rot } from './engine.js';
import { CELL, SIDES, element, form, joints, stone, stoneDefs } from './stones.js';

/** A block's colour by what it pays, from a cheap green to a rich magenta, as a fee explorer shows the mempool. */
const FEE_COLOURS = { 1: '#0A6B3D', 2: '#0A6B3D', 3: '#1F7433', 4: '#4C7A1C', 5: '#7B7A0C', 6: '#8F690E', 7: '#A76016', 8: '#B8452E', 9: '#A1205A' };
const LONG_PRESS_MS = 340;
const PASS_CONFIRM_MS = 3000;
const PASS_CONFIRM_MIN_MS = 250;
/** The cell in px below which the page scrolls to the block, and the largest one. */
const COMFORTABLE_CELL = 27;
/** On a phone: how far the stage reaches into the page's margin, the gap beside the block, and the narrowest folded ladder, in px. */
const PHONE_BLEED = 10;
const PHONE_GAP = 8;
const MIN_STRIP = 64;
const MAX_CELL = 40;
/** The block, the gap and the ladder, in cells (board.css). */
const STAGE_UNITS = 15.3;
const SIDE_KEYS = ['w', 'b'];
/** The colour of stones that may not lie where they are shown. */
const REFUSED = '#FF5A5F';

/** The cells a form takes with `target` as its middle, kept on the block. */
function cellsFor(f, o, target) {
    const shape = ORI[f][o];
    const height = Math.max(...shape.map((part) => part[0])) + 1;
    const width = Math.max(...shape.map((part) => part[1])) + 1;
    const row = Math.min(R - height, Math.max(0, ((target / C) | 0) - ((height - 1) >> 1)));
    const column = Math.min(C - width, Math.max(0, (target % C) - ((width - 1) >> 1)));

    return shape.map(([a, b]) => (row + a) * C + column + b);
}

/** The way a form lies on the ladder, and first in the hand: flat, so the tallest stands three rows high. */
function flatOf(f) {
    const height = (shape) => Math.max(...shape.map((part) => part[0])) + 1;

    return ORI[f].reduce((best, shape, index) => (height(shape) < height(ORI[f][best]) ? index : best), 0);
}

function html(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;

    return node;
}

export function mountPieceBoard(c) {
    const svg = c.$refs.board;
    const stage = svg.closest('[data-piece-stage]');
    const box = svg.closest('[data-board-box]');
    const row = box.parentElement;
    const words = c.t.pieces;
    const calm = matchMedia('(prefers-reduced-motion: reduce)').matches;

    let hand = null;
    let target = START[0];
    let turnedFor = null;
    let shown = readPieces(c.piecesAt(c.shownPly));
    let passArmed = 0;
    let passTimer = null;
    let tiles = [];
    let scrollsTo = false;
    let lastEdge = 0;

    /** Black sees the block from its own side: the same cell turned half way round. */
    const turned = () => c.color === 'b';
    const onView = (cell) => (turned() ? N - 1 - cell : cell);
    const partOf = (cell) => [(onView(cell) / C) | 0, onView(cell) % C];
    const live = () => c.canMove;
    const sideKey = (g) => SIDE_KEYS[g.side];

    /* The drawing: the cells once, the layers above them on every change. */
    stage.prepend(stoneDefs());
    svg.setAttribute('viewBox', `0 0 ${C * CELL} ${R * CELL}`);
    svg.replaceChildren();
    const cells = element('g');
    for (let cell = 0; cell < N; cell++) {
        cells.append(element('rect', { x: (cell % C) * CELL + 4, y: ((cell / C) | 0) * CELL + 4, width: CELL - 8, height: CELL - 8, rx: 8, fill: 'var(--bs-cell)' }));
    }
    const coordinates = element('g', { 'aria-hidden': 'true' });
    const starts = element('g');
    const jointLayer = element('g');
    const stoneLayer = element('g');
    const lastLayer = element('g');
    const ghost = element('g', { class: 'bs-ghost' });
    svg.append(cells, coordinates, starts, jointLayer, stoneLayer, lastLayer, ghost);
    const stoneAt = new Map();

    /** The names of the files and ranks on the edge cells, as the moves write them; the stones lie over them. */
    function drawCoordinates() {
        coordinates.replaceChildren();
        const label = (text, x, y, anchor) => {
            const node = element('text', { x, y, 'text-anchor': anchor, 'font-size': 24, 'font-weight': 700, fill: '#59608A' });
            node.textContent = text;
            coordinates.append(node);
        };
        for (let column = 0; column < C; column++) label(cellName(onView((R - 1) * C + column))[0], column * CELL + 88, (R - 1) * CELL + 88, 'end');
        for (let line = 0; line < R; line++) label(cellName(onView(line * C)).slice(1), 12, line * CELL + 32, 'start');
    }

    function drawStarts() {
        starts.replaceChildren();
        START.forEach((cell, index) => {
            const side = SIDE_KEYS[index];
            const [r, col] = partOf(cell);
            const x = col * CELL;
            const y = r * CELL;
            const colour = SIDES[side].colour;
            const group = element('g', { class: 'bs-start', 'data-start': side });
            group.append(
                element('rect', { x: x + 4, y: y + 4, width: CELL - 8, height: CELL - 8, rx: 8, fill: colour, 'fill-opacity': 0.16 }),
                element('rect', { x: x + 38, y: y + 38, width: 24, height: 24, rx: 4, fill: colour, class: 'bs-start-mark' }),
            );
            starts.append(group);
        });
    }

    function drawStones(g) {
        const parts = { w: [], b: [] };
        const fresh = [];
        const wanted = new Set();
        g.board.forEach((value, cell) => {
            if (!value) return;
            const side = SIDE_KEYS[value - 1];
            const [r, col] = partOf(cell);
            const id = side + cell;
            wanted.add(id);
            parts[side].push([r, col]);
            if (stoneAt.has(id)) return;
            const node = stone(side, r, col);
            stoneAt.set(id, node);
            stoneLayer.append(node);
            fresh.push(node);
        });
        for (const [id, node] of stoneAt) {
            if (!wanted.has(id)) {
                node.remove();
                stoneAt.delete(id);
            }
        }
        jointLayer.replaceChildren(...joints('w', parts.w), ...joints('b', parts.b));
        // A move lands; a whole game loaded or stepped through does not.
        if (!calm && fresh.length <= 5) {
            fresh.forEach((node, index) => node.animate(
                [{ opacity: 0, transform: 'scale(0.55)' }, { opacity: 1, transform: 'scale(1.08)', offset: 0.7 }, { opacity: 1, transform: 'scale(1)' }],
                { duration: 280, delay: index * 45, easing: 'cubic-bezier(0.2, 0.8, 0.2, 1)', fill: 'backwards' },
            ));
        }
    }

    function drawLast() {
        lastLayer.replaceChildren();
        const path = (c.shownPly > 0 ? c.state.moves[c.shownPly - 1]?.path : null) ?? [];
        for (const name of path) {
            const [r, col] = partOf(cellOf(name));
            lastLayer.append(element('rect', { x: col * CELL + 6, y: r * CELL + 6, width: CELL - 12, height: CELL - 12, rx: 13, fill: 'none', stroke: '#fff', 'stroke-opacity': 0.55, 'stroke-width': 3 }));
        }
    }

    /** Stones where they may lie; where they may not, a red outline that leaves the stones under it in view, and the reason. */
    function showGhost() {
        ghost.replaceChildren();
        if (!hand || !live()) {
            hint.textContent = '';

            return;
        }
        const placed = cellsFor(hand.tile.f, hand.o, target);
        const why = refuse(shown, shown.side, placed);
        hint.textContent = why ? words.why[why] : '';
        ghost.dataset.refused = why ?? '';
        say(words.at.replace(':cells', placed.map(cellName).join(', ')) + (why ? ' ' + words.why[why] : ''));
        if (!why) {
            ghost.append(form(sideKey(shown), placed.map(partOf)));

            return;
        }
        placed.forEach((cell) => {
            const [r, col] = partOf(cell);
            const taken = shown.board[cell] !== 0;
            ghost.append(element('rect', { x: col * CELL + 7, y: r * CELL + 7, width: CELL - 14, height: CELL - 14, rx: 12, fill: REFUSED, 'fill-opacity': taken ? 0.34 : 0.14, stroke: REFUSED, 'stroke-width': 5 }));
        });
    }

    /* The head, the ladder and the controls, built once. */
    const head = stage.querySelector('[data-piece-head]');
    const ladder = stage.querySelector('[data-mempool]');
    const dock = stage.querySelector('[data-piece-dock]');
    const help = stage.querySelector('[data-piece-help]');

    const feeOf = SIDE_KEYS.map((side) => {
        const card = html('div', 'bs-fee is-' + side);
        card.dataset.test = 'fee-' + side;
        const value = html('b', '', '0');
        const name = html('span', '');
        const unit = html('small', '', 'sat');
        const clock = html('time', 'bs-clock');
        card.append(name, value, unit, clock);

        return { card, value, name, side, clock };
    });
    const order = html('div', 'bs-order');
    const marks = html('div', 'bs-marks');
    marks.setAttribute('aria-hidden', 'true');
    const orderMarks = ORDER.map((side) => {
        const mark = html('i', 'is-' + SIDE_KEYS[side]);
        marks.append(mark);

        return mark;
    });
    const queueLine = html('span', '');
    queueLine.dataset.test = 'queue-left';
    order.append(marks, queueLine);
    head.replaceChildren(feeOf[0].card, order, feeOf[1].card);
    ladder.replaceChildren();
    // On a phone the ladder lies folded beside the block, a tap on this opens it in full size over the block.
    const fold = html('button', 'bs-fold', '‹');
    fold.type = 'button';
    fold.dataset.test = 'ladder-fold';
    fold.hidden = true;
    fold.addEventListener('click', () => unfold(!ladder.classList.contains('is-open')));
    ladder.append(fold);

    const note = html('p', 'bs-note');
    note.setAttribute('role', 'alert');
    note.dataset.test = 'piece-note';
    const hint = html('p', 'bs-hint');
    hint.dataset.test = 'piece-hint';
    // What a screen reader hears of the block: the stones shown and the moves made.
    const speech = html('p', 'sr-only');
    speech.setAttribute('aria-live', 'polite');
    speech.dataset.test = 'piece-speech';
    const say = (text) => {
        if (speech.textContent !== text) speech.textContent = text;
    };
    const buttons = html('div', 'bs-buttons');
    const button = (label, test, run) => {
        const node = html('button', 'bs-button', label);
        node.type = 'button';
        node.dataset.test = test;
        node.addEventListener('click', run);
        buttons.append(node);

        return node;
    };
    const rotateButton = button(words.rotate, 'piece-rotate', () => turn(rot));
    const mirrorButton = button(words.mirror, 'piece-mirror', () => turn(flip));
    const setButton = button(words.place, 'piece-set', () => commit());
    setButton.classList.add('is-main');
    const passButton = button(words.pass, 'piece-pass', () => passTurn());
    // A phone's chat handle gives its height to the block; this button stands in for it.
    const chatToggle = document.querySelector('[data-test=chat-sheet-toggle]');
    if (chatToggle) {
        const chatButton = button(words.chat, 'piece-chat', () => chatToggle.click());
        chatButton.classList.add('is-phone');
        buttons.classList.add('has-chat');
    }
    dock.replaceChildren(note, hint, buttons, speech);
    help.textContent = matchMedia('(hover: none)').matches ? words.touch : words.mouse;

    function makeTile(f) {
        const node = html('button', 'bs-rung');
        node.type = 'button';
        const front = html('span', 'bs-front');
        const picture = element('svg', { viewBox: `0 0 ${5 * CELL} ${3 * CELL}`, 'aria-hidden': 'true' });
        const pay = html('span', 'bs-pay');
        const sats = html('b');
        const parts = html('small');
        pay.append(sats, parts);
        front.append(pay, picture, html('span', 'bs-fuse'));
        node.append(front);
        const shape = ORI[f][flatOf(f)];
        const height = Math.max(...shape.map((part) => part[0])) + 1;
        const width = Math.max(...shape.map((part) => part[1])) + 1;
        picture.append(form('tx', shape, (3 - height) / 2, (5 - width) / 2));
        const tile = { f, node, sats, parts };
        node.addEventListener('click', () => choose(tile));
        ladder.append(node);

        return tile;
    }

    /** One element per transaction, matched by form and order: what is taken leaves, the rest moves up a rung. */
    function drawLadder(g) {
        const keep = [];
        let from = 0;
        g.pool.forEach((tx) => {
            const found = tiles.findIndex((tile, index) => index >= from && tile.f === tx.f);
            if (found >= 0) {
                keep.push(tiles[found]);
                from = found + 1;
            } else {
                const tile = makeTile(tx.f);
                tile.node.style.setProperty('--rung', 5);
                tile.node.style.opacity = '0';
                keep.push(tile);
            }
        });
        tiles.filter((tile) => !keep.includes(tile)).forEach((tile) => {
            tile.node.style.opacity = '0';
            setTimeout(() => tile.node.remove(), 260);
        });
        tiles = keep;
        void ladder.offsetHeight;
        tiles.forEach((tile, rung) => {
            const fee = SIZE[tile.f] + BONUS[rung];
            const held = hand?.tile === tile;
            const burning = g.fuse && g.ti % 2 === 1 && rung === 0;
            tile.node.style.setProperty('--rung', rung);
            tile.node.style.setProperty('--bs-fee', FEE_COLOURS[fee] ?? FEE_COLOURS[9]);
            tile.node.style.opacity = '';
            tile.node.setAttribute('aria-disabled', live() ? 'false' : 'true');
            tile.node.dataset.test = 'tx-' + rung;
            tile.node.dataset.form = NAMES[tile.f];
            tile.node.dataset.burning = burning ? '1' : '0';
            tile.node.classList.toggle('is-burning', burning);
            tile.node.setAttribute('aria-pressed', held ? 'true' : 'false');
            tile.node.setAttribute('aria-label', words.tile.replace(':rung', rung + 1).replace(':form', NAMES[tile.f]).replace(':size', SIZE[tile.f]).replace(':bonus', BONUS[rung]).replace(':fee', fee) + (burning ? ', ' + words.burning : ''));
            tile.sats.textContent = fee + ' sat';
            tile.parts.textContent = SIZE[tile.f] + ' vB + ' + BONUS[rung];
        });
    }

    function draw() {
        if (turnedFor !== turned()) {
            turnedFor = turned();
            stoneLayer.replaceChildren();
            stoneAt.clear();
            drawCoordinates();
            drawStarts();
        }
        shown = readPieces(c.piecesAt(c.shownPly));
        if (!live() || !tiles.includes(hand?.tile)) hand = null;
        drawStones(shown);
        drawLast();
        const active = c.state.status === 'active';
        starts.querySelectorAll('[data-start]').forEach((group, index) => {
            group.classList.toggle('is-due', active && shown.side === index && !shown.played[index]);
            group.style.display = shown.played[index] ? 'none' : '';
        });
        feeOf.forEach(({ card, value, name, side }, index) => {
            value.textContent = shown.score[index];
            name.textContent = SIDES[side].sign + ' ' + (side === 'w' ? c.t.white : c.t.black) + (c.color === side ? ' (' + words.you + ')' : '');
            card.classList.toggle('is-turn', active && shown.side === index);
        });
        orderMarks.forEach((mark, index) => mark.classList.toggle('is-now', active && index === shown.ti));
        queueLine.textContent = words.queue.replace(':count', shown.queue);
        drawLadder(shown);
        const holding = !!hand && live();
        [rotateButton, mirrorButton, setButton].forEach((node) => node.setAttribute('aria-disabled', holding ? 'false' : 'true'));
        passButton.setAttribute('aria-disabled', live() ? 'false' : 'true');
        const info = !c.error && active && shown.passes > 0;
        const noteText = c.error || (info ? words.passed : '');
        if (note.textContent !== noteText) note.textContent = noteText;
        note.classList.toggle('is-info', info);
        const last = c.state.moves?.[c.shownPly - 1]?.notation;
        if (last) say(last);
        const playing = c.color !== null && c.state.status !== 'finished' && c.state.status !== 'aborted';
        dock.classList.toggle('is-off', !playing);
        dock.style.visibility = playing ? '' : 'hidden';
        help.style.display = playing ? '' : 'none';
        // With a stone in hand a finger on the block moves the stone, not the page.
        svg.style.touchAction = holding ? 'none' : 'pan-y';
        svg.style.cursor = holding ? 'none' : '';
        showGhost();
    }

    function choose(tile) {
        if (!live()) return;
        c.error = '';
        hand = hand?.tile === tile ? null : { tile, slot: tiles.indexOf(tile), o: flatOf(tile.f) };
        note.textContent = '';
        if (hand && !shown.played[shown.side]) target = START[shown.side];
        draw();
        if (hand) {
            unfold(false);
            bringIntoView();
        }
    }

    /** On a phone the block can be taller than the room above the buttons: the stones just taken come into view. */
    function bringIntoView() {
        if (!matchMedia('(width < 64rem)').matches) return;
        const b = svg.getBoundingClientRect();
        const y = b.top + (partOf(target)[0] + 0.5) * (b.height / R);
        const bottom = dock.getBoundingClientRect().top - b.height / 7;
        const top = b.height / 5;
        if (y > bottom) scrollBy({ top: y - bottom, behavior: calm ? 'auto' : 'smooth' });
        else if (y < top) scrollBy({ top: y - top, behavior: calm ? 'auto' : 'smooth' });
    }

    function turn(change) {
        if (!hand || !live()) return;
        const forms = ORI[hand.tile.f];
        const wanted = key(change(forms[hand.o]));
        hand.o = forms.findIndex((shape) => key(shape) === wanted);
        showGhost();
    }

    function shake() {
        if (calm) return;
        ghost.animate([{ transform: 'translateX(0)' }, { transform: 'translateX(-18px)' }, { transform: 'translateX(18px)' }, { transform: 'translateX(0)' }], { duration: 220 });
    }

    function commit() {
        if (!hand || !live()) return;
        const placed = cellsFor(hand.tile.f, hand.o, target);
        if (refuse(shown, shown.side, placed)) {
            shake();
            // A page teaching the game answers with its own hint.
            c.$root.dispatchEvent(new CustomEvent('blockspace-refused', { bubbles: true }));

            return;
        }
        const held = hand;
        hand = null;
        ghost.replaceChildren();
        c.send(moveText(held.slot, placed)).then((answer) => {
            // Refused or lost: the stones are back in the hand as they were.
            if (answer?.ok === false && live() && tiles.includes(held.tile)) {
                hand = held;
                draw();
            }
        });
    }

    function passTurn() {
        if (!live()) return;
        if (!passArmed) {
            passArmed = performance.now();
            passButton.textContent = words.passSure;
            passButton.classList.add('is-sure');
            passTimer = setTimeout(() => resetPass(), PASS_CONFIRM_MS);

            return;
        }
        // A double click is no confirmation.
        if (performance.now() - passArmed < PASS_CONFIRM_MIN_MS) return;
        resetPass();
        hand = null;
        c.send('pass');
    }

    function resetPass() {
        clearTimeout(passTimer);
        passArmed = 0;
        passButton.textContent = words.pass;
        passButton.classList.remove('is-sure');
    }

    /* The block: the cell under a pointer, as the rules count it. */
    const cellAt = (x, y) => {
        const b = svg.getBoundingClientRect();
        const column = Math.floor(((x - b.left) / b.width) * C);
        const line = Math.floor(((y - b.top) / b.height) * R);

        return column < 0 || line < 0 || column >= C || line >= R ? null : onView(line * C + column);
    };
    const unit = () => svg.getBoundingClientRect().width / C;
    let press = null;
    svg.addEventListener('pointerdown', (event) => {
        if (!hand || !live() || event.button !== 0 || event.pointerType === 'mouse') return;
        if (event.isTrusted) svg.setPointerCapture(event.pointerId);
        press = { x: event.clientX, y: event.clientY, tapped: cellAt(event.clientX, event.clientY) ?? target, base: target, moved: false };
    });
    svg.addEventListener('pointermove', (event) => {
        if (!hand || !live()) return;
        if (event.pointerType === 'mouse') {
            const cell = cellAt(event.clientX, event.clientY);
            if (cell !== null && cell !== target) {
                target = cell;
                showGhost();
            }

            return;
        }
        if (!press) return;
        const direction = turned() ? -1 : 1;
        const dx = Math.round((event.clientX - press.x) / unit()) * direction;
        const dy = Math.round((event.clientY - press.y) / unit()) * direction;
        if (dx || dy) press.moved = true;
        if (press.moved) {
            const line = Math.min(R - 1, Math.max(0, ((press.base / C) | 0) + dy));
            const column = Math.min(C - 1, Math.max(0, (press.base % C) + dx));
            const next = line * C + column;
            if (next !== target) {
                target = next;
                showGhost();
            }
        }
    });
    svg.addEventListener('pointerup', (event) => {
        if (event.button !== 0) return;
        if (event.pointerType === 'mouse') {
            const cell = cellAt(event.clientX, event.clientY);
            if (hand && live() && cell !== null) {
                target = cell;
                commit();
            }

            return;
        }
        if (press && !press.moved && hand) {
            // A tap on the stone shown sets it, a tap beside it moves it there.
            if (cellsFor(hand.tile.f, hand.o, target).includes(press.tapped)) commit();
            else {
                target = press.tapped;
                showGhost();
            }
        }
        press = null;
    });
    svg.addEventListener('pointercancel', () => (press = null));

    /* The right button: a click turns, held down it mirrors. */
    let rightTimer = null;
    let rightDone = true;
    stage.addEventListener('contextmenu', (event) => event.preventDefault());
    stage.addEventListener('pointerdown', (event) => {
        if (event.button !== 2) return;
        rightDone = false;
        clearTimeout(rightTimer);
        rightTimer = setTimeout(() => {
            rightDone = true;
            turn(flip);
        }, LONG_PRESS_MS);
    });
    const leaving = new AbortController();
    window.addEventListener('pointerup', (event) => {
        if (event.button !== 2) return;
        clearTimeout(rightTimer);
        if (!rightDone) turn(rot);
        rightDone = true;
    }, { signal: leaving.signal });

    /** Where the screen ends for the game: the tab bar, on a phone the chat's handle above it. */
    function lowerEdge() {
        const tabbar = document.documentElement.classList.contains('is-focus') ? 0 : parseFloat(getComputedStyle(document.body).paddingBottom) || 0;
        const sheet = document.querySelector('[data-test=chat-sheet-toggle]')?.closest('section');
        const open = !document.documentElement.classList.contains('bs-playing') && matchMedia('(width < 64rem)').matches && sheet && getComputedStyle(sheet).position === 'fixed' && sheet.getBoundingClientRect().height > 0;

        let edge = open ? Math.min(innerHeight - tabbar, sheet.getBoundingClientRect().top) : innerHeight - tabbar;
        // The action dock's bar and tab (components/⚡match-dock) stand on top of that: nothing of the game lies under them.
        document.querySelectorAll('[data-test=dock-bar-tab], [data-test=dock-mobile-bar]').forEach((bar) => {
            const { height, top } = bar.getBoundingClientRect();
            if (height > 0 && matchMedia('(width < 64rem)').matches) edge = Math.min(edge, top);
        });

        return edge;
    }

    /** The buttons follow the tab bar and the chat's handle, which slide without telling. */
    function placeDock() {
        const edge = lowerEdge();
        if (edge !== lastEdge) {
            lastEdge = edge;
            fit();
        }
    }

    /** The bottom of what stays on top of the page: the header, the tutorial's bar under it. */
    function upperEdge() {
        return document.querySelector('.shell-header')?.getBoundingClientRect().bottom ?? 56;
    }

    /** On a phone: the block as large as the screen allows, the ladder folded into what is left beside it. */
    function fitPhone(edge) {
        const bar = document.querySelector('[data-phone-bar]');
        const barHeight = bar && bar.offsetHeight > 0 ? bar.offsetHeight : 0;
        if (bar) bar.style.top = upperEdge() + 'px';
        const width = stage.parentElement.clientWidth + 2 * PHONE_BLEED;
        const room = edge - upperEdge() - barHeight - head.offsetHeight - dock.offsetHeight - 16;
        const size = Math.max(14, Math.floor(Math.min(room / R, (width - PHONE_GAP - MIN_STRIP) / C)));
        // A tablet has room for the whole ladder, a phone only for the strip that is left.
        const strip = Math.min(width - PHONE_GAP - size * C, size * 5.6);
        stage.style.setProperty('--u', size + 'px');
        stage.style.setProperty('--strip', strip + 'px');
        stage.style.setProperty('--strip-unit', Math.min(size, strip / 5.6) + 'px');
        const folds = strip / 5.6 < size - 0.01;
        fold.hidden = !folds;
        if (!folds) unfold(false);
        dock.style.bottom = innerHeight - edge + 'px';
        stage.style.paddingBottom = dock.offsetHeight + 8 + 'px';
    }

    function unfold(open) {
        ladder.classList.toggle('is-open', open);
        fold.setAttribute('aria-expanded', open ? 'true' : 'false');
        fold.textContent = open ? '›' : '‹';
        fold.setAttribute('aria-label', open ? words.foldLadder : words.unfoldLadder);
    }

    /** The cell as large as the room allows: the block fills the first screen, the whole stage stays in the column. */
    function fit() {
        const phone = matchMedia('(width < 64rem)').matches;
        const low = phone && innerHeight < 500;
        const compact = phone && !low;
        stage.classList.toggle('is-compact', compact);
        document.documentElement.classList.toggle('bs-playing', compact && c.color !== null && c.state.status === 'active');
        help.textContent = low ? words.portrait : matchMedia('(hover: none)').matches ? words.touch : words.mouse;
        const edge = lowerEdge();
        if (compact) return fitPhone(edge);
        fold.hidden = true;
        unfold(false);
        const room = stage.closest('[data-fit-room]');
        const width = room ? room.clientWidth - (phone ? 0 : Number(room.dataset.fitRoom)) : stage.parentElement.clientWidth;
        // The mouse hint may lie under the first screen, the buttons not.
        const under = dock.offsetHeight + 28;
        // Where the block stands, or, where that leaves too little, with the page scrolled so that it starts under the header.
        const header = parseFloat(getComputedStyle(document.documentElement).scrollPaddingTop) || 56;
        const inPlace = (edge - (row.getBoundingClientRect().top + scrollY) - under) / R;
        const scrolled = (edge - header - head.offsetHeight - 16 - under) / R;
        scrollsTo = inPlace < COMFORTABLE_CELL;
        const size = Math.floor(Math.min(width / STAGE_UNITS, Math.max(scrollsTo ? scrolled : inPlace, low ? 18 : 20), MAX_CELL));
        stage.style.setProperty('--u', Math.max(14, size) + 'px');
        dock.style.bottom = '';
        stage.style.paddingBottom = '';
    }

    /** A running game opens on the block, not on what stands above it; on a phone the tutorial as well. */
    function openOnBlock() {
        const compact = stage.classList.contains('is-compact');
        if (compact) {
            setFocus(true);
            const first = document.querySelector('[data-phone-bar]') ?? stage;
            if (first.offsetHeight > 0 && c.state.status !== 'finished') scrollTo({ top: first.getBoundingClientRect().top + scrollY - upperEdge(), behavior: 'auto' });

            return;
        }
        if (!scrollsTo || c.source || c.color === null || c.state.status !== 'active') return;
        const header = parseFloat(getComputedStyle(document.documentElement).scrollPaddingTop) || 56;
        scrollTo({ top: stage.getBoundingClientRect().top + scrollY - header - 8, behavior: 'auto' });
    }

    function drawClocks() {
        const shownClocks = !c.source && c.state.status === 'active';
        feeOf.forEach(({ clock, side }) => {
            clock.textContent = shownClocks ? c.clock(side) : '';
            clock.classList.toggle('is-running', c.state.clock.running === side);
        });
    }
    const clocks = setInterval(() => {
        if (!svg.isConnected) {
            leaving.abort();
            document.documentElement.classList.remove('bs-playing');

            return clearInterval(clocks);
        }
        drawClocks();
        placeDock();
    }, 400);
    addEventListener('resize', () => fit(), { signal: leaving.signal });

    return {
        render: draw,
        fit,
        openOnBlock,

        /** A move came in: nothing stays in the hand that is no longer on the ladder. */
        changed() {
            hand = null;
            resetPass();
        },

        /** The keys of the input; true when one was used. */
        key(event) {
            if (event.target.closest?.('input, textarea, select') || event.metaKey || event.ctrlKey || event.altKey || !live()) return false;
            const name = event.key.toLowerCase();
            if (name >= '1' && name <= '5' && tiles[Number(name) - 1]) {
                choose(tiles[Number(name) - 1]);

                return true;
            }
            if (!hand) return false;
            if (name === 'r') turn(rot);
            else if (name === 'f') turn(flip);
            else if (name === 'enter' && !event.target.closest?.('a, .bs-button')) {
                // Also from the rung it came from: Enter sets the stones, it does not put them back.
                event.preventDefault();
                commit();
            }
            else if (name === 'escape') {
                hand = null;
                draw();
            } else if (name.startsWith('arrow')) {
                event.preventDefault();
                const direction = turned() ? -1 : 1;
                const line = ((target / C) | 0) + ((name === 'arrowdown') - (name === 'arrowup')) * direction;
                const column = (target % C) + ((name === 'arrowright') - (name === 'arrowleft')) * direction;
                target = Math.min(R - 1, Math.max(0, line)) * C + Math.min(C - 1, Math.max(0, column));
                showGhost();
            } else return false;

            return true;
        },
    };
}
