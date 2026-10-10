/**
 * Stinger: a wall of blocks covers the cut and clears it again (TIMING.stingerMs = 1600, opaque at stingerPeakMs = 700).
 * 16 x 9 tiles of 120 logical px swing up from edge-on in a diagonal wave (bottom left first, curve `set`), the whole
 * wall stands from 600 to 820 ms while their rims flash and a light line crosses it, then they tip away in the same
 * wave (curve `leave`). It carries no text, so the reading rule has nothing to hold; the sound's hit lands on the peak.
 */

import { CURVES, span } from '../curves.js';
import { GLOW } from '../stage.js';
import { COLOR } from '../tokens.js';
import { TIMING } from '../timing.js';

export function createStinger(stage, timeline, particles, { start, onPeak = null }) {
    const { THREE } = stage;
    const COLS = 16;
    const ROWS = 9;
    const S = 120;
    const geometry = new THREE.BoxGeometry(S * 1.012, S * 1.012, 26);
    const material = new THREE.ShaderMaterial({
        uniforms: {
            flash: { value: 0 },
            sweep: { value: -2000 },
            face: { value: new THREE.Color(COLOR.glass) },
            deep: { value: new THREE.Color(COLOR.ground) },
            hot: { value: new THREE.Color(COLOR.btc) },
            metal: { value: new THREE.Color(COLOR.metal) },
        },
        vertexShader: `varying vec2 vUv; varying vec3 vN; varying vec3 vW; varying float vTone;
            void main() { vUv = uv; vTone = fract(sin(dot(instanceMatrix[3].xy, vec2(12.9898, 78.233))) * 43758.5453); vN = normalize(mat3(instanceMatrix) * normal); vec4 w = instanceMatrix * vec4(position, 1.0); vW = (modelMatrix * w).xyz;
              gl_Position = projectionMatrix * viewMatrix * modelMatrix * w; }`,
        fragmentShader: `uniform float flash; uniform float sweep; uniform vec3 face; uniform vec3 deep; uniform vec3 hot; uniform vec3 metal;
            varying vec2 vUv; varying vec3 vN; varying vec3 vW; varying float vTone;
            void main() {
                vec3 c;
                if (vN.z > 0.5) {
                    vec2 e = min(vUv, 1.0 - vUv);
                    float rim = 1.0 - smoothstep(0.0, 0.018, min(e.x, e.y));
                    c = mix(deep, face, vUv.y) * (0.75 + 0.5 * vTone);
                    c += hot * rim * (0.18 + 0.7 * flash);
                    float d = (vW.x + vW.y * 0.3) - sweep;
                    c += vec3(1.0, 0.78, 0.5) * (exp(-d * d / 300.0) * 0.32 + exp(-d * d / 20000.0) * 0.08);
                } else {
                    c = mix(metal, hot * 0.7, 0.3 + 0.4 * flash) * (0.6 + 0.4 * abs(vN.y));
                }
                gl_FragColor = vec4(c, 1.0);
            }`,
    });
    const mesh = new THREE.InstancedMesh(geometry, material, COLS * ROWS);
    mesh.layers.enable(GLOW);
    mesh.frustumCulled = false;
    mesh.renderOrder = 10;
    mesh.visible = false;
    stage.scene.add(mesh);
    const tiles = [];
    for (let r = 0; r < ROWS; r++) {
        for (let c = 0; c < COLS; c++) {
            const p = stage.toWorld(c * S + S / 2, r * S + S / 2);
            // Wave from bottom left to top right, 0..280 ms.
            const delay = ((c / (COLS - 1)) * 0.65 + ((ROWS - 1 - r) / (ROWS - 1)) * 0.35) * 280;
            tiles.push({ p, delay, depth: Math.abs((Math.sin(c * 12.9898 + r * 78.233) * 43758.5453) % 1) * 18 });
        }
    }
    const dummy = new THREE.Object3D();
    const peak = TIMING.stingerPeakMs;
    const seg = timeline.add({ kind: 'stinger', slot: 'full', texts: [], start, introMs: peak, holdMs: 120, outroMs: TIMING.stingerMs - peak - 120 });
    let peaked = false;

    function update(t) {
        const ph = timeline.phase(seg, t);
        mesh.visible = ph.name !== 'before' && ph.name !== 'after';
        timeline.observe(seg, ph, t, []);
        if (!mesh.visible) return;
        const x = t - seg.start;
        tiles.forEach((tile, i) => {
            const up = CURVES.set(span(x, tile.delay, 420));
            const away = CURVES.leave(span(x, 820 + tile.delay, 420));
            const angle = (1 - up) * 90 - away * 90;
            const s = 0.35 + 0.65 * Math.min(up, 1 - away * 0.65);
            // At the peak the tiles stand at slightly different depths, so the wall shows its blocks' sides.
            // 60 px in front of every overlay (the whole wall scales up 2.4 % about the centre and still covers the frame),
            // so no text or plate shows through the cover.
            dummy.position.set(tile.p.x, tile.p.y, 60 + 40 * (1 - up) + 30 * away + tile.depth * up * (1 - away));
            dummy.rotation.set((angle * Math.PI) / 180, 0, 0);
            dummy.scale.set(s, s, 1);
            dummy.updateMatrix();
            mesh.setMatrixAt(i, dummy.matrix);
        });
        mesh.instanceMatrix.needsUpdate = true;
        material.uniforms.flash.value = Math.exp(-Math.pow((x - peak) / 140, 2));
        material.uniforms.sweep.value = -1300 + 2600 * CURVES.sweep(span(x, 520, 520));
        if (!peaked && x >= peak) {
            peaked = true;
            particles.emit(0, 500, 90, { w: 1920, h: 80, speed: 220, rise: 30, lifeMs: 1400, sizePx: 8 });
            if (onPeak) onPeak();
        }
    }

    return {
        seg,
        update,
        dispose() {
            stage.scene.remove(mesh);
            geometry.dispose();
            material.dispose();
        },
    };
}
