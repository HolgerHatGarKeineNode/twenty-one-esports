/*
 * The 3D battle arena (three.js r128): a themed stage per currency space, two armies of figures
 * (plebs, Bitcoin maxis with laser eyes, ASIC rigs), dice that are thrown, bounce and land on their
 * number, and the drama of fiat: banks ooze banknotes that turn grey as they fall (purchasing power),
 * every hit blows a geyser of notes out of the bank, a conquest drops a giant ₿ coin that crushes it.
 * window.Arena: themeFor, open, throw, dimDice, casualties, charge, inflation, laser, candle, finale, close.
 *
 * The game page (plan "Hyperbitcoinization", P2) loads this as part of its Vite entry, after three.js r128 as a
 * global (public/hyper/vendor). Assets come from window.HYPER_ASSETS (default /hyper/, public/hyper), and the
 * texts painted into the scene go through window.hyperT (English keys, the page's dictionary); the theme memes
 * and pair titles are translated by the page where it shows them.
 */
(function () {
    'use strict';
    const THREE = window.THREE;
    const ASSET = window.HYPER_ASSETS || '/hyper/';
    const tr = (text) => (typeof window.hyperT === 'function' ? window.hyperT(text) : text);
    // Marks a key the page translates where it shows it (theme memes, pair titles).
    const T = (text) => text;
    if (!THREE) { window.Arena = null; return; }
    const REDUCED = matchMedia('(prefers-reduced-motion: reduce)').matches;
    const R = Math.random;
    const emit = (name) => window.dispatchEvent(new CustomEvent(name));

    /* ---------- Themes: one stage per currency space, each with a meme ---------- */
    const THEMES = {
        dollar: { pano: 'rooftop_day', floor: 'asphalt_02', sky: ['#0e2a5c', '#5f8fd6'], ground: '#2d3f63', fog: '#6d8fc7', light: '#fff2dc', particle: { glyph: '$', color: '#7fe0a6', mode: 'fall' }, props: 'fed', cur: '$', note: '#8fbf6a', meme: T('Brrrr zone: the Fed prints more') },
        euro: { pano: 'german_town_street', floor: 'cobblestone_floor_001', sky: ['#1a2236', '#7d8aa8'], ground: '#3a4458', fog: '#8a94ad', light: '#e9eeff', particle: { glyph: '€', color: '#ffd76b', mode: 'fall' }, props: 'ezb', cur: '€', note: '#d9b36a', meme: T('Frankfurt: Lagarde only sees charts') },
        pound: { pano: 'belfast_sunset', floor: 'cobblestone_02', sky: ['#1b2433', '#6b7891'], ground: '#33413a', fog: '#7b879c', light: '#d9e2f2', particle: { glyph: '|', color: '#9fb6d9', mode: 'rain' }, props: 'london', cur: '£', note: '#c98a8a', meme: T('London fog: interest in the endless rain') },
        swiss: { pano: 'alps_field', floor: 'snow_02', sky: ['#1a3a66', '#c9dcf2'], ground: '#d9e3ee', fog: '#c6d6ea', light: '#ffffff', particle: { glyph: '✻', color: '#ffffff', mode: 'snow' }, props: 'vault', cur: 'Fr', note: '#e8c34a', meme: T('Safecrackers wanted: bank secrecy against HODL') },
        rubel: { pano: 'winter_evening', floor: 'snow_03', sky: ['#1d2a44', '#a9b9d4'], ground: '#cfd8e6', fog: '#b4c2d8', light: '#fff4e6', particle: { glyph: '❄', color: '#ffffff', mode: 'snow' }, props: 'kremlin', cur: '₽', note: '#8ab0d9', meme: T('Winter is coming. So is inflation.') },
        yuan: { pano: 'shanghai_bund', floor: 'brick_floor_02', sky: ['#3a0d10', '#c4523a'], ground: '#4a2a22', fog: '#a3483a', light: '#ffd9b0', particle: { glyph: '¥', color: '#ffcf4a', mode: 'fall' }, props: 'pagoda', cur: '¥', note: '#d97a7a', meme: T('Behind the great firewall') },
        yen: { pano: 'misty_pines', floor: 'gravel', sky: ['#2a1b3d', '#f2a7c3'], ground: '#5b4a5e', fog: '#e7a8c4', light: '#ffeaf2', particle: { glyph: '✿', color: '#ffc2dc', mode: 'petal' }, props: 'fuji', cur: '¥', note: '#b9a0d0', meme: T('Zero interest under cherry blossoms') },
        afro: { pano: 'qwantani_afternoon', floor: 'dry_ground_01', sky: ['#3a1406', '#f39a3c'], ground: '#8a5a2a', fog: '#e48b3b', light: '#ffd29a', particle: { glyph: '·', color: '#ffd29a', mode: 'dust' }, props: 'savanna', cur: 'AF', note: '#7fc6b4', meme: T('Savannah of growth') },
        sa: { pano: 'fouriesburg_mountain_midday', floor: 'forest_ground_04', sky: ['#0d2a1c', '#5aa36a'], ground: '#2f4a2a', fog: '#4f8a5a', light: '#fff0c8', particle: { glyph: '•', color: '#ff7a2a', mode: 'ember' }, props: 'volcano', cur: 'R$', note: '#a6c97a', meme: T('Volcano bonds: mining with lava') },
        ozean: { pano: 'fish_hoek_beach', floor: 'coast_sand_01', sky: ['#0b3a5c', '#7cc8e8'], ground: '#e2c98f', fog: '#8fd0ea', light: '#fff6dc', particle: { glyph: '~', color: '#ffffff', mode: 'dust' }, props: 'beach', cur: 'A$', note: '#c49ad9', meme: T('Down Under: fiat stands on its head') },
        mine: { pano: 'industrial_sunset_02', floor: 'brushed_concrete', sky: ['#0b0f1c', '#2a2140'], ground: '#1d2030', fog: '#2a2a44', light: '#ffcf8a', particle: { glyph: '₿', color: '#f7931a', mode: 'rise' }, props: 'farm', cur: '$', note: '#8fbf6a', meme: T('Mining hub: the hashrate is boiling') },
    };
    /* ---------- Event cards: one short scene each ---------- */
    const V = (x, y, z) => new THREE.Vector3(x, y, z);
    function coinMesh(r = 0.3) {
        const face = new THREE.MeshStandardMaterial({ map: glyphTexture('₿', '#ffffff', 128, '#f7931a'), metalness: 0.6, roughness: 0.3 });
        const c = new THREE.Mesh(new THREE.CylinderGeometry(r, r, r * 0.2, 32), [new THREE.MeshStandardMaterial({ color: '#d9861a', metalness: 0.9, roughness: 0.25 }), face, face]);
        c.castShadow = true; return c;
    }
    function beam(from, to, color, width = 0.06, life = 350) {
        const len = from.distanceTo(to);
        const b = new THREE.Mesh(new THREE.CylinderGeometry(width, width, len, 8), new THREE.MeshBasicMaterial({ color, transparent: true }));
        b.position.copy(from).lerp(to, 0.5); b.quaternion.setFromUnitVectors(V(0, 1, 0), to.clone().sub(from).normalize()); scene.add(b);
        tween(life, (p) => { b.material.opacity = 1 - p; }, () => scene.remove(b));
    }
    const EVENTS = {
        // The Fed printer goes full brrrr: a storm of notes, the bank bursts.
        brrrr: { theme: 'dollar', run() {
            const pr = stage.userData.printer;
            floatLabel('BRRRR', '#7fe0a6', V(1.5, 2.6, 1), 5);
            tween(2600, (p) => { spawnNotes(pr, 4, { speed: 1.5, up: 4, life: 5, dir: V(-2.5, 1.5, 2) }); if (p > 0.3) shakeAmt = Math.max(shakeAmt, 0.06); });
            setTimeout(() => Arena.inflation(2), 900); setTimeout(() => Arena.inflation(2.5), 1900);
        } },
        // 51 %: lightning out of the sky into the mining farm, hashrate overload.
        attack51: { theme: 'mine', run() {
            floatLabel('51 % HASHRATE', '#f7931a', V(0, 3.2, -1), 5);
            for (let i = 0; i < 14; i++) setTimeout(() => { const x = -5 + Math.random() * 10; const z = -4 - Math.random() * 3.6; beam(V(x + (Math.random() - 0.5) * 2, 12, z), V(x, 1.6, z), '#ffd08a', 0.07, 300); sparks(V(x, 1.4, z), '#f7931a', 18, 4); shakeAmt = Math.max(shakeAmt, 0.15); emit('arena-laser'); }, 150 + i * 170);
        } },
        // Lost keys: a pile of coins falls into a black hole.
        keys: { theme: 'swiss', run() {
            const hole = mesh(new THREE.CircleGeometry(0.1, 40), new THREE.MeshBasicMaterial({ color: '#000000' }), 0, 0.03, 0.5, false); hole.rotation.x = -Math.PI / 2; scene.add(hole);
            tween(700, (p) => hole.scale.setScalar(1 + p * 18));
            const coins = Array.from({ length: 21 }, (_, i) => { const c = coinMesh(0.28); c.position.set((Math.random() - 0.5) * 3, 0.5 + i * 0.12, 0.5 + (Math.random() - 0.5) * 1.5); c.rotation.set(Math.random(), Math.random(), Math.random()); scene.add(c); return c; });
            setTimeout(() => coins.forEach((c, i) => { const x0 = c.position.x; const z0 = c.position.z; const y0 = c.position.y; tween(900 + i * 40, (p) => { c.position.set(x0 * (1 - p), y0 - p * p * 4, 0.5 + (z0 - 0.5) * (1 - p)); c.rotation.y += 0.3; c.scale.setScalar(1 - p * 0.8); }, () => scene.remove(c)); }), 800);
            setTimeout(() => { floatLabel(tr('SEED? GONE.'), '#ff5d73', V(0, 1.6, 1.5), 4); emit('arena-down'); }, 1300);
            setTimeout(() => tween(500, (p) => hole.scale.setScalar(19 * (1 - p) + 0.01), () => scene.remove(hole)), 2300);
        } },
        // Diamond hands: a giant diamond rises and sparkles.
        diamond: { theme: 'mine', run() {
            const d = mesh(new THREE.OctahedronGeometry(1.1, 0), new THREE.MeshPhysicalMaterial({ color: '#7fdcff', emissive: '#1a6f9a', emissiveIntensity: 0.35, metalness: 0.1, roughness: 0.02, clearcoat: 1, flatShading: true, transparent: true, opacity: 0.88, toneMapped: true }), 0, -1.5, 0.5);
            d.scale.y = 1.4; scene.add(d);
            const glow = new THREE.PointLight('#5fd8ff', 3, 10); glow.position.set(0, 2, 1.5); scene.add(glow);
            tween(900, (p) => { d.position.y = -1.5 + (1 - Math.pow(1 - p, 3)) * 3.6; });
            tween(3000, (p) => { d.rotation.y = p * 8; if (Math.random() < 0.3) sparks(d.position.clone().add(V((Math.random() - 0.5) * 2, (Math.random() - 0.5) * 2, 0.6)), '#bff4ff', 6, 1.5); });
            setTimeout(() => { floatLabel('DIAMOND HANDS', '#8fe8ff', V(0, 3.6, 1), 5); emit('arena-up'); }, 800);
        } },
        // El Salvador: a coin flip above the volcano, then the candle says up or down.
        salvador: { theme: 'sa', run(o) {
            const c = coinMesh(0.9); c.position.set(0, 1, 1); scene.add(c);
            tween(1500, (p) => { c.position.y = 1 + Math.sin(p * Math.PI) * 3.5; c.rotation.x = p * 22; }, () => { c.rotation.x = Math.PI / 2; Arena.candle(!!o.up); floatLabel(o.up ? 'TO THE MOON' : tr('OUCH'), o.up ? '#f7931a' : '#ff5d73', V(0, 3, 1.4), 4); });
        } },
        // Exit scam: the governor's plane takes off from the central bank, trailing cash.
        scam: { theme: 'dollar', zoneTheme: true, run() {
            const bk = stage.userData.bank; const at = bk ? bk.roof.clone() : V(0, 2, -4);
            const pl = new THREE.Group(); const white = mat('#f2f2f2', { m: 0.4, r: 0.3 });
            pl.add(mesh(new THREE.CylinderGeometry(0.22, 0.18, 2.2, 12), white, 0, 0, 0)); pl.children[0].rotation.z = Math.PI / 2;
            pl.add(mesh(new THREE.BoxGeometry(0.5, 0.05, 2.4), white, 0.1, 0, 0)); pl.add(mesh(new THREE.BoxGeometry(0.3, 0.6, 0.05), white, -0.95, 0.3, 0));
            pl.add(mesh(new THREE.ConeGeometry(0.22, 0.5, 12), mat('#c33'), 1.35, 0, 0)); pl.children[3].rotation.z = -Math.PI / 2;
            pl.position.copy(at); scene.add(pl);
            tween(2600, (p) => { pl.position.set(at.x - 2 + p * 14, at.y + 0.3 + p * p * 6, at.z + p * 6); pl.rotation.z = 0.25 + p * 0.2; spawnNotes(pl.position.clone().add(V(-1.2, 0, 0)), 2, { speed: 1, up: 0.5, life: 4 }); });
            if (bk) setTimeout(() => Arena.inflation(1), 400);
            floatLabel('EXIT SCAM', '#ff5d73', V(0, 1.4, 1.5), 4.5);
        } },
        // Pizza day: ten coins fly into two pizzas, the pizzas are gone.
        pizza: { theme: 'ozean', run() {
            const pz = [-1.3, 1.3].map((x) => { const g = new THREE.Group(); g.position.set(x, 0.2, 0.8); g.add(mesh(new THREE.CylinderGeometry(1, 1, 0.12, 32), mat('#e8b86a'), 0, 0, 0)); g.add(mesh(new THREE.CylinderGeometry(0.88, 0.88, 0.13, 32), mat('#d1452e'), 0, 0.01, 0)); for (let i = 0; i < 9; i++) g.add(mesh(new THREE.CylinderGeometry(0.12, 0.12, 0.14, 12), mat('#9a2a1a'), Math.cos(i * 2.4) * 0.55 * Math.sqrt(i / 9 + 0.1), 0.03, Math.sin(i * 2.4) * 0.55 * Math.sqrt(i / 9 + 0.1))); scene.add(g); return g; });
            for (let i = 0; i < 10; i++) setTimeout(() => { const c = coinMesh(0.25); const tx = i % 2 ? 1.3 : -1.3; c.position.set(-6, 3, 2); scene.add(c); tween(700, (p) => { c.position.set(-6 + (tx + 6) * p, 3 + Math.sin(p * Math.PI) * 2 - p * 2.5, 2 - p * 1.2); c.rotation.x += 0.4; }, () => { scene.remove(c); sparks(V(tx, 0.4, 0.8), '#f7931a', 8, 2); emit('arena-land'); }); }, i * 120);
            setTimeout(() => { floatLabel(tr('10,000 BTC'), '#f7931a', V(0, 2.4, 1.2), 4); pz.forEach((g) => tween(700, (p) => g.scale.setScalar(1 - p * 0.99), () => scene.remove(g))); }, 1700);
        } },
        // Lagarde's crystal ball: purple glow, a question mark, nothing to see.
        lagarde: { theme: 'euro', run() {
            scene.add(mesh(new THREE.CylinderGeometry(0.5, 0.7, 0.8, 16), mat('#6b4a2a'), 0, 0.4, 0.8));
            const ball = mesh(new THREE.SphereGeometry(0.9, 32, 24), new THREE.MeshStandardMaterial({ color: '#c9b3ff', emissive: '#7a4aff', emissiveIntensity: 0.4, transparent: true, opacity: 0.55, roughness: 0.05 }), 0, 1.7, 0.8); scene.add(ball);
            const q = labelSprite('?', '#ffffff', 1.4, true); q.position.set(0, 1.7, 0.8); scene.add(q);
            const l = new THREE.PointLight('#a07aff', 2.5, 8); l.position.set(0, 1.8, 1.6); scene.add(l);
            tween(3000, (p) => { ball.material.emissiveIntensity = 0.3 + Math.abs(Math.sin(p * 12)) * 0.8; q.material.rotation = Math.sin(p * 10) * 0.4; });
            setTimeout(() => floatLabel(tr('LOOKS PROFESSIONAL'), '#c9b3ff', V(0, 3.2, 1), 5), 600);
        } },
        // Buy the dip: a red candle crashes, then the orange one climbs.
        dip: { theme: 'mine', run() {
            Arena.candle(false, -2.4); floatLabel('DIP!', '#ff5d73', V(-1.4, 2.4, 1), 3);
            setTimeout(() => { Arena.candle(true, 2.4); floatLabel('BUY THE DIP', '#f7931a', V(0, 2.8, 1), 4.5); }, 1300);
        } },
        // Not your keys: the wind blows the stack away.
        nokeys: { theme: 'pound', zoneTheme: true, run() {
            for (let i = 0; i < 6; i++) setTimeout(() => spawnNotes(V(-1 + Math.random() * 2, 0.3, 0.8), 40, { speed: 1, up: 2.5, life: 4, dir: V(6, 1, 1.5) }), i * 250);
            floatLabel('NOT YOUR KEYS', '#ff5d73', V(0, 2.4, 1.2), 4.5); emit('arena-paper');
        } },
    };
    const PAIRS = {
        'dollar|yuan': T('Trade war'), 'euro|pound': T('Brexit reloaded'), 'euro|rubel': T('Gas summit'), 'yen|yuan': T('Neighbours’ quarrel'),
        'dollar|sa': T('Fed against volcano'), 'dollar|euro': T('Transatlantic rate dispute'), 'dollar|pound': T('Special relationship, cancelled'),
        'afro|euro': T('Colonial repayment'), 'ozean|yuan': T('Iron ore poker'), 'rubel|yuan': T('Boundless friendship ends'),
        'euro|swiss': T('Bank secrecy under fire'), 'afro|yuan': T('Silk Road debt'), 'dollar|rubel': T('Cold rate war'),
    };
    Object.entries(THEMES).forEach(([k, v]) => { v.key = k; });
    // Generated art (OpenRouter / Gemini image): matte-painted backdrops, cut-out central banks and units, a banknote.
    const ART3 = true;
    // Decoded images by URL: a prepared battle builds its textures from memory, without a frame of empty stage.
    const IMG = {}; const IMGP = {};
    function loadImg(url) {
        if (!IMGP[url]) IMGP[url] = new Promise((done) => { const i = new Image(); i.crossOrigin = 'anonymous'; i.onload = () => { (i.decode ? i.decode() : Promise.resolve()).catch(() => {}).then(() => { IMG[url] = i; done(true); }); }; i.onerror = () => done(false); i.src = url; });
        return IMGP[url];
    }
    function texFrom(url) { if (IMG[url]) { const t = new THREE.Texture(IMG[url]); t.needsUpdate = true; return t; } return new THREE.TextureLoader().load(url); }
    function artTex(url, srgb = true) { const t = texFrom(url); if (srgb) t.encoding = THREE.sRGBEncoding; t.anisotropy = 8; return t; }
    // A soft round contact shadow for cut-out sprites.
    const BLOB = (() => { const c = document.createElement('canvas'); c.width = c.height = 128; const g = c.getContext('2d'); const gr = g.createRadialGradient(64, 64, 4, 64, 64, 62); gr.addColorStop(0, 'rgba(0,0,0,0.55)'); gr.addColorStop(1, 'rgba(0,0,0,0)'); g.fillStyle = gr; g.fillRect(0, 0, 128, 128); return new THREE.CanvasTexture(c); })();
    function blob(x, z, w, d) { const m = new THREE.Mesh(new THREE.PlaneGeometry(w, d), new THREE.MeshBasicMaterial({ map: BLOB, transparent: true, depthWrite: false })); m.rotation.x = -Math.PI / 2; m.position.set(x, 0.02, z); return m; }
    // The theme's matte painting on a curved screen behind the stage: parallax as the camera drifts.
    function backdrop(group, key) {
        // Portrait event sets: a taller canvas (gently stretched), the camera there stands further back and sees more height.
        const ev = key.startsWith('ev-'); const r = ev ? 22 : 44; const arc = ev ? 2.6 : 2.2; const h = r * arc * 9 / 21 * (ev && camera.aspect < 1 ? 1.45 : 1);
        const geo = new THREE.CylinderGeometry(r, r, h, 64, 1, true, Math.PI - arc / 2, arc);
        const m = new THREE.Mesh(geo, new THREE.MeshBasicMaterial({ map: artTex(`${ASSET}art/bd-${key}.jpg?v=1`), side: THREE.BackSide, fog: false, toneMapped: false, depthWrite: false }));
        m.position.set(0, h / 2 - h * (ev ? 0.5 : 0.12), ev ? 0 : -2); m.renderOrder = -1; group.add(m);
    }
    // The central bank as a cut-out: squash and stretch work on its group, the flag sits on the roof.
    function bankSprite(group, key, color) {
        const g = new THREE.Group(); g.position.set(0, 0, -5.2);
        const w = 5.3; const plane = new THREE.Mesh(new THREE.PlaneGeometry(w, w), new THREE.MeshBasicMaterial({ map: artTex(`${ASSET}art/bank-${key}.webp?v=1`), transparent: true, alphaTest: 0.08, toneMapped: false }));
        plane.position.y = w / 2 - 0.3; g.add(plane); group.add(blob(0, -4.9, 5.4, 2.2));
        const flag = mesh(new THREE.BoxGeometry(0.7, 0.42, 0.02), mat(color, { e: color, ei: 0.3 }), 0.4, w - 0.2, 0.1, false); g.add(flag);
        g.add(mesh(new THREE.CylinderGeometry(0.02, 0.02, 1.0, 6), mat('#bbbbbb'), 0, w - 0.45, 0.1, false));
        group.add(g);
        group.userData.bank = { group: g, flag, front: new THREE.Vector3(0, 1.2, -4.5), roof: new THREE.Vector3(0, w * 0.62, -5.0), width: 3.0 };
    }
    function themeFor(attZone, defZone, defMine, defSwiss) {
        const key = defMine ? 'mine' : defSwiss ? 'swiss' : defZone;
        return { key, theme: THEMES[key] ?? THEMES.dollar, title: PAIRS[[attZone, defZone].sort().join('|')] ?? null };
    }

    /* ---------- Textures: CC0 from Poly Haven (polyhaven.com), colour + normal map, cached ---------- */
    const loader = new THREE.TextureLoader(); const TEXC = {};
    function tex(url, rep = 1, srgb = true) {
        const k = url + '|' + rep; if (TEXC[k]) return TEXC[k];
        const t = texFrom(url); t.wrapS = t.wrapT = THREE.RepeatWrapping; t.repeat.set(rep, rep); t.anisotropy = 8;
        if (srgb) t.encoding = THREE.sRGBEncoding;
        return (TEXC[k] = t);
    }
    // A physically based material from one texture set; color tints it.
    function pbr(name, rep = 1, color = '#ffffff', o = {}) {
        return new THREE.MeshStandardMaterial({ map: tex(`${ASSET}t/${name}_d.jpg?v=2`, rep), normalMap: tex(`${ASSET}t/${name}_n.jpg?v=1`, rep, false), normalScale: new THREE.Vector2(o.n ?? 1, o.n ?? 1), color, roughness: o.r ?? 0.85, metalness: o.m ?? 0, emissive: o.e ?? 0x000000, emissiveIntensity: o.ei ?? 1 });
    }
    const PANOC = {};

    /* ---------- Renderer ---------- */
    let pmrem = null; let renderer = null; let scene = null; let camera = null; let raf = 0; let t0 = 0; let last = 0;
    let stage = null; let parts = null; let dice = []; const armies = { a: [], d: [] }; const fx = []; const anims = [];
    let shakeAmt = 0; let throwAnim = null; let cine = false;
    function init(canvas) {
        if (renderer) return true;
        try { renderer = new THREE.WebGLRenderer({ canvas, antialias: true }); } catch (e) { return false; }
        renderer.setPixelRatio(Math.min(2, devicePixelRatio));
        renderer.shadowMap.enabled = true; renderer.shadowMap.type = THREE.PCFSoftShadowMap;
        renderer.outputEncoding = THREE.sRGBEncoding;
        renderer.toneMapping = THREE.ACESFilmicToneMapping; renderer.toneMappingExposure = 1.05;
        pmrem = new THREE.PMREMGenerator(renderer);
        scene = new THREE.Scene();
        camera = new THREE.PerspectiveCamera(42, 16 / 9, 0.1, 200);
        return true;
    }
    function size() {
        const c = renderer.domElement; const w = c.clientWidth; const h = c.clientHeight; const pr = renderer.getPixelRatio();
        if (c.width !== Math.round(w * pr) || c.height !== Math.round(h * pr)) renderer.setSize(w, h, false);
        camera.aspect = w / Math.max(1, h); camera.updateProjectionMatrix();
    }
    function tween(dur, fn, done) { anims.push({ s: performance.now(), dur: REDUCED ? Math.min(dur, 200) : dur, fn, done }); }
    const mat = (color, o = {}) => new THREE.MeshStandardMaterial({ color, roughness: o.r ?? 0.7, metalness: o.m ?? 0.05, emissive: o.e ?? 0x000000, emissiveIntensity: o.ei ?? 1, transparent: !!o.t, opacity: o.op ?? 1 });
    function mesh(geo, m, x = 0, y = 0, z = 0, shadow = true) { const me = new THREE.Mesh(geo, m); me.position.set(x, y, z); me.castShadow = shadow; me.receiveShadow = true; return me; }
    function canvasTex(c) { const t = new THREE.CanvasTexture(c); t.encoding = THREE.sRGBEncoding; t.anisotropy = 4; return t; }
    function glyphTexture(text, color, px = 128, bg = null) {
        const c = document.createElement('canvas'); c.width = c.height = px; const g = c.getContext('2d');
        if (bg) { g.fillStyle = bg; g.beginPath(); g.arc(px / 2, px / 2, px / 2, 0, Math.PI * 2); g.fill(); g.strokeStyle = 'rgba(255,255,255,0.5)'; g.lineWidth = px * 0.04; g.beginPath(); g.arc(px / 2, px / 2, px * 0.42, 0, Math.PI * 2); g.stroke(); }
        g.fillStyle = color; g.font = `900 ${px * 0.62}px Unbounded, Arial, sans-serif`; g.textAlign = 'center'; g.textBaseline = 'middle'; g.fillText(text, px / 2, px / 2 + px * 0.04);
        return canvasTex(c);
    }
    function labelSprite(text, color = '#ffffff', w = 2.4, big = false) {
        const c = document.createElement('canvas'); c.width = 512; c.height = 96; const g = c.getContext('2d');
        g.fillStyle = big ? 'rgba(0,0,0,0)' : 'rgba(4,10,24,0.8)'; g.fillRect(0, 0, 512, 96);
        if (!big) { g.strokeStyle = color; g.lineWidth = 4; g.strokeRect(2, 2, 508, 92); }
        let fs = big ? 54 : 40; g.font = `900 ${fs}px Unbounded, Arial, sans-serif`;
        // Shrink the type until the text fits the label with a margin.
        while (fs > 16 && g.measureText(text).width > 470) { fs -= 2; g.font = `900 ${fs}px Unbounded, Arial, sans-serif`; }
        g.textAlign = 'center'; g.textBaseline = 'middle';
        if (big) { g.lineWidth = 10; g.strokeStyle = 'rgba(0,0,0,0.85)'; g.strokeText(text, 256, 50); g.fillStyle = color; } else g.fillStyle = '#fff';
        g.fillText(text, 256, 50);
        const s = new THREE.Sprite(new THREE.SpriteMaterial({ map: canvasTex(c), transparent: true, depthTest: !big, toneMapped: false })); s.scale.set(w, w * 96 / 512, 1); s.renderOrder = big ? 10 : 0; return s;
    }

    /* ---------- Banknotes: one instanced mesh, every note a little paper physics body ---------- */
    const NOTE_MAX = 900; let notes = null; const N = [];
    const dummy = new THREE.Object3D(); const white = new THREE.Color(1, 1, 1); const grey = new THREE.Color(0.38, 0.38, 0.4); const tmpC = new THREE.Color();
    function noteTexture(theme) {
        const c = document.createElement('canvas'); c.width = 256; c.height = 122; const g = c.getContext('2d');
        g.fillStyle = theme.note; g.fillRect(0, 0, 256, 122);
        g.strokeStyle = 'rgba(0,0,0,0.35)'; g.lineWidth = 6; g.strokeRect(5, 5, 246, 112);
        g.strokeStyle = 'rgba(255,255,255,0.35)'; g.lineWidth = 2; g.strokeRect(13, 13, 230, 96);
        for (let i = 0; i < 9; i++) { g.strokeStyle = `rgba(0,0,0,${0.05 + (i % 2) * 0.04})`; g.beginPath(); g.ellipse(128, 61, 30 + i * 9, 18 + i * 5, 0, 0, Math.PI * 2); g.stroke(); }
        g.fillStyle = 'rgba(255,255,255,0.55)'; g.beginPath(); g.arc(62, 61, 30, 0, Math.PI * 2); g.fill();
        g.fillStyle = 'rgba(0,0,0,0.7)'; g.textAlign = 'center'; g.textBaseline = 'middle';
        g.font = '900 30px Unbounded, Arial, sans-serif'; g.fillText(theme.cur, 62, 63);
        g.font = '900 24px Unbounded, Arial, sans-serif'; g.fillText('1.000.000', 172, 50);
        g.font = '700 16px Unbounded, Arial, sans-serif'; g.fillText('FIAT', 172, 82);
        return canvasTex(c);
    }
    function buildNotes(theme) {
        const m = ART3 ? new THREE.MeshStandardMaterial({ map: artTex(ASSET + 'art/note.jpg?v=1'), color: new THREE.Color(theme.note).lerp(new THREE.Color('#ffffff'), 0.55), side: THREE.DoubleSide, roughness: 0.85 }) : new THREE.MeshStandardMaterial({ map: noteTexture(theme), side: THREE.DoubleSide, roughness: 0.85 });
        notes = new THREE.InstancedMesh(new THREE.PlaneGeometry(0.46, 0.22), m, NOTE_MAX);
        notes.instanceMatrix.setUsage(THREE.DynamicDrawUsage);
        for (let i = 0; i < NOTE_MAX; i++) notes.setColorAt(i, white);
        notes.count = 0; notes.frustumCulled = false; scene.add(notes); N.length = 0;
    }
    // Spawns n notes at a point: speed is the sideways scatter, up the launch height, dir an optional push.
    function spawnNotes(at, n, { speed = 3, up = 4, life = 4.5, dir = null, spreadX = 0.2 } = {}) {
        if (!notes) return;
        for (let i = 0; i < n; i++) {
            if (N.length >= NOTE_MAX) N.shift();
            const v = new THREE.Vector3((R() - 0.5) * speed, up * (0.55 + R() * 0.7), (R() - 0.5) * speed);
            if (dir) v.add(dir.clone().multiplyScalar(0.6 + R() * 0.8));
            N.push({ p: at.clone().add(new THREE.Vector3((R() - 0.5) * spreadX, (R() - 0.5) * 0.2, (R() - 0.5) * 0.2)), v, r: new THREE.Euler(R() * 6, R() * 6, R() * 6), w: new THREE.Vector3((R() - 0.5) * 14, (R() - 0.5) * 14, (R() - 0.5) * 14), age: 0, life: life * (0.7 + R() * 0.6), seed: R() * 10 });
        }
    }
    function updateNotes(dt) {
        if (!notes) return;
        for (let i = N.length - 1; i >= 0; i--) {
            const n = N[i]; n.age += dt;
            if (n.age > n.life) { N.splice(i, 1); continue; }
            if (n.p.y > 0.02) {
                n.v.y -= 3.4 * dt; n.v.multiplyScalar(1 - Math.min(0.9, 1.5 * dt));
                n.p.x += (n.v.x + Math.sin(n.age * 5 + n.seed) * 0.7) * dt; n.p.y += n.v.y * dt; n.p.z += (n.v.z + Math.cos(n.age * 4 + n.seed) * 0.4) * dt;
                n.r.x += n.w.x * dt; n.r.y += n.w.y * dt; n.r.z += n.w.z * dt;
            } else { n.p.y = 0.02 + (i % 7) * 0.002; n.r.x = -Math.PI / 2; n.r.y = 0; }
        }
        N.forEach((n, i) => {
            const fade = Math.min(1, (n.life - n.age) / 0.6);
            dummy.position.copy(n.p); dummy.rotation.copy(n.r); dummy.scale.setScalar(Math.max(0.001, fade)); dummy.updateMatrix();
            notes.setMatrixAt(i, dummy.matrix);
            // Inflation in one picture: the longer a note exists, the less colour (purchasing power) it keeps.
            notes.setColorAt(i, tmpC.copy(white).lerp(grey, Math.min(1, n.age / n.life * 1.4)));
        });
        notes.count = N.length; notes.instanceMatrix.needsUpdate = true; if (notes.instanceColor) notes.instanceColor.needsUpdate = true;
    }

    /* ---------- Props ---------- */
    function bank(group, color, name, x = 0, z = -4.2, s = 1) {
        const g = new THREE.Group(); g.position.set(x, 0, z); g.scale.setScalar(s);
        const stone = pbr('marble_01', 1, '#ffffff', { r: 0.35 });
        g.add(mesh(new THREE.BoxGeometry(3.4, 0.25, 1.8), stone, 0, 0.125, 0));
        g.add(mesh(new THREE.BoxGeometry(3.1, 0.2, 1.6), stone, 0, 0.35, 0));
        for (let i = 0; i < 6; i++) g.add(mesh(new THREE.CylinderGeometry(0.11, 0.13, 1.6, 12), stone, -1.25 + i * 0.5, 1.25, 0.55));
        g.add(mesh(new THREE.BoxGeometry(3.0, 1.5, 1.0), pbr('marble_01', 1, '#ddd3bf', { r: 0.4 }), 0, 1.2, -0.2));
        // Windows stuffed with cash: they glow money-green.
        for (let i = 0; i < 5; i++) g.add(mesh(new THREE.BoxGeometry(0.28, 0.5, 0.02), mat('#8fbf6a', { e: '#5f9f3a', ei: 0.5 }), -1.0 + i * 0.5, 1.15, 0.31, false));
        g.add(mesh(new THREE.BoxGeometry(3.3, 0.22, 1.7), stone, 0, 2.15, 0));
        const roof = mesh(new THREE.CylinderGeometry(0.01, 1.0, 3.5, 3, 1), stone, 0, 2.65, 0); roof.rotation.z = Math.PI / 2; roof.rotation.y = Math.PI / 2; roof.scale.set(1, 1, 0.55); g.add(roof);
        const flag = mesh(new THREE.BoxGeometry(0.7, 0.42, 0.02), mat(color, { e: color, ei: 0.25 }), 0.4, 3.75, 0); g.add(flag);
        g.add(mesh(new THREE.CylinderGeometry(0.02, 0.02, 1.2, 6), mat('#999'), 0, 3.45, 0));
        // The name sits on a plaque before the steps, so it never runs into the screen title above.
        const lbl = labelSprite(name, color, 2.2); lbl.position.set(0, 0.42, 1.25); g.add(lbl);
        group.add(g);
        group.userData.bank = { group: g, flag, front: new THREE.Vector3(x, 1.2 * s, z + 0.7 * s), roof: new THREE.Vector3(x, 2.9 * s, z), width: 2.6 * s };
        return g;
    }
    function tree(group, x, z, kind = 'pine', s = 1) {
        const g = new THREE.Group(); g.position.set(x, 0, z); g.scale.setScalar(s);
        g.add(mesh(new THREE.CylinderGeometry(0.06, 0.09, 0.6, 6), mat('#5a3b22'), 0, 0.3, 0));
        if (kind === 'pine') { g.add(mesh(new THREE.ConeGeometry(0.4, 0.9, 7), mat('#2f5a3a'), 0, 0.95, 0)); g.add(mesh(new THREE.ConeGeometry(0.3, 0.7, 7), mat('#3c6e47'), 0, 1.35, 0)); }
        if (kind === 'palm') { const t = mesh(new THREE.CylinderGeometry(0.05, 0.08, 1.6, 6), mat('#8a6a3a'), 0, 0.8, 0); t.rotation.z = 0.15; g.add(t); for (let i = 0; i < 6; i++) { const l = mesh(new THREE.BoxGeometry(0.9, 0.03, 0.18), mat('#3f8a3f'), Math.cos(i) * 0.35, 1.6, Math.sin(i) * 0.35); l.rotation.y = i; l.rotation.z = -0.4; g.add(l); } }
        if (kind === 'baobab') { g.children[0].scale.set(3, 2, 3); g.add(mesh(new THREE.SphereGeometry(0.7, 8, 6), mat('#4f6b2a'), 0, 1.5, 0)); g.children[1].scale.y = 0.4; }
        if (kind === 'blossom') g.add(mesh(new THREE.SphereGeometry(0.55, 10, 8), mat('#f5a9c8'), 0, 1.0, 0));
        if (kind === 'birch') { g.children[0].material = mat('#eeeeee'); g.children[0].scale.set(0.8, 2.2, 0.8); g.add(mesh(new THREE.SphereGeometry(0.35, 8, 6), mat('#c9a646'), 0, 1.35, 0)); }
        group.add(g);
    }
    function mountain(group, x, z, r, h, color, snow = true) {
        group.add(mesh(new THREE.ConeGeometry(r, h, 9), pbr('rock_face', 2, color, { r: 0.95 }), x, h / 2, z, false));
        if (snow) group.add(mesh(new THREE.ConeGeometry(r * 0.38, h * 0.38, 7), mat('#ffffff', { r: 0.5 }), x, h * 0.81, z, false));
    }
    const PROPS = {
        // The bank itself prints: notes stream from its doors, no extra machine on stage.
        fedPrinterOnly(g) {
            g.userData.printer = new THREE.Vector3(0, 1.0, -4.3);
        },
        fed(g) {
            bank(g, '#2f62d8', 'FED · NEW YORK');
            for (let i = 0; i < 7; i++) g.add(mesh(new THREE.BoxGeometry(0.8, 2 + (i * 1.7) % 4, 0.8), pbr('brushed_concrete', 1, '#9db0d6', { e: '#ffd28a', ei: 0.04 }), -6 + i * 2, 1 + ((i * 1.7) % 4) / 2, -7.5));
            const printer = new THREE.Group(); printer.position.set(3.6, 0, -2.4); printer.rotation.y = -0.5;
            printer.add(mesh(new THREE.BoxGeometry(1.4, 0.9, 0.9), pbr('metal_plate', 1, '#7f9a7f', { m: 0.7, r: 0.4 }), 0, 0.45, 0));
            for (let i = 0; i < 3; i++) { const r = mesh(new THREE.CylinderGeometry(0.12, 0.12, 1.0, 12), mat('#2a2a2a', { m: 0.8 }), -0.4 + i * 0.4, 0.95, 0); r.rotation.x = Math.PI / 2; r.userData.spinX = 9; printer.add(r); }
            const lbl = labelSprite('BRRRR', '#7fe0a6', 1.2); lbl.position.set(0, 1.7, 0); printer.add(lbl); g.add(printer);
            g.userData.printer = new THREE.Vector3(3.2, 1.0, -1.9);
        },
        ezb(g) {
            bank(g, '#2d8bd6', tr('ECB · FRANKFURT'), -2.4, -4.4, 0.8);
            const tower = new THREE.Group(); tower.position.set(2.8, 0, -5);
            tower.add(mesh(new THREE.BoxGeometry(1.2, 6, 1.2), pbr('metal_plate', 2, '#8fb3e0', { m: 0.8, r: 0.25, e: '#8fc6ff', ei: 0.08 }), 0, 3, 0));
            tower.add(mesh(new THREE.BoxGeometry(1.0, 5.4, 1.0), pbr('metal_plate', 2, '#6a8cb8', { m: 0.8, r: 0.25 }), 0.9, 2.7, 0.3));
            const euro = mesh(new THREE.TorusGeometry(0.5, 0.08, 8, 24, Math.PI * 1.6), mat('#ffd76b', { e: '#ffb300', ei: 0.6 }), 0, 6.8, 0.6); euro.rotation.z = 0.6; euro.userData.spinY = 1; tower.add(euro); g.add(tower);
        },
        london(g) {
            bank(g, '#7b45d6', 'BANK OF ENGLAND', -1.2, -4.4, 0.85);
            const ben = new THREE.Group(); ben.position.set(3.6, 0, -4.6);
            ben.add(mesh(new THREE.BoxGeometry(0.9, 4.2, 0.9), pbr('marble_01', 1, '#d8c48f'), 0, 2.1, 0));
            ben.add(mesh(new THREE.BoxGeometry(1.05, 1.0, 1.05), pbr('marble_01', 1, '#e0cf9c'), 0, 4.5, 0));
            ben.add(mesh(new THREE.CircleGeometry(0.36, 24), mat('#fff8e0', { e: '#fff3c0', ei: 0.4 }), 0, 4.5, 0.53, false));
            ben.add(mesh(new THREE.ConeGeometry(0.7, 1.4, 4), mat('#3d4a3a'), 0, 5.7, 0));
            g.add(ben);
        },
        vault(g) {
            mountain(g, -5, -7, 2.6, 5.5, '#7a8aa3'); mountain(g, -1, -8, 3.2, 7, '#8494ad'); mountain(g, 4.5, -7.5, 2.8, 6, '#7a8aa3');
            const door = new THREE.Group(); door.position.set(0, 1.6, -3.6);
            const disc = mesh(new THREE.CylinderGeometry(1.5, 1.5, 0.4, 40), pbr('metal_plate', 1, '#c9d0da', { m: 0.95, r: 0.25 }), 0, 0, 0); disc.rotation.x = Math.PI / 2; door.add(disc);
            const wheel = new THREE.Group(); wheel.position.z = 0.25;
            for (let i = 0; i < 3; i++) { const sp = mesh(new THREE.BoxGeometry(2.0, 0.12, 0.12), mat('#e9edf4', { m: 0.9, r: 0.2 }), 0, 0, 0); sp.rotation.z = (i * Math.PI) / 3; wheel.add(sp); }
            wheel.userData.spinZ = 0.4; door.add(wheel);
            const cross = new THREE.Group(); cross.position.set(0, 2.2, 0);
            cross.add(mesh(new THREE.BoxGeometry(0.9, 0.9, 0.08), mat('#d63a3a'), 0, 0, 0)); cross.add(mesh(new THREE.BoxGeometry(0.55, 0.16, 0.1), mat('#fff'), 0, 0, 0.02)); cross.add(mesh(new THREE.BoxGeometry(0.16, 0.55, 0.1), mat('#fff'), 0, 0, 0.02));
            door.add(cross); g.add(door);
            for (let i = 0; i < 6; i++) g.add(mesh(new THREE.BoxGeometry(0.5, 0.2, 0.25), mat('#f2c94c', { m: 1, r: 0.25 }), 2.2 + (i % 3) * 0.55, 0.1 + Math.floor(i / 3) * 0.2, -2.6));
            const lbl = labelSprite(tr('SNB · VAULT'), '#d63a3a', 2.4); lbl.position.set(0, 4.6, -3.6); g.add(lbl);
            g.userData.bank = { group: door, flag: cross.children[0], front: new THREE.Vector3(0, 1.6, -3.2), roof: new THREE.Vector3(0, 3.2, -3.6), width: 2.4 };
        },
        kremlin(g) {
            bank(g, '#e07b22', 'BANK OF RUSSIA', -2.6, -4.6, 0.75);
            const cols = ['#d63a3a', '#2f62d8', '#3c9c40', '#e6c22c'];
            for (let i = 0; i < 4; i++) {
                const x = 1.2 + i * 1.2; const h = 1.8 + (i % 2) * 0.9;
                g.add(mesh(new THREE.CylinderGeometry(0.35, 0.35, h, 10), pbr('marble_01', 1, '#f2ebdb'), x, h / 2, -5));
                const dome = mesh(new THREE.SphereGeometry(0.48, 12, 10), mat(cols[i], { r: 0.4 }), x, h + 0.35, -5); dome.scale.y = 1.2; g.add(dome);
                g.add(mesh(new THREE.ConeGeometry(0.25, 0.6, 10), mat(cols[i]), x, h + 1.0, -5));
            }
            for (let i = 0; i < 6; i++) tree(g, -6 + i * 0.7, -2 - (i % 2) * 1.5, 'birch', 0.9);
        },
        pagoda(g) {
            bank(g, '#c42a2a', tr('PBOC · BEIJING'), 2.8, -4.6, 0.75);
            const p = new THREE.Group(); p.position.set(-2.6, 0, -5);
            for (let i = 0; i < 5; i++) { const w = 2.2 - i * 0.35; p.add(mesh(new THREE.BoxGeometry(w * 0.7, 0.7, w * 0.7), pbr('marble_01', 1, '#c0392b', { r: 0.6 }), 0, 0.35 + i * 0.95, 0)); const r = mesh(new THREE.ConeGeometry(w * 0.75, 0.45, 4), pbr('red_slate_roof_tiles_01', 1, '#5a5a5a', { r: 0.7 }), 0, 0.92 + i * 0.95, 0); r.rotation.y = Math.PI / 4; p.add(r); }
            g.add(p);
            for (let i = 0; i < 6; i++) { const l = mesh(new THREE.SphereGeometry(0.22, 12, 10), new THREE.MeshBasicMaterial({ color: '#ff2a1a', toneMapped: false }), -4 + i * 1.6, 3.2, -2.5, false); l.scale.y = 1.25; l.userData.bob = i; g.add(l); }
            for (let i = 0; i < 9; i++) g.add(mesh(new THREE.BoxGeometry(1.0, 0.8, 0.6), mat('#8a7a6a'), -8 + i * 2, 0.4, -8.5));
        },
        fuji(g) {
            mountain(g, 0, -10, 5, 6, '#4a5b8a');
            bank(g, '#e6c22c', tr('BOJ · TOKYO'), 3.2, -4.4, 0.7);
            const torii = new THREE.Group(); torii.position.set(-2.8, 0, -3.6); const red = mat('#d63a2a');
            torii.add(mesh(new THREE.CylinderGeometry(0.1, 0.12, 2.2, 10), red, -0.8, 1.1, 0)); torii.add(mesh(new THREE.CylinderGeometry(0.1, 0.12, 2.2, 10), red, 0.8, 1.1, 0));
            torii.add(mesh(new THREE.BoxGeometry(2.4, 0.18, 0.25), red, 0, 2.25, 0)); torii.add(mesh(new THREE.BoxGeometry(2.0, 0.12, 0.18), red, 0, 1.8, 0));
            g.add(torii);
            for (let i = 0; i < 5; i++) tree(g, -6 + i * 3, -6 + (i % 2), 'blossom', 1.1);
            const zero = labelSprite(tr('RATE 0.00 %'), '#e6c22c', 1.8); zero.position.set(-2.8, 3.2, -3.6); g.add(zero);
        },
        savanna(g) {
            bank(g, '#17a39a', tr('AFRICAN CENTRAL BANK'), 0, -4.6, 0.75);
            for (let i = 0; i < 4; i++) tree(g, -6 + i * 4, -6.5 + (i % 2) * 1.2, 'baobab', 1.2);
            g.add(mesh(new THREE.SphereGeometry(2, 24, 16), new THREE.MeshBasicMaterial({ color: '#ffb35a', toneMapped: false }), -4, 4, -14, false));
        },
        volcano(g) {
            g.add(mesh(new THREE.ConeGeometry(3.2, 4, 10, 1, true), mat('#3a2a26', { r: 1 }), -3, 2, -7, false));
            g.add(mesh(new THREE.CylinderGeometry(0.9, 0.9, 0.2, 16), mat('#ff5a1a', { e: '#ff3a00', ei: 2 }), -3, 3.95, -7, false));
            const lamp = new THREE.PointLight('#ff6a1a', 2.2, 12); lamp.position.set(-3, 4.6, -7); g.add(lamp);
            bank(g, '#3c9c40', 'BANCO CENTRAL', 3, -4.6, 0.7);
            for (let i = 0; i < 5; i++) tree(g, -6 + i * 3, -3 - (i % 2) * 1.5, 'palm', 1);
            const vb = labelSprite('VOLCANO BONDS', '#ff7a2a', 2.0); vb.position.set(-3, 5.4, -7); g.add(vb);
        },
        beach(g) {
            const sea = mesh(new THREE.PlaneGeometry(40, 12), mat('#2a8ac4', { r: 0.2, m: 0.3 }), 0, 0.02, -9, false); sea.rotation.x = -Math.PI / 2; g.add(sea);
            bank(g, '#9b3fc6', 'RBA · SYDNEY', -2.6, -4.6, 0.7);
            const opera = new THREE.Group(); opera.position.set(3, 0, -5);
            for (let i = 0; i < 3; i++) { const s = mesh(new THREE.SphereGeometry(0.9 - i * 0.15, 12, 8, 0, Math.PI, 0, Math.PI / 2), mat('#f4f4f0', { r: 0.3 }), i * 0.7, 0, 0); s.rotation.y = -0.6; s.scale.set(0.7, 1.5, 1); opera.add(s); }
            g.add(opera);
            for (let i = 0; i < 4; i++) tree(g, -6 + i * 4, -2.6, 'palm', 1.1);
        },
        farm(g) {
            for (let r = 0; r < 3; r++) for (let i = 0; i < 6; i++) {
                const rack = new THREE.Group(); rack.position.set(-5 + i * 2, 0, -4 - r * 1.8);
                rack.add(mesh(new THREE.BoxGeometry(1.4, 1.6, 0.7), pbr('metal_plate', 1, '#5a6684', { m: 0.8, r: 0.35 }), 0, 0.8, 0));
                for (let k = 0; k < 4; k++) { const led = mesh(new THREE.BoxGeometry(1.2, 0.05, 0.02), mat('#f7931a', { e: '#f7931a', ei: 1.5 }), 0, 0.3 + k * 0.35, 0.36, false); led.userData.blink = R() * 6; rack.add(led); }
                g.add(rack);
            }
            const lbl = labelSprite('HASHRATE ▲', '#f7931a', 2.2); lbl.position.set(0, 3.4, -6); g.add(lbl);
        },
    };

    /* ---------- Figures ---------- */
    function figure(kind, color) {
        const g = new THREE.Group();
        const body = mat(color, { r: 0.55 });
        if (kind === 'asic') {
            g.add(mesh(new THREE.BoxGeometry(0.42, 0.34, 0.32), pbr('metal_plate', 1, '#8aa6d6', { m: 0.8, r: 0.3 }), 0, 0.2, 0));
            for (let k = 0; k < 3; k++) { const led = mesh(new THREE.BoxGeometry(0.36, 0.03, 0.02), mat(color, { e: color, ei: 1.4 }), 0, 0.1 + k * 0.09, 0.17, false); led.userData.blink = R() * 6; g.add(led); }
            const fan = mesh(new THREE.CylinderGeometry(0.11, 0.11, 0.03, 12), mat('#222'), 0, 0.2, -0.17); fan.rotation.x = Math.PI / 2; fan.userData.spinZ = 12; g.add(fan);
        } else {
            g.add(mesh(new THREE.CylinderGeometry(0.11, 0.15, 0.42, 10), body, 0, 0.23, 0));
            g.add(mesh(new THREE.SphereGeometry(0.11, 12, 10), mat('#f1c9a5', { r: 0.6 }), 0, 0.55, 0));
            g.add(mesh(new THREE.CylinderGeometry(0.115, 0.115, 0.06, 10), body, 0, 0.64, 0));
            if (kind === 'maxi') {
                const eye = mat('#ff2020', { e: '#ff0000', ei: 3 });
                g.add(mesh(new THREE.SphereGeometry(0.022, 8, 6), eye, -0.04, 0.56, 0.1, false)); g.add(mesh(new THREE.SphereGeometry(0.022, 8, 6), eye, 0.04, 0.56, 0.1, false));
                const laser = new THREE.MeshBasicMaterial({ color: '#ff3030', transparent: true, opacity: 0.6 });
                [-0.04, 0.04].forEach((x) => { const l = new THREE.Mesh(new THREE.CylinderGeometry(0.008, 0.008, 2.2, 6), laser); l.rotation.x = Math.PI / 2; l.position.set(x, 0.56, 1.2); g.add(l); });
            } else {
                const sign = new THREE.Mesh(new THREE.PlaneGeometry(0.16, 0.16), new THREE.MeshBasicMaterial({ map: glyphTexture('$', '#0b2a18', 64), color: '#7fe0a6' }));
                sign.position.set(0, 0.26, 0.16); g.add(sign);
            }
        }
        return g;
    }
    // Plebs wear their faction's uniform; Maxis and ASICs look the same on every side.
    function unitSprite(kind, color, por) {
        const g = new THREE.Group();
        const src = kind === 'pleb' && por ? `${ASSET}art/sol-${por}.webp?v=1` : `${ASSET}art/unit-${kind}.webp?v=1`;
        const sp = new THREE.Sprite(new THREE.SpriteMaterial({ map: artTex(src), toneMapped: false, alphaTest: 0.1 }));
        const h = kind === 'asic' ? 0.78 : 1.0; sp.center.set(0.5, 0.02); sp.scale.set(h * 0.66, h, 1); g.add(sp);
        const ring = new THREE.Mesh(new THREE.CircleGeometry(0.27, 28), new THREE.MeshBasicMaterial({ color, transparent: true, opacity: 0.55, toneMapped: false }));
        const edge = new THREE.Mesh(new THREE.RingGeometry(0.24, 0.3, 28), new THREE.MeshBasicMaterial({ color, toneMapped: false })); edge.rotation.x = -Math.PI / 2; edge.position.y = 0.035; g.add(edge);
        ring.rotation.x = -Math.PI / 2; ring.position.y = 0.03; g.add(ring);
        g.add(blob(0, 0, 0.6, 0.35));
        return g;
    }
    function buildArmy(side, units, color, por) {
        const kinds = [...Array(Math.min(units.asic, 4)).fill('asic'), ...Array(Math.min(units.maxi, 4)).fill('maxi'), ...Array(Math.min(units.pleb, 18)).fill('pleb')].slice(0, 22);
        const dir = side === 'a' ? -1 : 1;
        // A block formation: a small army stands in a few short rows, not in one file (which reads as a pillar from the front).
        const cols = Math.max(2, Math.min(6, Math.ceil(Math.sqrt(kinds.length * 1.6))));
        return kinds.map((k, i) => {
            const f = ART3 ? unitSprite(k, color, por) : figure(k, color);
            const row = Math.floor(i / cols); const col = i % cols;
            f.position.set(dir * (2.5 + row * 0.62), 0, (col - (cols - 1) / 2) * 0.56 + (row % 2) * 0.18);
            f.rotation.y = dir * -Math.PI / 2 * 0.85;
            f.userData.base = f.position.clone(); f.userData.kind = k;
            scene.add(f); return f;
        });
    }

    /* ---------- Dice ---------- */
    const PIP = { 1: [[0.5, 0.5]], 2: [[0.27, 0.27], [0.73, 0.73]], 3: [[0.27, 0.27], [0.5, 0.5], [0.73, 0.73]], 4: [[0.27, 0.27], [0.73, 0.27], [0.27, 0.73], [0.73, 0.73]], 5: [[0.27, 0.27], [0.73, 0.27], [0.5, 0.5], [0.27, 0.73], [0.73, 0.73]], 6: [[0.27, 0.25], [0.73, 0.25], [0.27, 0.5], [0.73, 0.5], [0.27, 0.75], [0.73, 0.75]] };
    const texCache = {};
    function dieTex(v, base, pip) {
        const k = v + base; if (texCache[k]) return texCache[k];
        const c = document.createElement('canvas'); c.width = c.height = 128; const g = c.getContext('2d');
        const photo = IMG[base === '#f7931a' ? ASSET + 'art/dice-orange.jpg?v=1' : ASSET + 'art/dice-white.jpg?v=1'];
        if (photo) g.drawImage(photo, 0, 0, 128, 128); else { g.fillStyle = base; g.fillRect(0, 0, 128, 128); }
        const gr = g.createRadialGradient(40, 36, 6, 64, 64, 96); gr.addColorStop(0, 'rgba(255,255,255,0.45)'); gr.addColorStop(0.45, 'rgba(255,255,255,0)'); gr.addColorStop(1, 'rgba(0,0,0,0.28)');
        g.fillStyle = gr; g.fillRect(0, 0, 128, 128);
        // Bevelled edge: light top-left, dark bottom-right, so the cube reads rounded.
        g.lineWidth = 7; g.strokeStyle = 'rgba(255,255,255,0.35)'; g.beginPath(); g.moveTo(3, 125); g.lineTo(3, 3); g.lineTo(125, 3); g.stroke();
        g.strokeStyle = 'rgba(0,0,0,0.35)'; g.beginPath(); g.moveTo(125, 3); g.lineTo(125, 125); g.lineTo(3, 125); g.stroke();
        PIP[v].forEach(([x, y]) => { g.beginPath(); g.arc(x * 128, y * 128, 11, 0, Math.PI * 2); g.fillStyle = pip; g.fill(); g.beginPath(); g.arc(x * 128 - 3, y * 128 - 3, 4, 0, Math.PI * 2); g.fillStyle = 'rgba(255,255,255,0.18)'; g.fill(); });
        texCache[k] = canvasTex(c); return texCache[k];
    }
    // Material order of a box: +X, -X, +Y, -Y, +Z, -Z. Opposite faces add up to 7.
    const FACE_VALUES = [3, 4, 1, 6, 2, 5];
    const UP_ROT = { 1: [0, 0], 6: [Math.PI, 0], 3: [0, Math.PI / 2], 4: [0, -Math.PI / 2], 2: [-Math.PI / 2, 0], 5: [Math.PI / 2, 0] };
    function makeDie(attacker) {
        const base = attacker ? '#f7931a' : '#e8eef8'; const pip = attacker ? '#2a1400' : '#0b1530';
        const m = new THREE.Mesh(new THREE.BoxGeometry(0.62, 0.62, 0.62), FACE_VALUES.map((v) => new THREE.MeshPhysicalMaterial({ map: dieTex(v, base, pip), roughness: 0.3, metalness: 0.02, clearcoat: 1, clearcoatRoughness: 0.12 })));
        m.castShadow = true; return m;
    }

    /* ---------- Particles ---------- */
    function buildParticles(p) {
        const n = p.mode === 'rain' ? 500 : 180;
        const geo = new THREE.BufferGeometry(); const pos = new Float32Array(n * 3);
        for (let i = 0; i < n; i++) { pos[i * 3] = (R() - 0.5) * 22; pos[i * 3 + 1] = R() * 9; pos[i * 3 + 2] = (R() - 0.5) * 14 - 2; }
        geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
        const sizePx = p.mode === 'rain' ? 0.18 : p.mode === 'dust' ? 0.08 : 0.32;
        const pts = new THREE.Points(geo, new THREE.PointsMaterial({ map: glyphTexture(p.glyph, p.color, 64), size: sizePx, transparent: true, depthWrite: false, opacity: 0.9 }));
        pts.userData.mode = p.mode; return pts;
    }
    function sparks(at, color, n = 26, power = 3) {
        const geo = new THREE.BufferGeometry(); const pos = new Float32Array(n * 3); const vel = [];
        for (let i = 0; i < n; i++) { pos.set([at.x, at.y + 0.3, at.z], i * 3); vel.push(new THREE.Vector3((R() - 0.5) * power, R() * power, (R() - 0.5) * power)); }
        geo.setAttribute('position', new THREE.BufferAttribute(pos, 3));
        const pts = new THREE.Points(geo, new THREE.PointsMaterial({ color, size: 0.12, transparent: true })); pts.userData = { vel, life: 1 };
        scene.add(pts); fx.push(pts);
    }
    // Portrait screens are narrow: wide captions move towards the middle and shrink, so they stay on screen.
    const portrait = () => camera && camera.aspect < 1;
    function floatLabel(text, color, at, w = 3.2) {
        if (portrait()) { w *= 0.62; at = at.clone(); at.x *= 0.35; }
        const s = labelSprite(text, color, w, true); s.position.copy(at); scene.add(s);
        const y0 = at.y;
        tween(3600, (p) => { s.position.y = y0 + p * 0.6; s.material.opacity = p < 0.75 ? 1 : 1 - (p - 0.75) / 0.25; s.scale.set(w * (0.6 + Math.min(1, p * 5) * 0.4), w * 96 / 512 * (0.6 + Math.min(1, p * 5) * 0.4), 1); }, () => scene.remove(s));
    }

    /* ---------- Loop ---------- */
    function frame(now) {
        const t = (now - t0) / 1000; const dt = Math.min(0.05, (now - last) / 1000 || 0.016); last = now;
        size();
        const ang = REDUCED ? 0 : Math.sin(t * 0.18) * 0.18;
        // Portrait: pull back so both armies fit; an event scene has no armies, so it may come closer.
        const dist = camera.aspect < 1 ? 10.5 / Math.max(0.45, camera.aspect) * (cine ? 0.62 : 0.86) : 10.5;
        // Event scenes have no armies on the floor: the camera sits lower and looks up into the painted backdrop.
        camera.position.set(Math.sin(ang) * dist, (cine ? 3.2 : 5.6) + (dist - 10.5) * 0.3, Math.cos(ang) * dist);
        if (shakeAmt > 0.002) { camera.position.x += (R() - 0.5) * shakeAmt; camera.position.y += (R() - 0.5) * shakeAmt; shakeAmt *= 0.9; }
        camera.lookAt(0, cine ? (camera.aspect < 1 ? 3.0 : 2.7) : 1.6, -1.2);
        scene.traverse((o) => {
            const u = o.userData;
            if (u.spinX) o.rotation.x += u.spinX * dt;
            if (u.spinY) o.rotation.y += u.spinY * dt;
            if (u.spinZ) o.rotation.z += u.spinZ * dt;
            if (u.blink !== undefined && o.material) o.material.emissiveIntensity = 0.6 + Math.abs(Math.sin(t * 3 + u.blink)) * 1.6;
            if (u.bob !== undefined) o.position.y = 3.2 + Math.sin(t * 1.4 + u.bob) * 0.18;
        });
        // Fiat never stops: the bank oozes notes, the printer goes brrrr.
        const bk = stage?.userData.bank;
        if (bk && !REDUCED && R() < dt * 5) spawnNotes(bk.front.clone().add(new THREE.Vector3((R() - 0.5) * bk.width, 0, 0)), 1, { speed: 0.8, up: 1.2, life: 6, dir: new THREE.Vector3(0, 0, 1.4) });
        const pr = stage?.userData.printer;
        if (pr && !REDUCED && R() < dt * 9) spawnNotes(pr, 1, { speed: 0.6, up: 2.2, life: 5, dir: new THREE.Vector3(-1.2, 0.4, 1.0) });
        if (parts && !REDUCED) {
            const a = parts.geometry.attributes.position; const mode = parts.userData.mode;
            for (let i = 0; i < a.count; i++) {
                let x = a.getX(i); let y = a.getY(i); let z = a.getZ(i);
                if (mode === 'rise') { y += 0.02; if (y > 9) y = 0; }
                else if (mode === 'rain') { y -= 0.22; x -= 0.03; if (y < 0) { y = 9; x = (R() - 0.5) * 22; } }
                else if (mode === 'ember') { y += 0.025; x += Math.sin(t + i) * 0.01; if (y > 9) { y = 2; x = -3 + (R() - 0.5) * 2; z = -7; } }
                else { y -= mode === 'dust' ? 0.004 : 0.022; x += Math.sin(t * 0.8 + i) * 0.008; if (y < 0) y = 9; }
                a.setXYZ(i, x, y, z);
            }
            a.needsUpdate = true;
        }
        if (throwAnim) throwAnim(now);
        for (let i = anims.length - 1; i >= 0; i--) { const an = anims[i]; const p = Math.min(1, (now - an.s) / an.dur); an.fn(p); if (p >= 1) { anims.splice(i, 1); if (an.done) an.done(); } }
        updateNotes(dt);
        for (let i = fx.length - 1; i >= 0; i--) {
            const p = fx[i]; const a = p.geometry.attributes.position; p.userData.life -= dt * 1.3;
            p.userData.vel.forEach((v, k) => { v.y -= 6 * dt; a.setXYZ(k, a.getX(k) + v.x * dt, Math.max(0.02, a.getY(k) + v.y * dt), a.getZ(k) + v.z * dt); });
            a.needsUpdate = true; p.material.opacity = Math.max(0, p.userData.life);
            if (p.userData.life <= 0) { scene.remove(p); p.geometry.dispose(); fx.splice(i, 1); }
        }
        renderer.render(scene, camera);
        raf = requestAnimationFrame(frame);
    }

    /* ---------- API ---------- */
    // Everything a stage of this theme shows, so it can be fetched before the stage opens.
    function urlsFor(key) {
        const th = THEMES[key] ?? THEMES.dollar; const pbrSet = (n) => [`${ASSET}t/${n}_d.jpg?v=2`, `${ASSET}t/${n}_n.jpg?v=1`];
        return [`${ASSET}art/bd-${key}.jpg?v=1`, `${ASSET}art/bank-${key}.webp?v=1`, ASSET + 'art/note.jpg?v=1', ASSET + 'art/unit-pleb.webp?v=1', ASSET + 'art/unit-maxi.webp?v=1', ASSET + 'art/unit-asic.webp?v=1', ASSET + 'art/candle-up.webp?v=1', ASSET + 'art/candle-down.webp?v=1', ASSET + 'art/dice-orange.jpg?v=1', ASSET + 'art/dice-white.jpg?v=1', ASSET + 'art/rubble-marble.webp?v=1', ASSET + 'art/rubble-modern.webp?v=1', ASSET + 'art/rubble-marble-plain.webp?v=1', ASSET + 'art/rubble-modern-plain.webp?v=1', ...['fed', 'ezb', 'goldbug', 'shit', 'no'].map((k) => `${ASSET}art/fall-${k}.webp?v=1`), ...Object.entries(EVENTS).filter(([, v]) => v.theme === key && !v.zoneTheme).map(([k]) => `${ASSET}art/bd-ev-${k}.jpg?v=1`), ...['you', 'fed', 'ezb', 'goldbug', 'shit', 'no'].map((k) => `${ASSET}art/sol-${k}.webp?v=1`),
            `${ASSET}sky/${th.pano}.jpg?v=1`, ...pbrSet(th.floor), ...pbrSet('velour_velvet'), ...pbrSet('wood_table_worn'), ...(th.props === 'fed' ? pbrSet('metal_plate') : [])];
    }
    const READY = {};
    function prepare(key, onProgress) {
        const urls = urlsFor(key); let n = 0;
        return Promise.all(urls.map((u) => loadImg(u).then(() => { n++; if (onProgress) onProgress(n / urls.length); }))).then(() => { READY[key] = true; });
    }
    window.Arena = {
        isReady: (key) => !!READY[key], prepare,
        eventKey: (kind, o = {}) => { const sc = EVENTS[kind]; return sc ? (o.zone && sc.zoneTheme && THEMES[o.zone] ? o.zone : sc.theme) : 'dollar'; },
        // Quietly fetch every stage in the background, one after another.
        async preloadAll() { for (const k of Object.keys(THEMES)) { if (!READY[k]) { await prepare(k); await new Promise((r) => setTimeout(r, 120)); } } },
        themeFor,
        open(canvas, opts) {
            if (!init(canvas)) return false;
            cine = opts.table === false;
            size(); // the camera knows the screen shape before any scene places its captions
            cancelAnimationFrame(raf); throwAnim = null; anims.length = 0;
            while (scene.children.length) scene.remove(scene.children[0]);
            dice = []; armies.a = []; armies.d = []; fx.length = 0; shakeAmt = 0;
            const { theme } = opts;
            const sky = document.createElement('canvas'); sky.width = 4; sky.height = 256; const sg = sky.getContext('2d');
            const gr = sg.createLinearGradient(0, 0, 0, 256); gr.addColorStop(0, theme.sky[0]); gr.addColorStop(1, theme.sky[1]); sg.fillStyle = gr; sg.fillRect(0, 0, 4, 256);
            scene.background = canvasTex(sky); scene.environment = null;
            scene.fog = new THREE.Fog(theme.fog, 20, 60); scene.userData.noPano = false;
            const applyPano = (t) => {
                if (scene.userData.theme !== theme) return;
                scene.environment = PANOC[theme.pano].env;
                // An event set keeps its dark backdrop and has no fog; the panorama only lights it.
                if (scene.userData.noPano) return;
                scene.background = t; if (scene.fog) scene.fog.color.copy(PANOC[theme.pano].horizon);
            };
            scene.userData.theme = theme;
            if (theme.pano) {
                if (PANOC[theme.pano]?.env) applyPano(PANOC[theme.pano].tex);
                else {
                    const url = `${ASSET}sky/${theme.pano}.jpg?v=1`;
                    const done = (t) => {
                    t.mapping = THREE.EquirectangularReflectionMapping; t.encoding = THREE.sRGBEncoding;
                    // Fog takes the colour of the panorama's horizon, so the floor melts into the photo.
                    const c = document.createElement('canvas'); c.width = 64; c.height = 32; const g = c.getContext('2d'); g.drawImage(t.image, 0, 0, 64, 32);
                    const d = g.getImageData(0, 15, 64, 2).data; let r = 0; let gg = 0; let b = 0; for (let i = 0; i < d.length; i += 4) { r += d[i]; gg += d[i + 1]; b += d[i + 2]; }
                    const n = d.length / 4; const horizon = new THREE.Color(r / n / 255, gg / n / 255, b / n / 255).convertSRGBToLinear();
                    PANOC[theme.pano] = { tex: t, env: pmrem.fromEquirectangular(t).texture, horizon };
                    applyPano(t);
                    };
                    if (IMG[url]) { const t = new THREE.Texture(IMG[url]); t.needsUpdate = true; done(t); } else loader.load(url, done);
                }
            }
            scene.add(new THREE.HemisphereLight(theme.sky[1], theme.ground, 0.75));
            const sun = new THREE.DirectionalLight(theme.light, 1.15); sun.position.set(-6, 10, 6); sun.castShadow = true;
            sun.shadow.mapSize.set(2048, 2048); sun.shadow.bias = -0.0004; Object.assign(sun.shadow.camera, { left: -10, right: 10, top: 10, bottom: -10 }); scene.add(sun);
            const ground = mesh(new THREE.CircleGeometry(ART3 ? 43 : 60, 64), theme.floor ? pbr(theme.floor, 26, '#' + new THREE.Color(theme.ground).lerp(new THREE.Color('#ffffff'), 0.72).getHexString(), { r: 0.95 }) : mat(theme.ground, { r: 0.95 }), 0, 0, -2, false); ground.rotation.x = -Math.PI / 2; scene.add(ground);
            if (opts.table !== false) {
                scene.add(mesh(new THREE.CylinderGeometry(1.75, 1.85, 0.12, 48), pbr('velour_velvet', 3, '#3fa86e', { r: 1 }), 0, 0.06, 0));
                const rim = mesh(new THREE.TorusGeometry(1.8, 0.09, 12, 64), pbr('wood_table_worn', 2, '#d9a066', { r: 0.45 }), 0, 0.12, 0); rim.rotation.x = Math.PI / 2; scene.add(rim);
            }
            stage = new THREE.Group(); scene.add(stage);
            if (ART3) {
                backdrop(stage, opts.bd ?? theme.key);
                bankSprite(stage, theme.key, opts.def ? opts.def.color : '#ffffff');
                // The painting is the whole set: the bank stays only as an invisible anchor for the effects.
                if (opts.bd) { stage.userData.bank.group.visible = false; ground.visible = false; scene.fog = null; scene.userData.noPano = true; scene.background = new THREE.Color('#05070d'); stage.children.forEach((ch) => { if (ch.material?.map === BLOB) ch.visible = false; }); }
                if (theme.props === 'fed') { (PROPS.fedPrinterOnly)(stage); }
            } else (PROPS[theme.props] ?? PROPS.fed)(stage);
            parts = buildParticles(theme.particle); scene.add(parts);
            buildNotes(theme);
            if (opts.att) armies.a = buildArmy('a', opts.att.units, opts.att.color, opts.att.por);
            if (opts.def) armies.d = buildArmy('d', opts.def.units, opts.def.color, opts.def.por);
            // Opening shot: a first gush of notes, so the stage is alive from the first frame.
            const bk = stage.userData.bank; if (bk) spawnNotes(bk.roof, 40, { speed: 3, up: 4, life: 5 });
            t0 = performance.now(); last = t0; raf = requestAnimationFrame(frame);
            return true;
        },
        // Throw: attacker dice from the left, defender dice from the right; resolves when all have landed.
        throw(att, def, fast = false) {
            return new Promise((done) => {
                dice.forEach((d) => scene.remove(d)); dice = [];
                const items = [];
                const add = (vals, attacker) => vals.forEach((v, i) => {
                    const m = makeDie(attacker); scene.add(m); dice.push(m);
                    const from = new THREE.Vector3(attacker ? -3.4 : 3.4, 2.6 + R(), 1.4 + i * 0.3);
                    const to = new THREE.Vector3((attacker ? -0.9 : 0.55) + i * (attacker ? 0.64 : 0.68), 0.43, attacker ? -0.4 + (i % 2) * 0.2 : 0.6 + (i % 2) * 0.15);
                    const [rx, rz] = UP_ROT[v];
                    const qEnd = new THREE.Quaternion().setFromEuler(new THREE.Euler(rx, 0, rz)).premultiply(new THREE.Quaternion().setFromAxisAngle(new THREE.Vector3(0, 1, 0), (R() - 0.5) * 0.6));
                    items.push({ m, from, to, qEnd, axis: new THREE.Vector3(R() - 0.5, R() - 0.5, R() - 0.5).normalize(), spin: 14 + R() * 8, delay: i * 70 + (attacker ? 0 : 120) });
                });
                add(att, true); add(def, false);
                const dur = REDUCED ? 1 : fast ? 650 : 1250; const start = performance.now();
                throwAnim = (now) => {
                    let all = true;
                    items.forEach((it) => {
                        const p = Math.min(1, Math.max(0, (now - start - it.delay) / dur));
                        if (p < 1) all = false;
                        const x = it.from.x + (it.to.x - it.from.x) * (1 - Math.pow(1 - p, 2));
                        const z = it.from.z + (it.to.z - it.from.z) * p;
                        // Three bounces of shrinking height.
                        const b = p < 0.45 ? 1 - Math.pow(p / 0.45, 2) : p < 0.75 ? Math.sin(((p - 0.45) / 0.3) * Math.PI) * 0.35 : p < 0.92 ? Math.sin(((p - 0.75) / 0.17) * Math.PI) * 0.1 : 0;
                        it.m.position.set(x, it.to.y + (p < 0.45 ? (it.from.y - it.to.y) * b : b * 1.6), z);
                        if (p < 0.8) it.m.quaternion.setFromAxisAngle(it.axis, it.spin * (1 - p) * (1 - p) * 3);
                        else it.m.quaternion.slerp(it.qEnd, Math.min(1, (p - 0.8) / 0.2 + 0.05));
                        if (p >= 1 && !it.done) { it.done = true; it.m.quaternion.copy(it.qEnd); emit('arena-land'); }
                    });
                    if (all) { throwAnim = null; done(); }
                };
            });
        },
        dimDice(i) { const d = dice[i]; if (d) { d.material.forEach((m) => m.color.setRGB(0.3, 0.3, 0.32)); const y = d.position.y; tween(300, (p) => { d.position.y = y - p * 0.1; }); } },
        casualties(side, n) {
            const list = armies[side]; const dir = side === 'a' ? -1 : 1;
            for (let k = 0; k < n && list.length; k++) {
                const f = list.pop();
                sparks(f.position, side === 'a' ? '#f7931a' : '#9cc7ff');
                const vx = dir * (1.5 + R()); const vy = 2.5 + R();
                const x0 = f.position.x; const y0 = f.position.y;
                tween(900, (p) => { const tt = p * 0.9; f.position.x = x0 + vx * tt; f.position.y = Math.max(0, y0 + vy * tt - 5 * tt * tt); f.rotation.z = dir * p * 4; f.scale.setScalar(1 - p * 0.5); }, () => scene.remove(f));
            }
        },
        charge(side) {
            const list = armies[side]; const dir = side === 'a' ? 1 : -1;
            tween(520, (p) => { const s = Math.sin(p * Math.PI); list.forEach((f) => { f.position.x = f.userData.base.x + dir * s * 0.6; f.position.y = Math.abs(Math.sin(p * Math.PI * 4)) * 0.1; }); });
        },
        // A hit: the defender's bank bursts at the seams and spits a geyser of notes.
        inflation(strength = 1) {
            const bk = stage?.userData.bank; if (!bk) return;
            shakeAmt = Math.max(shakeAmt, 0.12 * strength);
            spawnNotes(bk.roof, Math.round(60 * strength), { speed: 4 + strength, up: 6 + strength * 1.5, life: 5 });
            spawnNotes(bk.front, Math.round(30 * strength), { speed: 2, up: 2, life: 5, dir: new THREE.Vector3(0, 0, 3), spreadX: bk.width });
            const g = bk.group; const s0 = g.scale.x;
            tween(500, (p) => { const k = Math.sin(p * Math.PI * 3) * (1 - p) * 0.12; g.scale.set(s0 * (1 + k), s0 * (1 - k * 0.6), s0 * (1 + k)); }, () => g.scale.setScalar(s0));
            floatLabel(`INFLATION +${Math.round(10 + strength * 20 + R() * 15)} %`, '#7fe0a6', bk.front.clone().add(new THREE.Vector3(0, 0.2, 1.4)));
            emit('arena-paper');
        },
        // Laser eyes: every maxi of the attacker fires at the defending army.
        laser() {
            const maxis = armies.a.filter((f) => f.userData.kind === 'maxi'); if (!maxis.length) return false;
            const target = armies.d.length ? armies.d[Math.floor(R() * armies.d.length)].position.clone().add(new THREE.Vector3(0, 0.4, 0)) : new THREE.Vector3(3, 0.5, 0);
            maxis.forEach((f) => {
                const from = f.position.clone().add(new THREE.Vector3(0, 0.56, 0)); const len = from.distanceTo(target);
                const beam = new THREE.Mesh(new THREE.CylinderGeometry(0.035, 0.035, len, 8), new THREE.MeshBasicMaterial({ color: '#ff2a2a', transparent: true, opacity: 1 }));
                beam.position.copy(from).lerp(target, 0.5); beam.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), target.clone().sub(from).normalize()); scene.add(beam);
                tween(500, (p) => { beam.material.opacity = 1 - p; beam.scale.x = beam.scale.z = 1 + Math.sin(p * Math.PI) * 1.5; }, () => scene.remove(beam));
            });
            sparks(target, '#ff3030', 30, 4); emit('arena-laser');
            return true;
        },
        // Number go up (orange) or rekt (red): a candle behind the attacker.
        candle(up, x = -5.8) {
            const g = new THREE.Group(); g.position.set(x, 0, -1.6); scene.add(g);
            const color = up ? '#f7931a' : '#ff3b4f';
            const url = `${ASSET}art/candle-${up ? 'up' : 'down'}.webp?v=1`; const ar = IMG[url] ? IMG[url].width / IMG[url].height : 0.3;
            const body = new THREE.Sprite(new THREE.SpriteMaterial({ map: artTex(url), toneMapped: false, transparent: true })); body.center.set(0.5, 0); g.add(body);
            const wick = new THREE.Object3D();
            const glow = new THREE.PointLight(color, 2.2, 7); glow.position.set(0, 1.5, 0.6); g.add(glow);
            const H = up ? 4.2 : 0.4;
            const lbl = labelSprite(up ? 'NUMBER GO UP' : 'REKT', color, portrait() ? 1.5 : 2.4, true); scene.add(lbl); const lx = portrait() ? x * 0.45 : x;
            tween(650, (p) => {
                const e = 1 - Math.pow(1 - p, 3); const h = up ? 0.05 + e * H : 4 - e * (4 - H);
                body.scale.set(Math.max(0.4, h) * ar, Math.max(0.4, h), 1); glow.position.y = h * 0.6;
                lbl.position.set(lx, h + 0.8, -1.6);
            }, () => tween(3000, (p) => { g.scale.setScalar(1 - Math.max(0, p - 0.6) / 0.4 * 0.99); lbl.material.opacity = 1 - Math.max(0, p - 0.6) / 0.4; }, () => { scene.remove(g); scene.remove(lbl); }));
            emit(up ? 'arena-up' : 'arena-down');
        },
        // Conquest: a giant ₿ coin falls from the sky, crushes the bank, a shockwave blows all the notes away.
        // Conquest: the winner's emblem falls from the sky (a ₿ coin, a money printer, a gold bar …), crushes the bank, notes fly.
        finale(color, por = 'you', tag = 'TOPPLED') {
            return new Promise((done) => {
                const bk = stage?.userData.bank;
                const at = bk ? bk.roof.clone() : new THREE.Vector3(0, 0.4, -3);
                const face = new THREE.MeshStandardMaterial({ map: glyphTexture('₿', '#ffffff', 256, '#f7931a'), metalness: 0.6, roughness: 0.3 });
                const btc = !ART3 || por === 'you';
                const coin = btc ? new THREE.Mesh(new THREE.CylinderGeometry(0.95, 0.95, 0.2, 48), [new THREE.MeshStandardMaterial({ color: '#d9861a', metalness: 0.9, roughness: 0.25 }), face, face])
                    : new THREE.Sprite(new THREE.SpriteMaterial({ map: artTex(`${ASSET}art/fall-${por}.webp?v=1`), toneMapped: false }));
                if (!btc) coin.scale.set(2.4, 2.4, 1);
                if (btc) coin.rotation.x = Math.PI / 2; coin.castShadow = true; coin.position.set(at.x, at.y + 14, at.z + 0.4); scene.add(coin);
                emit('arena-fall');
                tween(REDUCED ? 100 : 700, (p) => { coin.position.y = at.y + 14 - p * p * 13.85; if (btc) coin.rotation.z = p * 9; else coin.material.rotation = Math.sin(p * 9) * 0.4; }, () => {
                    shakeAmt = 0.9; emit('arena-impact');
                    spawnNotes(at.clone().add(new THREE.Vector3(0, 0.4, 0)), 380, { speed: 11, up: 7, life: 5.5, spreadX: 1.5 });
                    const ring = mesh(new THREE.RingGeometry(0.6, 1.0, 48), new THREE.MeshBasicMaterial({ color: '#ffd08a', transparent: true, side: THREE.DoubleSide }), at.x, 0.06, at.z + 0.5, false);
                    ring.rotation.x = -Math.PI / 2; scene.add(ring);
                    tween(800, (p) => { ring.scale.setScalar(1 + p * 12); ring.material.opacity = 1 - p; }, () => scene.remove(ring));
                    if (bk) {
                        const g = bk.group; const s0 = g.scale.x;
                        tween(600, (p) => { const sq = p < 0.3 ? 1 - p / 0.3 * 0.55 : 0.45 + Math.sin((p - 0.3) / 0.7 * Math.PI) * 0.08; g.scale.set(s0 * (1 + (1 - sq) * 0.35), s0 * sq, s0 * (1 + (1 - sq) * 0.35)); });
                        bk.flag.material.color.set(color); bk.flag.material.emissive = new THREE.Color(color);
                        if (ART3) setTimeout(() => {
                            const modern = ['euro', 'ozean', 'mine'].includes(scene.userData.theme?.key);
                            const w = 4.6; const rub = new THREE.Mesh(new THREE.PlaneGeometry(w, w), new THREE.MeshBasicMaterial({ map: artTex(`${ASSET}art/rubble-${modern ? 'modern' : 'marble'}${por === 'you' ? '' : '-plain'}.webp?v=1`), transparent: true, alphaTest: 0.08, toneMapped: false }));
                            rub.position.set(g.position.x, w / 2 - 0.5, g.position.z + 0.3); scene.add(rub);
                            const flagY = bk.flag.position.y;
                            tween(380, (p) => { rub.scale.setScalar(0.6 + p * 0.4); g.children.forEach((ch) => { if (ch.material && ch !== bk.flag) { ch.material.transparent = true; ch.material.opacity = 1 - p; } }); bk.flag.position.y = flagY - p * (flagY - 1.6); });
                            sparks(new THREE.Vector3(g.position.x, 1, g.position.z + 0.5), '#d8d2c4', 30, 4);
                        }, 520);
                    }
                    floatLabel(tag, por === 'you' ? '#ffb54d' : color, new THREE.Vector3(at.x, 1.0, at.z + 2.2), 4);
                    tween(260, (p) => { coin.position.y = at.y + 0.15 + Math.sin(p * Math.PI) * 0.4; });
                    armies.a.forEach((f, i) => tween(900, (p) => { f.position.y = Math.abs(Math.sin((p * 3 + i * 0.13) * Math.PI)) * 0.35; }));
                    armies.d.forEach((f) => sparks(f.position, '#9cc7ff', 10));
                    Arena.casualties('d', armies.d.length);
                    setTimeout(done, REDUCED ? 600 : 2800);
                });
            });
        },
        // An event card as a short 3D scene; resolves when it is over (about 3 s).
        event(canvas, kind, o = {}) {
            const sc = EVENTS[kind]; if (!sc) return null;
            if (!this.open(canvas, { theme: THEMES[o.zone && sc.zoneTheme ? o.zone : sc.theme], table: false, bd: sc.zoneTheme ? undefined : `ev-${kind}` })) return null;
            return new Promise((done) => { sc.run(o); setTimeout(done, REDUCED ? 1500 : sc.dur ?? 5500); });
        },
        close() { cancelAnimationFrame(raf); throwAnim = null; anims.length = 0; N.length = 0; },
    };
    const Arena = window.Arena;
})();
