/**
 * The league's own "21" mark (the same artwork as public/favicon.svg and resources/views/components/logo.blade.php),
 * as data: the stinger extrudes it into a real block of metal, the ticker draws it into its chip. No other mark is
 * used anywhere in the broadcast.
 *
 * Coordinates are the SVG's (viewBox 112 112 800 800, y down). The orange slit through the "1" is cut out of the
 * glyph here (the "1" is two pieces), so an extrusion shows it as a real gap.
 */

export const MARK_BOX = Object.freeze({ x: 112, y: 112, size: 800, radius: 144 });

const TWO = 'M169 458C182 360 270 302 381 302C500 302 572 360 572 436C572 520 490 565 372 614H583V712H179V627C300 560 448 500 448 437C448 410 425 397 381 397C330 397 300 420 292 458Z';
const ONE = 'M614 344L765 312H821V712H733V417L614 438Z';
const rect = (x, y, w, h) => `M${x} ${y}H${x + w}V${y + h}H${x}Z`;

/** The glyphs: the "2", the "1" in two pieces either side of its slit, and the four ticks. */
export const MARK_GLYPHS = Object.freeze([
    TWO,
    ONE,
    rect(830, 312, 25, 400),
    rect(733, 252, 34, 60),
    rect(821, 252, 34, 60),
    rect(733, 712, 34, 60),
    rect(821, 712, 34, 60),
]);

/** Draw the mark into a 2D context at (x, y) with `size` px; `tile: false` draws the glyphs alone in `ink`. */
export function drawMark(ctx, x, y, size, { tile = true, ink = '#17120A', fill = '#F7931A' } = {}) {
    const s = size / MARK_BOX.size;
    ctx.save();
    ctx.translate(x, y);
    ctx.scale(s, s);
    ctx.translate(-MARK_BOX.x, -MARK_BOX.y);
    if (tile) {
        ctx.fillStyle = fill;
        ctx.beginPath();
        ctx.roundRect(MARK_BOX.x, MARK_BOX.y, MARK_BOX.size, MARK_BOX.size, MARK_BOX.radius);
        ctx.fill();
    }
    ctx.fillStyle = ink;
    MARK_GLYPHS.forEach((d) => ctx.fill(new Path2D(d)));
    ctx.restore();
}

/** Absolute M/L/H/V/C/Z path data (all the mark uses) to a THREE.Shape, y flipped so it stands upright in world space. */
function toShape(THREE, d) {
    const shape = new THREE.Shape();
    const tokens = d.match(/[MLHVCZ]|-?\d+(?:\.\d+)?/g);
    let i = 0;
    let cmd = null;
    let x = 0;
    let y = 0;
    const num = () => +tokens[i++];
    while (i < tokens.length) {
        if (/[MLHVCZ]/.test(tokens[i])) cmd = tokens[i++];
        if (cmd === 'M') { x = num(); y = num(); shape.moveTo(x, -y); cmd = 'L'; }
        else if (cmd === 'L') { x = num(); y = num(); shape.lineTo(x, -y); }
        else if (cmd === 'H') { x = num(); shape.lineTo(x, -y); }
        else if (cmd === 'V') { y = num(); shape.lineTo(x, -y); }
        else if (cmd === 'C') {
            const [x1, y1, x2, y2] = [num(), num(), num(), num()];
            x = num(); y = num();
            shape.bezierCurveTo(x1, -y1, x2, -y2, x, -y);
        } else if (cmd === 'Z') { cmd = null; }
    }

    return shape;
}

/**
 * The mark as geometry, centred on its origin, `size` world units across: { tile, glyphs } extrusions. The tile is a
 * bevelled rounded square; the glyphs stand proud of its face by `relief`.
 */
export function markGeometry(THREE, size = 300, { depth = 34, relief = 12 } = {}) {
    const s = size / MARK_BOX.size;
    const { x, y, size: S, radius: r } = MARK_BOX;
    const tile = new THREE.Shape();
    tile.moveTo(x + r, -y);
    tile.lineTo(x + S - r, -y);
    tile.quadraticCurveTo(x + S, -y, x + S, -(y + r));
    tile.lineTo(x + S, -(y + S - r));
    tile.quadraticCurveTo(x + S, -(y + S), x + S - r, -(y + S));
    tile.lineTo(x + r, -(y + S));
    tile.quadraticCurveTo(x, -(y + S), x, -(y + S - r));
    tile.lineTo(x, -(y + r));
    tile.quadraticCurveTo(x, -y, x + r, -y);
    const centre = (g) => { g.translate(-(x + S / 2), y + S / 2, 0); g.scale(s, s, 1); return g; };
    const tileGeo = centre(new THREE.ExtrudeGeometry(tile, { depth: depth / s, bevelEnabled: true, bevelThickness: 18, bevelSize: 14, bevelSegments: 3, curveSegments: 10 }));
    tileGeo.scale(1, 1, s);
    const glyphGeo = centre(new THREE.ExtrudeGeometry(MARK_GLYPHS.map((d) => toShape(THREE, d)), { depth: relief / s, bevelEnabled: true, bevelThickness: 6, bevelSize: 4, bevelSegments: 2, curveSegments: 10 }));
    glyphGeo.scale(1, 1, s);
    // Glyphs sit on the tile's front (its bevel included).
    glyphGeo.translate(0, 0, depth + 18 * s);

    return { tile: tileGeo, glyphs: glyphGeo };
}
