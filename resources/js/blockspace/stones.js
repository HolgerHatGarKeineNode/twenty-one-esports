/*
 * The stones of Blockspace, drawn as vectors: one cut stone per side, a flat table in the middle and four facets around
 * it. On the block and on the ladder the same symbol at the same size, so a form on the ladder is the form on the block.
 * Edge to edge a side's stones always belong to one transaction (the rules forbid anything else), so a small joint
 * ties them together and a form reads as one piece.
 */
export const SVG = 'http://www.w3.org/2000/svg';

/** The units of one cell in every drawing. */
export const CELL = 100;

/** Each side's colour and the tones of its cut, light falling from the top left. */
export const SIDES = {
    w: { sign: '₿', colour: '#F7931A', table: ['#FFD98F', '#F9A02B'], tones: ['#FFD27F', '#FFBD55', '#F7931A', '#D9730A', '#A85203'], joint: '#B85C05' },
    b: { sign: '⚡', colour: '#8DB4FF', table: ['#E6EFFF', '#8FB4FF'], tones: ['#D6E4FF', '#B4CDFF', '#7FA6F8', '#5579DC', '#3451A6'], joint: '#3F5FBC' },
    // A transaction still in the mempool belongs to nobody: clear stones, which take a miner's colour in the block.
    tx: { table: ['#FFFFFF', '#E3E7EF'], tones: ['#FFFFFF', '#F1F3F8', '#D9DEE8', '#B9C0CE', '#939BAD'], joint: '#A3ABBC' },
};

export function element(name, attributes = {}) {
    const node = document.createElementNS(SVG, name);
    for (const [key, value] of Object.entries(attributes)) node.setAttribute(key, value);

    return node;
}

/** The cut: eight facets around an eight-cornered table, each as its four corners, with the tone of its light. */
const FACETS = [
    ['20,6 80,6 62,28 38,28', 1],
    ['80,6 94,20 72,38 62,28', 2],
    ['94,20 94,80 72,62 72,38', 3],
    ['94,80 80,94 62,72 72,62', 4],
    ['80,94 20,94 38,72 62,72', 3],
    ['20,94 6,80 28,62 38,72', 2],
    ['6,80 6,20 28,38 28,62', 1],
    ['6,20 20,6 38,28 28,38', 0],
];

/** The one drawing of each stone, for every <use> on the page. */
export function stoneDefs() {
    const stone = (side, s) => `
        <linearGradient id="bs-table-${side}" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${s.table[0]}"/><stop offset="1" stop-color="${s.table[1]}"/></linearGradient>
        <symbol id="bs-stone-${side}" viewBox="0 0 100 100">
            ${FACETS.map(([points, tone]) => `<polygon points="${points}" fill="${s.tones[tone]}"/>`).join('')}
            <polygon points="38,28 62,28 72,38 72,62 62,72 38,72 28,62 28,38" fill="url(#bs-table-${side})"/>
            <polygon points="38,28 62,28 28,62 28,38" fill="#fff" fill-opacity=".28"/>
        </symbol>`;
    const svg = element('svg', { width: 0, height: 0, 'aria-hidden': 'true' });
    svg.style.position = 'absolute';
    svg.innerHTML = `<defs>${stone('w', SIDES.w)}${stone('b', SIDES.b)}${stone('tx', SIDES.tx)}</defs>`;

    return svg;
}

export function stone(side, row, column) {
    return element('use', { href: '#bs-stone-' + side, x: column * CELL, y: row * CELL, width: CELL, height: CELL, class: 'bs-stone' });
}

/** The joints of stones given as [row, column], each side once, under the stones. */
export function joints(side, parts) {
    const taken = new Set(parts.map(([row, column]) => row + ':' + column));
    const nodes = [];
    for (const [row, column] of parts) {
        if (taken.has(row + ':' + (column + 1))) nodes.push(element('rect', { x: column * CELL + 88, y: row * CELL + 38, width: 24, height: 24, fill: SIDES[side].joint }));
        if (taken.has(row + 1 + ':' + column)) nodes.push(element('rect', { x: column * CELL + 38, y: row * CELL + 88, width: 24, height: 24, fill: SIDES[side].joint }));
    }

    return nodes;
}

/** A whole form as one group, at this offset in cells. */
export function form(side, parts, top = 0, left = 0) {
    const group = element('g');
    const placed = parts.map(([row, column]) => [row + top, column + left]);
    group.append(...joints(side, placed), ...placed.map(([row, column]) => stone(side, row, column)));

    return group;
}
