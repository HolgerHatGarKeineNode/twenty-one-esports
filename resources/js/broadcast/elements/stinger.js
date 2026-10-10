/**
 * Stinger: a real transition. A shutter of six slanted slabs (18 deg, the system's one angle besides the plates'
 * 45 deg chamfer) slams in from the right behind a racing orange blade, covers the whole frame by TIMING.stingerPeakMs
 * (700), and at the peak the league's "21" mark punches through it as a block of metal: light rays open behind it, a
 * flash and an anamorphic flare cross it, embers burst out. From TIMING.stingerClearMs (900) the shutter carries on to
 * the left in the same order, the mark riding with it, and clears the new scene by TIMING.stingerMs (1600).
 *
 * Layers, back to front: slabs (opaque glass-metal, a hot leading edge each, a light sweep across the wall); rays and
 * halo; the mark (orange tile, dark glyphs standing proud, lit with a key light, specular and a warm fresnel rim); the
 * flare and embers. It carries no text, so the reading rule has nothing to hold; the sound's hit lands on the peak.
 * While it is on air the centre guard is off: the stinger is the one element allowed over the stream.
 */

import { CURVES, span } from '../curves.js';
import { GUARD } from '../guard.js';
import { markGeometry } from '../mark.js';
import { createEnergy, createFlare, createHalo } from '../materials.js';
import { GLOW } from '../stage.js';
import { COLOR } from '../tokens.js';
import { TIMING } from '../timing.js';

const SLANT = Math.tan((18 * Math.PI) / 180);
const BAND_H = 1180;
const BANDS = 6;
const STRIDE = 400;
const TRAVEL = 2900;

function bandShape(THREE, w) {
    const s = SLANT * BAND_H;
    const shape = new THREE.Shape();
    shape.moveTo(0, 0);
    shape.lineTo(w, 0);
    shape.lineTo(w - s, -BAND_H);
    shape.lineTo(-s, -BAND_H);
    shape.closePath();

    return shape;
}

const LIT_VERT = `varying vec3 vN; varying vec3 vV; varying vec3 vPos;
    void main() { vPos = position; vN = normalize(normalMatrix * normal); vec4 mv = modelViewMatrix * vec4(position, 1.0); vV = -mv.xyz; gl_Position = projectionMatrix * mv; }`;

/** Lit metal for the mark: key light from the top left, a tight specular, a warm fresnel rim and a sweep. */
function litMaterial(THREE, base, { rim = COLOR.btcHi, spec = 0.9, ambient = 0.35, low = base } = {}) {
    return new THREE.ShaderMaterial({
        uniforms: { hi: { value: new THREE.Color(base) }, lo: { value: new THREE.Color(low) }, rim: { value: new THREE.Color(rim) }, sweep: { value: -2 }, spec: { value: spec }, ambient: { value: ambient } },
        vertexShader: LIT_VERT,
        fragmentShader: `uniform vec3 hi; uniform vec3 lo; uniform vec3 rim; uniform float sweep; uniform float spec; uniform float ambient; varying vec3 vN; varying vec3 vV; varying vec3 vPos;
            void main() {
                vec3 n = normalize(vN); vec3 V = normalize(vV); vec3 L = normalize(vec3(-0.35, 0.6, 0.72));
                float diff = max(dot(n, L), 0.0);
                float s = pow(max(dot(n, normalize(L + V)), 0.0), 48.0);
                float fres = pow(1.0 - max(dot(n, V), 0.0), 3.0);
                vec3 base = mix(lo, hi, clamp(vPos.y / 300.0 + 0.55, 0.0, 1.0));
                float d = vPos.x * 0.0045 + vPos.y * 0.0022 - sweep;
                vec3 c = base * (ambient + 0.8 * diff) + vec3(1.0, 0.93, 0.82) * s * spec + rim * fres * 0.9 + vec3(1.0, 0.9, 0.75) * exp(-d * d * 140.0) * 0.32;
                gl_FragColor = vec4(c, 1.0);
            }`,
    });
}

export function createStinger(stage, timeline, particles, { start, onPeak = null }) {
    const { THREE } = stage;
    const root = new THREE.Group();
    root.visible = false;
    stage.scene.add(root);

    // The shutter.
    const faceMat = new THREE.ShaderMaterial({
        uniforms: {
            sweep: { value: -3000 },
            flash: { value: 0 },
            deep: { value: new THREE.Color(COLOR.ground) },
            face: { value: new THREE.Color('#202027') },
            hot: { value: new THREE.Color(COLOR.btc) },
            hi: { value: new THREE.Color(COLOR.btcHi) },
            slant: { value: SLANT },
            width: { value: STRIDE + 4 },
        },
        vertexShader: `varying vec3 vPos; varying vec3 vW; void main() { vPos = position; vec4 w = modelMatrix * vec4(position, 1.0); vW = w.xyz; gl_Position = projectionMatrix * viewMatrix * w; }`,
        fragmentShader: `uniform float sweep; uniform float flash; uniform vec3 deep; uniform vec3 face; uniform vec3 hot; uniform vec3 hi; uniform float slant; uniform float width;
            varying vec3 vPos; varying vec3 vW;
            float hash(vec2 p) { return fract(sin(dot(p, vec2(12.9898, 78.233))) * 43758.5453); }
            void main() {
                float edgeX = vPos.y * slant;
                float dl = (vPos.x - edgeX) * 0.951;
                float dr = (width + edgeX - vPos.x) * 0.951;
                float along = clamp(dl / width, 0.0, 1.0);
                vec3 c = mix(face, deep, along * 0.85 + (-vPos.y / 1180.0) * 0.25);
                // Brushed along the slant.
                c *= 0.92 + 0.08 * hash(vec2(floor((vPos.x - edgeX) * 0.5), 1.0));
                c += vec3(0.06) * (1.0 - smoothstep(0.0, 2.0, dr));
                // Hot leading edge with its own soft light on the face.
                c = mix(c, mix(hot, hi, 0.25 + 0.6 * flash), 1.0 - smoothstep(4.0, 7.0, dl));
                c += hot * exp(-dl / 26.0) * 0.32;
                // A light sweep across the whole wall.
                float d = (vW.x - vW.y * slant) - sweep;
                c += vec3(1.0, 0.82, 0.55) * (exp(-d * d / 900.0) * 0.30 + exp(-d * d / 60000.0) * 0.10);
                gl_FragColor = vec4(c, 1.0);
            }`,
    });
    const sideMat = new THREE.MeshBasicMaterial({ color: new THREE.Color('#141418') });
    const bandGeo = new THREE.ExtrudeGeometry(bandShape(THREE, STRIDE + 4), { depth: 30, bevelEnabled: false });
    const bands = [];
    for (let i = 0; i < BANDS; i++) {
        const m = new THREE.Mesh(bandGeo, [faceMat, sideMat]);
        m.layers.enable(GLOW);
        m.renderOrder = 10;
        m.frustumCulled = false;
        const home = -1000 + i * STRIDE;
        m.position.set(home + TRAVEL, BAND_H / 2, 70 + (i % 2) * 8);
        root.add(m);
        bands.push({ m, home, delay: 80 + i * 36 });
    }
    // The blade: a narrow orange slab racing ahead of the shutter, in and out.
    const bladeMat = new THREE.ShaderMaterial({
        uniforms: { a: { value: new THREE.Color(COLOR.btcHi) }, b: { value: new THREE.Color(COLOR.btcDeep) } },
        vertexShader: 'varying vec3 vPos; void main() { vPos = position; gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0); }',
        fragmentShader: 'uniform vec3 a; uniform vec3 b; varying vec3 vPos; void main() { gl_FragColor = vec4(mix(b, a, clamp(1.0 + vPos.y / 1180.0, 0.0, 1.0)), 1.0); }',
    });
    const blade = new THREE.Mesh(new THREE.ExtrudeGeometry(bandShape(THREE, 64), { depth: 20, bevelEnabled: false }), [bladeMat, bladeMat]);
    blade.layers.enable(GLOW);
    blade.renderOrder = 10;
    blade.frustumCulled = false;
    const bladeHome = -1000 - 64 - 24;
    blade.position.set(bladeHome + TRAVEL, BAND_H / 2, 96);
    root.add(blade);

    // The mark and its light.
    const rays = createEnergy(THREE, '/broadcast/art/energy-rays.webp', 1150, 1150, { round: true });
    rays.position.set(0, 0, 120);
    rays.renderOrder = 11;
    root.add(rays);
    const halo = createHalo(THREE, 900, COLOR.btc, 0);
    halo.position.set(0, 0, 118);
    halo.renderOrder = 11;
    root.add(halo);
    const geo = markGeometry(THREE, 300);
    const tileMat = litMaterial(THREE, COLOR.btcHi, { low: COLOR.btcDeep, rim: '#FFE2B8', spec: 1.1 });
    const glyphMat = litMaterial(THREE, '#1E1710', { rim: COLOR.btc, spec: 1.2, ambient: 0.6 });
    const mark = new THREE.Group();
    mark.add(new THREE.Mesh(geo.tile, tileMat), new THREE.Mesh(geo.glyphs, glyphMat));
    mark.children.forEach((c) => { c.renderOrder = 12; c.frustumCulled = false; });
    mark.position.set(0, 0, 160);
    root.add(mark);
    const flare = createFlare(THREE, 1700, 44);
    // Behind the mark: the flash crosses the frame, never the mark's face.
    flare.position.set(0, 0, 140);
    flare.renderOrder = 13;
    root.add(flare);

    const peak = TIMING.stingerPeakMs;
    const clear = TIMING.stingerClearMs;
    const seg = timeline.add({ kind: 'stinger', slot: 'full', texts: [], start, introMs: peak, holdMs: clear - peak, outroMs: TIMING.stingerMs - clear });
    let peaked = false;
    let guarded = true;

    function update(t) {
        const ph = timeline.phase(seg, t);
        root.visible = ph.name !== 'before' && ph.name !== 'after';
        timeline.observe(seg, ph, t, []);
        if (root.visible && guarded) {
            guarded = false;
            GUARD.guardOn.value = 0;
        }
        if (!root.visible) {
            if (!guarded && ph.name === 'after') {
                guarded = true;
                GUARD.guardOn.value = 1;
            }

            return;
        }
        const x = t - seg.start;
        const offset = (delay) => TRAVEL * (1 - CURVES.set(span(x, delay, 480))) - TRAVEL * CURVES.leave(span(x, clear + delay - 80, 460));
        bands.forEach((b) => { b.m.position.x = b.home + offset(b.delay); });
        blade.position.x = bladeHome + offset(20);
        faceMat.uniforms.sweep.value = -1600 + 3200 * CURVES.sweep(span(x, 560, 520));
        const flash = Math.exp(-Math.pow((x - peak) / 120, 2));
        faceMat.uniforms.flash.value = flash;

        // The mark: punches in just before the peak, rides out with the middle of the shutter.
        const inK = CURVES.set(span(x, 500, 320));
        const outK = CURVES.leave(span(x, clear + 100, 420));
        mark.scale.setScalar(Math.max(0.001, inK * (1 + 0.06 * flash)));
        mark.rotation.set(0.12 * (1 - inK), -1.2 * (1 - inK) + 0.5 * outK, 0);
        mark.position.x = offset(80 + 2.5 * 36) * (outK > 0 ? 1 : 0);
        tileMat.uniforms.sweep.value = glyphMat.uniforms.sweep.value = -1.6 + 3.2 * CURVES.sweep(span(x, 640, 420));
        const light = span(x, 480, 200) * (1 - span(x, clear + 60, 380));
        rays.material.uniforms.opacity.value = (0.55 + 0.45 * flash) * light;
        rays.material.uniforms.spin.value = x * 0.0004;
        rays.scale.setScalar(0.7 + 0.3 * light + 0.15 * flash);
        rays.position.x = mark.position.x;
        halo.material.uniforms.opacity.value = (0.2 + 0.35 * flash) * light;
        halo.position.x = mark.position.x;
        flare.material.uniforms.opacity.value = flash * 0.9;
        if (!peaked && x >= peak) {
            peaked = true;
            // From a ring around the mark (the tile's half diagonal is ~212 px): the burst frames it, never dusts its face.
            particles.emit(960, 540, 160, { speed: 620, rise: 0, lifeMs: 1100, sizePx: 10, radial: true, inner: 225, z: 126 });
            if (onPeak) onPeak();
        }
    }

    return {
        seg,
        update,
        dispose() {
            if (!guarded) GUARD.guardOn.value = 1;
            stage.scene.remove(root);
            root.traverse((o) => {
                if (o.geometry) o.geometry.dispose();
                (Array.isArray(o.material) ? o.material : [o.material]).forEach((m) => m && m.dispose());
            });
        },
    };
}
