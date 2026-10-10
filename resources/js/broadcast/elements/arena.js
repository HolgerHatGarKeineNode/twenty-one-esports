/**
 * The full-screen scenes' room (plan P5, P6): a plate of the asset pack (the arena with its light rig behind the break
 * scene, the haze with its floating blocks behind the bracket) as the deepest layer, so the scene stands in a space
 * instead of on a flat colour. Background motion only, nothing a viewer reads:
 *   - a push-in of 4 % over a minute and back (curve `drift`), so the room breathes without being noticed;
 *   - the rig's warm light pulses softly (the plate's orange parts brighten and dim in slow waves across the frame);
 *   - `shade` darkens where the scene puts words (a rectangle in logical px, soft edged), and a vignette closes the
 *     frame; fine grain keeps the dark tones from banding at 4K.
 * Exposure is low on purpose: the plate is atmosphere; the scene's glass and light have to stand in front of it.
 */

import { CURVES } from '../curves.js';
import { COLOR } from '../tokens.js';

const loader = new Map();

export function createArena(stage, { url, exposure = 0.6, shade = [], warmth = 1, scene = stage.scene, z = -900, pushMs = 60000, camera = null, distance = 8000 }) {
    const { THREE } = stage;
    if (!loader.has(url)) {
        const t = new THREE.TextureLoader().load(url);
        t.minFilter = THREE.LinearFilter;
        t.generateMipmaps = false;
        loader.set(url, t);
    }
    const rects = shade.slice(0, 3).map((r) => new THREE.Vector4(r.x, r.y, r.x + r.w, r.y + r.h));
    while (rects.length < 3) rects.push(new THREE.Vector4(0, 0, 0, 0));
    const material = new THREE.ShaderMaterial({
        uniforms: {
            map: { value: loader.get(url) },
            exposure: { value: exposure },
            time: { value: 0 },
            push: { value: 0 },
            opacity: { value: 0 },
            warmth: { value: warmth },
            shade: { value: rects },
            ground: { value: new THREE.Color(COLOR.ground) },
        },
        vertexShader: 'varying vec2 vUv; void main() { vUv = uv; gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0); }',
        fragmentShader: `uniform sampler2D map; uniform float exposure; uniform float time; uniform float push; uniform float opacity; uniform float warmth; uniform vec4 shade[3]; uniform vec3 ground;
            varying vec2 vUv;
            float hash(vec2 p) { return fract(sin(dot(p, vec2(12.9898, 78.233))) * 43758.5453); }
            void main() {
                vec2 uv = (vUv - 0.5) / (1.0 + push) + 0.5;
                vec3 c = texture2D(map, uv).rgb;
                // The warm light of the rig: orange-ish texels pulse in slow waves running across the frame.
                float warm = clamp((c.r - c.b) * 2.2, 0.0, 1.0);
                float wave = 0.5 + 0.5 * sin(time * 0.0009 + vUv.x * 7.0 - vUv.y * 2.0);
                c *= exposure * (1.0 + warm * warmth * (0.45 * wave - 0.1));
                vec2 p = vec2(vUv.x * 1920.0, (1.0 - vUv.y) * 1080.0);
                float dim = 0.0;
                for (int i = 0; i < 3; i++) {
                    vec4 r = shade[i];
                    if (r.z > r.x) {
                        vec2 d = max(r.xy - p, p - r.zw);
                        dim = max(dim, 1.0 - smoothstep(-60.0, 120.0, max(d.x, d.y)));
                    }
                }
                c = mix(c, c * 0.35, dim);
                vec2 v = vUv - 0.5;
                c *= 1.0 - smoothstep(0.35, 0.85, length(v * vec2(1.0, 0.8))) * 0.75;
                c += (hash(floor(p * 2.0) + fract(time * 0.01)) - 0.5) * 0.008;
                gl_FragColor = vec4(mix(ground, c, opacity), 1.0);
            }`,
        depthWrite: false,
        depthTest: false,
    });
    // On a plane `z` behind the frame plane, sized so it still fills the frame through the perspective camera; or, with
    // a moving `camera` (the bracket's flight), riding it `distance` ahead and covering whatever its aspect.
    const camZ = stage.camera.position.z;
    const k = (camZ - z) / camZ;
    const mesh = new THREE.Mesh(new THREE.PlaneGeometry(camera ? 1 : 1920 * k * 1.02, camera ? 1 : 1080 * k * 1.02), material);
    mesh.renderOrder = -100;
    mesh.frustumCulled = false;
    if (camera) {
        mesh.position.set(0, 0, -distance);
        camera.add(mesh);
    } else {
        mesh.position.set(0, 0, z);
        scene.add(mesh);
    }
    const cover = () => {
        const h = 2 * distance * Math.tan((camera.fov * Math.PI) / 360) * 1.02;
        const w = h * camera.aspect;
        if (camera.aspect > 16 / 9) mesh.scale.set(w, (w * 9) / 16, 1);
        else mesh.scale.set((h * 16) / 9, h, 1);
    };
    const born = stage.now();

    return {
        mesh,
        material,
        update(t) {
            if (camera) cover();
            material.uniforms.time.value = t;
            const cycle = ((t - born) % (pushMs * 2)) / pushMs;
            material.uniforms.push.value = 0.04 * CURVES.drift(cycle < 1 ? cycle : 2 - cycle);
            material.uniforms.opacity.value = Math.min(1, (t - born) / 900);
        },
        dispose() {
            (camera || scene).remove(mesh);
            mesh.geometry.dispose();
            material.dispose();
        },
    };
}
