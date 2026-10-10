/**
 * The broadcast's materials, built as small shaders so they stay the same at every resolution. A plate is four layers
 * that read as one object:
 *   glass  the face: smoked glass #1B1B21 -> #0A0A0B at 92 % cover, a static diagonal sheen, fine grain, a 1 px light
 *          stroke on every edge (the glass catching light), and a light sweep (uniform `sweep`, -0.4..1.4) with a wide
 *          soft band and a narrow specular streak
 *   lip    a 6 px brushed-metal rim along the top edge, lit hot where the sweep crosses it
 *   metal  the slab's sides: dark brushed metal warming towards the front edge
 *   flare  an anamorphic streak of light on the GLOW layer that rides the lip with the sweep (createFlare)
 * Plates are extruded slabs (32 logical px deep) with ONE 45 deg chamfer: the corner that leads the plate's build-in,
 * so the cut always says where the plate came from. Perspective shows their sides as they swing in.
 *
 * Every light here (halo, rays, flare, shock ring, rim) passes the centre guard (resources/js/broadcast/guard.js).
 */

import { GUARD, GUARD_GLSL } from './guard.js';
import { GLOW } from './stage.js';
import { COLOR } from './tokens.js';

const VERT_POS = 'varying vec3 vPos; varying vec2 vUv; void main() { vPos = position; vUv = uv; gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0); }';
const VERT = 'varying vec2 vUv; void main() { vUv = uv; gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0); }';

const ADDITIVE = (THREE) => ({
    transparent: true,
    depthWrite: false,
    blending: THREE.CustomBlending,
    blendEquation: THREE.AddEquation,
    blendSrc: THREE.OneFactor,
    blendDst: THREE.OneFactor,
    blendSrcAlpha: THREE.OneFactor,
    blendDstAlpha: THREE.OneMinusSrcAlphaFactor,
});

/** Chamfer size for a plate of height h: a quarter of it, 12..28 px. */
export const chamferFor = (h) => Math.round(Math.min(28, Math.max(12, h / 4)));

/**
 * Plate outline, origin left top, y down as negative world y: w x h with one corner cut (`tl`, `tr`, `bl`, `br`, or
 * null), extruded `depth` behind z = 0.
 */
export function plateGeometry(THREE, w, h, depth = 32, corner = null, c = chamferFor(h)) {
    const s = new THREE.Shape();
    const pts = [
        corner === 'tl' ? [[0, -c], [c, 0]] : [[0, 0]],
        corner === 'tr' ? [[w - c, 0], [w, -c]] : [[w, 0]],
        corner === 'br' ? [[w, -h + c], [w - c, -h]] : [[w, -h]],
        corner === 'bl' ? [[c, -h], [0, -h + c]] : [[0, -h]],
    ].flat();
    s.moveTo(...pts[0]);
    pts.slice(1).forEach((p) => s.lineTo(...p));
    s.closePath();
    const g = new THREE.ExtrudeGeometry(s, { depth, bevelEnabled: false });
    g.translate(0, 0, -depth);

    return g;
}

const CORNERS = { tl: [0, 1], tr: [1, 1], bl: [0, 0], br: [1, 0] };

export function glassMaterial(THREE, w, h, { corner = null, chamfer = chamferFor(h), top = COLOR.glass, bottom = COLOR.ground, cover = 0.92, spill = null } = {}) {
    const cc = CORNERS[corner] || [0, 0];
    const SIDES = { left: 0, right: 1, bottom: 2, top: 3 };

    return new THREE.ShaderMaterial({
        uniforms: {
            size: { value: new THREE.Vector2(w, h) },
            opacity: { value: 1 },
            sweep: { value: -1 },
            cover: { value: cover },
            top: { value: new THREE.Color(top) },
            bottom: { value: new THREE.Color(bottom) },
            warm: { value: new THREE.Color(COLOR.btcHi) },
            corner: { value: new THREE.Vector3(cc[0] * w, cc[1] * h, corner ? chamfer : 0) },
            spill: { value: new THREE.Vector2(spill ? SIDES[spill] : 0, spill ? 1 : 0) },
            hot: { value: new THREE.Color(COLOR.btc) },
        },
        vertexShader: VERT_POS,
        fragmentShader: `uniform vec2 size; uniform float opacity; uniform float sweep; uniform float cover; uniform vec3 top; uniform vec3 bottom; uniform vec3 warm; uniform vec3 corner; uniform vec2 spill; uniform vec3 hot;
            varying vec3 vPos;
            float hash(vec2 p) { return fract(sin(dot(p, vec2(12.9898, 78.233))) * 43758.5453); }
            void main() {
                vec2 px = vec2(vPos.x, vPos.y + size.y);
                vec2 q = px / size;
                vec3 c = mix(bottom, top, smoothstep(0.0, 1.0, q.y));
                // Static sheen: the glass is lit from the top left, a broad soft diagonal.
                c += vec3(0.05) * smoothstep(0.75, 0.0, q.x * 0.6 + (1.0 - q.y) * 0.9);
                c += (hash(floor(px * 1.5)) - 0.5) * 0.012;
                // Edge stroke: distance to the outline, the chamfer's diagonal included.
                float e = min(min(px.x, size.x - px.x), min(px.y, size.y - px.y));
                if (corner.z > 0.0) e = min(e, (abs(px.x - corner.x) + abs(px.y - corner.y) - corner.z) * 0.7071);
                c += vec3(0.16) * (1.0 - smoothstep(0.0, 1.5, e));
                c += vec3(0.035) * (1.0 - smoothstep(1.5, 10.0, e));
                // Light spill from the hot edge beside this plate: the glass is lit by it, warm and falling off fast.
                if (spill.y > 0.0) {
                    float ds = spill.x < 0.5 ? px.x : spill.x < 1.5 ? size.x - px.x : spill.x < 2.5 ? px.y : size.y - px.y;
                    c += hot * (exp(-ds / 22.0) * 0.20 + exp(-ds / 90.0) * 0.06);
                }
                // The sweep: a wide soft band and a narrow specular streak.
                float d = (px.x + (size.y - px.y) * 0.45) / size.x - sweep;
                c += warm * (exp(-d * d * 40.0) * 0.10 + exp(-d * d * 1800.0) * 0.30);
                // Brushed-metal lip along the top, hot where the sweep crosses it.
                float lip = smoothstep(size.y - 6.5, size.y - 5.5, px.y);
                float brush = 0.95 + 0.05 * hash(vec2(floor(px.x * 0.6), 3.0));
                vec3 metal = mix(vec3(0.20), vec3(0.46), smoothstep(size.y - 6.0, size.y, px.y)) * brush;
                float hot = exp(-d * d * 500.0);
                metal += vec3(1.0, 0.82, 0.55) * hot * 1.1;
                c = mix(c, metal, lip);
                gl_FragColor = vec4(c, mix(cover, 1.0, lip) * opacity);
            }`,
        transparent: true,
        depthWrite: false,
    });
}

/** The slab's sides: brushed metal, warm towards the front edge (z = 0), dark towards the back (z = -depth). */
export function metalMaterial(THREE, depth = 32) {
    return new THREE.ShaderMaterial({
        uniforms: { opacity: { value: 1 }, depth: { value: depth }, rim: { value: new THREE.Color(COLOR.btcDeep) }, base: { value: new THREE.Color(COLOR.metal) } },
        vertexShader: VERT_POS,
        fragmentShader: `uniform float opacity; uniform float depth; uniform vec3 rim; uniform vec3 base; varying vec3 vPos;
            void main() { float k = clamp(1.0 + vPos.z / depth, 0.0, 1.0); float streak = 1.0 + sin((vPos.x + vPos.y) * 0.9) * 0.05;
              vec3 c = mix(base * 0.7, base * 1.5, k) * streak; c = mix(c, rim, smoothstep(0.75, 1.0, k) * 0.65);
              gl_FragColor = vec4(c, opacity); }`,
        transparent: true,
        depthWrite: false,
    });
}

/**
 * A slab of w x h logical px, `depth` deep, with an optional chamfered `corner`; its origin is its left top front
 * corner, so it lays out like a box. `pivot` is what elements rotate; `box` can be shifted to move the hinge.
 */
export function createSlab(THREE, w, h, { depth = 32, face = null, corner = null, cover = 0.92, top, bottom, spill = null } = {}) {
    const group = new THREE.Group();
    const pivot = new THREE.Group();
    const front = face || glassMaterial(THREE, w, h, { corner, cover, top, bottom, spill });
    const side = metalMaterial(THREE, depth);
    // ExtrudeGeometry groups: 0 = caps (front, and the back the camera never sees), 1 = sides.
    const box = new THREE.Mesh(plateGeometry(THREE, w, h, depth, corner), [front, side]);
    box.renderOrder = 1;
    pivot.add(box);
    group.add(pivot);

    return { group, pivot, box, front, side, w, h, depth, setOpacity(o) { front.uniforms.opacity.value = o; side.uniforms.opacity.value = o; } };
}

/** The hot edge: a bar of orange light on the glow layer. Origin at its left top; it grows down with scale.y. */
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
        uniforms: { color: { value: new THREE.Color(color) }, opacity: { value: strength }, guardOn: GUARD.guardOn, screen: GUARD.screen },
        vertexShader: VERT,
        fragmentShader: `${GUARD_GLSL} uniform vec3 color; uniform float opacity; varying vec2 vUv;
            void main() { float d = length(vUv - 0.5) * 2.0; float a = pow(max(0.0, 1.0 - d), 2.2) * opacity * guard(); gl_FragColor = vec4(color * a, a); }`,
        ...ADDITIVE(THREE),
        blendDst: THREE.OneMinusSrcAlphaFactor,
    });
    const mesh = new THREE.Mesh(new THREE.PlaneGeometry(size, size), material);
    mesh.renderOrder = 0;

    return mesh;
}

/**
 * An anamorphic flare: a long thin streak of light with a hot core, on the glow layer. It rides a plate's lip with the
 * sweep and marks the moment something lands. Centred on its origin.
 */
export function createFlare(THREE, w = 360, h = 28, color = COLOR.btcHi) {
    const material = new THREE.ShaderMaterial({
        uniforms: { color: { value: new THREE.Color(color) }, opacity: { value: 0 }, guardOn: GUARD.guardOn, screen: GUARD.screen },
        vertexShader: VERT,
        fragmentShader: `${GUARD_GLSL} uniform vec3 color; uniform float opacity; varying vec2 vUv;
            void main() { vec2 p = (vUv - 0.5) * 2.0;
              float streak = exp(-p.y * p.y * 60.0) * (1.0 - smoothstep(0.0, 1.0, abs(p.x)));
              float core = exp(-dot(p * vec2(9.0, 1.4), p * vec2(9.0, 1.4)));
              vec3 c = (color * streak * 0.9 + vec3(1.0, 0.96, 0.9) * core) * opacity * guard();
              gl_FragColor = vec4(c, clamp(max(c.r, max(c.g, c.b)), 0.0, 1.0)); }`,
        ...ADDITIVE(THREE),
    });
    const mesh = new THREE.Mesh(new THREE.PlaneGeometry(w, h), material);
    mesh.layers.enable(GLOW);
    mesh.renderOrder = 6;

    return mesh;
}

/** A shock ring: expands from its centre and thins out as `progress` runs 0..1 (additive, glow layer). */
export function createShock(THREE, size, color = COLOR.btcHi) {
    const material = new THREE.ShaderMaterial({
        uniforms: { color: { value: new THREE.Color(color) }, progress: { value: 0 }, opacity: { value: 0 }, guardOn: GUARD.guardOn, screen: GUARD.screen },
        vertexShader: VERT,
        fragmentShader: `${GUARD_GLSL} uniform vec3 color; uniform float progress; uniform float opacity; varying vec2 vUv;
            void main() { float d = length(vUv - 0.5) * 2.0; float r = 0.15 + progress * 0.85; float w = 0.02 + 0.06 * (1.0 - progress);
              float ring = exp(-pow((d - r) / w, 2.0)); float a = ring * (1.0 - progress) * opacity * guard();
              vec3 c = mix(vec3(1.0, 0.95, 0.85), color, progress) * a; gl_FragColor = vec4(c, a); }`,
        ...ADDITIVE(THREE),
    });
    const mesh = new THREE.Mesh(new THREE.PlaneGeometry(size, size), material);
    mesh.layers.enable(GLOW);
    mesh.renderOrder = 6;

    return mesh;
}

const textures = new Map();

function loadTexture(THREE, url) {
    if (!textures.has(url)) {
        const waiting = [];
        const t = new THREE.TextureLoader().load(url, (tex) => waiting.splice(0).forEach((fn) => fn(tex.image)));
        t.generateMipmaps = true;
        t.minFilter = THREE.LinearMipmapLinearFilter;
        t.anisotropy = 8;
        t.userData = { waiting };
        textures.set(url, t);
    }

    return textures.get(url);
}

/**
 * An image plane (an asset of the pack) fitted inside w x h logical px with its own aspect ratio, centred on its
 * origin. The material adds a rim light on the figure's silhouette (`rim` strength, light coming from the side
 * `rimFrom` points to, in the image's uv: [1, 0] lights the right-hand edges), a cool top light, and a sheen (uniform `sheen` -0.5..1.5) that crosses the figure.
 * `clip` (world x from..to) fades it out at a rail's ends. Until the image is there the plane draws nothing.
 */
export function createImage(THREE, url, w, h, { rim = 0, rimFrom = [1, 0.4], rimColor = COLOR.btcHi } = {}) {
    const texture = loadTexture(THREE, url);
    const material = new THREE.ShaderMaterial({
        uniforms: {
            map: { value: texture },
            opacity: { value: 0 },
            rim: { value: rim },
            rimDir: { value: new THREE.Vector2(...rimFrom).normalize().multiplyScalar(-0.012) },
            rimColor: { value: new THREE.Color(rimColor) },
            sheen: { value: -1 },
            clip: { value: new THREE.Vector4(0, 0, 0, 0) },
        },
        vertexShader: `varying vec2 vUv; varying vec3 vWorld;
            void main() { vUv = uv; vec4 w = modelMatrix * vec4(position, 1.0); vWorld = w.xyz; gl_Position = projectionMatrix * viewMatrix * w; }`,
        fragmentShader: `uniform sampler2D map; uniform float opacity; uniform float rim; uniform vec2 rimDir; uniform vec3 rimColor; uniform float sheen; uniform vec4 clip;
            varying vec2 vUv; varying vec3 vWorld;
            void main() {
                vec4 t = texture2D(map, vUv);
                vec3 c = t.rgb;
                if (rim > 0.0) {
                    float behind = texture2D(map, vUv - rimDir).a;
                    float above = texture2D(map, vUv + vec2(0.0, 0.010)).a;
                    c += rimColor * t.a * (1.0 - behind) * rim;
                    c += vec3(0.85, 0.9, 1.0) * t.a * (1.0 - above) * rim * 0.35;
                }
                float d = (vUv.x + (1.0 - vUv.y) * 0.55) - sheen;
                c += vec3(1.0, 0.9, 0.72) * exp(-d * d * 260.0) * 0.55 * t.a;
                float k = 1.0;
                if (clip.z > clip.x) k = smoothstep(clip.x, clip.x + 48.0, vWorld.x) * (1.0 - smoothstep(clip.z - 48.0, clip.z, vWorld.x));
                gl_FragColor = vec4(c, t.a * opacity * k);
            }`,
        transparent: true,
        depthWrite: false,
    });
    const geometry = new THREE.PlaneGeometry(1, 1);
    const mesh = new THREE.Mesh(geometry, material);
    mesh.renderOrder = 2;
    const fit = (image) => {
        const a = image.width / image.height;
        const [fw, fh] = a > w / h ? [w, w / a] : [h * a, h];
        geometry.scale(fw, fh, 1);
        mesh.userData.size = { w: fw, h: fh };
    };
    if (texture.image && texture.image.width) fit(texture.image);
    else texture.userData.waiting.push(fit);

    return mesh;
}

/**
 * A light texture on black (energy-rays, energy-wide) added as light: colour adds, alpha grows only by the light's own
 * brightness, so its black never darkens the game under it. A round vignette hides the texture's border; `spin`
 * (radians) turns the light about its centre without turning the plane, so it can rotate slowly in the background.
 */
export function createEnergy(THREE, url, w, h, { round = false } = {}) {
    const material = new THREE.ShaderMaterial({
        uniforms: { map: { value: loadTexture(THREE, url) }, opacity: { value: 0 }, spin: { value: 0 }, round: { value: round ? 1 : 0 }, guardOn: GUARD.guardOn, screen: GUARD.screen },
        vertexShader: VERT,
        fragmentShader: `${GUARD_GLSL} uniform sampler2D map; uniform float opacity; uniform float spin; uniform float round; varying vec2 vUv;
            void main() {
                vec2 p = vUv - 0.5; float cs = cos(spin), sn = sin(spin); vec2 r = vec2(cs * p.x - sn * p.y, sn * p.x + cs * p.y) + 0.5;
                vec2 e = min(vUv, 1.0 - vUv);
                float v = round > 0.5 ? 1.0 - smoothstep(0.30, 0.5, length(p)) : smoothstep(0.0, 0.18, e.x) * smoothstep(0.0, 0.3, e.y);
                vec3 c = texture2D(map, r).rgb * opacity * v * guard();
                gl_FragColor = vec4(c, clamp(max(c.r, max(c.g, c.b)), 0.0, 1.0)); }`,
        ...ADDITIVE(THREE),
    });
    const mesh = new THREE.Mesh(new THREE.PlaneGeometry(w, h), material);
    mesh.renderOrder = 0;

    return mesh;
}
