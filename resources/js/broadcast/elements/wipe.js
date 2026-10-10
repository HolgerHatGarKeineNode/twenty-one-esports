/**
 * The panel wipe (plan P5): the stinger's language at the size of one column. Three slanted glass-metal bands (18 deg,
 * the system's second angle) race in from the right behind an orange blade, cover the column by TIMING.wipeCoverMs,
 * and carry on to the left until the column is clear at TIMING.wipeMs. Everything is cut to the column's rectangle in
 * the shader, so the bands never pass over the hero beside it. No text: nothing to read, nothing to hold.
 */

import { CURVES, span } from '../curves.js';
import { GLOW } from '../stage.js';
import { COLOR } from '../tokens.js';
import { TIMING } from '../timing.js';

const SLANT = Math.tan((18 * Math.PI) / 180);

export function createWipe(stage, timeline, { start, rect, onCover = null }) {
    const { THREE } = stage;
    const root = new THREE.Group();
    root.visible = false;
    stage.scene.add(root);
    const a = stage.toWorld(rect.x, rect.y);
    const b = stage.toWorld(rect.x + rect.w, rect.y + rect.h);
    const clip = new THREE.Vector4(a.x, b.y, b.x, a.y);
    const H = rect.h + 80;
    const bandW = rect.w * 0.62;
    const travel = rect.w + bandW + SLANT * H + 120;

    const mat = (hot) => new THREE.ShaderMaterial({
        uniforms: { clip: { value: clip }, deep: { value: new THREE.Color(COLOR.ground) }, face: { value: new THREE.Color('#2B2B33') }, hot: { value: new THREE.Color(hot ? COLOR.btcHi : COLOR.btc) }, w: { value: bandW }, isBlade: { value: hot ? 1 : 0 } },
        vertexShader: 'varying vec3 vW; varying vec3 vP; void main() { vP = position; vec4 w = modelMatrix * vec4(position, 1.0); vW = w.xyz; gl_Position = projectionMatrix * viewMatrix * w; }',
        fragmentShader: `uniform vec4 clip; uniform vec3 deep; uniform vec3 face; uniform vec3 hot; uniform float w; uniform float isBlade; varying vec3 vW; varying vec3 vP;
            float hash(vec2 p) { return fract(sin(dot(p, vec2(12.9898, 78.233))) * 43758.5453); }
            void main() {
                if (vW.x < clip.x || vW.x > clip.z || vW.y < clip.y || vW.y > clip.w) discard;
                if (isBlade > 0.5) { gl_FragColor = vec4(mix(hot, vec3(1.0, 0.93, 0.8), 0.35), 1.0); return; }
                // Distance from the band's slanted left (leading) and right edges, in px.
                float edgeX = vP.y * ${SLANT.toFixed(5)};
                float dl = (vP.x - edgeX) * 0.951;
                float dr = (w + edgeX - vP.x) * 0.951;
                float k = clamp(dl / w, 0.0, 1.0);
                vec3 c = mix(face, deep, k * 0.8 + (-vP.y / 900.0) * 0.25);
                c *= 0.93 + 0.07 * hash(vec2(floor((vP.x - edgeX) * 0.5), 1.0));
                // The hot leading edge and its light on the face; a thin cold rim on the trailing edge.
                c = mix(c, hot, 1.0 - smoothstep(3.0, 5.0, dl));
                c += hot * exp(-dl / 28.0) * 0.35;
                c += vec3(0.10) * (1.0 - smoothstep(0.0, 2.0, dr));
                gl_FragColor = vec4(c, 1.0);
            }`,
        depthWrite: false,
        depthTest: false,
        transparent: true,
    });
    const shape = (w) => {
        const s = new THREE.Shape();
        s.moveTo(0, 0);
        s.lineTo(w, 0);
        s.lineTo(w - SLANT * H, -H);
        s.lineTo(-SLANT * H, -H);
        s.closePath();

        return new THREE.ShapeGeometry(s);
    };
    const bands = [0, 1, 2].map((i) => {
        const m = new THREE.Mesh(shape(bandW), mat(false));
        m.renderOrder = 20 + i;
        m.position.set(0, a.y + 40, 30 + i);
        root.add(m);

        return { m, delay: i * 70, home: a.x + i * bandW * 0.8 };
    });
    const blade = new THREE.Mesh(shape(10), mat(true));
    blade.renderOrder = 24;
    blade.layers.enable(GLOW);
    blade.position.set(0, a.y + 40, 34);
    root.add(blade);

    const cover = TIMING.wipeCoverMs;
    const seg = timeline.add({ kind: 'wipe', slot: 'panelWipe', texts: [], start, introMs: cover, holdMs: 150, outroMs: TIMING.wipeMs - cover - 150 });
    let covered = false;

    function update(t) {
        const ph = timeline.phase(seg, t);
        root.visible = ph.name !== 'before' && ph.name !== 'after';
        timeline.observe(seg, ph, t, []);
        if (!root.visible) return;
        const x = t - seg.start;
        const offset = (delay) => travel * (1 - CURVES.set(span(x, delay, cover - 140))) - travel * CURVES.leave(span(x, cover + 150 + delay - 60, TIMING.wipeMs - cover - 220));
        bands.forEach((band) => { band.m.position.x = band.home + offset(band.delay); });
        blade.position.x = bands[0].home - 14 + offset(0);
        if (!covered && x >= cover) {
            covered = true;
            if (onCover) onCover();
        }
    }

    return {
        seg,
        update,
        dispose() {
            stage.scene.remove(root);
            root.traverse((o) => {
                if (o.geometry) o.geometry.dispose();
                if (o.material) o.material.dispose();
            });
        },
    };
}
