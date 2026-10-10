/**
 * The ticker (SLOTS.ticker): an orange live chip and a glass rail along the foot of the frame, its texts crawling at
 * TIMING.tickerPxPerS (64 logical px/s, cap 80), each separated by a small turning block. The crawl is the one place
 * text moves while read, as the plan allows it: slow enough that a word stays ~25 s on the rail. Its speed is measured
 * from the meshes' real positions every second (seg.observedPxPerS) for the tempo test.
 */

import { CURVES, span } from '../curves.js';
import { createHeat, createSlab } from '../materials.js';
import { createLine, disposeTree } from '../text.js';
import { COLOR, SLOTS, TYPE } from '../tokens.js';
import { TIMING } from '../timing.js';
import { DEG, place } from './lowerThird.js';

export function createTicker(stage, timeline, { start, label, items }) {
    const { THREE } = stage;
    const slot = SLOTS.ticker;
    const H = slot.h;
    const root = new THREE.Group();
    const o = stage.toWorld(slot.x, slot.y);
    root.position.set(o.x, o.y, 0);
    stage.scene.add(root);

    const chipText = createLine(stage, label, TYPE.tag, { color: COLOR.onBtc });
    const chipW = Math.ceil(chipText.width - chipText.pad * 2 + 40);
    const chipFace = new THREE.ShaderMaterial({
        uniforms: { opacity: { value: 1 }, sweep: { value: -1 }, a: { value: new THREE.Color(COLOR.btcHi) }, b: { value: new THREE.Color(COLOR.btc) } },
        vertexShader: 'varying vec2 vUv; void main() { vUv = uv; gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0); }',
        fragmentShader: `uniform float opacity; uniform float sweep; uniform vec3 a; uniform vec3 b; varying vec2 vUv;
            void main() { vec3 c = mix(b, a, vUv.y * 0.35); float d = vUv.x - sweep; c += vec3(0.25) * exp(-d * d * 60.0); gl_FragColor = vec4(c, opacity); }`,
        transparent: true,
        depthWrite: false,
    });
    const chip = createSlab(THREE, chipW, H, { face: chipFace, depth: 14 });
    root.add(chip.group);
    place(chipText, 20, (H - TYPE.tag.size * TYPE.tag.leading) / 2);
    chip.pivot.add(chipText.mesh);

    const railX = chipW + 8;
    const railW = slot.w - railX;
    const rail = createSlab(THREE, railW, H, { depth: 14 });
    rail.group.position.x = railX;
    root.add(rail.group);
    const under = createHeat(THREE, chipW, 3);
    under.position.set(0, -H - 3, 0);
    root.add(under);

    // The crawl: items and separators laid out once, repeated until they cover the rail twice, wrapped by length.
    const crawl = new THREE.Group();
    crawl.position.set(railX, 0, 1);
    root.add(crawl);
    const gap = 36;
    const sep = 10;
    const parts = [];
    let x = 0;
    const cubeGeo = new THREE.BoxGeometry(sep, sep, sep);
    // Lit like a block: each face its own brightness of btc orange, so the turning reads as a cube, not a dot.
    const cubeMat = new THREE.ShaderMaterial({
        uniforms: { opacity: { value: 1 }, hot: { value: new THREE.Color(COLOR.btc) }, hi: { value: new THREE.Color(COLOR.btcHi) } },
        vertexShader: 'varying vec3 vN; void main() { vN = normalize(normalMatrix * normal); gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0); }',
        fragmentShader: `uniform float opacity; uniform vec3 hot; uniform vec3 hi; varying vec3 vN;
            void main() { float l = 0.45 + 0.55 * max(0.0, dot(vN, normalize(vec3(-0.4, 0.7, 0.6)))); gl_FragColor = vec4(mix(hot * l, hi, pow(l, 6.0) * 0.6), opacity); }`,
        transparent: true,
    });
    const textY = (H - TYPE.crawl.size * TYPE.crawl.leading) / 2;
    do {
        items.forEach((text) => {
            const l = createLine(stage, text, TYPE.crawl, { color: COLOR.ink });
            l.material.uniforms.clipFade.value = 56;
            crawl.add(l.mesh);
            parts.push({ mesh: l.mesh, x, w: l.width - l.pad * 2, line: l, text: true, top: textY });
            x += l.width - l.pad * 2 + gap;
            const cube = new THREE.Mesh(cubeGeo, cubeMat);
            crawl.add(cube);
            parts.push({ mesh: cube, x, w: sep, cube: true });
            x += sep + gap;
        });
    } while (x < railW * 2);
    const length = x;

    const seg = timeline.add({
        kind: 'ticker', slot: 'ticker', texts: items, start, introMs: TIMING.tickerIntroMs, holdMs: 3_600_000, outroMs: 600,
        extra: { pxPerS: TIMING.tickerPxPerS, observedPxPerS: null },
    });
    const textMeshes = [];
    let probe = null;

    function layout(offset) {
        const left = 20;
        const clipL = root.position.x + railX + 12;
        const clipR = root.position.x + railX + railW - 12;
        parts.forEach((p) => {
            // Wrapped with a lead of 640 px: an item leaves the clip on the left before it jumps to the far right.
            const px = ((p.x - offset + 640) % length + length) % length - 640;
            if (p.text) {
                place(p.line, left + px, p.top, 0);
                p.line.material.uniforms.clip.value.set(clipL, 0, clipR, 0);
            } else {
                p.mesh.position.set(left + px + sep / 2, -H / 2, 0);
                const wx = root.position.x + railX + left + px;
                p.mesh.visible = wx > clipL + 8 && wx < clipR - 8;
            }
        });
    }

    function update(t) {
        const ph = timeline.phase(seg, t);
        root.visible = ph.name !== 'before' && ph.name !== 'after';
        timeline.observe(seg, ph, t, textMeshes);
        if (!root.visible) return;
        let chipK = 1, railK = 1, itemK = 1;
        if (ph.name === 'intro') {
            chipK = CURVES.set(span(ph.t, 0, 600));
            railK = CURVES.set(span(ph.t, 120, 780));
            itemK = CURVES.reveal(span(ph.t, 500, 400));
        } else if (ph.name === 'outro') {
            itemK = 1 - CURVES.leave(span(ph.t, 0, 300));
            railK = chipK = 1 - CURVES.leave(span(ph.t, 150, 450));
        }
        chip.pivot.rotation.x = (1 - chipK) * 80 * DEG;
        rail.pivot.rotation.x = (1 - railK) * 80 * DEG;
        chip.setOpacity(Math.min(1, chipK * 1.4));
        rail.setOpacity(Math.min(1, railK * 1.4));
        chipText.material.uniforms.opacity.value = Math.min(1, chipK * 1.4);
        under.scale.x = Math.max(0.001, chipK);
        chipFace.uniforms.sweep.value = -0.3 + 1.6 * CURVES.sweep(span(t % 9000, 0, 1600));
        const moving = Math.max(0, t - seg.start - seg.introMs);
        const offset = (moving * TIMING.tickerPxPerS) / 1000;
        layout(offset);
        parts.forEach((p) => {
            if (p.text) p.line.material.uniforms.opacity.value = itemK;
            else p.mesh.rotation.set(t / 2400, t / 1700, 0);
        });
        cubeMat.uniforms.opacity.value = itemK;

        // Speed from real positions: the first text mesh's world x, sampled once a second, wraps skipped.
        const m = parts[0].mesh;
        const v = new THREE.Vector3();
        m.getWorldPosition(v);
        if (ph.name === 'hold') {
            if (!probe) probe = { t, x: v.x };
            else if (t - probe.t >= 1000) {
                const d = probe.x - v.x;
                if (d >= 0 && d < length / 2) {
                    const speed = (d / (t - probe.t)) * 1000;
                    seg.observedPxPerS = Math.max(seg.observedPxPerS || 0, +speed.toFixed(2));
                }
                probe = { t, x: v.x };
            }
        }
    }

    return {
        seg,
        update,
        dispose() {
            stage.scene.remove(root);
            disposeTree(root);
        },
    };
}
