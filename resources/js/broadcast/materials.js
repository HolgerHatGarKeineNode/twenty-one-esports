/**
 * The broadcast's three materials, built as small shaders so they stay the same at every resolution:
 *   glass  the plate's face: smoked glass from #17171B to #0A0A0B at 90 % cover, a fine grain, a lit top lip, and a
 *          light sweep (uniform `sweep`, -0.4..1.4 across the plate) that passes once in the build-in and once per hold
 *   metal  the slab's sides: dark brushed metal with a warm rim where the hot edge lights it
 *   heat   the hot edge: btc orange, on the GLOW layer so it blooms (resources/js/broadcast/stage.js)
 * Plates are slabs (a box, 32 logical px deep): the perspective shows their sides while they swing in, and a little
 * of the inner side at rest, which is what makes them read as placed blocks instead of flat stickers.
 */

import { GLOW } from './stage.js';
import { COLOR } from './tokens.js';

const VERT = 'varying vec2 vUv; void main() { vUv = uv; gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0); }';

export function glassMaterial(THREE, w, h) {
    return new THREE.ShaderMaterial({
        uniforms: {
            size: { value: new THREE.Vector2(w, h) },
            opacity: { value: 1 },
            sweep: { value: -1 },
            top: { value: new THREE.Color(COLOR.glass) },
            bottom: { value: new THREE.Color(COLOR.ground) },
            warm: { value: new THREE.Color(COLOR.btcHi) },
        },
        vertexShader: VERT,
        fragmentShader: `uniform vec2 size; uniform float opacity; uniform float sweep; uniform vec3 top; uniform vec3 bottom; uniform vec3 warm;
            varying vec2 vUv;
            float hash(vec2 p) { return fract(sin(dot(p, vec2(12.9898, 78.233))) * 43758.5453); }
            void main() {
                vec2 px = vUv * size;
                vec3 c = mix(bottom, top, smoothstep(0.0, 1.0, vUv.y));
                c += (hash(floor(px * 1.5)) - 0.5) * 0.012;
                float lip = smoothstep(2.0, 0.0, size.y - px.y);
                c += vec3(0.16) * lip;
                float d = (px.x + (size.y - px.y) * 0.45) / size.x - sweep;
                float band = exp(-d * d * 90.0) * 0.22 + exp(-d * d * 900.0) * 0.18;
                c += warm * band;
                gl_FragColor = vec4(c, 0.9 * opacity);
            }`,
        transparent: true,
        depthWrite: false,
    });
}

export function metalMaterial(THREE) {
    return new THREE.ShaderMaterial({
        uniforms: { opacity: { value: 1 }, rim: { value: new THREE.Color(COLOR.btcDeep) }, base: { value: new THREE.Color(COLOR.metal) } },
        vertexShader: VERT,
        fragmentShader: `uniform float opacity; uniform vec3 rim; uniform vec3 base; varying vec2 vUv;
            void main() { float streak = 1.0 + sin(vUv.x * 220.0) * 0.06; vec3 c = mix(base * 1.35 * streak, rim, smoothstep(0.45, 1.0, vUv.y) * 0.7);
              gl_FragColor = vec4(c, opacity); }`,
        transparent: true,
        depthWrite: false,
    });
}

/** A slab of w x h logical px, `depth` deep; its origin is its left top front corner, so it lays out like a box. */
export function createSlab(THREE, w, h, { depth = 32, face = null } = {}) {
    const group = new THREE.Group();
    const pivot = new THREE.Group();
    const front = face || glassMaterial(THREE, w, h);
    const side = metalMaterial(THREE);
    // BoxGeometry face order: +x, -x, +y, -y, +z (front), -z.
    const box = new THREE.Mesh(new THREE.BoxGeometry(w, h, depth), [side, side, side, side, front, side]);
    box.position.set(w / 2, -h / 2, -depth / 2);
    box.renderOrder = 1;
    pivot.add(box);
    group.add(pivot);

    return { group, pivot, box, front, side, w, h, depth, setOpacity(o) { front.uniforms.opacity.value = o; side.uniforms.opacity.value = o; } };
}

/** The hot edge: a thin bar of orange light on the glow layer. Origin at its top; it grows down with scale.y. */
export function createHeat(THREE, w, h, color = COLOR.btc) {
    const material = new THREE.MeshBasicMaterial({ color: new THREE.Color(color), transparent: true, depthWrite: false });
    const mesh = new THREE.Mesh(new THREE.PlaneGeometry(w, h), material);
    mesh.geometry.translate(w / 2, -h / 2, 0);
    mesh.layers.enable(GLOW);
    mesh.renderOrder = 3;

    return mesh;
}

/** A soft radial light (additive), for halos behind emblems and the stinger's flash. */
export function createHalo(THREE, size, color = COLOR.btc, strength = 0.6) {
    const material = new THREE.ShaderMaterial({
        uniforms: { color: { value: new THREE.Color(color) }, opacity: { value: strength } },
        vertexShader: VERT,
        fragmentShader: `uniform vec3 color; uniform float opacity; varying vec2 vUv;
            void main() { float d = length(vUv - 0.5) * 2.0; float a = pow(max(0.0, 1.0 - d), 2.2) * opacity; gl_FragColor = vec4(color * a, a); }`,
        transparent: true,
        depthWrite: false,
        blending: THREE.CustomBlending,
        blendSrc: THREE.OneFactor,
        blendDst: THREE.OneMinusSrcAlphaFactor,
        blendSrcAlpha: THREE.OneFactor,
        blendDstAlpha: THREE.OneMinusSrcAlphaFactor,
    });
    const mesh = new THREE.Mesh(new THREE.PlaneGeometry(size, size), material);
    mesh.renderOrder = 0;

    return mesh;
}

const textures = new Map();

/**
 * An image plane (an asset of the pack) fitted inside w x h logical px with its own aspect ratio (keyed assets are
 * cropped to their figure), centred on its origin. Until the image is there the plane draws nothing.
 */
export function createImage(THREE, url, w, h) {
    const material = new THREE.MeshBasicMaterial({ transparent: true, depthWrite: false, opacity: 0 });
    const geometry = new THREE.PlaneGeometry(1, 1);
    const mesh = new THREE.Mesh(geometry, material);
    mesh.renderOrder = 2;
    const fit = (image) => {
        const a = image.width / image.height;
        const [fw, fh] = a > w / h ? [w, w / a] : [h * a, h];
        geometry.scale(fw, fh, 1);
        mesh.userData.size = { w: fw, h: fh };
    };
    if (!textures.has(url)) {
        const waiting = [];
        const t = new THREE.TextureLoader().load(url, (tex) => waiting.splice(0).forEach((fn) => fn(tex.image)));
        t.generateMipmaps = true;
        t.minFilter = THREE.LinearMipmapLinearFilter;
        t.anisotropy = 8;
        t.userData = { waiting };
        textures.set(url, t);
    }
    const texture = textures.get(url);
    material.map = texture;
    if (texture.image && texture.image.width) fit(texture.image);
    else texture.userData.waiting.push(fit);

    return mesh;
}

/**
 * A light texture on black (energy-wide, energy-rays) added as light: colour adds, alpha grows only by the light's own
 * brightness, so its black never darkens the game under it. A soft vignette hides the texture's border.
 */
export function createEnergy(THREE, url, w, h) {
    if (!textures.has(url)) textures.set(url, new THREE.TextureLoader().load(url));
    const material = new THREE.ShaderMaterial({
        uniforms: { map: { value: textures.get(url) }, opacity: { value: 0 } },
        vertexShader: VERT,
        fragmentShader: `uniform sampler2D map; uniform float opacity; varying vec2 vUv;
            void main() { vec2 e = min(vUv, 1.0 - vUv); float v = smoothstep(0.0, 0.18, e.x) * smoothstep(0.0, 0.3, e.y);
              vec3 c = texture2D(map, vUv).rgb * opacity * v; gl_FragColor = vec4(c, clamp(max(c.r, max(c.g, c.b)), 0.0, 1.0)); }`,
        transparent: true,
        depthWrite: false,
        blending: THREE.CustomBlending,
        blendEquation: THREE.AddEquation,
        blendSrc: THREE.OneFactor,
        blendDst: THREE.OneFactor,
        blendSrcAlpha: THREE.OneFactor,
        blendDstAlpha: THREE.OneMinusSrcAlphaFactor,
    });
    const mesh = new THREE.Mesh(new THREE.PlaneGeometry(w, h), material);
    mesh.renderOrder = 0;

    return mesh;
}
