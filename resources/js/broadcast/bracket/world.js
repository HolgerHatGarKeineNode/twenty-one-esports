/**
 * The 3D bracket (plan P6): one tournament stage as a lit structure in its own three.js scene, shared by the OBS
 * bracket scene (resources/js/broadcast/variants/bracket.js: a camera flight page by page) and the tournament page's
 * 3D view (resources/js/broadcast/bracket/page.js: orbit and buttons).
 *
 * Structure first: an elimination bracket stands in columns by round (later rounds a step closer to the viewer, the
 * final larger, on light); a double elimination puts the lower bracket under the upper one and the grand final to
 * the right; a round robin or Swiss stage is its standings as a stack of rows (the top three in metal with medals)
 * beside the round's pairings; free-for-all heats stand side by side, each listing its places.
 *
 * A match is a glass slab (the broadcast's plate: smoked glass, metal lip, 24 px deep) with one row per side: seed
 * (mono, grey), name (Unbounded 600, white; a loser steps back to grey, an open side reads grey "Winner of M1-3"), score
 * (mono) at the right; the winner's row carries a hot edge; a match up now carries a "Live" tab. Connectors are light
 * paths from a match to the match its winner goes to: dark metal until decided, then lit orange.
 *
 * Live (setData): what changed between two data sets. A match that turned `done` is an advance, held back until the
 * caller plays it (playAdvance), so a viewer sees it happen: the winner's row lights (0-500 ms), the loser steps back,
 * the light runs the connector (400-1300), the name lands in the next match with a spark (1200-1900). Anything else
 * that changed takes a short crossfade at once. A different structure (a new stage) rebuilds the world (`rebuilt`).
 *
 * pages(): the camera stops: an overview of the whole stage, then groups of at most four matches with the matches
 * they feed (so 64 players page through readable names), the final on its own, and the champion with the crown.
 * framing(page, aspect, reserve): the camera pose that fits a page into the frame's free area.
 */

import { CURVES, span } from '../curves.js';
import { createEnergy, createHalo, createHeat, createImage, createShock, createSlab } from '../materials.js';
import { createParticles } from '../particles.js';
import { GLOW } from '../stage.js';
import { createLine, disposeTree } from '../text.js';
import { COLOR, TYPE } from '../tokens.js';
import { TIMING } from '../timing.js';
import { createChip } from '../elements/board.js';

export const PW = 440;
export const PH = 112;
const ROW_H = 46;
const ROW_TOP = 10;
const CG = 180;
const RG = 34;
const RISE = 40;
const FOV = 30;
// Lines are drawn sharper than 1:1, so a page the camera frames above logical size still reads crisp.
const BOOST = 1.6;

const NAME = { family: 'display', weight: 600, size: 26, leading: 1.2, tracking: 0 };
const SEED = { family: 'mono', weight: 500, size: 20, leading: 1.2, tracking: 0 };
const SCORE = { family: 'mono', weight: 500, size: 28, leading: 1.2, tracking: 0 };
const COLHEAD = { family: 'display', weight: 700, size: 26, leading: 1.2, tracking: 0 };
const SMALL = { family: 'mono', weight: 500, size: 22, leading: 1.2, tracking: 0 };

const boxW = (line) => line.width - line.pad * 2;
const seedOf = (side) => side.seed ?? side.entry?.seed ?? null;

/** The stage the scene shows: the first with a match still to play, else the last (TournamentTv::currentStage). */
export function currentStage(stages) {
    const open = (b) => (b.status === 'waiting' || b.status === 'ready') && b.bracket !== 'bye';
    for (const stage of stages || []) if (boxesOf(stage).some(open)) return stage;

    return (stages || [])[(stages || []).length - 1] || null;
}

export function boxesOf(stage) {
    const out = [];
    (stage?.parts || []).forEach((part) => {
        if (part.kind === 'bracket') part.sections.forEach((s) => s.columns.forEach((c) => out.push(...c.matches)));
        else if (part.kind === 'table') Object.values(part.rounds || {}).forEach((r) => out.push(...r));
        else out.push(...(part.heats || []));
    });

    return out;
}

/** The structure of a stage: its parts and match keys; when it changes, the world is built anew. */
export function structureOf(stage) {
    if (!stage) return '';

    return JSON.stringify((stage.parts || []).map((p) => [p.kind, p.title, boxesOf({ parts: [p] }).filter((b) => b.bracket !== 'bye').map((b) => b.key), p.kind === 'table' ? p.rows.length : 0]));
}

const sideKey = (s) => JSON.stringify([s.name, s.known, s.score, s.won, seedOf(s)]);

export function createBracketWorld(stage, { words, art, reserve = { top: 0, bottom: 0, side: 0 }, perPage = 4, withNext = true }) {
    const { THREE } = stage;
    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(FOV, 16 / 9, 10, 40000);
    camera.position.set(0, 0, 3000);
    scene.add(camera);
    stage.addWorld(scene, camera);
    const particles = createParticles(stage, { scene });
    const textStage = Object.create(stage, { textScale: { get: () => stage.textScale * BOOST } });
    const webgl2 = stage.renderer.capabilities.isWebGL2;

    let root = null;
    let plates = new Map();
    let connectors = [];
    let pageList = [];
    let finalPlate = null;
    let champion = null;
    let structure = '';
    let data = null;
    const pending = new Map();
    const anims = [];

    const line = (text, style, opts) => {
        const l = createLine(textStage, String(text), style, opts);
        if (webgl2) {
            const tex = l.material.uniforms.map.value;
            tex.generateMipmaps = true;
            tex.minFilter = THREE.LinearMipmapLinearFilter;
            tex.needsUpdate = true;
        }

        return l;
    };
    const at = (l, x, y, z = 0.8) => {
        l.placed = { left: x, top: y, z };
        l.mesh.position.set(x - l.pad + l.width / 2, -(y - l.pad + l.height / 2), z);
    };

    // ---------------------------------------------------------------- plates

    function sideRow(plate, side, i, born) {
        const g = plate.slab.pivot;
        const top = ROW_TOP + i * ROW_H;
        const seed = seedOf(side);
        const row = { lines: [], bar: null, born, key: sideKey(side), side };
        const seedW = seed ? 34 : 0;
        if (seed) {
            const s = line(String(seed), SEED, { color: COLOR.ink3 });
            at(s, 18, top + (ROW_H - SEED.size * SEED.leading) / 2);
            row.lines.push(s);
        }
        let scoreW = 0;
        if (side.score !== null && side.score !== undefined && side.score !== '') {
            const sc = line(String(side.score), SCORE, { color: side.won ? COLOR.btcHi : COLOR.ink3 });
            at(sc, plate.w - 20 - boxW(sc), top + (ROW_H - SCORE.size * SCORE.leading) / 2);
            row.lines.push(sc);
            scoreW = boxW(sc) + 18;
        }
        const decided = plate.box.status === 'done';
        const color = !side.known || (decided && !side.won) ? COLOR.ink3 : COLOR.ink;
        const n = line(side.name, NAME, { color, maxWidth: plate.w - 18 - seedW - 20 - scoreW - (plate.chip && i === 0 ? plate.chip.width + 8 : 0) });
        at(n, 18 + seedW, top + (ROW_H - NAME.size * NAME.leading) / 2);
        row.lines.push(n);
        row.name = n;
        if (decided && side.won) {
            row.bar = createHeat(THREE, 5, ROW_H - 12);
            row.bar.position.set(0, -(top + 6), 1);
            g.add(row.bar);
        }
        row.lines.forEach((l) => g.add(l.mesh));

        return row;
    }

    function createPlate(box, x, y, z, { scale = 1, final = false } = {}) {
        const w = PW;
        const h = PH + Math.max(0, box.sides.length - 2) * ROW_H;
        const live = !!box.live;
        const slab = createSlab(THREE, w, h, { depth: 24, corner: 'br', spill: live || final ? 'left' : null, top: final ? '#24242B' : undefined });
        slab.group.position.set(x, -y, z);
        slab.group.scale.setScalar(scale);
        root.add(slab.group);
        const plate = { box, slab, x, y, z, w, h, scale, final, rows: [], heats: [], chip: null, texts: [], key: box.key };
        if (live || final) {
            const edge = createHeat(THREE, 5, h);
            edge.position.set(-5, 0, 1);
            slab.pivot.add(edge);
            plate.heats.push(edge);
            if (!final) plate.liveEdge = edge;
        }
        if (live) addChip(plate, -1);
        plate.rows = box.sides.map((side, i) => sideRow(plate, side, i, -1));
        plate.texts = box.sides.map((s) => s.name);

        return plate;
    }

    /** The "Live" tab on a plate's top edge; born at t (-1: there from the start). */
    function addChip(plate, t) {
        plate.chip = createChip(textStage, words.live);
        plate.chip.mesh.renderOrder = 6;
        at(plate.chip, plate.w - 16 - plate.chip.width, -18, 2);
        plate.slab.pivot.add(plate.chip.mesh);
        plate.chipBorn = t;
    }

    function setPlateBox(plate, box, t) {
        const old = plate.box;
        plate.box = box;
        // Up now or not any more: the tab and the hot edge follow (a decided match drops them as its winner lights).
        if (plate.chip && !box.live) {
            anims.push({ kind: 'unlive', plate, chip: plate.chip, edge: plate.liveEdge, t });
            plate.heats = plate.heats.filter((h) => h !== plate.liveEdge);
            plate.chip = null;
            plate.liveEdge = null;
        } else if (!plate.chip && box.live) {
            addChip(plate, t + 600);
        }
        box.sides.forEach((side, i) => {
            const was = plate.rows[i];
            if (was && was.key === sideKey(side) && (old.status === 'done') === (box.status === 'done')) return;
            // A replaced row covers first (0-300 ms), the new one reveals after it (250-750 ms): never two names at once.
            const row = sideRow(plate, side, i, was ? t + 250 : t);
            if (was) {
                was.dying = t;
                anims.push({ kind: 'drop', row: was, plate, t });
            }
            plate.rows[i] = row;
            if (t > 0 && side.known && !(was && was.side.known)) {
                // A name lands: a spark at the row.
                const p = worldOf(plate, 0, ROW_TOP + i * ROW_H + ROW_H / 2);
                anims.push({ kind: 'spark', p, t });
            }
        });
        plate.texts = box.sides.map((s) => s.name);
    }

    function worldOf(plate, lx, ly) {
        return new THREE.Vector3(plate.x + lx * plate.scale, -(plate.y + ly * plate.scale), plate.z + 14);
    }

    // ---------------------------------------------------------------- connectors

    const tubeMat = () => new THREE.ShaderMaterial({
        uniforms: { progress: { value: 0 }, head: { value: -1 }, dim: { value: new THREE.Color('#3A3A44') }, hot: { value: new THREE.Color(COLOR.btc) }, hi: { value: new THREE.Color(COLOR.btcHi) }, opacity: { value: 1 } },
        vertexShader: 'varying vec2 vUv; void main() { vUv = uv; gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0); }',
        fragmentShader: `uniform float progress; uniform float head; uniform vec3 dim; uniform vec3 hot; uniform vec3 hi; uniform float opacity; varying vec2 vUv;
            void main() {
                float lit = 1.0 - smoothstep(progress - 0.02, progress, vUv.x);
                vec3 c = mix(dim, hot, lit);
                float hd = exp(-pow((vUv.x - head) / 0.045, 2.0));
                c += hi * hd * 1.6;
                gl_FragColor = vec4(c, opacity);
            }`,
        transparent: true,
        depthWrite: false,
    });

    function connect(from, to, sideIndex) {
        const a = new THREE.Vector3(from.x + PW * from.scale, -(from.y + (from.h * from.scale) / 2), from.z - 6);
        const ty = to.y + (ROW_TOP + sideIndex * ROW_H + ROW_H / 2) * to.scale;
        const b = new THREE.Vector3(to.x, -ty, to.z - 6);
        const mx = a.x + (b.x - a.x) * 0.5;
        const path = new THREE.CurvePath();
        const p1 = new THREE.Vector3(mx, a.y, a.z + (b.z - a.z) * 0.5);
        const p2 = new THREE.Vector3(mx, b.y, a.z + (b.z - a.z) * 0.5);
        [[a, p1], [p1, p2], [p2, b]].forEach(([u, v]) => { if (u.distanceTo(v) > 0.5) path.add(new THREE.LineCurve3(u, v)); });
        if (path.curves.length === 0) return null;
        const mesh = new THREE.Mesh(new THREE.TubeGeometry(path, 64, 2.4, 6, false), tubeMat());
        mesh.layers.enable(GLOW);
        mesh.renderOrder = 0.5;
        root.add(mesh);
        const c = { from: from.key, to: to.key, side: sideIndex, mesh, lit: from.box.status === 'done' };
        mesh.material.uniforms.progress.value = c.lit ? 1.02 : 0;

        return c;
    }

    // ---------------------------------------------------------------- layout

    const columnHeads = [];

    function head(text, x, y, z, w = PW) {
        const l = line(text, COLHEAD, { color: COLOR.ink2, maxWidth: w });
        at(l, x, y, z);
        root.add(l.mesh);
        const rule = createHeat(THREE, w, 2, '#5A5A64');
        rule.position.set(x, -(y + 42), z);
        rule.layers.disable(GLOW);
        root.add(rule);
        const entry = { text, line: l, rule, x, y, z, keys: [] };
        columnHeads.push(entry);

        return entry;
    }

    function layoutBracket(part, ox, oy) {
        const members = [];
        const sections = part.sections || [];
        const isGrandFinal = (s) => s.columns.every((c) => c.matches.every((m) => m.bracket === 'grand-final' || m.bracket === 'reset'));
        const regular = sections.filter((s) => !isGrandFinal(s));
        const gfs = sections.filter(isGrandFinal);
        let y0 = oy;
        let maxCols = 0;
        const placed = [];
        regular.forEach((section) => {
            const cols = section.columns;
            maxCols = Math.max(maxCols, cols.length);
            const first = cols[0]?.matches.filter((m) => m.bracket !== 'bye') || [];
            const step = PH + RG;
            const height = Math.max(1, first.length) * step;
            cols.forEach((col, c) => {
                const x = ox + c * (PW + CG);
                const z = c * RISE;
                const h = head(section.title ? `${section.title}, ${col.label}` : col.label, x, y0 - 76, z);
                const list = col.matches.filter((m) => m.bracket !== 'bye');
                h.keys = list.map((m) => m.key);
                // A match stands level with the matches that feed it; without feeders the column spreads evenly.
                const ys = list.map((box, i) => {
                    const feeders = (box.from || []).map((k) => (k ? plates.get(k) : null)).filter(Boolean);
                    if (c > 0 && feeders.length > 0) return feeders.reduce((n, f) => n + f.y + (f.h * f.scale) / 2, 0) / feeders.length - PH / 2;

                    return y0 + (height / Math.max(1, list.length)) * (i + 0.5) - step / 2;
                });
                const order = ys.map((y, i) => [y, i]).sort((a, b) => a[0] - b[0]);
                for (let k = 1; k < order.length; k++) order[k][0] = Math.max(order[k][0], order[k - 1][0] + step);
                order.forEach(([y, i]) => {
                    const box = list[i];
                    const isFinal = section.title === null && c === cols.length - 1 && box.bracket === 'main';
                    const p = createPlate(box, x, y, z + (isFinal ? RISE * 2 : 0), { scale: isFinal ? 1.18 : 1, final: isFinal });
                    plates.set(box.key, p);
                    placed.push({ p, section: section.title, col: c, label: col.label });
                    if (isFinal) finalPlate = p;
                });
            });
            y0 += height + 260;
        });
        // The grand final (and its reset) to the right of everything, centred between the brackets.
        gfs.forEach((section) => {
            section.columns.forEach((col, c) => {
                const x = ox + (maxCols + c) * (PW + CG) + 40;
                const z = (maxCols + c) * RISE + RISE * 2;
                const list = col.matches.filter((m) => m.bracket !== 'bye');
                const mid = (oy + y0 - 260) / 2;
                const h = head(section.title || col.label, x, mid - 76 - (list.length - 1) * (PH + RG) / 2 - 40, z);
                h.keys = list.map((m) => m.key);
                list.forEach((box, i) => {
                    const y = mid - PH / 2 + (i - (list.length - 1) / 2) * (PH * 1.18 + RG);
                    const p = createPlate(box, x, y, z, { scale: 1.18, final: true });
                    plates.set(box.key, p);
                    placed.push({ p, section: section.title, col: maxCols + c, label: section.title || col.label });
                    if (box.bracket === 'grand-final' && !finalPlate) finalPlate = p;
                    if (box.bracket === 'reset' && box.status !== 'skipped') finalPlate = p;
                });
            });
        });
        // Light paths: from each match to where its winner goes.
        placed.forEach(({ p }) => (p.box.from || []).forEach((k, i) => {
            const f = k ? plates.get(k) : null;
            if (f) {
                const c = connect(f, p, i);
                if (c) connectors.push(c);
            }
        }));
        members.push(...placed);

        return members;
    }

    function layoutTable(part, ox, oy) {
        const members = [];
        const W = 680;
        const RH = 64;
        const standingsHead = head(part.title ? `${part.title}, ${words.standings}` : words.standings, ox, oy - 76, 0, W);
        (part.rows || []).forEach((row, i) => {
            const top = i < 3;
            const slab = createSlab(THREE, W, RH, { depth: 18, corner: null, top: top ? COLOR.metal : undefined, bottom: top ? '#1D1D22' : undefined, spill: i === 0 ? 'left' : null });
            const y = oy + i * (RH + 10);
            slab.group.position.set(ox, -y, 0);
            root.add(slab.group);
            const g = slab.pivot;
            const lines = [];
            const medal = top ? art[['medal-gold', 'medal-silver', 'medal-bronze'][i]] : null;
            const extras = [];
            if (medal) {
                const m = createImage(THREE, medal, 46, 46, { rim: 0.6 });
                m.position.set(38, -RH / 2, 8);
                g.add(m);
                extras.push(m);
            } else {
                const r = line(String(row.rank), SCORE, { color: COLOR.ink3 });
                at(r, 22, (RH - SCORE.size * SCORE.leading) / 2);
                lines.push(r);
            }
            const pts = line(String(row.points), SCORE, { color: COLOR.ink });
            at(pts, W - 24 - boxW(pts), (RH - SCORE.size * SCORE.leading) / 2);
            lines.push(pts);
            const wdl = line(`${row.wins} ${row.ties} ${row.losses}`, SMALL, { color: COLOR.ink2 });
            at(wdl, W - 24 - boxW(pts) - 28 - boxW(wdl), (RH - SMALL.size * SMALL.leading) / 2);
            lines.push(wdl);
            const n = line(row.name, NAME, { color: COLOR.ink, maxWidth: W - 80 - boxW(pts) - boxW(wdl) - 80 });
            at(n, 80, (RH - NAME.size * NAME.leading) / 2);
            lines.push(n);
            lines.forEach((l) => g.add(l.mesh));
            if (i === 0) {
                const bar = createHeat(THREE, 5, RH);
                bar.position.set(-5, 0, 1);
                g.add(bar);
            }
            const p = { box: { key: `row-${i}`, sides: [], status: 'row' }, slab, x: ox, y, z: 0, w: W, h: RH, scale: 1, rows: [{ lines }], heats: [], texts: [row.name, String(row.points)], key: `row-${part.title}-${i}`, row: true, extras };
            plates.set(p.key, p);
            standingsHead.keys.push(p.key);
            members.push({ p, col: 0, label: words.standings, kind: 'standings' });
        });
        // The round on now: its pairings in two columns beside the table.
        const round = tableRound(part);
        if (round) {
            const px = ox + W + 200;
            const ph = head(`${words.pairings}, ${round.label}`, px, oy - 76, RISE, PW * 2 + 60);
            round.boxes.filter((b) => b.bracket !== 'bye').forEach((box, i) => {
                const p = createPlate(box, px + (i % 2) * (PW + 60), oy + Math.floor(i / 2) * (PH + RG), RISE);
                plates.set(box.key, p);
                ph.keys.push(box.key);
                members.push({ p, col: 1, label: round.label, kind: 'pairings' });
            });
        }

        return members;
    }

    function tableRound(part) {
        const rounds = part.rounds || {};
        const numbers = Object.keys(rounds).sort((a, b) => a - b);
        if (numbers.length === 0) return null;
        const open = numbers.find((n) => rounds[n].some((b) => (b.status === 'waiting' || b.status === 'ready') && b.bracket !== 'bye'));
        const n = open ?? numbers[numbers.length - 1];

        return { number: +n, label: rounds[n][0]?.round || `${n}`, boxes: rounds[n] };
    }

    function layoutHeats(part, ox, oy) {
        const members = [];
        const hh = part.title ? head(part.title, ox, oy - 76, 0, PW * 3 + 120) : null;
        if (hh) hh.keys = (part.heats || []).map((b) => b.key);
        (part.heats || []).forEach((box, i) => {
            const sides = [...box.sides].sort((a, b) => (parseInt(String(a.score).replace('#', ''), 10) || 99) - (parseInt(String(b.score).replace('#', ''), 10) || 99));
            const p = createPlate({ ...box, sides }, ox + (i % 3) * (PW + 60), oy + Math.floor(i / 3) * (PH + 4 * ROW_H + 80), 0);
            plates.set(box.key, p);
            members.push({ p, col: i % 3, row: Math.floor(i / 3), label: box.round || part.title || '', kind: 'heats' });
        });

        return members;
    }

    // ---------------------------------------------------------------- build

    let decor = [];

    function build(next) {
        if (root) {
            scene.remove(root);
            disposeTree(root);
        }
        root = new THREE.Group();
        scene.add(root);
        plates = new Map();
        connectors = [];
        columnHeads.length = 0;
        finalPlate = null;
        champion = null;
        decor = [];
        pending.clear();
        anims.length = 0;
        const st = currentStage(next.stages);
        structure = structureOf(st);
        const groups = [];
        let ox = 0;
        (st?.parts || []).forEach((part) => {
            const members = part.kind === 'bracket' ? layoutBracket(part, ox, 0) : part.kind === 'table' ? layoutTable(part, ox, 0) : layoutHeats(part, ox, 0);
            groups.push({ part, members });
            const maxX = Math.max(ox, ...members.map((m) => m.p.x + m.p.w * m.p.scale));
            ox = maxX + 520;
        });
        if (finalPlate) dressFinal(finalPlate);
        if (next.champion && finalPlate) crown(next.champion, -1);
        pageList = paginate(groups, next);
        data = next;
    }

    function dressFinal(p) {
        const c = new THREE.Vector3(p.x + (p.w * p.scale) / 2, -(p.y + (p.h * p.scale) / 2), p.z - 40);
        const rays = createEnergy(THREE, '/broadcast/art/energy-rays.webp', 1300, 1300, { round: true });
        rays.position.copy(c);
        rays.material.uniforms.opacity.value = 0.26;
        root.add(rays);
        const halo = createHalo(THREE, 900, COLOR.btc, 0.2);
        halo.position.copy(c).add(new THREE.Vector3(0, 0, -4));
        root.add(halo);
        decor.push({ kind: 'rays', mesh: rays }, { kind: 'halo', mesh: halo });
    }

    /** The champion over the final: the crown, the word and the name; `t` < 0 stands it there, else it lands at t. */
    function crown(name, t) {
        if (!finalPlate || champion) return;
        const p = finalPlate;
        const cx = p.x + (p.w * p.scale) / 2;
        const topY = p.y - 30;
        const img = createImage(THREE, art.crown, 300, 300, { rim: 1.2, rimFrom: [0, -1] });
        img.position.set(cx, -(topY - 160), p.z + 30);
        root.add(img);
        const label = line(words.champion, COLHEAD, { color: COLOR.btcHi });
        const nameLine = line(name, TYPE.hero, { color: COLOR.ink, maxWidth: 1100, minScale: 0.5 });
        const nameTop = topY - 320 - 96;
        at(nameLine, cx - boxW(nameLine) / 2, nameTop, p.z + 30);
        at(label, cx - boxW(label) / 2, nameTop - 44, p.z + 30);
        root.add(label.mesh, nameLine.mesh);
        const shock = createShock(THREE, 900);
        shock.position.set(cx, -(topY - 160), p.z + 40);
        root.add(shock);
        champion = { name, img, label, nameLine, shock, t, at: new THREE.Vector3(cx, -(topY - 160), p.z + 30), box: { x0: cx - 600, x1: cx + 600, y0: nameTop - 60, y1: p.y + p.h * p.scale + 40, z: p.z } };
    }

    // ---------------------------------------------------------------- pages

    function bboxOf(list, extraTop = 110) {
        const xs = list.flatMap((p) => [p.x, p.x + p.w * p.scale]);
        const ys = list.flatMap((p) => [p.y, p.y + p.h * p.scale]);

        return { x0: Math.min(...xs) - 30, x1: Math.max(...xs) + 30, y0: Math.min(...ys) - extraTop, y1: Math.max(...ys) + 40, z: list.reduce((n, p) => n + p.z, 0) / list.length };
    }

    const textsOf = (list, labels) => [...new Set(labels)].concat(list.flatMap((p) => p.texts));

    function paginate(groups, next) {
        const pages = [];
        const all = [...plates.values()];
        if (all.length === 0) return pages;
        pages.push({ id: 'overview', kind: 'overview', title: next.name, plates: all, box: bboxOf(all, 220), texts: [next.name, ...columnHeads.filter((h) => !h.section).map((h) => h.text).filter((v, i, a) => a.indexOf(v) === i)] });
        groups.forEach(({ part, members }) => {
            if (part.kind === 'bracket') {
                const bySection = new Map();
                members.forEach((m) => {
                    const k = `${m.section ?? ''}`;
                    if (!bySection.has(k)) bySection.set(k, new Map());
                    const cols = bySection.get(k);
                    if (!cols.has(m.col)) cols.set(m.col, []);
                    cols.get(m.col).push(m);
                });
                let prev = null;
                bySection.forEach((cols, section) => {
                    const keys = [...cols.keys()].sort((a, b) => a - b);
                    keys.forEach((c) => {
                        const col = cols.get(c).sort((a, b) => a.p.y - b.p.y);
                        // Even pages: five matches at four a page make three and two, not four and one.
                        const size = Math.ceil(col.length / Math.ceil(col.length / perPage));
                        for (let i = 0; i < col.length; i += size) {
                            const chunk = col.slice(i, i + size).map((m) => m.p);
                            const keysIn = new Set(chunk.map((p) => p.key));
                            const fed = (withNext ? cols.get(keys[keys.indexOf(c) + 1]) || [] : []).map((m) => m.p).filter((p) => (p.box.from || []).some((k) => keysIn.has(k)));
                            const list = [...chunk, ...fed];
                            if (prev && list.every((p) => prev.includes(p))) continue;
                            if (list.length === 1 && list[0] === finalPlate) continue;
                            const labels = [section ? `${section}, ${col[0].label}` : col[0].label, ...(fed[0] ? [cols.get(keys[keys.indexOf(c) + 1])[0].label] : [])];
                            pages.push({ id: `p${pages.length}`, kind: 'page', title: labels[0], plates: list, box: bboxOf(list), texts: textsOf(list, labels), col: c, section });
                            prev = list;
                        }
                    });
                });
            } else if (part.kind === 'table') {
                const rows = members.filter((m) => m.kind === 'standings').map((m) => m.p);
                for (let i = 0; i < rows.length; i += perPage * 2) {
                    const list = rows.slice(i, i + perPage * 2);
                    pages.push({ id: `p${pages.length}`, kind: 'page', title: part.title ? `${part.title}, ${words.standings}` : words.standings, plates: list, box: bboxOf(list), texts: textsOf(list, [words.standings]) });
                }
                const pairs = members.filter((m) => m.kind === 'pairings').map((m) => m.p);
                for (let i = 0; i < pairs.length; i += perPage) {
                    const list = pairs.slice(i, i + perPage);
                    pages.push({ id: `p${pages.length}`, kind: 'page', title: words.pairings, plates: list, box: bboxOf(list), texts: textsOf(list, [words.pairings]) });
                }
            } else {
                const heats = members.map((m) => m.p);
                for (let i = 0; i < heats.length; i += 3) {
                    const list = heats.slice(i, i + 3);
                    pages.push({ id: `p${pages.length}`, kind: 'page', title: part.title || '', plates: list, box: bboxOf(list), texts: textsOf(list, members.slice(i, i + 3).map((m) => m.label)) });
                }
            }
        });
        if (finalPlate) {
            const list = [finalPlate];
            pages.push({ id: 'final', kind: 'final', title: finalPlate.box.round || '', plates: list, box: bboxOf(list, 140), texts: textsOf(list, [finalPlate.box.round || '']) });
        }
        if (champion) pages.push({ id: 'champion', kind: 'champion', title: words.champion, plates: [finalPlate], box: champion.box, texts: [words.champion, champion.name, ...finalPlate.texts] });

        return pages;
    }

    /**
     * The camera pose for a page: it fits the page's box into the frame's free area (`reserve`, logical px kept for
     * the HUD at the top and bottom), never closer than 1.45 logical px per world unit (names stay crisp), from a
     * little left and below (yaw, pitch in degrees) so the slabs show their depth.
     */
    function framing(page, aspect = camera.aspect, { yaw = -7, pitch = 4, maxScale = 1.45, res = reserve } = {}) {
        const b = page.box;
        const tan = Math.tan((FOV * Math.PI) / 360);
        const fracV = (1080 - res.top - res.bottom) / 1080;
        const fracH = (1920 * Math.min(1, aspect / (16 / 9)) - res.side * 2) / (1920 * Math.min(1, aspect / (16 / 9)));
        const bw = b.x1 - b.x0;
        const bh = b.y1 - b.y0;
        let d = Math.max(bh / (2 * tan * fracV), bw / (2 * tan * aspect * fracH));
        d = Math.max(d, 1080 / maxScale / (2 * tan));
        const cx = (b.x0 + b.x1) / 2;
        // The free area's centre sits (top - bottom) / 2 logical px off the frame's centre.
        const shift = ((res.bottom - res.top) / 2 / 1080) * 2 * d * tan;
        const cy = -(b.y0 + b.y1) / 2 - shift;
        const target = new THREE.Vector3(cx, cy, b.z);
        const ry = (yaw * Math.PI) / 180;
        const rx = (pitch * Math.PI) / 180;
        const pos = new THREE.Vector3(cx + Math.sin(ry) * d, cy - Math.sin(rx) * d, b.z + Math.cos(ry) * Math.cos(rx) * d);

        return { pos, target, scale: 1080 / (2 * d * tan) };
    }

    function setPose(pose) {
        camera.position.copy(pose.pos);
        camera.lookAt(pose.target);
        camera.updateMatrixWorld(true);
    }

    // ---------------------------------------------------------------- live

    function boxIndex(st) {
        const map = new Map();
        boxesOf(st).forEach((b) => map.set(b.key, b));

        return map;
    }

    /**
     * Take a new data set. Returns { rebuilt } or { advances: [keys] } (matches newly decided, held for playAdvance).
     */
    function setData(next, t) {
        const st = currentStage(next.stages);
        if (!root || structureOf(st) !== structure) {
            build(next);

            return { rebuilt: true, advances: [] };
        }
        const now = boxIndex(st);
        const advances = [];
        const changed = [];
        now.forEach((box, key) => {
            const p = plates.get(key);
            if (!p) return;
            const was = pending.get(key)?.box || p.box;
            if (JSON.stringify(was) === JSON.stringify(box)) return;
            if (box.status === 'done' && was.status !== 'done') {
                pending.set(key, { box, targets: [] });
                advances.push(key);
            } else {
                changed.push([key, box]);
            }
        });
        // A match fed by a decision still held waits for it and changes when the name lands; anything else now.
        changed.forEach(([key, box]) => {
            const feeder = (box.from || []).find((k) => k && pending.has(k));
            if (feeder) pending.get(feeder).targets.push([key, box]);
            else if (!pending.has(key)) setPlateBox(plates.get(key), box, t);
            else pending.get(key).box = box;
        });
        if (next.champion && !champion && !advances.length) crown(next.champion, t);
        data = { ...next, pendingChampion: next.champion && !champion ? next.champion : null };
        pageList = pageList.map((pg) => ({ ...pg, texts: pg.kind === 'overview' ? pg.texts : textsOf(pg.plates, pg.texts.slice(0, pg.kind === 'champion' ? 2 : 1)) }));

        return { rebuilt: false, advances };
    }

    /** Play a held advance from `t` (the caller has the camera on its page). */
    function playAdvance(key, t) {
        const held = pending.get(key);
        const p = plates.get(key);
        if (!held || !p) return;
        pending.delete(key);
        setPlateBox(p, held.box, t);
        connectors.filter((c) => c.from === key).forEach((c) => anims.push({ kind: 'run', c, t: t + 400 }));
        (held.targets || []).forEach(([k, box]) => {
            const target = plates.get(k);
            if (target) anims.push({ kind: 'land', plate: target, box, t: t + 1200 });
        });
        if (data?.pendingChampion && pending.size === 0) anims.push({ kind: 'crown', name: data.pendingChampion, t: t + 1600 });
    }

    /** Apply every held advance at once (no camera on it: a page boundary passed it by). */
    function flush(t) {
        [...pending.keys()].forEach((k) => playAdvance(k, t));
    }

    // The page in focus: its matches at full light, the rest of the structure a step back (25 %), so the eye goes
    // where the camera stands; the overview lights everything. It eases over `ms` from `t` (the flight).
    let focus = { set: null, from: new Map(), t: 0, ms: 1 };
    function setFocus(page, t, ms = 900) {
        const set = page && page.kind !== 'overview' ? new Set(page.plates.map((p) => p.key)) : null;
        const from = new Map();
        plates.forEach((p, k) => from.set(k, p.light ?? 1));
        focus = { set, from, t, ms };
    }
    function applyFocus(t) {
        const k = CURVES.sweep(span(t, focus.t, focus.ms));
        plates.forEach((p, key) => {
            const goal = !focus.set || focus.set.has(key) ? 1 : 0.1;
            const v = (focus.from.get(key) ?? 1) + (goal - (focus.from.get(key) ?? 1)) * k;
            p.light = v;
            p.slab.setOpacity(0.35 + 0.65 * v);
            p.heats.forEach((h) => { h.material.opacity = v; });
            p.rows.forEach((r) => {
                (r.lines || []).forEach((l) => { l.material.uniforms.opacity.value = 0.25 + 0.75 * v; });
                if (r.bar) r.bar.material.opacity = v;
            });
            if (p.chip) p.chip.material.uniforms.opacity.value = v * (p.chipBorn > 0 ? CURVES.reveal(span(t, p.chipBorn, 400)) : 1);
            (p.extras || []).forEach((x) => { x.material.uniforms.opacity.value = 0.3 + 0.7 * v; });
        });
        // Round headers follow their matches; the champion's words stand out only where the final is in focus.
        columnHeads.forEach((h) => {
            const v = h.keys && h.keys.length ? Math.max(...h.keys.map((key) => plates.get(key)?.light ?? 1)) : 1;
            h.line.material.uniforms.opacity.value = 0.12 + 0.88 * v;
            if (h.rule) h.rule.material.opacity = v;
        });
        if (champion && finalPlate) {
            const v = finalPlate.light ?? 1;
            champion.label.material.uniforms.opacity.value = v;
            champion.nameLine.material.uniforms.opacity.value = v;
            champion.fade = v;
        }
    }

    const pageOf = (key) => pageList.find((pg) => pg.kind === 'page' && pg.plates.some((p) => p.key === key)) || null;

    // ---------------------------------------------------------------- frame

    function update(t, dt = 16) {
        if (!root) return;
        for (let i = anims.length - 1; i >= 0; i--) {
            const a = anims[i];
            if (t < a.t) continue;
            const k = t - a.t;
            if (a.kind === 'drop') {
                const v = 1 - CURVES.leave(span(k, 0, 300));
                a.row.lines.forEach((l) => { l.material.uniforms.reveal.value = v; });
                if (a.row.bar) a.row.bar.material.opacity = v;
                if (k > 320) {
                    a.row.lines.forEach((l) => { a.plate.slab.pivot.remove(l.mesh); disposeTree(l.mesh); });
                    if (a.row.bar) a.plate.slab.pivot.remove(a.row.bar);
                    anims.splice(i, 1);
                }
            } else if (a.kind === 'unlive') {
                const v = 1 - CURVES.leave(span(k, 0, 400));
                a.chip.material.uniforms.opacity.value = v;
                if (a.edge) a.edge.material.opacity = v;
                if (k > 420) {
                    a.plate.slab.pivot.remove(a.chip.mesh);
                    disposeTree(a.chip.mesh);
                    if (a.edge) a.plate.slab.pivot.remove(a.edge);
                    anims.splice(i, 1);
                }
            } else if (a.kind === 'spark') {
                particles.emit(a.p.x + 960, 540 - a.p.y, 40, { w: 20, h: 6, speed: 260, rise: 20, lifeMs: 900, sizePx: 7, radial: true, z: a.p.z });
                anims.splice(i, 1);
            } else if (a.kind === 'run') {
                const v = CURVES.sweep(span(k, 0, 900));
                a.c.mesh.material.uniforms.progress.value = v * 1.02;
                a.c.mesh.material.uniforms.head.value = k < 1000 ? v : -1;
                if (k > 1000) { a.c.lit = true; anims.splice(i, 1); }
            } else if (a.kind === 'land') {
                setPlateBox(a.plate, a.box, a.t);
                anims.splice(i, 1);
            } else if (a.kind === 'crown') {
                crown(a.name, a.t);
                if (pageList.length && !pageList.some((pg) => pg.kind === 'champion')) pageList.push({ id: 'champion', kind: 'champion', title: words.champion, plates: [finalPlate], box: champion.box, texts: [words.champion, champion.name, ...finalPlate.texts] });
                anims.splice(i, 1);
            }
        }
        // Reveals of new rows (born > 0): 0-500 ms after birth; the winner's hot edge grows with them.
        plates.forEach((p) => p.rows.forEach((row) => {
            if (row.born < 0 || row.dying) return;
            const k = CURVES.reveal(span(t - row.born, 0, 500));
            row.lines.forEach((l) => { l.material.uniforms.reveal.value = k; });
            if (row.bar) row.bar.scale.y = Math.max(0.001, CURVES.set(span(t - row.born, 0, 500)));
            if (t - row.born > 520) row.born = -1;
        }));
        decor.forEach((d) => { if (d.kind === 'rays') d.mesh.material.uniforms.spin.value = t * 0.00004; });
        applyFocus(t);
        if (champion) {
            const k = champion.t < 0 ? 1 : CURVES.set(span(t - champion.t, 0, 900));
            champion.img.position.y = champion.at.y + (1 - k) * 260;
            champion.img.material.uniforms.opacity.value = champion.img.userData.size ? Math.min(1, k * 1.4) * (champion.fade ?? 1) : 0;
            champion.img.material.uniforms.sheen.value = -0.5 + 2 * CURVES.sweep(span((t % 6000), 3500, 1400));
            const w = champion.t < 0 ? 1 : CURVES.reveal(span(t - champion.t, 700, 700));
            champion.label.material.uniforms.reveal.value = w;
            champion.nameLine.material.uniforms.reveal.value = w;
            const s = champion.t < 0 ? 1 : span(t - champion.t, 760, 900);
            champion.shock.material.uniforms.progress.value = s;
            champion.shock.material.uniforms.opacity.value = champion.t < 0 || s >= 1 ? 0 : 1;
            if (champion.t >= 0 && !champion.burst && t - champion.t > 760) {
                champion.burst = true;
                particles.emit(champion.at.x + 960, 540 - champion.at.y, 180, { speed: 640, rise: 30, lifeMs: 1400, sizePx: 10, radial: true, inner: 160, z: champion.at.z + 10 });
            }
        }
        // Dust in the room: a few embers drift up through the structure.
        if (Math.random() < 0.25) {
            const b = pageList[0]?.box;
            if (b) particles.emit(b.x0 + 960 + Math.random() * (b.x1 - b.x0), 540 + b.y1, 1, { speed: 10, rise: 34, lifeMs: 9000, sizePx: 5, z: -60 + Math.random() * 160 });
        }
        particles.update(dt);
    }

    /** Screen position (logical px of the 1920x1080 frame) of a world point through the camera. */
    function toScreen(v) {
        const p = v.clone().project(camera);

        return { x: (p.x + 1) * 960, y: (1 - p.y) * 540 };
    }

    return {
        scene,
        camera,
        particles,
        setData,
        playAdvance,
        flush,
        pageOf,
        pages: () => pageList,
        framing,
        setPose,
        setFocus,
        update,
        toScreen,
        plates: () => plates,
        connectors: () => connectors,
        hasPending: () => pending.size > 0,
        textMeshes(page) {
            return page.plates.flatMap((p) => p.rows.flatMap((r) => (r.lines || []).map((l) => l.mesh)));
        },
        champion: () => champion?.name ?? null,
        structure: () => structure,
        get data() { return data; },
        dispose() {
            if (root) {
                scene.remove(root);
                disposeTree(root);
            }
        },
    };
}
