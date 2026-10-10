/**
 * The bracket scene (plan P6): the preset's tournament as a 3D bracket, full screen and opaque, for the stream between
 * matches or as its own scene. resources/js/broadcast/bracket/world.js builds the structure; this file is the camera
 * work and the frame around it.
 *
 * Hierarchy: 1. the matches on the page the camera stands on (names, scores, who won); 2. the structure (round
 * headers, light paths, the final on its light); 3. the frame: the banner top left (the tournament, where it
 * stands), the round rail along the foot (which part of the bracket this is), the QR code of the tournament page.
 *
 * Tempo: a page is a camera stop. The flight away is the page's build-out (TIMING.bracketFlightOutMs), the flight to
 * the next its build-in (TIMING.bracketFlightInMs); while a page holds (TIMING.bracketPageMs at least, more when its
 * names need it) the camera does not move, measured on the screen: holdMotionPx is how far any of the page's names
 * moved in logical px while held. The cycle: an overview of the whole stage, the pages from the round on now to the
 * final, the earlier rounds, the final on its own, the champion with the crown.
 *
 * Live: every `.tournament.changed` and poll brings a snapshot; a match newly decided is held back and played on its
 * own page: as soon as the page on air has been read, the camera flies to the match, the winner lights, the light runs
 * to the next match and the name lands there; the page then holds long enough to read the new name. A new stage (the
 * group stage over) takes a stinger and a new structure. Before the draw there is no bracket yet: the scene shows the
 * tournament with its countdown and QR code (the break scene's hero) until the pairings stand.
 */

import { CURVES, span } from '../curves.js';
import { createBracketWorld, currentStage, structureOf } from '../bracket/world.js';
import { createArena } from '../elements/arena.js';
import { createHero } from '../elements/hero.js';
import { createHeat } from '../materials.js';
import { createLine, disposeTree } from '../text.js';
import { COLOR, SLOTS } from '../tokens.js';
import { TIMING, holdForTexts } from '../timing.js';
import { runShell } from './shell.js';

const DAY = 86_400_000;
const RESERVE = { top: 170, bottom: 150, side: 170 };
const RAIL = { x: 96, y: 958, w: 1040 };
const QR_SLOT = Object.freeze({ x: 1184, y: 904, w: 640, h: 104 });
const RAIL_STYLE = { family: 'display', weight: 600, size: 22, leading: 1.2, tracking: 0 };

export function runBracket(ctx) {
    const { b, texts: t, sound, director } = ctx;
    const T = () => ctx.snapshot().tournament;
    if (!T()) return runShell(ctx);
    const modules = () => ctx.snapshot().preset.modules;
    b.fullScreen();
    const stage = b.stage;
    const { THREE } = stage;

    const world = createBracketWorld(stage, {
        words: { live: t.live, champion: t.champion, standings: t.standings, pairings: t.pairings },
        art: { crown: ctx.art('crown'), 'medal-gold': ctx.art('medal-gold'), 'medal-silver': ctx.art('medal-silver'), 'medal-bronze': ctx.art('medal-bronze') },
        reserve: RESERVE,
    });
    const arena = createArena(stage, { url: '/broadcast/art/plate-haze.webp', exposure: 0.75, camera: world.camera, warmth: 0.6 });
    let lastT = null;
    stage.onFrame((now) => {
        const dt = lastT === null ? 16 : Math.min(100, now - lastT);
        lastT = now;
        arena.update(now);
        world.update(now, dt);
        frame(now);
    });

    const t0 = stage.now();
    b.stinger({ start: t0 + 300, onPeak: () => sound.play('hit') });
    director.later(t0 + 300, () => sound.play('whoosh'));
    const after = t0 + 300 + TIMING.stingerMs;

    // The frame: banner, QR code, round rail.
    let banner = null;
    let bannerKey = '';
    const bannerFor = (start) => {
        const tour = T();
        bannerKey = `${tour.name}|${tour.statusLine}`;
        banner = b.lowerThird({ slot: SLOTS.banner, kind: 'banner', start, name: tour.name, line: tour.statusLine, emblem: ctx.art(tour.emblem) === 'mark' ? null : ctx.art(tour.emblem), mark: ctx.art(tour.emblem) === 'mark', holdMs: 3_600_000 });
    };
    bannerFor(after + 100);
    setInterval(() => {
        const tour = T();
        if (!tour || !banner || `${tour.name}|${tour.statusLine}` === bannerKey) return;
        const now = stage.now();
        if (now < banner.seg.start + Math.max(TIMING.rotationMs, banner.seg.introMs + banner.seg.holdRuleMs)) return;
        banner.retire(now);
        bannerFor(banner.seg.end + 400);
    }, 1000);
    if (modules().qr && T().qr) b.lowerThird({ slot: QR_SLOT, kind: 'join', start: after + 700, name: t.scanFollow, line: T().url.replace(/^https?:\/\//, ''), qr: T().qr, holdMs: 3_600_000 });
    const rail = createRail(stage);
    // The frame's ground: the top and foot bands darken towards the edge, so the banner, the rail and the QR card
    // stand on calm ground whatever part of the structure passes behind them.
    const shade = new THREE.Mesh(new THREE.PlaneGeometry(1920, 1080), new THREE.ShaderMaterial({
        uniforms: { ground: { value: new THREE.Color(COLOR.ground) } },
        vertexShader: 'varying vec2 vUv; void main() { vUv = uv; gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0); }',
        fragmentShader: `uniform vec3 ground; varying vec2 vUv;
            void main() { float y = (1.0 - vUv.y) * 1080.0;
              float a = max(1.0 - smoothstep(110.0, ${RESERVE.top + 40}.0, y), smoothstep(${1080 - RESERVE.bottom - 90}.0, ${1080 - RESERVE.bottom + 40}.0, y)) * 0.88;
              gl_FragColor = vec4(ground * a, a); }`,
        transparent: true,
        depthWrite: false,
        depthTest: false,
        premultipliedAlpha: true,
        blending: THREE.CustomBlending,
        blendSrc: THREE.OneFactor,
        blendDst: THREE.OneMinusSrcAlphaFactor,
    }));
    shade.renderOrder = -50;
    stage.scene.add(shade);

    // ------------------------------------------------------------ pages and the camera
    let cur = null;
    let flight = null;
    let pose = null;
    let order = [];
    let at = 0;
    const queue = [];
    let hero = null;
    let refRef = null;
    // Page segments still to be stamped to their end (the one leaving keeps its outro after the next one started).
    const open = [];

    const farPose = (p) => ({ pos: p.pos.clone().sub(p.target).multiplyScalar(2.2).add(p.target).add(new THREE.Vector3(-600, -300, 0)), target: p.target.clone() });

    function orderPages() {
        const pages = world.pages();
        const overview = pages.filter((p) => p.kind === 'overview');
        const regular = pages.filter((p) => p.kind === 'page');
        const open = (p) => p.plates.some((pl) => pl.box.status === 'ready' || pl.box.status === 'waiting');
        const first = Math.max(0, regular.findIndex(open));
        const tail = pages.filter((p) => p.kind === 'final' || p.kind === 'champion');

        return [...overview, ...regular.slice(first), ...regular.slice(0, first), ...tail];
    }

    function startPage(page, start, fromPose) {
        const holdBase = page.kind === 'overview' ? TIMING.bracketOverviewMs : page.kind === 'champion' ? TIMING.bracketChampionMs : TIMING.bracketPageMs;
        const seg = b.timeline.add({
            kind: 'bracketPage', slot: 'bracket', texts: page.texts, start, introMs: TIMING.bracketFlightInMs,
            holdMs: Math.max(holdBase, holdForTexts(page.texts)), outroMs: TIMING.bracketFlightOutMs,
            extra: { page: page.id, pageKind: page.kind, title: page.title, plates: page.plates.length, screenScale: 0 },
        });
        const to = world.framing(page, world.camera.aspect, page.kind === 'overview' ? { yaw: -16, pitch: 9, maxScale: 1.1 } : {});
        seg.screenScale = +to.scale.toFixed(3);
        flight = { from: fromPose || farPose(to), to, t0: fromPose ? start - TIMING.bracketFlightOutMs : start - 300, t1: start + TIMING.bracketFlightInMs };
        cur = { page, seg, played: new Set() };
        open.push(seg);
        world.setFocus(page, start - TIMING.bracketFlightOutMs, TIMING.bracketFlightOutMs + TIMING.bracketFlightInMs);
        refRef = null;
        rail.show(page, start, [...new Set(world.pages().filter((p) => p.kind !== 'overview').map((p) => p.title).filter(Boolean))]);
    }

    function nextPage(start) {
        if (order.length === 0) return;
        // A decision waiting for its page: that page comes next.
        const waiting = queue.find((q) => world.pageOf(q.key));
        let page = null;
        if (waiting) page = world.pageOf(waiting.key);
        if (!page) page = order[at++ % order.length];
        startPage(page, start, pose);
    }

    function begin(start) {
        order = orderPages();
        at = 0;
        if (order.length === 0) {
            showHero(start);

            return;
        }
        if (hero) {
            hero.retire(start - TIMING.heroOutroMs);
            hero = null;
        }
        pose = null;
        nextPage(start);
    }

    function showHero(start) {
        if (hero) return;
        const tour = T();
        const left = Date.parse(tour.startsAt) - Date.now();
        const spec = { state: 'soon', headline: tour.name, line: tour.gameName, label: left > 0 && left < DAY ? t.startsIn : t.starts, startsAt: left > 0 && left < DAY ? tour.startsAt : null, big: left > 0 && left < DAY ? null : tour.starts, when: tour.statusLine };
        hero = b.element(createHero(stage, b.timeline, b.particles, { start, hero: spec, art: {} }));
    }

    function frame(now) {
        if (!cur || !flight) return;
        // The camera: one smooth flight from the last page's hold end to this page's build-in end, pulled back a
        // little at its middle so it reads as a move through the structure, not a zoom.
        const k = CURVES.sweep(span(now, flight.t0, flight.t1 - flight.t0));
        const p = flight.from.pos.clone().lerp(flight.to.pos, k);
        const tg = flight.from.target.clone().lerp(flight.to.target, k);
        const back = p.clone().sub(tg).multiplyScalar(0.22 * Math.sin(Math.PI * k));
        pose = { pos: p.add(back), target: tg };
        world.setPose(pose);
        for (let i = open.length - 1; i >= 0; i--) {
            const p0 = b.timeline.phase(open[i], now);
            b.timeline.observe(open[i], p0, now, []);
            if (p0.name === 'after') open.splice(i, 1);
        }
        const ph = b.timeline.phase(cur.seg, now);
        // Measured on the screen: how far the page's names move while it holds.
        if (ph.name === 'hold') {
            const pts = world.textMeshes(cur.page).slice(0, 8).map((m) => {
                const v = new THREE.Vector3();
                m.getWorldPosition(v);

                return world.toScreen(v);
            });
            if (!refRef) refRef = pts;
            else pts.forEach((q, i) => {
                const r = refRef[i];
                if (r) cur.seg.holdMotionPx = Math.max(cur.seg.holdMotionPx, +Math.hypot(q.x - r.x, q.y - r.y).toFixed(3));
            });
        }
    }

    // The scheduler: page turns, live decisions, a new structure.
    setInterval(() => {
        if (!cur) return;
        const now = stage.now();
        const seg = cur.seg;
        const holdStart = seg.start + seg.introMs;
        const holdEnd = holdStart + seg.holdMs;
        // A decision on this page plays once the camera stands; the page then holds for the name that lands.
        queue.filter((q) => cur.page.plates.some((p) => p.key === q.key)).forEach((q) => {
            if (now < holdStart + 300 || cur.played.has(q.key)) return;
            cur.played.add(q.key);
            queue.splice(queue.indexOf(q), 1);
            world.playAdvance(q.key, now);
            q.playedAt = now;
            const need = now + TIMING.advanceMs + Math.max(TIMING.bracketPageMs * 0.6, holdForTexts(cur.page.texts) * 0.5) - holdStart;
            if (need > seg.holdMs) {
                seg.holdMs = Math.round(need);
                seg.end = seg.start + seg.introMs + seg.holdMs + seg.outroMs;
            }
            cur.page.played = true;
        });
        // A decision elsewhere: once this page has been read, cut to it.
        const elsewhere = queue.some((q) => world.pageOf(q.key) && world.pageOf(q.key) !== cur.page);
        const read = Math.max(TIMING.bracketPageMs, seg.holdRuleMs);
        if (elsewhere && now >= holdStart + read && now < holdEnd) {
            seg.holdMs = Math.round(now - holdStart);
            seg.end = seg.start + seg.introMs + seg.holdMs + seg.outroMs;
        }
        // A decision older than two minutes without a page: applied quietly.
        queue.filter((q) => now - q.at > 120000).forEach((q) => { queue.splice(queue.indexOf(q), 1); world.flush(now); });
        if (now >= seg.end - 60) nextPage(seg.end);
    }, 100);

    // ------------------------------------------------------------ live data
    let rebuilding = false;
    ctx.onSnapshot((next) => {
        const tour = next.tournament;
        if (!tour) return;
        const st = currentStage(tour.stages);
        if (structureOf(st) !== world.structure()) {
            if (rebuilding) return;
            rebuilding = true;
            const start = stage.now() + 400;
            b.stinger({ start, onPeak: () => sound.play('hit') });
            director.later(start, () => sound.play('whoosh'));
            director.later(start + TIMING.stingerPeakMs, () => {
                world.setData(T(), stage.now());
                cur = null;
                begin(stage.now() + 200);
                rebuilding = false;
            });

            return;
        }
        const r = world.setData(tour, stage.now());
        r.advances.forEach((key) => queue.push({ key, at: stage.now() }));
        order = orderPages().length ? orderPages() : order;
    });

    world.setData(T(), t0);
    begin(t0 + 300 + TIMING.stingerPeakMs);

    window.bracketScene = {
        pages: () => world.pages().map((p) => ({ id: p.id, kind: p.kind, title: p.title, plates: p.plates.map((pl) => pl.key), texts: p.texts })),
        current: () => (cur ? { page: cur.page.id, kind: cur.page.kind, start: cur.seg.start, end: cur.seg.end } : null),
        queue: () => queue.map((q) => q.key),
        pending: () => world.hasPending(),
        champion: () => world.champion(),
        /** Screen box (logical px) of a match's plate through the camera now. */
        plateOnScreen(key) {
            const p = world.plates().get(key);
            if (!p) return null;
            const a = world.toScreen(new THREE.Vector3(p.x, -p.y, p.z));
            const c = world.toScreen(new THREE.Vector3(p.x + p.w * p.scale, -(p.y + p.h * p.scale), p.z));

            return { x0: a.x, y0: a.y, x1: c.x, y1: c.y };
        },
        lit: () => world.connectors().filter((c) => c.lit).map((c) => c.from),
    };

    return () => ({ page: cur?.page.id ?? null, pageKind: cur?.page.kind ?? null, pages: world.pages().length, queue: queue.length, hero: !!hero });
}

/**
 * The round rail along the foot: the stops of the bracket in order (round 1 to the final, the champion), the one on
 * air in white with a hot bar under it, the others dimmed; the bar slides to the next stop during the flight, the
 * labels never move.
 */
function createRail(stage) {
    const { THREE } = stage;
    const root = new THREE.Group();
    stage.scene.add(root);
    const o = stage.toWorld(0, 0);
    const bar = createHeat(THREE, 1, 4);
    root.add(bar);
    let labels = [];
    let key = '';
    let from = { x: RAIL.x, w: 0 };
    let to = { x: RAIL.x, w: 0 };
    let movedAt = -1e9;
    let shownAt = 0;
    let active = null;

    function build(stops) {
        labels.forEach((l) => { root.remove(l.line.mesh); disposeTree(l.line.mesh); });
        labels = [];
        let x = RAIL.x;
        for (const text of stops) {
            const line = createLine(stage, text, RAIL_STYLE, { color: COLOR.ink, maxWidth: 360 });
            const w = line.width - line.pad * 2;
            if (x + w > RAIL.x + RAIL.w) {
                disposeTree(line.mesh);
                break;
            }
            line.mesh.position.set(o.x + x - line.pad + line.width / 2, o.y - (RAIL.y - line.pad + line.height / 2), 0.6);
            root.add(line.mesh);
            labels.push({ text, line, x, w });
            x += w + 44;
        }
    }

    stage.onFrame((t) => {
        const k = CURVES.sweep(span(t, movedAt, TIMING.bracketFlightOutMs + TIMING.bracketFlightInMs));
        bar.scale.x = Math.max(1, from.w + (to.w - from.w) * k);
        bar.position.set(o.x + from.x + (to.x - from.x) * k, o.y - (RAIL.y + 36), 1);
        const fade = Math.min(1, Math.max(0, (t - shownAt) / 600));
        bar.material.opacity = to.w > 0 ? fade : 0;
        labels.forEach((l) => { l.line.material.uniforms.opacity.value = fade * (l.text === active ? 1 : 0.42); });
    });

    return {
        /** The page on air from `t` (its build-in start); `stops` are the bracket's page titles in order. */
        show(page, t, stops) {
            const k = JSON.stringify(stops);
            if (k !== key) {
                key = k;
                build(stops);
                shownAt = t;
            }
            active = page.title;
            const hit = labels.find((l) => l.text === page.title);
            from = { ...to };
            to = hit ? { x: hit.x, w: hit.w } : { x: to.x, w: 0 };
            movedAt = t - TIMING.bracketFlightOutMs;
        },
    };
}
