/**
 * Text as crisp textures: each line is drawn by the 2D canvas at the stage's text scale (drawing-buffer height / 1080,
 * so 2 in a 4K source), uploaded without mipmaps and mapped 1:1 onto a plane of its logical size. A texel then lands on
 * a screen pixel and the edges stay as sharp as the browser's own text. A resize redraws every line at the new scale.
 *
 * The material reveals the line behind a soft mask from left to right (uniform `reveal` 0..1) with a warm leading edge;
 * at reveal 1 the edge has left the line and nothing on it changes any more (the reading rule: no text moves while read).
 */

import { TYPE } from './tokens.js';

const lines = new Set();

const VERT = `varying vec2 vUv; varying vec3 vWorld;
    void main() { vUv = uv; vec4 w = modelMatrix * vec4(position, 1.0); vWorld = w.xyz; gl_Position = projectionMatrix * viewMatrix * w; }`;

const FRAG = `uniform sampler2D map; uniform float opacity; uniform float reveal; uniform float soft; uniform vec3 edge;
    uniform vec4 clip; uniform float clipFade;
    varying vec2 vUv; varying vec3 vWorld;
    void main() {
        vec4 t = texture2D(map, vUv);
        float front = reveal * (1.0 + soft);
        float m = 1.0 - smoothstep(front - soft, front, vUv.x);
        float band = (1.0 - smoothstep(0.0, soft, abs(vUv.x - (front - soft * 0.5)))) * step(reveal, 0.999);
        float c = 1.0;
        if (clip.z > clip.x) {
            c = smoothstep(clip.x, clip.x + clipFade, vWorld.x) * (1.0 - smoothstep(clip.z - clipFade, clip.z, vWorld.x));
        }
        vec3 rgb = mix(t.rgb, edge, band * 0.75);
        gl_FragColor = vec4(rgb, t.a * m * opacity * c);
    }`;

function fontFamily(kind) {
    const css = getComputedStyle(document.documentElement).getPropertyValue(kind === 'mono' ? '--font-face-mono' : '--font-face-display').trim();

    return css || (kind === 'mono' ? "'JetBrains Mono'" : "'Unbounded'");
}

/** Position a line inside its parent (origin left top) by its type box, without padding; kept across redraws. */
export function placeLine(line, left = line.placed.left, top = line.placed.top, z = line.placed.z) {
    line.placed = { left, top, z };
    line.mesh.position.set(left - line.pad + line.width / 2, -(top - line.pad + line.height / 2), z);
}

/** Wait until the two families can draw (the canvas does not wait for a webfont by itself). */
export async function fontsReady() {
    const loads = [];
    Object.values(TYPE).forEach((s) => loads.push(document.fonts.load(`${s.weight} ${s.size}px ${fontFamily(s.family)}`, 'Satoshi 21 ÄÖÜß')));
    await Promise.allSettled(loads);
    await document.fonts.ready;
}

/**
 * A line of text. `style` is a TYPE entry (resources/js/broadcast/tokens.js); `maxWidth` in logical px shrinks the
 * line down to 72 % of its size before it would overflow, then cuts it with an ellipsis. `width`/`height` are logical.
 */
export function createLine(stage, text, style, { color = '#FFFFFF', maxWidth = Infinity } = {}) {
    const { THREE } = stage;
    const canvas = document.createElement('canvas');
    const texture = new THREE.CanvasTexture(canvas);
    texture.generateMipmaps = false;
    texture.minFilter = THREE.LinearFilter;
    texture.magFilter = THREE.LinearFilter;
    texture.anisotropy = 4;
    const material = new THREE.ShaderMaterial({
        uniforms: {
            map: { value: texture },
            opacity: { value: 1 },
            reveal: { value: 1 },
            soft: { value: 0.18 },
            edge: { value: new THREE.Color('#F9B25F') },
            clip: { value: new THREE.Vector4(0, 0, 0, 0) },
            clipFade: { value: 48 },
        },
        vertexShader: VERT,
        fragmentShader: FRAG,
        transparent: true,
        depthWrite: false,
    });
    const mesh = new THREE.Mesh(new THREE.PlaneGeometry(1, 1), material);
    // Text draws after the glass (renderOrder 1) and images (2), never under them.
    mesh.renderOrder = 5;
    const line = { mesh, material, text, width: 0, height: 0, size: style.size, ascent: 0 };
    mesh.userData.line = line;

    function draw(scale) {
        const ctx = canvas.getContext('2d');
        const family = fontFamily(style.family);
        let size = style.size;
        const font = (s) => `${style.weight} ${s * scale}px ${family}`;
        const tracking = (style.tracking || 0) * size;
        // Layout is measured at a fixed reference scale, never at the output's: a small preview (scale 0.2) measures
        // coarsely and would cut a line that fits, and the same text must lay out the same in 1080p and 4K.
        const REF = 2;
        const measure = (s, str) => {
            ctx.font = `${style.weight} ${s * REF}px ${family}`;
            ctx.letterSpacing = `${(style.tracking || 0) * s * REF}px`;

            return ctx.measureText(str).width / REF;
        };
        let str = text;
        let w = measure(size, str);
        if (w > maxWidth) {
            size = Math.max(style.size * 0.72, style.size * (maxWidth / w));
            w = measure(size, str);
            while (w > maxWidth && str.length > 1) {
                str = str.slice(0, -2).trimEnd() + '…';
                w = measure(size, str);
            }
        }
        // Line box: the type's own line height, padded so descenders and the reveal's soft edge have room.
        const pad = Math.ceil(size * 0.12);
        const lineH = Math.ceil(size * style.leading);
        const W = Math.ceil(w + pad * 2 + tracking);
        const H = lineH + pad * 2;
        canvas.width = Math.ceil(W * scale);
        canvas.height = Math.ceil(H * scale);
        ctx.font = font(size);
        ctx.letterSpacing = `${(style.tracking || 0) * size * scale}px`;
        ctx.fillStyle = color;
        ctx.textBaseline = 'alphabetic';
        const m = ctx.measureText('Hg');
        const asc = m.fontBoundingBoxAscent ?? size * 0.9 * scale;
        const desc = m.fontBoundingBoxDescent ?? size * 0.25 * scale;
        const base = pad * scale + (lineH * scale - (asc + desc)) / 2 + asc;
        ctx.fillText(str, pad * scale, base);
        texture.needsUpdate = true;
        mesh.scale.set(W, H, 1);
        line.width = W;
        line.height = H;
        line.size = size;
        line.pad = pad;
        line.shown = str;
        if (line.placed) placeLine(line);
    }

    draw(stage.textScale);
    line.redraw = () => draw(stage.textScale);
    lines.add(line);

    return line;
}

/**
 * A drawn plane of w x h logical px that behaves like a line (same material: reveal, opacity, clip; placed with
 * placeLine; redrawn crisp at every text scale): `draw(ctx, w, h)` paints it in logical units. The ticker draws its
 * segment chips, emblems and the league mark this way, so they clip at the rail's ends exactly like its words.
 * `redraw()` repaints it, e.g. once an image it draws has loaded.
 */
export function createCanvasPlane(stage, w, h, draw) {
    const { THREE } = stage;
    const canvas = document.createElement('canvas');
    const texture = new THREE.CanvasTexture(canvas);
    texture.generateMipmaps = false;
    texture.minFilter = THREE.LinearFilter;
    texture.magFilter = THREE.LinearFilter;
    const material = new THREE.ShaderMaterial({
        uniforms: {
            map: { value: texture },
            opacity: { value: 1 },
            reveal: { value: 1 },
            soft: { value: 0.18 },
            edge: { value: new THREE.Color('#F9B25F') },
            clip: { value: new THREE.Vector4(0, 0, 0, 0) },
            clipFade: { value: 48 },
        },
        vertexShader: VERT,
        fragmentShader: FRAG,
        transparent: true,
        depthWrite: false,
    });
    const mesh = new THREE.Mesh(new THREE.PlaneGeometry(1, 1), material);
    mesh.renderOrder = 5;
    const line = { mesh, material, text: '', width: w, height: h, size: h, pad: 0 };
    mesh.userData.line = line;
    const paint = (scale) => {
        canvas.width = Math.ceil(w * scale);
        canvas.height = Math.ceil(h * scale);
        const ctx = canvas.getContext('2d');
        ctx.setTransform(scale, 0, 0, scale, 0, 0);
        draw(ctx, w, h);
        texture.needsUpdate = true;
        mesh.scale.set(w, h, 1);
        if (line.placed) placeLine(line);
    };
    paint(stage.textScale);
    line.redraw = () => paint(stage.textScale);
    lines.add(line);

    return line;
}

/** Redraw every line at the stage's new text scale (wired once per stage). */
export function wireTextScale(stage) {
    stage.onTextScale(() => lines.forEach((l) => l.redraw()));
}

/** Free an element's GPU objects; text lines leave the redraw set. Shared image textures (createImage) stay cached. */
export function disposeTree(root) {
    root.traverse((o) => {
        const line = o.userData.line;
        if (line) {
            lines.delete(line);
            line.material.uniforms.map.value.dispose();
        }
        if (o.geometry) o.geometry.dispose();
        (Array.isArray(o.material) ? o.material : [o.material]).forEach((m) => m && m.dispose());
    });
}
