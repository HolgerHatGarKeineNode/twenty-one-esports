/**
 * Proof of Pong's arena (plan "Proof of Pong", P1): draws a frame of the game and nothing else; resources/js/pong/game.js
 * owns the simulation and hands over what to draw. A placeholder for P3's AAA arena: field, paddles, ball(s), glow.
 *
 * With WebGL it is a three.js scene (Hyperbitcoinization's copy, the global THREE); without it (headless browsers,
 * old phones) the same picture on a 2D canvas, so the game never depends on a GPU. Landscape shows side 0 on the
 * left; portrait turns the field, side 0 (the player) at the bottom, side 1 at the top.
 */
import { HEIGHT, PADDLE_DEPTH, PADDLE_X, WIDTH } from './physics.js';

const ORANGE = '#f7931a';
const VIOLET = '#a78bfa';
const LINE = 'rgba(247, 147, 26, 0.22)';

/** Whether this browser can open a WebGL context (asked once, the probe canvas thrown away). */
function hasWebGL() {
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

function create2D(canvas) {
    const ctx = canvas.getContext('2d');
    let w = 0;
    let h = 0;
    let portrait = false;
    let dpr = 1;

    const rect = (fx0, fy0, fx1, fy1) => {
        const [ax, ay] = toScreen(fx0, fy0, w, h, portrait);
        const [bx, by] = toScreen(fx1, fy1, w, h, portrait);

        return [Math.min(ax, bx), Math.min(ay, by), Math.abs(bx - ax), Math.abs(by - ay)];
    };

    return {
        kind: '2d',
        resize(width, height, isPortrait) {
            w = width;
            h = height;
            portrait = isPortrait;
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
            ctx.strokeStyle = LINE;
            ctx.lineWidth = 2;
            ctx.setLineDash([8, 10]);
            ctx.beginPath();
            const [m0x, m0y] = toScreen(WIDTH / 2, 0, w, h, portrait);
            const [m1x, m1y] = toScreen(WIDTH / 2, HEIGHT, w, h, portrait);
            ctx.moveTo(m0x, m0y);
            ctx.lineTo(m1x, m1y);
            ctx.stroke();
            ctx.setLineDash([]);

            // Paddles: the face at PADDLE_X, drawn PADDLE_DEPTH thick behind it.
            [[0, ORANGE], [1, VIOLET]].forEach(([side, colour]) => {
                const y = view.paddles[side];
                const [fx0, fx1] = side === 0 ? [PADDLE_X - PADDLE_DEPTH, PADDLE_X] : [WIDTH - PADDLE_X, WIDTH - PADDLE_X + PADDLE_DEPTH];
                const [x, yy, rw, rh] = rect(fx0, y - view.half, fx1, y + view.half);
                ctx.shadowColor = colour;
                ctx.shadowBlur = 18;
                ctx.fillStyle = colour;
                ctx.beginPath();
                ctx.roundRect(x, yy, rw, rh, Math.min(rw, rh) / 2);
                ctx.fill();
            });

            // Balls, with a glow.
            view.balls.forEach((ball, index) => {
                if (!view.alive[index]) return;
                const [x, y] = toScreen(ball[0], ball[1], w, h, portrait);
                const r = (ball[4] / HEIGHT) * (portrait ? w : h);
                ctx.shadowColor = ORANGE;
                ctx.shadowBlur = 24;
                ctx.fillStyle = '#fff4e0';
                ctx.beginPath();
                ctx.arc(x, y, Math.max(r, 2), 0, Math.PI * 2);
                ctx.fill();
            });
            ctx.shadowBlur = 0;
        },
    };
}

function create3D(canvas) {
    const THREE = window.THREE;
    const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: false });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.setClearColor(0x05070f, 1);

    const scene = new THREE.Scene();
    const root = new THREE.Group();
    scene.add(root);
    const camera = new THREE.OrthographicCamera(-80, 80, 45, -45, 0.1, 500);
    camera.position.set(0, 0, 100);
    camera.lookAt(0, 0, 0);

    // World units are field units / 1000, the field's centre at the origin, y up.
    const wx = (fx) => fx / 1000 - WIDTH / 2000;
    const wy = (fy) => HEIGHT / 2000 - fy / 1000;

    const floor = new THREE.Mesh(new THREE.PlaneGeometry(WIDTH / 1000, HEIGHT / 1000), new THREE.MeshBasicMaterial({ color: 0x0a1024 }));
    floor.position.z = -2;
    root.add(floor);

    const middle = new THREE.Mesh(new THREE.PlaneGeometry(0.4, HEIGHT / 1000), new THREE.MeshBasicMaterial({ color: 0xf7931a, transparent: true, opacity: 0.22 }));
    middle.position.z = -1;
    root.add(middle);

    // A soft round glow texture for sprites.
    const glowTexture = (() => {
        const c = document.createElement('canvas');
        c.width = c.height = 64;
        const g = c.getContext('2d');
        const grad = g.createRadialGradient(32, 32, 0, 32, 32, 32);
        grad.addColorStop(0, 'rgba(255,255,255,1)');
        grad.addColorStop(0.35, 'rgba(255,255,255,0.35)');
        grad.addColorStop(1, 'rgba(255,255,255,0)');
        g.fillStyle = grad;
        g.fillRect(0, 0, 64, 64);

        return new THREE.CanvasTexture(c);
    })();
    const glow = (colour, scale) => {
        const sprite = new THREE.Sprite(new THREE.SpriteMaterial({ map: glowTexture, color: colour, blending: THREE.AdditiveBlending, transparent: true, depthWrite: false }));
        sprite.scale.set(scale, scale, 1);

        return sprite;
    };

    const paddles = [0xf7931a, 0xa78bfa].map((colour) => {
        const mesh = new THREE.Mesh(new THREE.BoxGeometry(PADDLE_DEPTH / 1000, 1, 2), new THREE.MeshBasicMaterial({ color: colour }));
        const halo = glow(colour, 1);
        root.add(mesh, halo);

        return { mesh, halo };
    });
    const balls = [0, 1].map(() => {
        const mesh = new THREE.Mesh(new THREE.SphereGeometry(1, 20, 14), new THREE.MeshBasicMaterial({ color: 0xfff4e0 }));
        const halo = glow(0xf7931a, 1);
        root.add(mesh, halo);

        return { mesh, halo };
    });

    let portrait = false;

    return {
        kind: 'webgl',
        resize(width, height, isPortrait) {
            portrait = isPortrait;
            renderer.setSize(width, height, false);
            root.rotation.z = isPortrait ? Math.PI / 2 : 0;
            const [hw, hh] = isPortrait ? [HEIGHT / 2000, WIDTH / 2000] : [WIDTH / 2000, HEIGHT / 2000];
            Object.assign(camera, { left: -hw, right: hw, top: hh, bottom: -hh });
            camera.updateProjectionMatrix();
        },
        render(view) {
            [0, 1].forEach((side) => {
                const x = side === 0 ? PADDLE_X - PADDLE_DEPTH / 2 : WIDTH - PADDLE_X + PADDLE_DEPTH / 2;
                const { mesh, halo } = paddles[side];
                mesh.position.set(wx(x), wy(view.paddles[side]), 0);
                mesh.scale.y = (2 * view.half) / 1000;
                halo.position.copy(mesh.position);
                // A sprite faces the camera and ignores the turned field: its long side follows the screen.
                const long = (2 * view.half) / 1000 + 8;
                halo.scale.set(portrait ? long : 8, portrait ? 8 : long, 1);
            });
            balls.forEach(({ mesh, halo }, index) => {
                const ball = view.balls[index];
                const on = !!ball && view.alive[index];
                mesh.visible = halo.visible = on;
                if (!on) return;
                const r = ball[4] / 1000;
                mesh.position.set(wx(ball[0]), wy(ball[1]), 1);
                mesh.scale.setScalar(r);
                halo.position.copy(mesh.position);
                halo.scale.set(r * 9, r * 9, 1);
            });
            renderer.render(scene, camera);
        },
    };
}

/**
 * The arena on `canvas`: three.js when WebGL and THREE are there, else the 2D canvas.
 */
export function createArena(canvas) {
    if (window.THREE && hasWebGL()) {
        try {
            return create3D(canvas);
        } catch {
            // A context that fails after the probe falls back to 2D on a fresh canvas.
            const fresh = canvas.cloneNode(false);
            canvas.replaceWith(fresh);

            return create2D(fresh);
        }
    }

    return create2D(canvas);
}
