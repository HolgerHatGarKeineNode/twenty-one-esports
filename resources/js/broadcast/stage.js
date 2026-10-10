/**
 * The broadcast stage: one transparent three.js canvas laid out in logical units of the 1920x1080 design frame.
 *
 * - Camera: perspective, placed so that on the z = 0 plane one world unit is one logical pixel, (0, 0) the frame's
 *   centre. Plates are real slabs with depth, so the perspective shows their sides as they swing in.
 * - Frame: the canvas is always 16:9 (resources/css/broadcast.css fits it into any viewport, centred), so the
 *   1920x1080 frame, the 3D worlds under it, the centre guard and sampleAlpha share one mapping at every source size;
 *   nothing drifts against anything else in an ultrawide or square browser source.
 * - Size: the canvas' CSS size times devicePixelRatio, capped per quality tier. An OBS source of 3840x2160 at DPR 1
 *   draws 3840x2160; text textures are drawn at drawingBufferHeight / 1080 (resources/js/broadcast/text.js).
 * - Glow: selective bloom. Objects on layer GLOW (hot edges, cube rims) are rendered alone into a reduced target,
 *   blurred and added onto the canvas with premultiplied additive blending, so the canvas keeps alpha 0 wherever
 *   nothing is drawn (OBS keys on that alpha). Text is never on the glow layer: a glowing word reads soft.
 *   The blur kernel is createBloom's from resources/js/pong/arena.js (Proof of Pong), lifted, not shared.
 * - Quality tiers: high, medium, low; auto steps down when the p90 frame time exceeds 20 ms in two 3 s windows in a
 *   row (the first 5 s excluded), never up (no oscillation on air). `?tier=` forces one.
 */

import { GUARD, GUARD_GLSL } from './guard.js';

export const GLOW = 1;

export const TIERS = Object.freeze({
    high: { name: 'high', dprCap: 2, bloomDiv: 2, blurPasses: 3, particles: 1 },
    medium: { name: 'medium', dprCap: 1.5, bloomDiv: 4, blurPasses: 2, particles: 0.6 },
    low: { name: 'low', dprCap: 1, bloomDiv: 0, blurPasses: 0, particles: 0.3 },
});
const ORDER = ['high', 'medium', 'low'];

const QUAD_VERT = 'varying vec2 vUv; void main() { vUv = uv; gl_Position = vec4(position.xy, 0.0, 1.0); }';

function createGlow(THREE, renderer) {
    const quad = new THREE.Mesh(new THREE.PlaneGeometry(2, 2));
    quad.frustumCulled = false;
    const scene = new THREE.Scene();
    scene.add(quad);
    const camera = new THREE.OrthographicCamera(-1, 1, 1, -1, 0, 1);
    const options = { minFilter: THREE.LinearFilter, magFilter: THREE.LinearFilter, format: THREE.RGBAFormat };
    let a = new THREE.WebGLRenderTarget(2, 2, options);
    let b = new THREE.WebGLRenderTarget(2, 2, options);
    const blur = new THREE.ShaderMaterial({
        uniforms: { src: { value: null }, dir: { value: new THREE.Vector2() } },
        vertexShader: QUAD_VERT,
        fragmentShader: `uniform sampler2D src; uniform vec2 dir; varying vec2 vUv;
            void main() { vec4 c = texture2D(src, vUv) * 0.227;
              c += (texture2D(src, vUv + dir * 1.385) + texture2D(src, vUv - dir * 1.385)) * 0.316;
              c += (texture2D(src, vUv + dir * 3.231) + texture2D(src, vUv - dir * 3.231)) * 0.070;
              gl_FragColor = c; }`,
        depthTest: false,
        depthWrite: false,
    });
    // Premultiplied additive: colour adds light, alpha grows by the glow's own coverage, never shrinks what is there.
    const add = new THREE.ShaderMaterial({
        uniforms: { glow: { value: null }, strength: { value: 1.6 }, guardOn: GUARD.guardOn, screen: GUARD.screen },
        vertexShader: QUAD_VERT,
        // The bloom passes the centre guard too: blur spill from a hot edge next to the centre never lands in the stream.
        fragmentShader: `${GUARD_GLSL} uniform sampler2D glow; uniform float strength; varying vec2 vUv;
            void main() { vec3 g = texture2D(glow, vUv).rgb * strength * guard(); float a = clamp(max(g.r, max(g.g, g.b)), 0.0, 1.0);
              gl_FragColor = vec4(g, a); }`,
        transparent: true,
        depthTest: false,
        depthWrite: false,
        blending: THREE.CustomBlending,
        blendEquation: THREE.AddEquation,
        blendSrc: THREE.OneFactor,
        blendDst: THREE.OneFactor,
        blendSrcAlpha: THREE.OneFactor,
        blendDstAlpha: THREE.OneMinusSrcAlphaFactor,
    });
    const pass = (material, to) => {
        quad.material = material;
        renderer.setRenderTarget(to);
        renderer.render(scene, camera);
    };

    return {
        setSize(w, h) {
            a.setSize(Math.max(1, w), Math.max(1, h));
            b.setSize(Math.max(1, w), Math.max(1, h));
        },
        render(views, passes) {
            renderer.setRenderTarget(a);
            renderer.setClearColor(0x000000, 0);
            renderer.clear();
            views.forEach(([viewScene, viewCamera], i) => {
                const mask = viewCamera.layers.mask;
                viewCamera.layers.set(GLOW);
                if (i > 0) renderer.clearDepth();
                renderer.render(viewScene, viewCamera);
                viewCamera.layers.mask = mask;
            });
            for (let i = 1; i <= passes; i++) {
                blur.uniforms.src.value = a.texture;
                blur.uniforms.dir.value.set(i / a.width, 0);
                pass(blur, b);
                blur.uniforms.src.value = b.texture;
                blur.uniforms.dir.value.set(0, i / a.height);
                pass(blur, a);
            }
            add.uniforms.glow.value = a.texture;
            pass(add, null);
        },
        dispose() {
            a.dispose();
            b.dispose();
            a = b = null;
        },
    };
}

/** Percentile of a list of numbers (nearest rank). */
export function percentile(values, p) {
    if (!values.length) return 0;
    const sorted = [...values].sort((x, y) => x - y);

    return sorted[Math.min(sorted.length - 1, Math.max(0, Math.ceil((p / 100) * sorted.length) - 1))];
}

export function createStage(canvas, { THREE, tier: forced = null } = {}) {
    const renderer = new THREE.WebGLRenderer({ canvas, alpha: true, antialias: true, premultipliedAlpha: true, powerPreference: 'high-performance' });
    renderer.setClearColor(0x000000, 0);
    renderer.autoClear = false;

    const scene = new THREE.Scene();
    const FOV = 24;
    const distance = 540 / Math.tan((FOV * Math.PI) / 360);
    const camera = new THREE.PerspectiveCamera(FOV, 16 / 9, 10, distance * 4);
    camera.position.set(0, 0, distance);
    camera.layers.enable(GLOW);

    let tier = TIERS[forced] || TIERS.high;
    // Worlds drawn under the frame-plane scene, each with its own camera (the bracket's flight, plan P6): [scene, camera].
    const worlds = [];
    // A full-screen scene draws on an opaque ground; an overlay keeps alpha 0 wherever nothing is drawn.
    let backdrop = null;
    let glow = null;
    let textScale = 1;
    const listeners = new Set();
    const frames = [];
    // CPU time per frame: [update, render submit] in ms; the GPU's own time is not visible from here.
    const work = [];
    const windowFrames = [];
    let windowStart = 0;
    let slowWindows = 0;
    const startedAt = performance.now();
    let last = 0;
    let running = false;
    const updaters = new Set();

    function resize() {
        const w = Math.max(1, canvas.clientWidth);
        const h = Math.max(1, canvas.clientHeight);
        const ratio = Math.min(window.devicePixelRatio || 1, tier.dprCap);
        renderer.setPixelRatio(ratio);
        renderer.setSize(w, h, false);
        camera.aspect = w / h;
        camera.updateProjectionMatrix();
        worlds.forEach(([, c]) => { c.aspect = w / h; c.updateProjectionMatrix(); });
        const size = renderer.getDrawingBufferSize(new THREE.Vector2());
        GUARD.screen.value = { x: size.x, y: size.y };
        if (tier.bloomDiv && !glow) glow = createGlow(THREE, renderer);
        if (!tier.bloomDiv && glow) {
            glow.dispose();
            glow = null;
        }
        if (glow) glow.setSize(Math.round(size.x / tier.bloomDiv), Math.round(size.y / tier.bloomDiv));
        const scale = size.y / 1080;
        if (Math.abs(scale - textScale) > 0.01) {
            textScale = scale;
            listeners.forEach((fn) => fn(textScale));
        }
    }

    function setTier(name) {
        if (!TIERS[name] || TIERS[name] === tier) return;
        tier = TIERS[name];
        resize();
    }

    // GPU time per frame where the browser exposes timer queries (desktop Chromium on a real GPU): one query in flight,
    // read back a few frames later. Empty elsewhere (SwiftShader, Firefox), and then stats() says so.
    const gl = renderer.getContext();
    const timer = renderer.capabilities.isWebGL2 ? gl.getExtension('EXT_disjoint_timer_query_webgl2') : null;
    const gpu = [];
    let query = null;
    let queryOpen = false;

    function render() {
        if (timer && !query && !queryOpen) {
            query = gl.createQuery();
            gl.beginQuery(timer.TIME_ELAPSED_EXT, query);
            queryOpen = true;
        }
        draw();
        if (queryOpen) {
            gl.endQuery(timer.TIME_ELAPSED_EXT);
            queryOpen = false;
        } else if (query && gl.getQueryParameter(query, gl.QUERY_RESULT_AVAILABLE)) {
            if (!gl.getParameter(timer.GPU_DISJOINT_EXT)) gpu.push(gl.getQueryParameter(query, gl.QUERY_RESULT) / 1e6);
            if (gpu.length > 300) gpu.shift();
            gl.deleteQuery(query);
            query = null;
        }
    }

    function draw() {
        renderer.setRenderTarget(null);
        if (backdrop) renderer.setClearColor(backdrop, 1);
        else renderer.setClearColor(0x000000, 0);
        renderer.clear();
        const views = [...worlds.filter(([s]) => s.visible), [scene, camera]];
        views.forEach(([viewScene, viewCamera], i) => {
            if (i > 0) renderer.clearDepth();
            renderer.render(viewScene, viewCamera);
        });
        if (glow) glow.render(views, tier.blurPasses);
    }

    function frame(now) {
        if (!running) return;
        requestAnimationFrame(frame);
        const t = now - startedAt;
        if (last) {
            const dt = now - last;
            frames.push(dt);
            if (frames.length > 600) frames.shift();
            windowFrames.push(dt);
        }
        last = now;
        const w0 = performance.now();
        updaters.forEach((fn) => fn(t));
        const w1 = performance.now();
        render();
        work.push([w1 - w0, performance.now() - w1]);
        if (work.length > 600) work.shift();
        if (!forced && now - windowStart > 3000) {
            // Two slow windows in a row step down; one alone is a hitch (a texture upload, a shader compile).
            const slow = windowFrames.length > 30 && percentile(windowFrames, 90) > 20;
            slowWindows = slow ? slowWindows + 1 : 0;
            if (slowWindows >= 2) {
                const next = ORDER[ORDER.indexOf(tier.name) + 1];
                if (next) setTier(next);
                slowWindows = 0;
            }
            windowFrames.length = 0;
            windowStart = now;
        }
    }

    resize();
    new ResizeObserver(resize).observe(canvas);

    return {
        THREE,
        scene,
        camera,
        renderer,
        canvas,
        /** What is drawn, back to front: [scene, camera, 'world' | 'frame'] (the layout probe projects through these). */
        views: () => [...worlds.filter(([s]) => s.visible).map(([s, c]) => [s, c, 'world']), [scene, camera, 'frame']],
        /** Page clock of the stage, ms since it was created (the timeline's time base). */
        now: () => performance.now() - startedAt,
        get tier() { return tier; },
        get textScale() { return textScale; },
        onTextScale(fn) { listeners.add(fn); },
        onFrame(fn) { updaters.add(fn); },
        /** Draw `worldScene` through `worldCamera` under the frame plane (its aspect follows the canvas). */
        addWorld(worldScene, worldCamera) {
            const size = renderer.getSize(new THREE.Vector2());
            worldCamera.aspect = size.x / Math.max(1, size.y);
            worldCamera.updateProjectionMatrix();
            worlds.push([worldScene, worldCamera]);
        },
        /** An opaque ground colour for a full-screen scene; null keeps the canvas transparent. */
        setBackdrop(color) { backdrop = color === null ? null : new THREE.Color(color); },
        setTier,
        /** Stop drawing (a page view taken off screen); start() resumes. */
        stop() { running = false; last = 0; },
        start() {
            if (running) return;
            running = true;
            // The first 5 s (fonts, textures, shader compiles) do not count towards the tier.
            windowStart = performance.now() + 5000;
            requestAnimationFrame(frame);
        },
        /** Logical frame position (x right, y down, 0..1920 / 0..1080) to world units. */
        toWorld(x, y) { return { x: x - 960, y: 540 - y }; },
        /**
         * The world x/y that puts a point standing `z` in front of the frame plane on screen where (wx, wy) on the
         * plane would be: perspective pushes near things outwards, so a figure in front of a plate is pulled back in.
         */
        onScreen(wx, wy, z) { const k = (distance - z) / distance; return { x: wx * k, y: wy * k }; },
        stats() {
            const size = renderer.getDrawingBufferSize(new THREE.Vector2());

            return {
                frames: frames.length,
                p50: +percentile(frames, 50).toFixed(2),
                p95: +percentile(frames, 95).toFixed(2),
                p99: +percentile(frames, 99).toFixed(2),
                updateP95: +percentile(work.map((w) => w[0]), 95).toFixed(2),
                renderP95: +percentile(work.map((w) => w[1]), 95).toFixed(2),
                gpuP95: gpu.length ? +percentile(gpu, 95).toFixed(2) : null,
                gpuSamples: gpu.length,
                tier: tier.name,
                width: size.x,
                height: size.y,
                pixelRatio: renderer.getPixelRatio(),
                renderer: (() => {
                    const gl = renderer.getContext();
                    const ext = gl.getExtension('WEBGL_debug_renderer_info');

                    return ext ? gl.getParameter(ext.UNMASKED_RENDERER_WEBGL) : gl.getParameter(gl.RENDERER);
                })(),
            };
        },
        resetStats() { frames.length = 0; work.length = 0; gpu.length = 0; last = 0; },
        /**
         * Alpha (0..1) of the canvas at a logical frame position: renders now and reads the pixel back in the same task,
         * so it works without preserveDrawingBuffer.
         */
        sampleAlpha(x, y) {
            draw();
            const size = renderer.getDrawingBufferSize(new THREE.Vector2());
            const px = Math.round((x / 1920) * size.x);
            const py = Math.round(size.y - (y / 1080) * size.y) - 1;
            const out = new Uint8Array(4);
            gl.readPixels(px, Math.max(0, py), 1, 1, gl.RGBA, gl.UNSIGNED_BYTE, out);

            return out[3] / 255;
        },
    };
}
