/**
 * Proof of Pong's arena (plan "Proof of Pong", P1, the AAA arena P3): draws a frame of the game and its effects and
 * nothing else; game.js and live.js own the simulation, show.js decides when an effect plays.
 *
 * With WebGL it is a three.js scene (Hyperbitcoinization's copy, the global THREE, r128) on a full-screen canvas
 * behind the page: the arena's painted plate far below as the environment, a glass field with neon walls on top,
 * paddles in the figures' skins, an orange Bitcoin ball with a trail, particles, euro notes, a bloom pass. The
 * camera looks straight down at the field, so the field's plane lands exactly on the page's #field box (the input
 * and the overlays use that box); only things above or below the plane move with depth.
 *
 * Without WebGL (headless browsers, old phones) the same game on a 2D canvas inside #field, so it never depends on a
 * GPU. Landscape shows side 0 on the left; portrait turns the field, side 0 (the player) at the bottom.
 *
 * Quality tiers: `high` (bloom, every particle), `medium` (glow planes instead of bloom, fewer particles, pixel ratio
 * 1.5), `low` (pixel ratio 1, few particles). `auto` picks by device and steps down once the frames run slow.
 * Reduced motion (`motion: false`) turns off shake, particles, trail and the note rain.
 *
 * The P7 events are drawn from the frame alone (its event, the rally's seed and tick, physics.js patrol()): the tax
 * office's block and the border wall on the centre line where the physics has them, the fog of Few understand over
 * the middle third with the ball hidden under it, a Proof of Work paddle's length per side (`halves`), and a ball
 * waiting in the Arbeitsamt's queue (a sixth element) greyed out.
 */
import { neon } from './cast.js';
import { CONTROLS, FEW, GAP_HALF, HEIGHT, PADDLE_DEPTH, PADDLE_X, TAX, TAX_HALF_X, TAX_HALF_Y, WALL_HALF_X, WIDTH, patrol } from './physics.js';

const ORANGE = 0xf7931a;

/** Whether this browser can open a WebGL context (asked once, the probe canvas thrown away). */
export function hasWebGL() {
    try {
        const probe = document.createElement('canvas');

        return !!(window.WebGLRenderingContext && (probe.getContext('webgl2') || probe.getContext('webgl')));
    } catch {
        return false;
    }
}

/**
 * A field point in CSS pixels of a box `w` x `h`: landscape straight, portrait turned (field x upwards from the bottom).
 */
export function toScreen(fx, fy, w, h, portrait) {
    return portrait ? [(fy / HEIGHT) * w, h - (fx / WIDTH) * h] : [(fx / WIDTH) * w, (fy / HEIGHT) * h];
}

/** The field's y under a screen point (the player's paddle follows it): landscape the height, portrait the width. */
export function fieldYAt(px, py, w, h, portrait) {
    return Math.round(portrait ? (px / w) * HEIGHT : (py / h) * HEIGHT);
}

/** The obstacles' colours, 2D and three.js: the tax office red, the border wall brick orange, each with a light edge. */
const OBSTACLE = {
    tax: { fill: '#dc2626', edge: '#fecaca', body: 0xb91c1c, edgeHex: 0xfee2e2 },
    wall: { fill: '#ea580c', edge: '#fed7aa', body: 0xc2410c, edgeHex: 0xffedd5 },
};

/** A side's paddle half length in a frame: Proof of Work's per side, else the rally's. */
const halfIn = (view, side) => view.halves?.[side] ?? view.half;

/**
 * The obstacles of a frame on the centre line as field rectangles [x0, y0, x1, y1] and their kind: the tax block,
 * or the border wall's two parts around its gap. Empty for every other event.
 */
export function obstaclesOf(view) {
    if (!view || view.seed === undefined || view.tick === undefined) return [];
    const mid = WIDTH / 2;
    if (view.event === TAX) {
        const y = patrol(TAX, view.seed, view.tick);

        return [{ kind: 'tax', rect: [mid - TAX_HALF_X, y - TAX_HALF_Y, mid + TAX_HALF_X, y + TAX_HALF_Y] }];
    }
    if (view.event === CONTROLS) {
        const gap = patrol(CONTROLS, view.seed, view.tick);

        return [
            { kind: 'wall', rect: [mid - WALL_HALF_X, 0, mid + WALL_HALF_X, gap - GAP_HALF] },
            { kind: 'wall', rect: [mid - WALL_HALF_X, gap + GAP_HALF, mid + WALL_HALF_X, HEIGHT] },
        ].filter((o) => o.rect[3] - o.rect[1] > 0);
    }

    return [];
}

/** Whether a ball is hidden in this frame: Few understand hides it in the middle third. */
export const hiddenBall = (view, ball) => view?.event === FEW && ball[0] > WIDTH / 3 && ball[0] < (2 * WIDTH) / 3;

const NOOP_FX = { hit() {}, wall() {}, goal() {}, event() {}, setFigures() {}, setArena() {}, setQuality() {}, setMotion() {} };

function create2D(canvas) {
    const ctx = canvas.getContext('2d');
    let w = 0;
    let h = 0;
    let portrait = false;
    let dpr = 1;
    let colours = ['#f7931a', '#a78bfa'];
    let drawn = { obstacles: 0, fog: false, balls: [] };

    const rect = (fx0, fy0, fx1, fy1) => {
        const [ax, ay] = toScreen(fx0, fy0, w, h, portrait);
        const [bx, by] = toScreen(fx1, fy1, w, h, portrait);

        return [Math.min(ax, bx), Math.min(ay, by), Math.abs(bx - ax), Math.abs(by - ay)];
    };

    return {
        ...NOOP_FX,
        kind: '2d',
        quality: 'low',
        /** What the last frame drew of the P7 events (the browser test's handle). */
        drawn: () => drawn,
        setFigures(figures) {
            colours = figures.map((f) => neon(f));
        },
        resize(view) {
            w = view.field.w;
            h = view.field.h;
            portrait = view.portrait;
            dpr = Math.min(window.devicePixelRatio || 1, 2);
            canvas.width = Math.round(w * dpr);
            canvas.height = Math.round(h * dpr);
        },
        render(view) {
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            ctx.clearRect(0, 0, w, h);
            const bg = ctx.createRadialGradient(w / 2, h / 2, 0, w / 2, h / 2, Math.max(w, h) / 1.4);
            bg.addColorStop(0, '#0d1430');
            bg.addColorStop(1, '#05070f');
            ctx.fillStyle = bg;
            ctx.fillRect(0, 0, w, h);

            // The middle line, dashed.
            ctx.strokeStyle = 'rgba(247, 147, 26, 0.22)';
            ctx.lineWidth = 2;
            ctx.setLineDash([8, 10]);
            ctx.beginPath();
            const [m0x, m0y] = toScreen(WIDTH / 2, 0, w, h, portrait);
            const [m1x, m1y] = toScreen(WIDTH / 2, HEIGHT, w, h, portrait);
            ctx.moveTo(m0x, m0y);
            ctx.lineTo(m1x, m1y);
            ctx.stroke();
            ctx.setLineDash([]);

            // The P7 events: the fog over the middle third, the tax block and the border wall.
            drawn = { obstacles: obstaclesOf(view).length, fog: view.event === FEW, balls: view.balls.map((ball, index) => !!view.alive[index] && !hiddenBall(view, ball)) };
            if (view.event === FEW) {
                const [x, y, rw, rh] = rect(WIDTH / 3, 0, (2 * WIDTH) / 3, HEIGHT);
                ctx.fillStyle = 'rgba(76, 29, 149, 0.55)';
                ctx.fillRect(x, y, rw, rh);
            }
            obstaclesOf(view).forEach(({ kind, rect: r }) => {
                const [x, y, rw, rh] = rect(...r);
                // Solid colour and a light edge (P8, review: grey read pale on the dark glass).
                ctx.shadowColor = kind === 'tax' ? '#ef4444' : '#f97316';
                ctx.shadowBlur = 10;
                ctx.fillStyle = OBSTACLE[kind].fill;
                ctx.fillRect(x, y, rw, rh);
                ctx.shadowBlur = 0;
                ctx.strokeStyle = OBSTACLE[kind].edge;
                ctx.lineWidth = 2;
                ctx.strokeRect(x + 1, y + 1, rw - 2, rh - 2);
            });

            // Paddles: the face at PADDLE_X, drawn PADDLE_DEPTH thick behind it.
            [0, 1].forEach((side) => {
                const y = view.paddles[side];
                const half = halfIn(view, side);
                const [fx0, fx1] = side === 0 ? [PADDLE_X - PADDLE_DEPTH, PADDLE_X] : [WIDTH - PADDLE_X, WIDTH - PADDLE_X + PADDLE_DEPTH];
                const [x, yy, rw, rh] = rect(fx0, y - half, fx1, y + half);
                ctx.shadowColor = colours[side];
                ctx.shadowBlur = 18;
                ctx.fillStyle = colours[side];
                ctx.beginPath();
                ctx.roundRect(x, yy, rw, rh, Math.min(rw, rh) / 2);
                ctx.fill();
            });

            // Balls, with a glow.
            view.balls.forEach((ball, index) => {
                if (!view.alive[index] || hiddenBall(view, ball)) return;
                const [x, y] = toScreen(ball[0], ball[1], w, h, portrait);
                const r = (ball[4] / HEIGHT) * (portrait ? w : h);
                const waiting = ball.length > 5;
                ctx.shadowColor = waiting ? '#ef4444' : '#f7931a';
                ctx.shadowBlur = 24;
                ctx.fillStyle = waiting ? '#d1d5db' : '#fff4e0';
                ctx.beginPath();
                ctx.arc(x, y, Math.max(r, 2), 0, Math.PI * 2);
                ctx.fill();
            });
            ctx.shadowBlur = 0;
        },
    };
}

/* ---------- Textures, drawn once on a canvas ------------------------------------------------------------------- */

function canvasTexture(THREE, w, h, draw) {
    const c = document.createElement('canvas');
    c.width = w;
    c.height = h;
    draw(c.getContext('2d'), w, h);
    const texture = new THREE.CanvasTexture(c);
    texture.anisotropy = 4;

    return texture;
}

const glowDraw = (g, w) => {
    const grad = g.createRadialGradient(w / 2, w / 2, 0, w / 2, w / 2, w / 2);
    grad.addColorStop(0, 'rgba(255,255,255,1)');
    grad.addColorStop(0.25, 'rgba(255,255,255,0.45)');
    grad.addColorStop(1, 'rgba(255,255,255,0)');
    g.fillStyle = grad;
    g.fillRect(0, 0, w, w);
};

/** The Bitcoin coin on the ball: orange with a white ₿, wrapped twice around the sphere so it shows from above. */
const coinDraw = (g, w, h) => {
    const grad = g.createLinearGradient(0, 0, 0, h);
    grad.addColorStop(0, '#ffb347');
    grad.addColorStop(0.5, '#f7931a');
    grad.addColorStop(1, '#c46a00');
    g.fillStyle = grad;
    g.fillRect(0, 0, w, h);
    g.fillStyle = '#fff8ec';
    g.font = `900 ${h * 0.62}px system-ui, sans-serif`;
    g.textAlign = 'center';
    g.textBaseline = 'middle';
    [0.25, 0.75].forEach((x) => g.fillText('₿', w * x, h * 0.54));
};

const pizzaDraw = (g, w, h) => {
    g.fillStyle = '#f5c26b';
    g.fillRect(0, 0, w, h);
    g.fillStyle = '#e25822';
    g.fillRect(0, h * 0.18, w, h * 0.64);
    g.fillStyle = '#ffe9a8';
    for (let i = 0; i < 26; i++) g.fillRect((i * 37) % w, (i * 53) % (h * 0.6) + h * 0.2, 10, 6);
    g.fillStyle = '#a3171c';
    for (let i = 0; i < 12; i++) {
        g.beginPath();
        g.arc(((i * 71) % w) + 8, ((i * 29) % (h * 0.5)) + h * 0.25, h * 0.07, 0, Math.PI * 2);
        g.fill();
    }
};

const noteDraw = (g, w, h) => {
    g.fillStyle = '#7fb77e';
    g.fillRect(0, 0, w, h);
    g.strokeStyle = '#2f6b3a';
    g.lineWidth = 6;
    g.strokeRect(6, 6, w - 12, h - 12);
    g.fillStyle = '#2f6b3a';
    g.beginPath();
    g.arc(w * 0.72, h / 2, h * 0.3, 0, Math.PI * 2);
    g.fill();
    g.fillStyle = '#d9f2c8';
    g.font = `900 ${h * 0.46}px system-ui, sans-serif`;
    g.textAlign = 'center';
    g.textBaseline = 'middle';
    g.fillText('€', w * 0.72, h * 0.53);
    g.fillStyle = '#2f6b3a';
    g.font = `800 ${h * 0.3}px system-ui, sans-serif`;
    g.fillText('100', w * 0.3, h * 0.52);
};

/** A paddle's skin from the cast (cast.json `skin`: two colours and a pattern), long side vertical. */
function skinDraw([a, b, pattern]) {
    return (g, w, h) => {
        g.fillStyle = a;
        g.fillRect(0, 0, w, h);
        g.fillStyle = b;
        g.strokeStyle = b;
        switch (pattern) {
            case 'stripes':
                for (let y = 0; y < h; y += 32) g.fillRect(0, y, w, 16);
                break;
            case 'pinstripe':
                for (let x = 6; x < w; x += 14) g.fillRect(x, 0, 2, h);
                break;
            case 'dots':
                for (let y = 10; y < h; y += 22) for (let x = (y / 22) % 2 ? 10 : 22; x < w; x += 24) { g.beginPath(); g.arc(x, y, 5, 0, Math.PI * 2); g.fill(); }
                break;
            case 'chevron':
                g.lineWidth = 6;
                for (let y = 0; y < h + 32; y += 28) { g.beginPath(); g.moveTo(0, y); g.lineTo(w / 2, y - 16); g.lineTo(w, y); g.stroke(); }
                break;
            case 'knit':
                g.lineWidth = 3;
                for (let y = 0; y < h; y += 10) for (let x = 0; x < w; x += 12) { g.beginPath(); g.moveTo(x, y); g.lineTo(x + 6, y + 8); g.lineTo(x + 12, y); g.stroke(); }
                break;
            case 'plaid':
                g.globalAlpha = 0.55;
                for (let y = 0; y < h; y += 36) g.fillRect(0, y, w, 12);
                for (let x = 4; x < w; x += 24) g.fillRect(x, 0, 8, h);
                g.globalAlpha = 1;
                break;
            case 'rainbow':
                ['#ef4444', '#f97316', '#facc15', '#22c55e', '#3b82f6', '#a855f7'].forEach((c, i, all) => { g.fillStyle = c; g.fillRect(0, (h / all.length) * i, w, h / all.length + 1); });
                break;
            case 'laser':
                g.shadowColor = b;
                g.shadowBlur = 12;
                g.fillRect(w / 2 - 4, 0, 8, h);
                g.shadowBlur = 0;
                break;
            case 'notes':
                g.font = `900 ${w * 0.7}px system-ui, sans-serif`;
                g.textAlign = 'center';
                for (let y = 40; y < h; y += 56) g.fillText('€', w / 2, y);
                break;
            default:
                break;
        }
        // Bright edges along both long sides: the paddle reads as a lit object on any colour.
        g.fillStyle = 'rgba(255,255,255,0.75)';
        g.fillRect(0, 0, 3, h);
        g.fillRect(w - 3, 0, 3, h);
    };
}

/* ---------- Shaders ------------------------------------------------------------------------------------------- */

/** Points with their own size and alpha (trail, particles): soft round sprites, added on top. */
function pointsMaterial(THREE, map) {
    return new THREE.ShaderMaterial({
        uniforms: { map: { value: map }, scale: { value: 400 } },
        vertexShader: `attribute float size; attribute float alpha; attribute vec3 tint; varying float vA; varying vec3 vC;
            uniform float scale;
            void main() { vA = alpha; vC = tint; vec4 mv = modelViewMatrix * vec4(position, 1.0);
              gl_PointSize = size * scale / -mv.z; gl_Position = projectionMatrix * mv; }`,
        fragmentShader: `uniform sampler2D map; varying float vA; varying vec3 vC;
            void main() { vec4 t = texture2D(map, gl_PointCoord); gl_FragColor = vec4(vC * t.rgb, t.a * vA); }`,
        transparent: true,
        depthWrite: false,
        blending: THREE.AdditiveBlending,
    });
}

const QUAD_VERT = 'varying vec2 vUv; void main() { vUv = uv; gl_Position = vec4(position.xy, 0.0, 1.0); }';

/** A small bloom: bright parts of the frame at a quarter of its size, blurred twice, added back. */
function createBloom(THREE, renderer) {
    const quad = new THREE.Mesh(new THREE.PlaneGeometry(2, 2));
    const scene = new THREE.Scene();
    scene.add(quad);
    const camera = new THREE.OrthographicCamera(-1, 1, 1, -1, 0, 1);
    const target = (w, h, multisample) => {
        const options = { minFilter: THREE.LinearFilter, magFilter: THREE.LinearFilter, format: THREE.RGBAFormat };
        if (multisample && renderer.capabilities.isWebGL2 && THREE.WebGLMultisampleRenderTarget) {
            const rt = new THREE.WebGLMultisampleRenderTarget(w, h, options);
            rt.samples = 4;

            return rt;
        }

        return new THREE.WebGLRenderTarget(w, h, options);
    };
    let full = target(4, 4, true);
    let a = target(2, 2);
    let b = target(2, 2);
    const bright = new THREE.ShaderMaterial({
        uniforms: { src: { value: null } },
        vertexShader: QUAD_VERT,
        fragmentShader: `uniform sampler2D src; varying vec2 vUv;
            void main() { vec3 c = texture2D(src, vUv).rgb; float l = max(c.r, max(c.g, c.b)); gl_FragColor = vec4(c * smoothstep(0.55, 0.95, l), 1.0); }`,
    });
    const blur = new THREE.ShaderMaterial({
        uniforms: { src: { value: null }, dir: { value: new THREE.Vector2() } },
        vertexShader: QUAD_VERT,
        fragmentShader: `uniform sampler2D src; uniform vec2 dir; varying vec2 vUv;
            void main() { vec3 c = texture2D(src, vUv).rgb * 0.227;
              c += (texture2D(src, vUv + dir * 1.385).rgb + texture2D(src, vUv - dir * 1.385).rgb) * 0.316;
              c += (texture2D(src, vUv + dir * 3.231).rgb + texture2D(src, vUv - dir * 3.231).rgb) * 0.070;
              gl_FragColor = vec4(c, 1.0); }`,
    });
    const combine = new THREE.ShaderMaterial({
        uniforms: { base: { value: null }, glow: { value: null }, strength: { value: 1.15 } },
        vertexShader: QUAD_VERT,
        fragmentShader: `uniform sampler2D base; uniform sampler2D glow; uniform float strength; varying vec2 vUv;
            void main() { vec3 c = texture2D(base, vUv).rgb + texture2D(glow, vUv).rgb * strength;
              vec2 d = vUv - 0.5; c *= 1.0 - dot(d, d) * 0.55; gl_FragColor = vec4(c, 1.0); }`,
    });
    const pass = (material, to) => {
        quad.material = material;
        renderer.setRenderTarget(to);
        renderer.render(scene, camera);
    };

    return {
        setSize(w, h) {
            full.setSize(w, h);
            a.setSize(Math.max(1, w >> 2), Math.max(1, h >> 2));
            b.setSize(Math.max(1, w >> 2), Math.max(1, h >> 2));
        },
        render(mainScene, mainCamera) {
            renderer.setRenderTarget(full);
            renderer.render(mainScene, mainCamera);
            bright.uniforms.src.value = full.texture;
            pass(bright, a);
            [1, 2].forEach((spread) => {
                blur.uniforms.src.value = a.texture;
                blur.uniforms.dir.value.set(spread / a.width, 0);
                pass(blur, b);
                blur.uniforms.src.value = b.texture;
                blur.uniforms.dir.value.set(0, spread / a.height);
                pass(blur, a);
            });
            combine.uniforms.base.value = full.texture;
            combine.uniforms.glow.value = a.texture;
            pass(combine, null);
        },
        dispose() {
            [full, a, b].forEach((rt) => rt.dispose());
            full = a = b = null;
        },
    };
}

/* ---------- The three.js arena -------------------------------------------------------------------------------- */

const TIERS = {
    high: { dpr: 2, bloom: true, particles: 220, notes: 42, trail: 16 },
    medium: { dpr: 1.5, bloom: false, particles: 100, notes: 26, trail: 10 },
    low: { dpr: 1, bloom: false, particles: 36, notes: 12, trail: 6 },
};

function create3D(canvas, options) {
    const THREE = window.THREE;
    const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: false, powerPreference: 'high-performance' });
    renderer.setClearColor(0x04060d, 1);
    renderer.info.autoReset = false;
    const gl = renderer.getContext();
    const info = gl.getExtension('WEBGL_debug_renderer_info');
    const gpu = info ? String(gl.getParameter(info.UNMASKED_RENDERER_WEBGL)) : '';

    const scene = new THREE.Scene();
    const root = new THREE.Group();
    scene.add(root);
    const FOV = 30;
    const camera = new THREE.PerspectiveCamera(FOV, 16 / 9, 1, 2000);
    scene.add(new THREE.AmbientLight(0xffffff, 0.55));
    const key = new THREE.DirectionalLight(0xffffff, 0.8);
    key.position.set(-40, 60, 120);
    scene.add(key);
    const ballLight = new THREE.PointLight(ORANGE, 1.6, 70, 2);
    scene.add(ballLight);

    const glowTex = canvasTexture(THREE, 128, 128, glowDraw);
    const coinTex = canvasTexture(THREE, 512, 256, coinDraw);
    const pizzaTex = canvasTexture(THREE, 512, 256, pizzaDraw);
    const noteTex = canvasTexture(THREE, 256, 128, noteDraw);

    // World units are field units / 1000, the field's centre at the origin, y up.
    const FW = WIDTH / 1000;
    const FH = HEIGHT / 1000;
    const wx = (fx) => fx / 1000 - FW / 2;
    const wy = (fy) => FH / 2 - fy / 1000;

    // The arena's plate far below the glass: the environment you play in (P6: it showed almost black, under a dark
    // glass, a blue-grey tint and a heavy blur). Lightly dimmed and darkened only at the screen's rim (in the texture);
    // the glass keeps a darker band along the walls and the goals, where the paddles and the bounces are. It drifts a
    // little against the ball (parallax), and turns with the field on an upright phone so its picture is not cropped
    // to a dark middle strip.
    const PLATE_Z = -140;
    const plateMat = new THREE.MeshBasicMaterial({ color: 0xc8ccd8, transparent: true, opacity: 0 });
    const plate = new THREE.Mesh(new THREE.PlaneGeometry(1, 1), plateMat);
    plate.position.z = PLATE_Z;
    scene.add(plate);
    let plateAspect = 16 / 9;
    const parallax = { x: 0, y: 0 };
    const PARALLAX = 0.045;

    // The glass field: a painted texture (grid, middle line, centre circle, both goals in the figures' colours).
    const fieldCanvas = document.createElement('canvas');
    fieldCanvas.width = 1600;
    fieldCanvas.height = 900;
    const fieldTex = new THREE.CanvasTexture(fieldCanvas);
    const glass = new THREE.Mesh(new THREE.PlaneGeometry(FW, FH), new THREE.MeshBasicMaterial({ map: fieldTex, transparent: true, depthWrite: false }));
    root.add(glass);
    const flashMat = new THREE.MeshBasicMaterial({ color: 0xffffff, transparent: true, opacity: 0, blending: THREE.AdditiveBlending, depthWrite: false });
    const flash = new THREE.Mesh(new THREE.PlaneGeometry(FW, FH), flashMat);
    flash.position.z = 0.05;
    root.add(flash);

    const glowPlane = (colour, opacity = 0.8) => new THREE.Mesh(new THREE.PlaneGeometry(1, 1), new THREE.MeshBasicMaterial({ map: glowTex, color: colour, transparent: true, opacity, blending: THREE.AdditiveBlending, depthWrite: false }));

    // Neon walls: top and bottom split in the two sides' colours, the goal lines faint.
    const tube = (w, h) => new THREE.Mesh(new THREE.BoxGeometry(w, h, 0.8), new THREE.MeshBasicMaterial({ color: 0xffffff }));
    const walls = [];
    [FH / 2 + 0.35, -FH / 2 - 0.35].forEach((y) => {
        [0, 1].forEach((side) => {
            const mesh = tube(FW / 2, 0.7);
            mesh.position.set((side === 0 ? -1 : 1) * FW / 4, y, 0.4);
            const halo = glowPlane(0xffffff, 0.55);
            halo.scale.set(FW / 2 + 6, 7, 1);
            halo.position.set(mesh.position.x, y, 0.1);
            root.add(mesh, halo);
            walls.push({ side, mesh, halo });
        });
    });
    const goalLines = [0, 1].map((side) => {
        const mesh = tube(0.35, FH);
        mesh.position.set((side === 0 ? -1 : 1) * (FW / 2 + 0.2), 0, 0.2);
        mesh.material.transparent = true;
        mesh.material.opacity = 0.55;
        root.add(mesh);

        return mesh;
    });

    // Paddles: lit boxes in the figure's skin, with a glow under them.
    const paddleGeo = new THREE.BoxGeometry(PADDLE_DEPTH / 1000, 1, 2.6);
    const paddles = [0, 1].map(() => {
        const material = new THREE.MeshStandardMaterial({ color: 0xffffff, roughness: 0.35, metalness: 0.15, emissiveIntensity: 0.3 });
        const mesh = new THREE.Mesh(paddleGeo, material);
        const halo = glowPlane(0xffffff, 0.75);
        root.add(halo, mesh);

        return { mesh, halo, material, shown: null };
    });

    // Balls: the coin, its glow, its trail.
    const ballGeo = new THREE.SphereGeometry(1, 32, 20);
    const balls = [0, 1].map(() => {
        const material = new THREE.MeshStandardMaterial({ map: coinTex, emissive: 0xf7931a, emissiveMap: coinTex, emissiveIntensity: 0.4, roughness: 0.3, metalness: 0.4 });
        const mesh = new THREE.Mesh(ballGeo, material);
        const halo = glowPlane(ORANGE, 0.7);
        root.add(halo, mesh);

        return { mesh, halo, material, last: null, history: [] };
    });

    // Trail and particles share one points material.
    const pointsMat = pointsMaterial(THREE, glowTex);
    const points = (n) => {
        const geo = new THREE.BufferGeometry();
        geo.setAttribute('position', new THREE.BufferAttribute(new Float32Array(n * 3), 3));
        geo.setAttribute('tint', new THREE.BufferAttribute(new Float32Array(n * 3), 3));
        geo.setAttribute('size', new THREE.BufferAttribute(new Float32Array(n), 1));
        geo.setAttribute('alpha', new THREE.BufferAttribute(new Float32Array(n), 1));
        const p = new THREE.Points(geo, pointsMat);
        p.frustumCulled = false;
        root.add(p);

        return p;
    };
    const TRAIL_MAX = 40;
    const trails = [0, 1].map(() => points(TRAIL_MAX));
    const PARTICLE_MAX = 220;
    const sparks = points(PARTICLE_MAX);
    const spark = Array.from({ length: PARTICLE_MAX }, () => ({ life: 0, max: 1, x: 0, y: 0, z: 0, vx: 0, vy: 0, vz: 0, c: new THREE.Color(), s: 1 }));
    let sparkNext = 0;

    // The note rain of Brrr: instanced notes falling from above the camera onto the glass.
    const NOTE_MAX = 42;
    const notes = new THREE.InstancedMesh(new THREE.PlaneGeometry(9, 4.5), new THREE.MeshBasicMaterial({ map: noteTex, side: THREE.DoubleSide, transparent: true }), NOTE_MAX);
    notes.frustumCulled = false;
    notes.visible = false;
    scene.add(notes);
    const note = Array.from({ length: NOTE_MAX }, () => ({ x: 0, y: 0, z: 0, r: 0, s: 0, v: 0 }));
    const dummy = new THREE.Object3D();

    // The P7 events: the tax office's block, the border wall's two parts, Few understand's fog over the middle third.
    // P8 (review: grey under bloom read pale): solid saturated bodies with a faint glow of their own, a light edge
    // drawn on top, and a weaker halo, so the bloom brightens the edge and not the whole block into a wash.
    const obstacleMat = (kind) => new THREE.MeshStandardMaterial({ color: OBSTACLE[kind].body, emissive: OBSTACLE[kind].body, emissiveIntensity: 0.28, roughness: 0.55, metalness: 0.05 });
    const unitBox = new THREE.BoxGeometry(1, 1, 1);
    const unitEdges = new THREE.EdgesGeometry(unitBox);
    const obstacle = (kind) => {
        const mesh = new THREE.Mesh(unitBox, obstacleMat(kind));
        mesh.add(new THREE.LineSegments(unitEdges, new THREE.LineBasicMaterial({ color: OBSTACLE[kind].edgeHex })));

        return mesh;
    };
    const taxBlock = obstacle('tax');
    const taxHalo = glowPlane(0xef4444, 0.35);
    const wallParts = [0, 1].map(() => {
        const mesh = obstacle('wall');
        const halo = glowPlane(0xf97316, 0.3);
        root.add(halo, mesh);

        return { mesh, halo };
    });
    root.add(taxHalo, taxBlock);
    const fog = new THREE.Mesh(new THREE.PlaneGeometry(FW / 3, FH), new THREE.MeshBasicMaterial({ map: glowTex, color: 0x6d28d9, transparent: true, opacity: 0, depthWrite: false }));
    fog.position.z = 3.2;
    root.add(fog);
    const fogCore = new THREE.Mesh(new THREE.PlaneGeometry(FW / 3, FH), new THREE.MeshBasicMaterial({ color: 0x1e1036, transparent: true, opacity: 0, depthWrite: false }));
    fogCore.position.z = 3.1;
    root.add(fogCore);

    // The Halving's cut: a blade of light across the field.
    const slash = glowPlane(0x67e8f9, 1);
    slash.visible = false;
    root.add(slash);

    let bloom = null;
    let tierName = 'high';
    let tier = TIERS.high;
    let motion = options.motion !== false;
    let portrait = false;
    let view = { W: 1, H: 1, field: { x: 0, y: 0, w: 1, h: 1 } };
    let figures = [null, null];
    let shake = 0;
    let eventFx = null;
    let flashColour = new THREE.Color();
    let lastNow = performance.now();
    let time = 0;
    let projScale = 400;

    function paintField() {
        const g = fieldCanvas.getContext('2d');
        const w = fieldCanvas.width;
        const h = fieldCanvas.height;
        g.clearRect(0, 0, w, h);
        // Tinted glass: the arena shows through the middle, a darker band along the walls and goals keeps the ball and
        // the paddles readable where they meet the edge.
        const glassTint = g.createRadialGradient(w / 2, h / 2, h * 0.2, w / 2, h / 2, w * 0.62);
        glassTint.addColorStop(0, 'rgba(5, 8, 20, 0.44)');
        glassTint.addColorStop(0.7, 'rgba(5, 8, 20, 0.54)');
        glassTint.addColorStop(1, 'rgba(5, 8, 20, 0.66)');
        g.fillStyle = glassTint;
        g.fillRect(0, 0, w, h);
        // Both goals glow faintly in their figure's colour.
        figures.forEach((f, side) => {
            if (!f) return;
            const x0 = side === 0 ? 0 : w;
            const grad = g.createLinearGradient(x0, 0, side === 0 ? w * 0.22 : w * 0.78, 0);
            grad.addColorStop(0, `${neon(f)}55`);
            grad.addColorStop(1, `${neon(f)}00`);
            g.fillStyle = grad;
            g.fillRect(side === 0 ? 0 : w * 0.78, 0, w * 0.22, h);
        });
        g.strokeStyle = 'rgba(160, 190, 255, 0.05)';
        g.lineWidth = 1.5;
        for (let x = 0; x <= w; x += w / 32) { g.beginPath(); g.moveTo(x, 0); g.lineTo(x, h); g.stroke(); }
        for (let y = 0; y <= h; y += h / 18) { g.beginPath(); g.moveTo(0, y); g.lineTo(w, y); g.stroke(); }
        g.strokeStyle = 'rgba(247, 147, 26, 0.5)';
        g.lineWidth = 5;
        g.setLineDash([22, 18]);
        g.beginPath();
        g.moveTo(w / 2, 0);
        g.lineTo(w / 2, h);
        g.stroke();
        g.setLineDash([]);
        g.lineWidth = 4;
        g.strokeStyle = 'rgba(247, 147, 26, 0.32)';
        g.beginPath();
        g.arc(w / 2, h / 2, h * 0.16, 0, Math.PI * 2);
        g.stroke();
        fieldTex.needsUpdate = true;
    }
    paintField();

    function applyTier() {
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, tier.dpr));
        renderer.setSize(view.W, view.H, false);
        if (tier.bloom && !bloom) bloom = createBloom(THREE, renderer);
        if (!tier.bloom && bloom) {
            bloom.dispose();
            bloom = null;
        }
        const size = renderer.getDrawingBufferSize(new THREE.Vector2());
        if (bloom) bloom.setSize(size.x, size.y);
        projScale = size.y / (2 * Math.tan((FOV * Math.PI) / 360));
        pointsMat.uniforms.scale.value = projScale;
        // Without bloom the glow planes carry the light.
        const glowBoost = tier.bloom ? 1 : 1.35;
        walls.forEach(({ halo }) => { halo.material.opacity = 0.5 * glowBoost; });
        paddles.forEach(({ halo }) => { halo.material.opacity = 0.7 * glowBoost; });
    }

    function placeCamera(dx = 0, dy = 0) {
        const { W, H, field } = view;
        // World units per CSS pixel: the field's long side on screen is FW, its short side FH.
        const k = (portrait ? FH : FW) / field.w;
        const distance = ((H * k) / 2) / Math.tan((FOV * Math.PI) / 360);
        camera.aspect = W / H;
        camera.updateProjectionMatrix();
        const cx = field.x + field.w / 2;
        const cy = field.y + field.h / 2;
        camera.position.set((W / 2 - cx) * k + dx, (cy - H / 2) * k + dy, distance);
        camera.lookAt(camera.position.x, camera.position.y, 0);

        // The plate covers the whole view at its depth, cropped to its own aspect ratio, with room for a shake and the
        // parallax; upright it lies turned like the field, so its long side runs along the screen's.
        const depth = distance - PLATE_Z;
        const vh = 2 * depth * Math.tan((FOV * Math.PI) / 360) * 1.12;
        const vw = vh * camera.aspect;
        const [pw, ph] = portrait ? [vh, vw] : [vw, vh];
        plate.rotation.z = portrait ? Math.PI / 2 : 0;
        plate.scale.set(pw, ph, 1);
        plate.position.x = camera.position.x - dx + parallax.x;
        plate.position.y = camera.position.y - dy + parallax.y;
        const map = plateMat.map;
        if (map) {
            const ratio = pw / ph;
            if (ratio > plateAspect) {
                map.repeat.set(1, plateAspect / ratio);
                map.offset.set(0, (1 - plateAspect / ratio) / 2);
            } else {
                map.repeat.set(ratio / plateAspect, 1);
                map.offset.set((1 - ratio / plateAspect) / 2, 0);
            }
        }
    }

    function burst(x, y, colour, count, speed, z = 1.5) {
        if (!motion) return;
        const n = Math.min(count, tier.particles);
        for (let i = 0; i < n; i++) {
            const p = spark[sparkNext];
            sparkNext = (sparkNext + 1) % Math.min(PARTICLE_MAX, Math.max(1, tier.particles));
            const a = Math.random() * Math.PI * 2;
            const v = speed * (0.35 + Math.random() * 0.65);
            Object.assign(p, { x, y, z, vx: Math.cos(a) * v, vy: Math.sin(a) * v, vz: 6 + Math.random() * 14, life: 0.45 + Math.random() * 0.5, s: 1.2 + Math.random() * 2.2 });
            p.max = p.life;
            p.c.set(colour);
        }
    }

    function stepSparks(dt) {
        const pos = sparks.geometry.attributes.position;
        const tint = sparks.geometry.attributes.tint;
        const size = sparks.geometry.attributes.size;
        const alpha = sparks.geometry.attributes.alpha;
        spark.forEach((p, i) => {
            if (p.life > 0) {
                p.life -= dt;
                p.vx *= 0.94;
                p.vy *= 0.94;
                p.vz -= 30 * dt;
                p.x += p.vx * dt;
                p.y += p.vy * dt;
                p.z = Math.max(0.2, p.z + p.vz * dt);
            }
            const live = Math.max(0, p.life / p.max);
            pos.setXYZ(i, p.x, p.y, p.z);
            tint.setXYZ(i, p.c.r, p.c.g, p.c.b);
            size.setX(i, p.s * (0.4 + live));
            alpha.setX(i, live);
        });
        [pos, tint, size, alpha].forEach((a) => { a.needsUpdate = true; });
    }

    function startNotes() {
        note.forEach((n, i) => Object.assign(n, { x: (Math.random() - 0.5) * FW * 1.1, y: (Math.random() - 0.5) * FH * 1.2, z: 20 + Math.random() * 90 + i, r: Math.random() * 6, s: 0.6 + Math.random() * 0.6, v: 18 + Math.random() * 16 }));
    }

    function stepNotes(dt, on) {
        notes.visible = on && motion && tier.notes > 0;
        if (!notes.visible) return;
        notes.count = Math.min(NOTE_MAX, tier.notes);
        for (let i = 0; i < notes.count; i++) {
            const n = note[i];
            n.z -= n.v * dt;
            n.r += dt * 2;
            n.x += Math.sin(time * 1.3 + i) * dt * 3;
            if (n.z < 0.6) Object.assign(n, { z: 90 + Math.random() * 40, x: (Math.random() - 0.5) * FW * 1.1, y: (Math.random() - 0.5) * FH * 1.2 });
            // The notes fall in the field's frame, so they turn with it on an upright phone.
            const [x, y] = portrait ? [-n.y, n.x] : [n.x, n.y];
            dummy.position.set(x, y, n.z);
            dummy.rotation.set(Math.sin(n.r) * 0.9, Math.cos(n.r * 0.7) * 0.9, n.r);
            dummy.scale.setScalar(n.s);
            dummy.updateMatrix();
            notes.setMatrixAt(i, dummy.matrix);
        }
        notes.instanceMatrix.needsUpdate = true;
    }

    const api = {
        kind: 'webgl',
        gpu,
        get quality() { return tierName; },
        /** What the last frame drew of the P7 events (the browser test's handle). */
        drawn: () => ({ obstacles: [taxBlock, ...wallParts.map((part) => part.mesh)].filter((mesh) => mesh.visible).length, fog: fog.visible, balls: balls.map((b) => b.mesh.visible) }),
        /** Draw calls and triangles of the last frame (bloom passes included). */
        stats: () => ({ calls: renderer.info.render.calls, triangles: renderer.info.render.triangles }),
        setQuality(name) {
            tierName = TIERS[name] ? name : 'high';
            tier = TIERS[tierName];
            applyTier();
        },
        setMotion(on) {
            motion = on;
        },
        setFigures(list) {
            figures = list;
            list.forEach((f, side) => {
                const p = paddles[side];
                if (p.shown === f.id) return;
                p.shown = f.id;
                const tex = canvasTexture(THREE, 64, 512, skinDraw(f.skin));
                p.material.map = tex;
                p.material.emissiveMap = tex;
                p.material.emissive = new THREE.Color(0xffffff);
                p.material.needsUpdate = true;
                p.halo.material.color.set(neon(f));
                goalLines[side].material.color.set(neon(f));
            });
            walls.forEach(({ side, mesh, halo }) => {
                mesh.material.color.set(neon(list[side]));
                halo.material.color.set(neon(list[side]));
            });
            paintField();
        },
        setArena(name) {
            // The plate is ambience, not a second field: drawn small and blurred, so its painted lines melt into light.
            const img = new Image();
            img.onload = () => {
                plateAspect = img.width / img.height;
                const small = document.createElement('canvas');
                small.width = 640;
                small.height = Math.round(640 / plateAspect);
                small.getContext('2d').drawImage(img, 0, 0, small.width, small.height);
                const texture = canvasTexture(THREE, 1280, Math.round(1280 / plateAspect), (g, w, h) => {
                    g.imageSmoothingQuality = 'high';
                    // A soft focus, not a smear: the arena stays recognisable, its painted lines no second field.
                    g.filter = 'blur(2.5px) saturate(1.15)';
                    g.drawImage(small, -4, -4, w + 8, h + 8);
                    g.filter = 'none';
                    // Darker only at the rim of the screen.
                    const rim = g.createRadialGradient(w / 2, h / 2, Math.min(w, h) * 0.38, w / 2, h / 2, Math.hypot(w, h) / 2);
                    rim.addColorStop(0, 'rgba(4, 6, 13, 0)');
                    rim.addColorStop(1, 'rgba(4, 6, 13, 0.7)');
                    g.fillStyle = rim;
                    g.fillRect(0, 0, w, h);
                });
                plateMat.map = texture;
                plateMat.needsUpdate = true;
                plateMat.opacity = 1;
                placeCamera();
            };
            img.src = `/pong/art/arena-${name}.webp`;
        },
        resize(next) {
            view = next;
            portrait = next.portrait;
            root.rotation.z = portrait ? Math.PI / 2 : 0;
            canvas.style.width = `${next.W}px`;
            canvas.style.height = `${next.H}px`;
            applyTier();
            placeCamera();
        },
        /** A paddle's hit at field point (x, y): sparks in its figure's colour, a flash of its glow. */
        hit(side, fx, fy) {
            const f = figures[side];
            burst(wx(fx), wy(fy), f ? neon(f) : '#ffffff', 26, 26);
            burst(wx(fx), wy(fy), f ? f.skin[1] : '#ffffff', 10, 18);
            paddles[side].pulse = 1;
        },
        wall(fx, fy) {
            burst(wx(fx), wy(fy), '#ffd9a0', 8, 14, 0.8);
        },
        /** A goal for `scorer`, the ball out at field y `fy`: an explosion at the goal line, a flash, a shake. */
        goal(scorer, fy) {
            const f = figures[scorer];
            const x = scorer === 0 ? FW / 2 : -FW / 2;
            const colour = f ? neon(f) : '#f7931a';
            burst(x, wy(fy), colour, 90, 48, 2);
            burst(x, wy(fy), '#ffffff', 30, 30, 2);
            flashColour.set(colour);
            flashMat.color.copy(flashColour);
            flashMat.opacity = 0.32;
            if (motion) shake = 1;
        },
        /** A meme event's staging on the field, for `ms` milliseconds (the announcement). */
        event(name, ms) {
            eventFx = { name, t: 0, ms: Math.max(600, ms) };
            const colours = { halving: '#67e8f9', brrr: '#22c55e', pizza: '#fb923c', difficulty: '#ef4444', tax: '#ef4444', controls: '#f97316', few: '#8b5cf6', pow: '#facc15', arbeitsamt: '#e11d48' };
            flashColour.set(colours[name] ?? '#ffffff');
            flashMat.color.copy(flashColour);
            flashMat.opacity = 0.28;
            if (name === 'brrr') startNotes();
        },
        render(state) {
            renderer.info.reset();
            const now = performance.now();
            const dt = Math.min(0.1, (now - lastNow) / 1000);
            lastNow = now;
            time += dt;
            const rally = state ?? { balls: [], alive: [], paddles: [HEIGHT >> 1, HEIGHT >> 1], half: 9000, event: null };

            // Paddles: a Difficulty Adjustment shortens them in ratchet steps, not at once; Proof of Work grows each
            // side's on its own the same way.
            [0, 1].forEach((side) => {
                const p = paddles[side];
                const x = side === 0 ? PADDLE_X - PADDLE_DEPTH / 2 : WIDTH - PADDLE_X + PADDLE_DEPTH / 2;
                const wanted = (2 * halfIn(rally, side)) / 1000;
                if (p.len === undefined) p.len = wanted;
                if (Math.abs(p.len - wanted) > 0.01) {
                    p.step = (p.step ?? 0) + dt;
                    if (p.step > 0.07) {
                        p.step = 0;
                        if (wanted > p.len) p.pulse = 1;
                        p.len += Math.sign(wanted - p.len) * Math.min(Math.abs(wanted - p.len), 0.6);
                    }
                }
                p.mesh.position.set(wx(x), wy(rally.paddles[side]), 1.3);
                p.mesh.scale.y = p.len;
                p.pulse = Math.max(0, (p.pulse ?? 0) - dt * 3);
                p.material.emissiveIntensity = 0.3 + p.pulse * 0.9;
                p.halo.position.set(p.mesh.position.x, p.mesh.position.y, 0.1);
                p.halo.scale.set(9 + p.pulse * 6, p.len + 9 + p.pulse * 6, 1);
            });

            // Balls with their trail; the light follows the first.
            const pizza = rally.event === 'pizza';
            let lit = false;
            balls.forEach((b, index) => {
                const ball = rally.balls[index];
                const on = !!ball && rally.alive[index] && !hiddenBall(rally, ball);
                b.mesh.visible = b.halo.visible = on;
                const trail = trails[index];
                if (!on) {
                    b.history.length = 0;
                    trail.visible = false;
                    return;
                }
                const map = pizza ? pizzaTex : coinTex;
                if (b.material.map !== map) {
                    b.material.map = map;
                    b.material.emissiveMap = map;
                    b.material.emissive.set(pizza ? 0xe25822 : 0xf7931a);
                    b.material.needsUpdate = true;
                }
                const r = ball[4] / 1000;
                const x = wx(ball[0]);
                const y = wy(ball[1]);
                b.mesh.position.set(x, y, r + 0.4);
                b.mesh.scale.setScalar(r);
                // Waiting in the Arbeitsamt's queue: grey, its glow red.
                const waiting = ball.length > 5;
                b.material.emissiveIntensity = waiting ? 0.05 : 0.4;
                b.halo.material.color.set(waiting ? 0xef4444 : ORANGE);
                // It rolls: a turn about the axis across its path, as far as it moved.
                const speed = Math.hypot(ball[2], ball[3]) / 1000;
                b.mesh.rotation.y += (ball[2] > 0 ? 1 : -1) * speed * dt * 4;
                b.mesh.rotation.x -= ball[3] / 1000 * dt * 4;
                b.halo.position.set(x, y, 0.2);
                b.halo.scale.set(r * 6.5, r * 6.5, 1);
                if (!lit) {
                    const world = b.mesh.getWorldPosition(new THREE.Vector3());
                    ballLight.position.set(world.x, world.y, 8);
                    lit = true;
                }

                // A jump (a new serve) clears the trail. A Halving's ball starts whole and halves before the eyes.
                const last = b.history[0];
                if (!last || Math.hypot(last[0] - x, last[1] - y) > 12) {
                    b.history.length = 0;
                    b.moved = false;
                } else if (!b.moved && (last[0] !== x || last[1] !== y)) {
                    // The serve: the ball leaves the middle.
                    b.moved = true;
                    if (rally.event === 'halving') {
                        b.halving = 0.6;
                        burst(x, y, '#67e8f9', 24, 30);
                    }
                }
                if (b.halving > 0) {
                    b.halving = Math.max(0, b.halving - dt);
                    b.mesh.scale.setScalar(r * (1 + b.halving / 0.6));
                }
                b.history.unshift([x, y, r]);
                b.history.length = Math.min(b.history.length, tier.trail);
                trail.visible = motion;
                const pos = trail.geometry.attributes.position;
                const tint = trail.geometry.attributes.tint;
                const size = trail.geometry.attributes.size;
                const alpha = trail.geometry.attributes.alpha;
                const colour = pizza ? [1, 0.55, 0.2] : [1, 0.6, 0.12];
                // A continuous streak: the points spread evenly along the ball's last positions.
                const path = b.history;
                for (let i = 0; i < TRAIL_MAX; i++) {
                    const k = i / (TRAIL_MAX - 1);
                    const at = k * Math.max(0, path.length - 1);
                    const a = path[Math.floor(at)];
                    const c = path[Math.min(path.length - 1, Math.floor(at) + 1)];
                    const f = at - Math.floor(at);
                    const fade = path.length > 1 ? 1 - k : 0;
                    pos.setXYZ(i, a ? a[0] + (c[0] - a[0]) * f : x, a ? a[1] + (c[1] - a[1]) * f : y, 0.6);
                    tint.setXYZ(i, colour[0], colour[1], colour[2]);
                    size.setX(i, a ? a[2] * 4.2 * fade + 0.4 : 0);
                    alpha.setX(i, fade * fade * 0.5);
                }
                [pos, tint, size, alpha].forEach((a) => { a.needsUpdate = true; });
            });
            ballLight.intensity = lit ? 1.6 : 0;

            // The P7 events' field: obstacles where the physics has them, the fog over the middle third.
            const obstacles = obstaclesOf(rally);
            const place = (mesh, halo, [x0, y0, x1, y1], depth) => {
                mesh.position.set(wx((x0 + x1) / 2), wy((y0 + y1) / 2), depth / 2);
                mesh.scale.set((x1 - x0) / 1000, (y1 - y0) / 1000, depth);
                halo.position.set(mesh.position.x, mesh.position.y, 0.1);
                halo.scale.set((x1 - x0) / 1000 + 8, (y1 - y0) / 1000 + 8, 1);
            };
            const tax = obstacles.find((o) => o.kind === 'tax');
            taxBlock.visible = taxHalo.visible = !!tax;
            if (tax) place(taxBlock, taxHalo, tax.rect, 3.4);
            const wallsShown = obstacles.filter((o) => o.kind === 'wall');
            wallParts.forEach((part, i) => {
                part.mesh.visible = part.halo.visible = !!wallsShown[i];
                if (wallsShown[i]) place(part.mesh, part.halo, wallsShown[i].rect, 2.6);
            });
            const fogOn = rally.event === FEW ? 1 : 0;
            fog.material.opacity += (fogOn * 0.9 - fog.material.opacity) * Math.min(1, dt * 4);
            fogCore.material.opacity = fog.material.opacity * 0.75;
            fog.visible = fogCore.visible = fog.material.opacity > 0.01;

            stepSparks(dt);
            stepNotes(dt, rally.event === 'brrr' || eventFx?.name === 'brrr');

            // The event's staging on the field: the Halving's cut sweeps once across it.
            slash.visible = false;
            if (eventFx) {
                eventFx.t += dt * 1000;
                const k = Math.min(1, eventFx.t / eventFx.ms);
                if (eventFx.name === 'halving' && k < 0.5) {
                    slash.visible = true;
                    slash.scale.set(FW * 0.9, 2.4, 1);
                    slash.position.set(0, motion ? (0.25 - k) * FH * 0.8 : 0, 5);
                    slash.rotation.z = -0.12;
                    slash.material.opacity = Math.sin(k * 2 * Math.PI);
                }
                if (k >= 1) eventFx = null;
            }

            flashMat.opacity = Math.max(0, flashMat.opacity - dt * 0.8);
            // The plate drifts a little against the first ball, slowly; still without motion.
            const lead = rally.balls[0];
            let [tx, ty] = [0, 0];
            if (motion && lead && rally.alive[0]) {
                const [sx, sy] = portrait ? [-wy(lead[1]), wx(lead[0])] : [wx(lead[0]), wy(lead[1])];
                [tx, ty] = [-sx * PARALLAX, -sy * PARALLAX];
            }
            const ease = Math.min(1, dt * 1.5);
            parallax.x += (tx - parallax.x) * ease;
            parallax.y += (ty - parallax.y) * ease;
            let dx = 0;
            let dy = 0;
            if (shake > 0) {
                shake = Math.max(0, shake - dt * 2.4);
                const a = shake * shake * 1.6;
                dx = (Math.random() - 0.5) * a;
                dy = (Math.random() - 0.5) * a;
            }
            placeCamera(dx, dy);

            if (bloom) bloom.render(scene, camera);
            else renderer.render(scene, camera);
        },
    };

    return api;
}

/**
 * The arena: three.js on `canvas3d` when WebGL and THREE are there, else the 2D canvas `canvas2d`. A context that
 * fails after the probe falls back to 2D.
 */
export function createArena(canvas2d, canvas3d, options = {}) {
    if (window.THREE && canvas3d && hasWebGL() && !options.force2d) {
        try {
            return create3D(canvas3d, options);
        } catch {
            canvas3d.remove();
        }
    }

    return create2D(canvas2d);
}

/** The tier `auto` starts at: phones and small screens medium, very few cores low, else high. */
export function autoQuality() {
    const cores = navigator.hardwareConcurrency || 4;
    if (cores <= 2) return 'low';
    if (matchMedia('(pointer: coarse)').matches || Math.min(innerWidth, innerHeight) < 600) return 'medium';

    return 'high';
}
