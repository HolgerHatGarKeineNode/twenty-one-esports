/**
 * Embers: a pooled point system in logical units. Bursts at the moment something lands (a pride moment's peak, the
 * stinger's cover), a slow drift behind pride moments. Additive, premultiplied, so the canvas stays clear where none
 * fly. The pool is sized by the quality tier (high 480, medium 288, low 144).
 */

import { GUARD, GUARD_GLSL } from './guard.js';
import { GLOW } from './stage.js';
import { COLOR } from './tokens.js';

export function createParticles(stage, { scene = stage.scene } = {}) {
    const { THREE } = stage;
    const MAX = 480;
    const pos = new Float32Array(MAX * 3);
    const col = new Float32Array(MAX * 3);
    const size = new Float32Array(MAX);
    const alpha = new Float32Array(MAX);
    const vel = new Float32Array(MAX * 2);
    const life = new Float32Array(MAX);
    const age = new Float32Array(MAX);
    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.BufferAttribute(pos, 3));
    geometry.setAttribute('color', new THREE.BufferAttribute(col, 3));
    geometry.setAttribute('size', new THREE.BufferAttribute(size, 1));
    geometry.setAttribute('alpha', new THREE.BufferAttribute(alpha, 1));
    const material = new THREE.ShaderMaterial({
        uniforms: { scale: { value: 1 }, guardOn: GUARD.guardOn, screen: GUARD.screen },
        vertexShader: `attribute float size; attribute float alpha; attribute vec3 color; uniform float scale; varying vec3 vColor; varying float vAlpha;
            void main() { vColor = color; vAlpha = alpha; vec4 mv = modelViewMatrix * vec4(position, 1.0); gl_PointSize = size * scale; gl_Position = projectionMatrix * mv; }`,
        fragmentShader: `${GUARD_GLSL} varying vec3 vColor; varying float vAlpha;
            void main() { float d = length(gl_PointCoord - 0.5) * 2.0; float a = pow(max(0.0, 1.0 - d), 1.8) * vAlpha * guard(); if (a < 0.003) discard; gl_FragColor = vec4(vColor * a, a); }`,
        transparent: true,
        depthWrite: false,
        blending: THREE.CustomBlending,
        blendSrc: THREE.OneFactor,
        blendDst: THREE.OneFactor,
        blendSrcAlpha: THREE.OneFactor,
        blendDstAlpha: THREE.OneMinusSrcAlphaFactor,
    });
    const points = new THREE.Points(geometry, material);
    points.frustumCulled = false;
    points.renderOrder = 4;
    // Embers glow: they are light, not dots.
    points.layers.enable(GLOW);
    scene.add(points);
    const hot = new THREE.Color(COLOR.btc);
    const warm = new THREE.Color(COLOR.btcHi);
    const white = new THREE.Color('#FFF4E4');
    let cursor = 0;

    const limit = () => Math.round(MAX * stage.tier.particles);
    const syncScale = () => { material.uniforms.scale.value = stage.renderer.getDrawingBufferSize(new THREE.Vector2()).y / 1080; };
    syncScale();
    stage.onTextScale(syncScale);

    /**
     * n embers from a logical point or box, rising and spreading; `radial` throws them out evenly in every direction
     * (a burst when something lands, starting `inner` px out), `z` sets the depth they fly at (in front of the element that emits them).
     */
    function emit(x, y, n, { w = 0, h = 0, speed = 60, rise = 40, lifeMs = 1800, sizePx = 7, radial = false, inner = 0, z = 4 } = {}) {
        const cap = limit();
        const count = Math.round(n * stage.tier.particles);
        for (let k = 0; k < count; k++) {
            const i = cursor % cap;
            cursor++;
            const p = stage.toWorld(x + Math.random() * w, y + Math.random() * h);
            const a = Math.random() * Math.PI * 2;
            // `inner`: a radial burst starts on a ring this far out, so it never crosses the figure it frames.
            pos[i * 3] = p.x + Math.cos(a) * inner;
            pos[i * 3 + 1] = p.y + Math.sin(a) * inner;
            pos[i * 3 + 2] = z + Math.random() * 30;
            if (radial) {
                const v = speed * (0.45 + 0.55 * Math.sqrt(Math.random()));
                vel[i * 2] = Math.cos(a) * v;
                vel[i * 2 + 1] = Math.sin(a) * v + rise;
            } else {
                vel[i * 2] = Math.cos(a) * speed * Math.random();
                vel[i * 2 + 1] = Math.abs(Math.sin(a)) * speed * Math.random() + rise;
            }
            const c = [hot, warm, white][Math.floor(Math.random() * 3)];
            col[i * 3] = c.r;
            col[i * 3 + 1] = c.g;
            col[i * 3 + 2] = c.b;
            size[i] = sizePx * (0.4 + Math.random() * 0.9);
            life[i] = lifeMs * (0.6 + Math.random() * 0.6);
            age[i] = 0;
        }
    }

    function update(dt) {
        const cap = limit();
        for (let i = 0; i < MAX; i++) {
            if (i >= cap || age[i] >= life[i]) {
                alpha[i] = 0;
                continue;
            }
            age[i] += dt;
            const k = age[i] / life[i];
            pos[i * 3] += (vel[i * 2] * dt) / 1000;
            pos[i * 3 + 1] += (vel[i * 2 + 1] * dt) / 1000;
            vel[i * 2] *= 0.985;
            vel[i * 2 + 1] *= 0.99;
            alpha[i] = Math.sin(Math.min(1, k) * Math.PI) * 0.9;
        }
        geometry.attributes.position.needsUpdate = true;
        geometry.attributes.alpha.needsUpdate = true;
        geometry.attributes.color.needsUpdate = true;
        geometry.attributes.size.needsUpdate = true;
    }

    return { emit, update, live: () => { let n = 0; for (let i = 0; i < MAX; i++) if (age[i] < life[i]) n++; return n; } };
}
