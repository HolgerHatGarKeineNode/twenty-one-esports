/**
 * The tournament page's 3D view (plan P6): the OBS bracket's world (world.js) in a box on the page, loaded only when a
 * viewer picks "3D" (resources/js/bracketView.js imports this file and three.js on demand: a viewer who stays on 2D
 * pays for neither).
 *
 * On a phone (an upright box) a page is one round's column, three matches at a time; wider, four matches with the
 * matches they feed. It starts on the part of the bracket that is being played (the first page with a match to play) and flies there
 * from afar. The viewer moves it: the round buttons fly page by page (the same flight as on air, 1.1 s), a drag turns
 * the structure up to 28 deg either way and 14 deg up or down (it springs back slowly when let go), and a pool of
 * light follows the pointer over the plates. No wheel: the page keeps its scroll. A touch drag sideways turns, an
 * upright one scrolls the page (touch-action: pan-y on the canvas).
 *
 * Live: a push on `tournament.{id}` (or every 30 s while the page is visible) fetches the bracket again; a decided
 * match plays its advance (winner lights, light runs, name lands) wherever it is. Drawing stops while the box is off
 * screen or 2D is chosen again.
 *
 * window.bracket3d (tests): { ready, state(), stats() }.
 */

import { CURVES, span } from '../curves.js';
import { createArena } from '../elements/arena.js';
import { GUARD } from '../guard.js';
import { createHalo } from '../materials.js';
import { createStage } from '../stage.js';
import { fontsReady, wireTextScale } from '../text.js';
import { COLOR } from '../tokens.js';
import { createBracketWorld } from './world.js';

const FLIGHT_MS = 1100;

export async function mountBracket3d(host, { url, tournamentId, words, art, onPage = () => {} }) {
    const THREE = window.THREE;
    await fontsReady();
    const canvas = host.querySelector('canvas');
    const stage = createStage(canvas, { THREE });
    wireTextScale(stage);
    GUARD.fullScreen = true;
    GUARD.guardOn.value = 0;
    stage.setBackdrop(COLOR.ground);
    const narrow = () => canvas.clientWidth / Math.max(1, canvas.clientHeight) < 1;
    const world = createBracketWorld(stage, { words, art, reserve: { top: 40, bottom: 40, side: 40 }, perPage: narrow() ? 3 : 4, withNext: !narrow() });
    const arena = createArena(stage, { url: '/broadcast/art/plate-haze.webp', exposure: 0.7, camera: world.camera, warmth: 0.5 });
    const light = createHalo(THREE, 900, COLOR.btcHi, 0);
    world.scene.add(light);

    let pages = [];
    let index = 0;
    let flight = null;
    let pose = null;
    const orbit = { yaw: 0, pitch: 0, vy: 0, vp: 0, dragging: false };
    let lightK = 0;
    let lightTarget = 0;
    let lastT = null;

    const pageList = () => world.pages().filter((p) => p.kind !== 'overview');

    function fly(to, now, ms = FLIGHT_MS) {
        const target = world.framing(pages[to], world.camera.aspect, { yaw: 0, pitch: 0 });
        flight = { from: pose || { pos: target.pos.clone().sub(target.target).multiplyScalar(2.4).add(target.target).add(new THREE.Vector3(-900, -400, 0)), target: target.target.clone() }, to: target, t0: now, t1: now + ms };
        index = to;
        world.setFocus(pages[to], now, ms);
        onPage({ index, count: pages.length, title: pages[to].title });
    }

    function applyData(data, initial = false) {
        const now = stage.now();
        const r = world.setData(data, now);
        if (r.rebuilt || initial) {
            pages = pageList();
            const open = pages.findIndex((p) => p.plates.some((pl) => pl.box.status === 'ready' || pl.box.status === 'waiting'));
            pose = null;
            if (pages.length) fly(Math.max(0, open), now, initial ? 1800 : FLIGHT_MS);
        } else {
            r.advances.forEach((k) => world.playAdvance(k, now));
            pages = pageList();
            if (index >= pages.length) index = Math.max(0, pages.length - 1);
        }
    }

    async function load(initial = false) {
        const res = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
        if (!res.ok) throw new Error(`bracket ${res.status}`);
        applyData(await res.json(), initial);
    }

    stage.onFrame((now) => {
        const dt = lastT === null ? 16 : Math.min(100, now - lastT);
        lastT = now;
        arena.update(now);
        world.update(now, dt);
        if (!flight) return;
        const k = CURVES.sweep(span(now, flight.t0, flight.t1 - flight.t0));
        const p = flight.from.pos.clone().lerp(flight.to.pos, k);
        const tg = flight.from.target.clone().lerp(flight.to.target, k);
        p.add(p.clone().sub(tg).multiplyScalar(0.2 * Math.sin(Math.PI * k)));
        pose = { pos: p, target: tg };
        if (!orbit.dragging) {
            orbit.yaw *= 0.985;
            orbit.pitch *= 0.985;
        }
        // The orbit turns the camera about the page's centre.
        const off = p.clone().sub(tg);
        const sph = new THREE.Spherical().setFromVector3(off);
        sph.theta += (orbit.yaw * Math.PI) / 180;
        sph.phi = Math.min(Math.PI - 0.2, Math.max(0.2, sph.phi - (orbit.pitch * Math.PI) / 180));
        world.setPose({ pos: tg.clone().add(new THREE.Vector3().setFromSpherical(sph)), target: tg });
        lightK += (lightTarget - lightK) * 0.08;
        light.material.uniforms.opacity.value = 0.22 * lightK;
    });

    // Pointer: drag turns, the light follows.
    const ray = new THREE.Raycaster();
    const plane = new THREE.Plane(new THREE.Vector3(0, 0, 1), 0);
    let last = null;
    canvas.addEventListener('pointerdown', (e) => {
        orbit.dragging = true;
        last = { x: e.clientX, y: e.clientY };
        canvas.setPointerCapture(e.pointerId);
    });
    canvas.addEventListener('pointermove', (e) => {
        const r = canvas.getBoundingClientRect();
        const ndc = new THREE.Vector2(((e.clientX - r.left) / r.width) * 2 - 1, -((e.clientY - r.top) / r.height) * 2 + 1);
        ray.setFromCamera(ndc, world.camera);
        if (pages[index]) plane.constant = -pages[index].box.z + 30;
        const hit = new THREE.Vector3();
        if (ray.ray.intersectPlane(plane, hit)) {
            light.position.copy(hit);
            light.position.z -= 30;
            lightTarget = 1;
        }
        if (!orbit.dragging || !last) return;
        orbit.yaw = Math.max(-28, Math.min(28, orbit.yaw - (e.clientX - last.x) * 0.18));
        orbit.pitch = Math.max(-14, Math.min(14, orbit.pitch + (e.clientY - last.y) * 0.12));
        last = { x: e.clientX, y: e.clientY };
    });
    const release = () => { orbit.dragging = false; last = null; };
    canvas.addEventListener('pointerup', release);
    canvas.addEventListener('pointercancel', release);
    canvas.addEventListener('pointerleave', () => { lightTarget = 0; });

    // Drawing only while the box is on screen.
    let visible = true;
    new IntersectionObserver((entries) => {
        visible = entries.some((e) => e.isIntersecting);
        if (visible && host.offsetParent !== null) stage.start();
        else stage.stop();
    }).observe(host);

    await load(true);
    stage.start();

    // Live.
    let timer = null;
    const refetch = () => {
        if (timer) return;
        timer = setTimeout(() => { timer = null; load().catch(() => {}); }, 600 + Math.random() * 1200);
    };
    if (window.Echo && tournamentId) window.Echo.channel(`tournament.${tournamentId}`).listen('.tournament.changed', refetch);
    setInterval(() => { if (!document.hidden && visible && host.offsetParent !== null) load().catch(() => {}); }, 30000);

    const api = {
        prev() { if (pages.length) fly((index - 1 + pages.length) % pages.length, stage.now()); },
        next() { if (pages.length) fly((index + 1) % pages.length, stage.now()); },
        pause() { stage.stop(); },
        resume() { if (visible) stage.start(); },
        reload: () => load(),
    };
    window.bracket3d = {
        ready: true,
        state: () => ({ index, count: pages.length, title: pages[index]?.title ?? null, pages: pages.map((p) => p.title), plates: world.plates().size, flying: !!flight && stage.now() < flight.t1, champion: world.champion() }),
        stats: () => stage.stats(),
        api,
    };

    return api;
}
