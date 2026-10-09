/**
 * The table of a live Hyperbitcoinization match (plan "Hyperbitcoinization", P2): the prototype v21's board,
 * HUD, 3D battles, event scenes and banners, driven by the server.
 *
 * The browser decides nothing. A click becomes an intent (`POST …/act`), offered only where the server's
 * `legal` (the snapshot of the seat to move) allows it; a refusal shows the server's message. Whatever
 * happens comes back as events (the action's answer, `hyper.updated` for everyone, the catch-up endpoint
 * after a gap) and is animated exactly as they say: the dice, the losses, the conquests, the cards. PlySync
 * keeps every ply shown once; the mirror writes each event into the table while it is shown, and after each
 * batch the page loads the snapshot again, so the table always ends where the server is.
 *
 * Spectators (`me` null) see the same table, read-only. Bot turns play no soundboard clips (audio.js).
 *
 * The replay (P3, `config.replay`, driven by replay.js) is a spectator's table whose plies come from the
 * replayed log instead of the server's push: every hand is open in the roster, jump() puts the table at
 * any ply. After a match the end screen offers a rematch (P3): each yes and the new match's url arrive as
 * `hyper.rematch` (onRematch()), and the page goes to the new match.
 */
import { ADJ, CARDS, FACTIONS, HOW, OCEANS, PIN_AT, SEA, SWISS_ZOOM, TDEF, ZONES, ZT } from './data.js';
import { applyEvent, banksOf, defenseBonus, fromSnapshot, odds, territoriesOf, units, zoneOwner } from './mirror.js';
import { PlySync } from './sync.js';
import { fmt, t } from './i18n.js';
import { readableMs } from './statsPlan.js';
import { lagging, movesOnByItself, quietBattle, tapGraceMs } from './pace.js';
import { AUD, ctx, cue, hoverTick, setAudioHooks, setIntensity, sfx, startMusic } from './audio.js';
import { readSettings, writeSettings } from './sounds.js';

const REDUCED = matchMedia('(prefers-reduced-motion: reduce)').matches;
const $ = (s) => document.querySelector(s);
const $$ = (s) => [...document.querySelectorAll(s)];
const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const b = (text) => `<b>${esc(text)}</b>`;
/** The 3D scenes need WebGL; without it (an old device, a headless browser) battles use the flat dice and cards a banner, with no failed context logged. */
const WEBGL = (() => { try { const c = document.createElement('canvas'); return !!(c.getContext('webgl2') || c.getContext('webgl')); } catch { return false; } })();

/** Plies further behind than this are not replayed: the page loads the snapshot instead. */
const CATCHUP_MAX = 80;
const SETTINGS_KEY = 'hb-settings';

let CFG = null;
let NET = null;
let A = '/hyper/';
let S = null;
let G = null;
let ME = null;
let sync = null;
let latest = null;
let ended = false;
let clockOffset = 0;
const live = { seat: null, deadline: null };
const connected = new Set();
const queue = [];
let running = false;
let settleTimer = 0;
let catching = null;
const TICKER = [];
/** The last plies whose events were shown, in order: a ply twice would be an animation shown twice. */
const SHOWN = [];
const UI = { scenes: true, unit: 'pleb', qty: '1', from: null, card: null, mode: 'owner', speed: 1, busy: false, hover: null };
const hooks = { onEmote: null, onEnd: null, onJump: null, onEndedAt: null };

/* ================= World geometry ================= */
let T = [];
let BY = {};
let SWISS_T = '';

function buildWorld() {
    T = TDEF.map(([id, name, zone, f = '']) => {
        const m = window.HB_MAP.terr[id];
        const [x, y] = PIN_AT[id] ?? [m.cx, m.cy];

        return { id, name, zone, bank: f.includes('b'), mine: f.includes('m'), x, y, cx: m.cx, cy: m.cy, d: m.d, path: new Path2D(m.d) };
    });
    BY = Object.fromEntries(T.map((x) => [x.id, x]));
    SWISS_T = `translate(${BY.zuerich.cx},${BY.zuerich.cy}) scale(${SWISS_ZOOM}) translate(${-BY.zuerich.cx},${-BY.zuerich.cy})`;
    BY.zuerich.x = BY.zuerich.cx; BY.zuerich.y = BY.zuerich.cy;
}
const tn = (id) => t(BY[id]?.name ?? id);

/* ================= Seats ================= */
const seat = (i) => (i === null || i === undefined ? null : G.seats[i] ?? null);
const fac = (i) => FACTIONS[seat(i)?.faction] ?? FACTIONS.bitcoiner;
const colorOf = (i) => (i === null || i === undefined ? '#5b6680' : fac(i).color);
const porOf = (i) => (i === null || i === undefined ? 'pleb' : fac(i).por);
const isMe = (i) => ME !== null && i === ME;
const isBot = (i) => !!seat(i)?.bot;
function nameOf(i) {
    const s = seat(i);
    if (!s) return t('neutral');
    const base = s.name ?? t(fac(i).name);

    return s.userId !== null && s.bot ? t(':name (bot)', { name: base }) : base;
}
const mySeat = () => (ME === null ? null : seat(ME));
/** A team match (P4): the two sides with their clans (HyperTeams), from the snapshot; null without teams. */
const sides = () => CFG?.snapshot?.teams ?? null;
const teamOf = (i) => seat(i)?.team ?? null;
const clanName = (team) => sides()?.[team]?.name ?? t('Team :number', { number: (team ?? 0) + 1 });
function clanLogo(team, size = 28) {
    const side = sides()?.[team];
    if (side?.logo) return `<img class="clan-logo" src="${esc(side.logo)}" alt="" width="${size}" height="${size}" style="width:${size}px;height:${size}px" data-clan-logo>`;

    return `<span class="clan-logo clan-tag" style="width:${size}px;height:${size}px">${esc(side?.tag ?? '🤖')}</span>`;
}
const playing = () => ME !== null && !!mySeat() && !mySeat().bot && !mySeat().left;
/** The server already gave this player the turn and its clock runs, while the page may still show earlier seats. */
const myClockRuns = () => playing() && isMe(live.seat) && !G.over;
/**
 * Catching up to a turn whose clock already runs (user 2026-10-09: the 90 s ran out while the page still played the
 * bots' moves): the other seats' events land at once, without arrows, banners or scenes, so the player can move.
 */
let rushing = false;
const myTurn = () => playing() && !G.over && G.cur === ME;
const idle = () => !running && queue.length === 0 && !UI.busy && S && S.ply === sync.ply && G.ply === S.ply;
/** What the server allows right now: the snapshot's `legal`, only while the page shows exactly that snapshot. */
const legalNow = () => (myTurn() && idle() && S.legal ? S.legal : null);

/* ================= Board: one canvas raster, moved by the zoom ================= */
let board; let bctx; let base; let g0; let RES = 2;
let PATHS; let landPath;
const TER = { land: null, sea: null, ocean: null };
let zt = null; let fitMode = 'meet'; let baseKey = ''; let lastHl = {};
const shade = (hex, f) => { const n = parseInt(hex.slice(1), 16); const ch = (s) => Math.max(0, Math.min(255, Math.round(((n >> s) & 255) * f))); return '#' + [ch(16), ch(8), ch(0)].map((v) => v.toString(16).padStart(2, '0')).join(''); };

function initBoard() {
    board = $('#board'); bctx = board.getContext('2d');
    RES = Math.min(2.5, Math.max(1.5, devicePixelRatio * 1.25));
    base = document.createElement('canvas'); base.width = 1600 * RES; base.height = 860 * RES; g0 = base.getContext('2d');
    PATHS = { neutral: new Path2D(window.HB_MAP.neutral), borders: new Path2D(window.HB_MAP.borders), sphere: new Path2D(window.HB_MAP.sphere), grat: new Path2D(window.HB_MAP.graticule) };
    landPath = new Path2D(); landPath.addPath(PATHS.neutral); T.forEach((x) => landPath.addPath(x.path));
    zt = window.d3.zoomIdentity;
    // The painted ocean, tiled.
    const i = new Image();
    i.onload = () => { const c = document.createElement('canvas'); c.width = c.height = 400; c.getContext('2d').drawImage(i, 0, 0, 400, 400); TER.ocean = c.getContext('2d').createPattern(c, 'repeat'); renderBase(true); };
    i.src = A + 'art/map-sea.jpg?v=1';
}
function viewMatrix() {
    const w = board.clientWidth; const h = board.clientHeight;
    const s = (fitMode === 'slice' ? Math.max : Math.min)(w / 1600, h / 860);

    return { s, ox: (w - 1600 * s) / 2, oy: (h - 860 * s) / 2, w, h };
}
function sizeBoard(v) {
    const dpr = devicePixelRatio || 1;
    if (board.width !== Math.round(v.w * dpr) || board.height !== Math.round(v.h * dpr)) { board.width = Math.round(v.w * dpr); board.height = Math.round(v.h * dpr); }
    bctx.setTransform(1, 0, 0, 1, 0, 0); bctx.clearRect(0, 0, board.width, board.height);
    bctx.setTransform(dpr * v.s * zt.k, 0, 0, dpr * v.s * zt.k, dpr * (v.ox + v.s * zt.x), dpr * (v.oy + v.s * zt.y));
}
function drawBoard() {
    const v = viewMatrix(); sizeBoard(v);
    bctx.imageSmoothingQuality = 'high';
    bctx.drawImage(base, 0, 0, 1600, 860);
}
function crisp() { const v = viewMatrix(); sizeBoard(v); drawScene(bctx, lastHl); }
function highlightState() {
    const st = {}; const L = legalNow();
    if (!L) return st;
    T.forEach((x) => {
        let glow;
        if (UI.card) glow = (L.cards[UI.card] ?? []).includes(x.id);
        else if (G.phase === 'buy') glow = L.deploy.includes(x.id);
        else if (G.phase === 'attack') glow = UI.from ? (x.id === UI.from || (L.attack[UI.from] ?? []).includes(x.id)) : x.id in L.attack;
        else glow = Object.keys(L.fortify).length === 0 ? true : UI.from ? (x.id === UI.from || (L.fortify[UI.from] ?? []).includes(x.id)) : x.id in L.fortify;
        st[x.id] = glow ? 1 : 0;
    });

    return st;
}
// Political colours: your land in full colour, everyone else a little muted.
function ownerFill(o) { if (o === null) return '#4a5470'; return isMe(o) ? shade(colorOf(o), 1.08) : shade(colorOf(o), 0.95); }
function renderBase(force = false) {
    if (!G) return;
    const hl = highlightState();
    const key = UI.mode + '|' + T.map((x) => (G.terr[x.id]?.owner ?? 'n') + (hl[x.id] ?? 2) + (G.terr[x.id]?.shield !== null ? 's' : '')).join('') + (TER.ocean ? 'O' : '');
    if (!force && key === baseKey) return;
    baseKey = key; lastHl = hl;
    g0.setTransform(RES, 0, 0, RES, 0, 0); g0.clearRect(0, 0, 1600, 860);
    drawScene(g0, hl);
    if (zt.k > 1.05) crisp(); else drawBoard();
}
const PLATE_AT = { dollar: [215, 300], sa: [640, 650], pound: [640, 228], euro: [622, 172], swiss: [660, 300], rubel: [1150, 118], yuan: [1135, 462], yen: [1452, 222], afro: [680, 470], ozean: [1395, 700] };
function drawScene(g, hl) {
    const own = (id) => G.terr[id]?.owner ?? null;
    const og = g.createRadialGradient(800, 380, 80, 800, 430, 900); og.addColorStop(0, '#143569'); og.addColorStop(0.6, '#0a1d42'); og.addColorStop(1, '#05112a');
    g.fillStyle = og; g.fill(PATHS.sphere);
    if (TER.ocean) { g.save(); g.clip(PATHS.sphere); g.globalAlpha = 0.3; g.globalCompositeOperation = 'screen'; g.fillStyle = TER.ocean; g.fillRect(0, 0, 1600, 860); g.restore(); }
    g.strokeStyle = 'rgba(120,160,230,0.11)'; g.lineWidth = 0.7; g.stroke(PATHS.grat);
    g.strokeStyle = 'rgba(120,170,255,0.3)'; g.lineWidth = 1.2; g.stroke(PATHS.sphere);
    g.font = 'italic 600 13px "Chakra Petch", sans-serif'; g.fillStyle = 'rgba(150,190,255,0.42)'; g.textAlign = 'center';
    window.HB_MAP.oceans.forEach(([n, x, y]) => { g.save(); g.letterSpacing = '4px'; g.fillText(t(OCEANS[n] ?? n), x, y); g.restore(); });
    g.save(); g.translate(2, 7); g.filter = 'blur(4px)'; g.fillStyle = 'rgba(1,4,12,0.8)'; g.fill(landPath); g.restore();
    g.fillStyle = '#26324f'; g.fill(PATHS.neutral);
    T.forEach((x) => {
        if (x.id === 'zuerich') return;
        const col = UI.mode === 'owner' ? ownerFill(own(x.id)) : ZONES[x.zone].color;
        const h = hl[x.id]; g.fillStyle = h === 0 ? shade(col, UI.mode === 'owner' ? 0.8 : 0.62) : h === 1 ? shade(col, 1.12) : col; g.fill(x.path);
    });
    const sh = g.createLinearGradient(0, 0, 0, 860); sh.addColorStop(0, 'rgba(255,255,255,0.10)'); sh.addColorStop(1, 'rgba(0,0,0,0.16)');
    g.save(); g.clip(landPath); g.fillStyle = sh; g.fillRect(0, 0, 1600, 860); g.restore();
    g.strokeStyle = 'rgba(4,10,24,0.35)'; g.lineWidth = 0.45; g.stroke(PATHS.borders);
    T.forEach((x) => { if (x.id !== 'zuerich') { g.strokeStyle = 'rgba(4,10,24,0.9)'; g.lineWidth = 0.9; g.stroke(x.path); } });
    if (UI.mode === 'owner') T.forEach((x) => { if (x.id === 'zuerich') return; g.save(); g.clip(x.path); g.strokeStyle = ZONES[x.zone].color; g.lineWidth = 3.2; g.globalAlpha = 0.9; g.stroke(x.path); g.restore(); });
    // Your land: a white edge and an orange glow, readable at a glance in both views.
    T.forEach((x) => { if (x.id === 'zuerich' || ME === null || own(x.id) !== ME) return; g.save(); g.shadowColor = 'rgba(255,170,60,0.95)'; g.shadowBlur = 9; g.strokeStyle = '#ffffff'; g.lineWidth = 1.5; g.stroke(x.path); g.restore(); });
    if (UI.mode === 'zone') T.forEach((x) => { if (x.id === 'zuerich') return; const o = own(x.id); g.save(); g.clip(x.path); g.strokeStyle = o === null ? '#5b6680' : colorOf(o); g.lineWidth = 6; g.globalAlpha = 0.95; g.stroke(x.path); g.restore(); });
    // Sea routes.
    g.save(); g.setLineDash([5, 6]); g.strokeStyle = 'rgba(170,205,255,0.55)'; g.lineWidth = 1.3;
    const seen = new Set();
    Object.entries(ADJ).forEach(([a, set]) => set.forEach((bb) => {
        const k = [a, bb].sort().join('|'); if (seen.has(k) || !SEA.has(k)) return; seen.add(k);
        const P1 = BY[a]; const P2 = BY[bb]; g.beginPath();
        if (k === 'alaska|kamtschatka') { g.moveTo(P1.x, P1.y); g.quadraticCurveTo(150, 120, 40, 130); g.moveTo(1560, 130); g.quadraticCurveTo(1450, 120, BY.kamtschatka.x, BY.kamtschatka.y); } else { const mx = (P1.x + P2.x) / 2; const my = (P1.y + P2.y) / 2 - Math.hypot(P1.x - P2.x, P1.y - P2.y) * 0.18; g.moveTo(P1.x, P1.y); g.quadraticCurveTo(mx, my, P2.x, P2.y); }
        g.stroke();
    }));
    g.restore();
    // Zone plates with the central bank, like the plebs' map.
    Object.entries(ZONES).forEach(([z, v]) => {
        const [x, y] = PLATE_AT[z]; const name = t(v.name); const bank = t(v.bank); const w = Math.max(name.length * 7.6, bank.length * 5.4) + 30;
        g.save(); g.translate(x, y);
        g.beginPath(); g.moveTo(-w / 2 + 7, -15); g.lineTo(w / 2, -15); g.lineTo(w / 2, 8); g.lineTo(w / 2 - 7, 15); g.lineTo(-w / 2, 15); g.lineTo(-w / 2, -8); g.closePath();
        g.fillStyle = 'rgba(5,12,28,0.78)'; g.fill(); g.strokeStyle = v.color; g.lineWidth = 1.4; g.stroke();
        const o = zoneOwner(G, z); if (o !== null) { g.fillStyle = colorOf(o); g.fillRect(-w / 2, -15, 4, 30); }
        g.fillStyle = '#fff'; g.font = '700 9px Unbounded, sans-serif'; g.textAlign = 'center'; g.fillText(name.toUpperCase(), 0, -2);
        g.fillStyle = 'rgba(255,255,255,0.75)'; g.font = '600 8px "Chakra Petch", sans-serif'; g.fillText(bank, 0, 10);
        g.restore();
    });
    // Switzerland: the enlarged vault, flag-red with a white cross.
    const z = BY.zuerich; const zo = own('zuerich'); const zh = hl.zuerich;
    g.save(); g.translate(z.cx, z.cy); g.scale(SWISS_ZOOM, SWISS_ZOOM); g.translate(-z.cx, -z.cy);
    g.shadowColor = 'rgba(0,0,0,0.7)'; g.shadowBlur = 3; g.shadowOffsetY = 1;
    const zcol = UI.mode === 'owner' ? ownerFill(zo) : '#d63a3a';
    g.fillStyle = zh === 0 ? shade(zcol, 0.62) : zh === 1 ? shade(zcol, 1.12) : zcol; g.fill(z.path);
    g.shadowColor = 'transparent';
    g.lineWidth = 1.6 / SWISS_ZOOM * 2.2; g.strokeStyle = UI.mode === 'owner' ? '#d63a3a' : zo === null ? '#5b6680' : colorOf(zo); g.stroke(z.path);
    if (ME !== null && zo === ME) { g.lineWidth = 1.2 / SWISS_ZOOM * 2.2; g.strokeStyle = '#fff'; g.shadowColor = 'rgba(255,170,60,0.95)'; g.shadowBlur = 6; g.stroke(z.path); g.shadowColor = 'transparent'; }
    g.lineWidth = 0.5 / SWISS_ZOOM * 2; g.strokeStyle = '#fff'; g.stroke(z.path);
    g.restore();
    g.fillStyle = '#fff'; const cxs = z.cx + 9; const cys = z.cy - 2; g.fillRect(cxs - 1.4, cys - 4, 2.8, 8); g.fillRect(cxs - 4, cys - 1.4, 8, 2.8);
}

/* SVG overlay: hit areas (invisible), hover outline, pins, effects. */
let svg; let vp; let zoom; let crispTimer = 0; let pinK = 0;
function buildOverlay() {
    const d3 = window.d3;
    svg = d3.select('#map'); vp = d3.select('#viewport');
    vp.selectAll('*').remove();
    const hits = vp.append('g');
    [...T.filter((x) => x.id !== 'zuerich'), BY.zuerich].forEach((x) => {
        const p = hits.append('path').attr('class', 'hit').attr('d', x.d).attr('id', 'hit-' + x.id)
            .on('click', () => onTerr(x.id)).on('mousemove', (e) => showTip(x.id, e)).on('mouseenter', () => hoverOn(x.id)).on('mouseleave', hoverOff);
        if (x.id === 'zuerich') p.attr('transform', SWISS_T);
    });
    vp.append('path').attr('id', 'hover');
    vp.append('g').attr('id', 'arrows');
    const pins = vp.append('g').attr('id', 'pins');
    T.forEach((x) => {
        const g = pins.append('g').attr('class', 'pin').attr('id', 'pin-' + x.id).attr('data-territory', x.id).attr('transform', `translate(${x.x},${x.y})`)
            .on('click', () => onTerr(x.id)).on('mousemove', (e) => showTip(x.id, e)).on('mouseenter', () => hoverOn(x.id)).on('mouseleave', hoverOff);
        g.append('circle').attr('class', 'target-ring').attr('r', 17).attr('opacity', 0);
        g.append('circle').attr('class', 'sel-ring').attr('r', 19).attr('opacity', 0);
        const s = g.append('g').attr('class', 'pin-body');
        s.append('ellipse').attr('class', 'shadow').attr('cx', 1).attr('cy', 15).attr('rx', 12).attr('ry', 3);
        s.append('circle').attr('class', 'disc').attr('r', 13);
        s.append('image').attr('class', 'tok').attr('x', -16).attr('y', -16).attr('width', 32).attr('height', 32);
        s.append('circle').attr('class', 'badge').attr('cx', 13).attr('cy', 10).attr('r', 11);
        s.append('text').attr('class', 'num').attr('x', 13).attr('y', 15.3);
        if (x.bank) s.append('path').attr('d', 'M-5,-17 h10 M-4,-18 v-4 M-1.3,-18 v-4 M1.3,-18 v-4 M4,-18 v-4 M-6,-22 l6,-3 l6,3z').attr('stroke', '#fff').attr('stroke-width', 1.3).attr('fill', 'rgba(0,0,0,.5)');
        if (x.mine) s.append('path').attr('d', 'M13,-14 l-5,7 h4 l-2,6 l6,-8 h-4 l2,-5z').attr('fill', '#ffb54d').attr('stroke', '#2a1500').attr('stroke-width', 0.7);
        s.append('text').attr('class', 'elite').attr('y', 28).attr('text-anchor', 'middle');
    });
    vp.append('g').attr('id', 'fx');
    zoom = d3.zoom().scaleExtent([1, 6]).translateExtent([[-200, -100], [1800, 960]]).on('zoom', (e) => {
        zt = e.transform; vp.attr('transform', zt); drawBoard(); scalePins();
        clearTimeout(crispTimer); crispTimer = setTimeout(() => { if (zt.k > 1.05) crisp(); }, 140);
    });
    svg.call(zoom).on('dblclick.zoom', null);
}
function hoverOn(id) {
    if (!G) return;
    UI.hover = id; const x = BY[id];
    window.d3.select('#hover').attr('d', x.d).attr('transform', id === 'zuerich' ? SWISS_T : null).attr('stroke-width', id === 'zuerich' ? 0.5 : 1.6);
    hoverTick();
}
function hoverOff() { UI.hover = null; window.d3.select('#hover').attr('d', null); hideTip(); }
function scalePins() {
    const v = viewMatrix(); const screen = v.s * zt.k;
    const k = Math.round(Math.min(1.5, Math.max(0.5, 0.85 / screen)) * 50) / 50;
    if (k === pinK) return; pinK = k;
    window.d3.selectAll('.pin-body').attr('transform', `scale(${k})`);
}
function fitMap() { fitMode = innerWidth / innerHeight < 1.2 ? 'slice' : 'meet'; svg.attr('preserveAspectRatio', fitMode === 'slice' ? 'xMidYMid slice' : 'xMidYMid meet'); pinK = 0; drawBoard(); scalePins(); }

function renderPins() {
    const L = legalNow();
    const d3 = window.d3;
    T.forEach((x) => {
        const tt = G.terr[x.id]; const o = tt.owner;
        const col = colorOf(o);
        const pin = d3.select('#pin-' + x.id);
        pin.attr('data-owner', o === null ? '' : String(o)).attr('data-units', units(G, x.id));
        pin.select('.tok').attr('href', o === null ? null : `${A}art/tok-${porOf(o)}.webp?v=1`);
        pin.select('.badge').attr('stroke', col);
        const shielded = tt.shield !== null; const mine = isMe(o);
        pin.select('.disc').attr('opacity', o === null ? 1 : 0).attr('fill', col).attr('stroke', shielded ? '#9cf' : mine ? '#fff' : 'rgba(0,0,0,.6)').attr('stroke-width', shielded ? 3 : mine ? 2.6 : 1.5);
        pin.select('.num').text(units(G, x.id));
        pin.select('.elite').text((tt.maxi ? `${tt.maxi}M ` : '') + (tt.asic ? `${tt.asic}A` : ''));
        const ring = pin.select('.target-ring');
        const isTarget = !!L && ((G.phase === 'attack' && UI.from && (L.attack[UI.from] ?? []).includes(x.id)) || (G.phase === 'fortify' && UI.from && (L.fortify[UI.from] ?? []).includes(x.id)) || (UI.card && (L.cards[UI.card] ?? []).includes(x.id)));
        const was = ring.attr('data-on') === '1';
        if (isTarget !== was) {
            ring.attr('data-on', isTarget ? '1' : '0'); window.gsap.killTweensOf(ring.node());
            if (isTarget && !REDUCED) { ring.attr('opacity', 1).attr('r', 15); window.gsap.to(ring.node(), { attr: { r: 26 }, opacity: 0, duration: 1.1, repeat: -1, ease: 'power2.out' }); } else ring.attr('opacity', 0);
        }
        pin.select('.sel-ring').attr('opacity', x.id === UI.from ? 1 : 0);
    });
}

/* ================= Tooltip ================= */
const oddsCache = new Map();
function tipOdds(id) {
    const L = legalNow();
    if (!L || G.phase !== 'attack' || !UI.from || !(L.attack[UI.from] ?? []).includes(id)) return '';
    const key = [UI.from, id, units(G, UI.from), units(G, id), G.terr[UI.from].asic, defenseBonus(G, id, G.terr[UI.from].asic > 0)].join('|');
    if (!oddsCache.has(key)) oddsCache.set(key, odds(G, UI.from, id, 400));
    const o = Math.round(oddsCache.get(key) * 100);

    return `<div class="row" style="margin-top:4px"><span>${esc(t('Odds from :name', { name: tn(UI.from) }))}</span><b style="color:${o >= 60 ? 'var(--fiat)' : o >= 35 ? 'var(--btc-hi)' : 'var(--loss)'}">${o} %</b></div>`;
}
function showTip(id, e) {
    if (!G) return;
    const x = BY[id]; const tt = G.terr[id]; const o = tt.owner; const z = ZONES[x.zone]; const zo = zoneOwner(G, x.zone);
    const troops = [t(':count plebs', { count: tt.pleb }), tt.maxi ? t(':count maxi', { count: tt.maxi }) : '', tt.asic ? t(':count ASIC', { count: tt.asic }) : ''].filter(Boolean).join(', ');
    const tip = $('#tip');
    tip.innerHTML = `<h4>${esc(tn(id))}</h4><div class="z" style="color:${z.color}">${esc(t(z.name))}${x.bank ? ' · ' + esc(t(z.bank)) : ''}${x.mine ? ' · ' + esc(t('Mining')) : ''}</div>
        <div class="row"><span>${esc(t('Held by'))}</span><b style="color:${o === null ? '#aaa' : colorOf(o)}">${esc(o === null ? t('neutral') : nameOf(o))}</b></div>
        <div class="row"><span>${esc(t('Troops'))}</span><b>${esc(troops)}</b></div>
        <div class="row"><span>${esc(t('Defence bonus'))}</span><b>+${defenseBonus(G, id, false)}</b></div>
        <div class="row"><span>${esc(t('Space complete'))}</span><b>${esc(zo === null ? t('no') : nameOf(zo))}</b></div>
        <div class="row"><span>${esc(t('Space bonus'))}</span><b>${esc(t(':sats sats/turn', { sats: fmt(z.sats) }))} · ${esc(t(z.bonus))}</b></div>${tipOdds(id)}`;
    tip.style.opacity = 1;
    tip.style.left = Math.min(innerWidth - 270, e.clientX + 18) + 'px'; tip.style.top = Math.min(innerHeight - 210, e.clientY + 18) + 'px';
}
function hideTip() { $('#tip').style.opacity = 0; }

/* ================= Juice ================= */
const gsap = () => window.gsap;
function shake(power = 6) { if (REDUCED) return; gsap().fromTo('#world', { x: 0, y: 0 }, { x: () => (Math.random() - 0.5) * power, y: () => (Math.random() - 0.5) * power, duration: 0.05, repeat: 5, yoyo: true, ease: 'none', onComplete: () => gsap().set('#world', { x: 0, y: 0 }) }); }
function flash(alpha = 1) { if (REDUCED) return; gsap().fromTo('#flash', { opacity: 0.85 * alpha }, { opacity: 0, duration: 0.5, ease: 'power2.out' }); }
function floatNum(id, text, color = '#ff5d73') {
    const x = BY[id]; if (!x) return;
    const n = window.d3.select('#fx').append('text').attr('class', 'float-num').attr('x', x.x).attr('y', x.y - 18).attr('fill', color).text(text);
    gsap().to(n.node(), { attr: { y: x.y - 62 }, opacity: 0, duration: REDUCED ? 0.4 : 1.2, ease: 'power2.out', onComplete: () => n.remove() });
}
function burst(id, color = '#ffb54d', count = 18, coin = false) {
    if (REDUCED) return;
    const x = BY[id]; const fx = window.d3.select('#fx');
    const ring = fx.append('circle').attr('cx', x.x).attr('cy', x.y).attr('r', 6).attr('fill', 'none').attr('stroke', color).attr('stroke-width', 4);
    gsap().to(ring.node(), { attr: { r: 80, 'stroke-width': 0 }, opacity: 0, duration: 0.8, ease: 'power3.out', onComplete: () => ring.remove() });
    for (let i = 0; i < count; i++) {
        const a = (Math.PI * 2 * i) / count + Math.random() * 0.4; const r = 30 + Math.random() * 55;
        const el = coin ? fx.append('text').attr('x', x.x).attr('y', x.y).attr('text-anchor', 'middle').attr('font-size', 14).attr('font-weight', 900).attr('fill', color).text('₿')
            : fx.append('circle').attr('cx', x.x).attr('cy', x.y).attr('r', 2 + Math.random() * 2.5).attr('fill', color);
        const ax = coin ? 'x' : 'cx'; const ay = coin ? 'y' : 'cy';
        gsap().to(el.node(), { attr: { [ax]: x.x + Math.cos(a) * r, [ay]: x.y + Math.sin(a) * r + (coin ? 22 : 0) }, opacity: 0, duration: 0.7 + Math.random() * 0.5, ease: 'power2.out', onComplete: () => el.remove() });
    }
}
function territoryPulse(id) {
    if (REDUCED || !BY[id]) return;
    const glow = window.d3.select('#fx').append('path').attr('d', BY[id].d).attr('transform', id === 'zuerich' ? SWISS_T : null).attr('fill', '#fff').attr('opacity', 0.55);
    gsap().to(glow.node(), { opacity: 0, duration: 0.7, ease: 'power2.out', onComplete: () => glow.remove() });
    const pin = $('#pin-' + id); if (pin) gsap().fromTo(pin, { scale: 1.7, transformOrigin: 'center center' }, { scale: 1, duration: 0.55, ease: 'back.out(3)' });
}
const POR = (k, ring = 'var(--btc)') => `<span class="pimg" style="--ring:${ring}"><img src="${A}art/por-${k}.jpg?v=1" alt="" decoding="async"></span>`;
/** Banners of the seat to move hold longer for a human's own moves; a bot turn runs at the chosen pace. */
/**
 * A caption over the table. Pacing (P4, user direction 2026-10-09): it stays at least readableMs() of its text
 * (3 s, or 60 ms per character), whatever the pace of the other seats; a big moment (`big`: a bank falls, a space
 * is complete, a knockout, a card, the end) then waits for a click, Enter or Space ("Tap to continue").
 */
async function banner(title, sub = '', hold = 1100, por = '', ring = 'var(--btc)', fast = false, big = false) {
    if (rushing) return;
    $('#banner-p').innerHTML = por ? POR(por, ring) : '';
    const fit = () => {
        const tEl = $('#banner-t'); const box = document.querySelector('#banner .bx'); const narrow = innerWidth < 820;
        const room = box.offsetWidth * (narrow ? 0.74 : 0.6);
        tEl.style.whiteSpace = narrow ? 'normal' : 'nowrap';
        let fs = narrow ? 30 : Math.min(52, box.offsetWidth * 0.075); tEl.style.fontSize = fs + 'px';
        const longest = () => Math.max(...tEl.textContent.split(' ').map((w) => { const sp = document.createElement('span'); sp.style.cssText = `font:900 ${fs}px var(--font-display);white-space:nowrap;position:absolute;visibility:hidden`; sp.textContent = w; document.body.appendChild(sp); const wd = sp.offsetWidth; sp.remove(); return wd; }));
        while (fs > 14 && (narrow ? (tEl.offsetHeight > fs * 1.05 * 2.2 || longest() > room) : tEl.scrollWidth > room)) { fs -= 1; tEl.style.fontSize = fs + 'px'; }
        const sb = $('#banner-s'); let ss = narrow ? 13 : 16; sb.style.fontSize = ss + 'px';
        while (ss > 9 && sb.scrollWidth > room) { ss -= 1; sb.style.fontSize = ss + 'px'; }
    };
    hold = !fast ? Math.max(1700, hold * 1.8) : UI.speed === 1 ? Math.max(1100, hold * 1.6) : hold;
    // Never faster than readable, at any pace of the other seats.
    hold = Math.max(hold, readableMs(`${title} ${sub}`));
    const bn = $('#banner'); $('#banner-t').textContent = title; $('#banner-s').textContent = sub; bn.hidden = false;
    bn.dataset.wait = '0';
    fit();
    sfx.whoosh();
    if (REDUCED) { await sleep(hold); if (big) await tapToContinue(bn); bn.hidden = true; return; }
    const tl = gsap().timeline();
    tl.fromTo('#banner .bx', { scaleX: 0.2, opacity: 0, y: 0 }, { scaleX: 1, opacity: 1, duration: 0.3, ease: 'expo.out' })
        .fromTo('#banner .bt', { scale: 1.5, opacity: 0 }, { scale: 1, opacity: 1, duration: 0.45, ease: 'expo.out' }, '<')
        .fromTo('#banner .bp', { y: 40, scale: 0.5, opacity: 0 }, { y: 0, scale: 1, opacity: 1, duration: 0.55, ease: 'back.out(2.2)' }, '<');
    await tl.then();
    await sleep(hold);
    if (big) await tapToContinue(bn);
    await gsap().to('#banner .bx', { opacity: 0, y: -24, duration: 0.3, ease: 'power2.in' }).then();
    bn.hidden = true;
}
/**
 * A big moment waits for the player: a click on it, Enter or Space. `data-wait` says it is waiting. Nobody is held
 * hostage by it, though (pace.js): at a multiplayer table every moment moves on by itself after a short wait (user
 * 2026-10-09: else one player's page holds everyone up); elsewhere a spectator, or a player whose own turn clock
 * already runs on the server, after TAP_GRACE_MS. The caption has been readable for its minimum time before this.
 */
function tapToContinue(el) {
    el.dataset.wait = '1';
    const grace = () => movesOnByItself({ seats: G.seats, playing: playing(), myTurnRuns: isMe(live.seat) && !G.over });

    return new Promise((done) => {
        const stop = new AbortController();
        const go = (e) => {
            if (e.type === 'keydown' && (!['Enter', ' ', 'ArrowRight'].includes(e.key) || e.target?.closest?.('input, textarea, select, [contenteditable]'))) return;
            e.preventDefault?.(); e.stopPropagation?.(); stop.abort(); el.dataset.wait = '0'; done();
        };
        // The grace may start later: the server can hand this player the turn while the moment is still waiting.
        let since = null;
        const watch = setInterval(() => { if (!grace()) { since = null; return; } since ??= Date.now(); if (Date.now() - since >= tapGraceMs(G.seats)) go({ type: 'timeout' }); }, 250);
        stop.signal.addEventListener('abort', () => clearInterval(watch));
        el.addEventListener('pointerdown', go, { signal: stop.signal });
        addEventListener('keydown', go, { capture: true, signal: stop.signal });
    });
}
function tickNum(el, to) {
    const from = parseFloat(el.dataset.v ?? '0') || 0; el.dataset.v = to;
    if (from === to) { el.textContent = fmt(to); return; }
    const obj = { v: from };
    gsap().to(obj, { v: to, duration: REDUCED ? 0 : 0.6, ease: 'power2.out', onUpdate: () => { el.textContent = fmt(obj.v); } });
    if (!REDUCED) gsap().fromTo(el, { scale: to > from ? 1.3 : 0.85 }, { scale: 1, duration: 0.45, ease: 'back.out(3)' });
    if (to > from && el.id === 'sats') sfx.coin();
}
function arrow(fromId, toId, color = '#ffb54d', speed = 1) {
    const P1 = BY[fromId]; const P2 = BY[toId];
    const mx = (P1.x + P2.x) / 2; const my = (P1.y + P2.y) / 2 - Math.hypot(P1.x - P2.x, P1.y - P2.y) * 0.25;
    const p = window.d3.select('#arrows').append('path').attr('class', 'arrow').attr('stroke', color).attr('d', `M${P1.x},${P1.y} Q${mx},${my} ${P2.x},${P2.y}`);
    const len = p.node().getTotalLength();
    p.attr('stroke-dasharray', len).attr('stroke-dashoffset', len);
    gsap().to(p.node(), { attr: { 'stroke-dashoffset': 0 }, duration: 0.35 / speed, ease: 'power2.out' });
    if (speed < 20) sfx.whoosh();

    return () => gsap().to(p.node(), { opacity: 0, duration: 0.3, onComplete: () => p.remove() });
}
/** A message in the instruction line for 4 s; the next render keeps it until then. */
let toastUntil = 0;
function toast(text) {
    $('#instruct-t').innerHTML = b(text);
    // Readable (P4): 4 s, longer for a long text.
    const stay = Math.max(4000, readableMs(String(text).replace(/<[^>]*>/g, '')));
    toastUntil = Date.now() + stay;
    if (!REDUCED) gsap().fromTo('#instruct', { x: -8 }, { x: 0, duration: 0.4, ease: 'elastic.out(1,0.3)' });
    clearTimeout(toast.timer); toast.timer = setTimeout(() => { toastUntil = 0; renderInstruct(); }, stay);
}
function log(text, hot = false, who = null) {
    TICKER.unshift({ text, hot, who });
    TICKER.length = Math.min(TICKER.length, 30);
    renderTicker();
}

/* ================= Battle screen with 3D dice ================= */
const BT = { from: null, to: null, three: false, spectate: false, open: false };
const PIPS = { 1: [5], 2: [1, 9], 3: [1, 5, 9], 4: [1, 3, 7, 9], 5: [1, 3, 5, 7, 9], 6: [1, 3, 4, 6, 7, 9] };
const FACE_T = { 1: 'rotateY(0deg)', 2: 'rotateY(90deg)', 3: 'rotateX(90deg)', 4: 'rotateX(-90deg)', 5: 'rotateY(-90deg)', 6: 'rotateY(180deg)' };
const SHOW = { 1: [0, 0], 2: [0, -90], 3: [-90, 0], 4: [90, 0], 5: [0, 90], 6: [0, 180] };
function dieHtml(cls) {
    const faces = [1, 2, 3, 4, 5, 6].map((n) => `<div class="f" style="transform:${FACE_T[n]} translateZ(26px)">${Array.from({ length: 9 }, (_, i) => (PIPS[n].includes(i + 1) ? '<i></i>' : '<span></span>')).join('')}</div>`).join('');

    return `<div class="dw"><div class="die3d ${cls}">${faces}</div></div>`;
}
function armyHtml(owner, id, mods, side) {
    const col = colorOf(owner); const n = units(G, id);

    return `<div class="shield" style="--pc:${col}">${POR(porOf(owner), col)}</div><div class="army-body frame shadowed"><h3>${esc(tn(id))}</h3><div class="who">${esc(side)} · ${esc(owner === null ? t('neutral') : nameOf(owner))}</div>
        <div class="cnt" data-cnt="${id}">${n}</div><div class="troops" data-troops="${id}" style="--pc:${col}">${'<i></i>'.repeat(Math.min(n, 30))}</div><div class="mods">${esc(mods)}</div></div>`;
}
// The battle's modifiers in one table, like Hearts of Iron: every bonus with its source.
function modHtml(from, to) {
    const D = G.terr[to]; const x = BY[to]; const asic = G.terr[from].asic > 0;
    const row = (label, val, cls) => `<div><span>${esc(label)}</span><b class="${cls}">${esc(val)}</b></div>`;
    const att = [row(t('Attack dice'), String(Math.min(3, units(G, from) - 1)), 'z')];
    if (asic) att.push(row(t('ASIC rig: highest die'), '+1', 'p'));
    if (asic && x.bank) att.push(row(t('ASIC cracks the central bank'), t('Bank +0'), 'p'));
    if (!asic) att.push(row(t('No ASIC rig along'), '±0', 'z'));
    const def = [row(t('Defence dice'), String(Math.min(2, units(G, to))), 'z')];
    if (x.bank && !asic) def.push(row(t('Central bank (:bank)', { bank: t(ZONES[x.zone].bank) }), '+1', 'n'));
    if (D.maxi > 0) def.push(row(t('Bitcoin maxi: HODL'), '+1', 'n'));
    if (x.zone === 'swiss') def.push(row(t('Swiss vault'), '+1', 'n'));
    if (D.owner !== null && ['rubel', 'ozean'].includes(x.zone) && zoneOwner(G, x.zone) === D.owner) def.push(row(t(':zone complete', { zone: t(ZONES[x.zone].name) }), '+1', 'n'));
    if (D.shield !== null) def.push(row('Diamond Hands', '+1', 'n'));
    def.push(row(t('A tie wins'), t('Defence'), 'z'));

    return `<div><h6>${esc(t('ATTACK'))}</h6>${att.join('')}</div><div><h6>${esc(t('DEFENCE'))}</h6>${def.join('')}</div>`;
}
// Until a stage's art is in memory, the battle screen shows a loader instead of a half-empty scene.
async function stageReady(key) {
    if (!window.Arena || !WEBGL || !UI.scenes || window.Arena.isReady(key)) return;
    const bt = $('#battle'); bt.classList.add('loading'); $('#b-load').hidden = false; $('#b-load-p').textContent = '0 %'; $('#b-load-bar').style.width = '0';
    await window.Arena.prepare(key, (p) => { $('#b-load-p').textContent = Math.round(p * 100) + ' %'; $('#b-load-bar').style.width = Math.round(p * 100) + '%'; });
    bt.classList.remove('loading'); $('#b-load').hidden = true;
}
async function openBattle(from, to, spectate = false) {
    if (G.over) return;
    const bt = $('#battle');
    bt.classList.toggle('spectate', spectate); bt.classList.remove('cine'); $('#skip-btn').hidden = !spectate;
    Object.assign(BT, { from, to, spectate, open: true, skip: false });
    const attacker = G.terr[from].owner; const dOwner = G.terr[to].owner;
    const asic = G.terr[from].asic > 0; const bonus = defenseBonus(G, to, asic);
    $('#battle-h').textContent = tn(to);
    $('#b-att').innerHTML = armyHtml(attacker, from, asic ? t('ASIC +1 on the highest die') : '', t('Offence'));
    $('#b-def').innerHTML = armyHtml(dOwner, to, bonus ? t('+:bonus on the highest die', { bonus }) : t('no bonus'), t('Defence'));
    $('#tug').style.setProperty('--dc', colorOf(dOwner));
    $('#dice-a').innerHTML = ''; $('#dice-d').innerHTML = ''; $('#b-result').innerHTML = '';
    const th = window.Arena ? window.Arena.themeFor(BY[from].zone, BY[to].zone, BY[to].mine, BY[to].zone === 'swiss') : null;
    const title = th?.title ? t(th.title) : null;
    $('#b-kicker').textContent = spectate ? t(':name attacks you', { name: nameOf(attacker) }) + ' · ' + (title ?? t('Battle for')) : title ? `${title} · ${t('Battle for')}` : t('Battle for');
    $('#b-meme').textContent = th ? t(th.theme.meme) : '';
    $('#b-mods').innerHTML = modHtml(from, to);
    updateOdds();
    bt.hidden = false; setIntensity(1); sfx.lock();
    if (th) await stageReady(th.key);
    BT.three = !!(th && UI.scenes && WEBGL && window.Arena && window.Arena.open($('#arena3d'), { theme: th.theme, att: { units: { ...G.terr[from] }, color: colorOf(attacker), por: porOf(attacker) }, def: { units: { ...G.terr[to] }, color: dOwner === null ? '#8a93a8' : colorOf(dOwner), por: dOwner === null ? null : porOf(dOwner) } }));
    $('#felt').hidden = BT.three;
    if (!REDUCED) {
        gsap().fromTo('#b-att', { x: -160, opacity: 0 }, { x: 0, opacity: 1, duration: 0.45, ease: 'power3.out' });
        gsap().fromTo('#b-def', { x: 160, opacity: 0 }, { x: 0, opacity: 1, duration: 0.45, ease: 'power3.out' });
        gsap().fromTo('#battle .bh', { y: -30, opacity: 0 }, { y: 0, opacity: 1, duration: 0.4 });
        gsap().fromTo('.b-dock', { y: 40, opacity: 0 }, { y: 0, opacity: 1, duration: 0.45, ease: 'power3.out' });
        gsap().fromTo('#arena3d', { scale: 1.25, opacity: 0 }, { scale: 1, opacity: 1, duration: 0.8, ease: 'power3.out' });
    }
}
function updateOdds() {
    const o = odds(G, BT.from, BT.to);
    $('#odds-n').textContent = t(':pct % chance to win', { pct: Math.round(o * 100) });
    gsap().to('#odds-bar', { width: Math.round(o * 100) + '%', duration: 0.5, ease: 'power2.out' });
    const can = !BT.spectate && units(G, BT.from) > 1 && G.terr[BT.from].owner === ME && G.terr[BT.to].owner !== ME && !UI.busy;
    $('#roll-btn').disabled = !can; $('#blitz-btn').disabled = !can;
}
function closeBattle() {
    if (BT.three && window.Arena) window.Arena.close();
    $('#battle').hidden = true; $('#battle').classList.remove('spectate', 'cine'); setIntensity(0);
    Object.assign(BT, { open: false, three: false, spectate: false });
}
function pairHtml(r) {
    const n = Math.min(r.attacker.length, r.defender.length); const out = [];
    for (let i = 0; i < n; i++) {
        const am = r.attacker_total[i]; const dm = r.defender_total[i];
        const a = r.attacker[i] + (am !== r.attacker[i] ? `+${am - r.attacker[i]}` : ''); const d = r.defender[i] + (dm !== r.defender[i] ? `+${dm - r.defender[i]}` : '');
        out.push(am > dm ? `<span class="w">${a} ▶ ${d}</span>` : `<span class="l">${a} ◀ ${d}</span>`);
    }

    return out.join('');
}
function updateCounts(r) {
    if (r.attacker_losses) floatNum(r.from, '−' + r.attacker_losses);
    if (r.defender_losses) floatNum(r.to, '−' + r.defender_losses);
    [r.from, r.to].forEach((id) => {
        const el = document.querySelector(`[data-cnt="${id}"]`); const tr = document.querySelector(`[data-troops="${id}"]`);
        if (el) { el.textContent = units(G, id); gsap().fromTo(el, { scale: 1.4, color: '#ff5d73' }, { scale: 1, color: '#eef3ff', duration: 0.45 }); }
        if (tr) tr.innerHTML = '<i></i>'.repeat(Math.min(units(G, id), 30));
    });
    render();
}
/** One roll in the battle screen: the server's dice, then its losses written into the table. */
async function animateDice(r, fast = false) {
    const an = r.attacker.length; const dn = r.defender.length;
    if (BT.three && window.Arena) {
        sfx.shake();
        await window.Arena.throw(r.attacker, r.defender, fast);
        const n = Math.min(an, dn);
        window.Arena.charge(r.defender_losses >= r.attacker_losses ? 'a' : 'd');
        for (let i = 0; i < n; i++) { window.Arena.dimDice(r.attacker_total[i] > r.defender_total[i] ? an + i : i); sfx.clash(); if (!fast) await sleep(120); }
        $('#b-result').innerHTML = pairHtml(r);
        if (!REDUCED) gsap().from('#b-result span', { y: 14, opacity: 0, scale: 1.3, duration: 0.3, stagger: 0.08, ease: 'back.out(3)' });
        const won = r.defender_units === 0;
        if (r.defender_losses && !won) { window.Arena.casualties('d', r.defender_losses); window.Arena.inflation(r.defender_losses); if (G.terr[r.from].maxi > 0) window.Arena.laser(); }
        if (r.attacker_losses) window.Arena.casualties('a', r.attacker_losses);
        if (r.defender_losses >= 2) { window.Arena.candle(true); if (!fast && Math.random() < 0.45) cue('battle.hit'); } else if (r.attacker_losses >= 2) window.Arena.candle(false);
        applyEvent(G, r); updateCounts(r);
        await sleep(fast ? 350 : 1100);

        return;
    }
    const lane = (vals, mods, cls, el) => { el.innerHTML = vals.map(() => dieHtml(cls)).join(''); return [...el.children].map((w, i) => ({ w, d: w.firstElementChild, v: vals[i], m: mods[i] })); };
    const LA = lane(r.attacker, r.attacker_total, 'a', $('#dice-a')); const LD = lane(r.defender, r.defender_total, 'd', $('#dice-d'));
    const all = [...LA, ...LD];
    sfx.shake();
    if (!REDUCED) {
        await Promise.all(all.map(({ d, v }, i) => {
            const [rx, ry] = SHOW[v]; const spins = fast ? 1 : 2;

            return gsap().fromTo(d, { y: -120, rotationX: gsap().utils.random(-720, 720), rotationY: gsap().utils.random(-720, 720), scale: 0.5 },
                { y: 0, scale: 1, rotationX: rx + 360 * spins, rotationY: ry + 360 * spins, duration: fast ? 0.45 : 0.85, delay: i * 0.05, ease: 'bounce.out', onComplete: () => sfx.land() }).then();
        }));
    } else all.forEach(({ d, v }) => { const [rx, ry] = SHOW[v]; d.style.transform = `rotateX(${rx}deg) rotateY(${ry}deg)`; });
    all.forEach(({ w, v, m }) => { if (m !== v) w.insertAdjacentHTML('beforeend', `<span class="mod">+${m - v}</span>`); });
    await sleep(fast ? 60 : 160);
    for (let i = 0; i < Math.min(an, dn); i++) {
        const aWin = r.attacker_total[i] > r.defender_total[i];
        (aWin ? LD[i] : LA[i]).w.classList.add('lost'); sfx.clash();
        gsap().fromTo((aWin ? LA[i] : LD[i]).w, { scale: 1.3 }, { scale: 1, duration: 0.35, ease: 'back.out(3)' });
        if (!fast) await sleep(140);
    }
    $('#b-result').innerHTML = pairHtml(r);
    applyEvent(G, r); updateCounts(r);
    if (r.attacker_losses || r.defender_losses) shake(3);
    await sleep(fast ? 120 : 500);
}

/* ================= Move dialog (move in after a conquest, fortify) ================= */
const MV = { from: null, to: null, kind: null };
function openMove(from, to, kind, min, max) {
    Object.assign(MV, { from, to, kind });
    const r = $('#move-range');
    r.min = min; r.max = max; r.value = max;
    $('#move-h').textContent = kind === 'conquest' ? t('Move troops in') : t('Move troops');
    $('#move-sub').textContent = t('From :from to :to. One unit always stays behind.', { from: tn(from), to: tn(to) });
    $('#move-n').textContent = max;
    // A conquest waits for its move-in; only a fortify can be called off.
    $('#move-cancel').hidden = kind === 'conquest';
    $('#move').hidden = false; sfx.select();
    gsap().fromTo('#move .panel', { y: 30, opacity: 0 }, { y: 0, opacity: 1, duration: 0.25, ease: 'power2.out' });
}
function closeMove() { $('#move').hidden = true; }

/* ================= Event cards ================= */
// An event card plays as a short 3D scene on the battle stage; a tap skips it.
async function cinematic(card, o) {
    const bt = $('#battle'); const c = CARDS[card];
    bt.classList.add('cine'); bt.classList.remove('spectate');
    $('#b-kicker').textContent = t('Event card'); $('#battle-h').textContent = t(c.name); $('#b-meme').textContent = t(c.text);
    $('#ev').hidden = false; $('#ev-h').textContent = t(c.name); $('#ev-t').textContent = t(c.text); $('#ev-j').textContent = t(c.joke); $('#ev-pic').style.backgroundImage = `url(${A}art/card-${card}.jpg?v=1)`;
    bt.hidden = false; setIntensity(1);
    await stageReady(window.Arena.eventKey(card, o));
    const scene = window.Arena.event($('#arena3d'), card, o);
    if (!REDUCED) { gsap().fromTo('#ev', { y: 60, opacity: 0 }, { y: 0, opacity: 1, duration: 0.6, ease: 'power3.out' }); gsap().fromTo('#ev-pic', { scale: 1.06 }, { scale: 1, duration: 1.4, ease: 'power2.out' }); }
    // Readable (P4): the card's texts stay at least readableMs() of them, then the scene waits for a tap.
    await sleep(readableMs(`${t(c.name)} ${t(c.text)} ${t(c.joke)}`));
    $('#battle .cine-hint').textContent = t('Tap to continue');
    await tapToContinue(bt);
    $('#battle .cine-hint').textContent = t('Tap to skip');
    if (scene) scene.catch?.(() => {});
    window.Arena.close(); bt.hidden = true; bt.classList.remove('cine'); $('#ev').hidden = true; setIntensity(0);
}
function dealAnim() {
    const cards = $$('#hand .card'); const last = cards[cards.length - 1];
    if (!last || REDUCED) return;
    const back = document.createElement('div'); back.className = 'cback'; last.appendChild(back);
    gsap().timeline().from(last, { y: -320, x: 260, rotation: 40, opacity: 0, duration: 0.6, ease: 'back.out(1.4)' })
        .to(last, { scaleX: 0, duration: 0.14, ease: 'power2.in', onComplete: () => back.remove() }, '+=0.15')
        .to(last, { scaleX: 1, duration: 0.18, ease: 'power2.out' });
}

/* ================= Events from the server, one batch at a time ================= */
function enqueue(batch) {
    batch.at ??= Date.now();
    queue.push(batch);
    clearTimeout(settleTimer);
    if (!running) runQueue();
}
async function runQueue() {
    running = true; render();
    try {
        while (queue.length) {
            const batch = queue.shift();
            try {
                await animateBatch(batch);
            } catch (error) {
                console.warn('[hyper] showing events failed', error);
            }
            G.ply = Math.max(G.ply, batch.to);
            SHOWN.push(batch.to); if (SHOWN.length > 200) SHOWN.shift();
        }
    } finally {
        running = false;
    }
    settleTimer = setTimeout(settle, 120);
}
/** The newest snapshot the page has, applied once the events up to its ply are shown. */
function keepSnapshot(s) {
    if (!latest || s.ply >= latest.ply) latest = s;
}
async function settle(force = false) {
    if (running || queue.length) return;
    if (force || !latest || latest.ply !== sync.ply) {
        const r = await NET.get(CFG.urls.snapshot);
        if (!r.ok || !r.data) return;
        keepSnapshot(r.data);
    }
    if (running || queue.length) return;
    if (latest.ply > sync.ply) { catchUp(); return; }
    if (latest.ply === sync.ply) applySnapshot(latest);
}
function applySnapshot(s) {
    S = s; ME = s.me ?? null;
    clockOffset = (s.server_ms ?? Date.now()) - Date.now();
    live.seat = s.seat; live.deadline = s.deadline_ms;
    G = fromSnapshot(s);
    document.body.classList.toggle('spectator', !playing());
    if (UI.card && !(s.legal?.cards ?? {})[UI.card]) UI.card = null;
    if (UI.from && G.terr[UI.from]?.owner !== ME) UI.from = null;
    render();
    // A conquest waits for the move-in, also after a reload.
    const pending = s.legal?.pending_move;
    if (pending && myTurn() && $('#move').hidden && !BT.open) openMove(pending.from, pending.to, 'conquest', 0, pending.max);
    if (!pending && MV.kind === 'conquest' && !$('#move').hidden) closeMove();
    if (G.over && !ended) showEnd(G.winner, G.byLimit, G.round, G.seats.map((x) => x.loot), true);
    // The server's end time (P5c): the spectator poll drops every vote signed after it, also on a live page.
    if (G.over && G.endedAt !== null) hooks.onEndedAt?.(G.endedAt);
}
/** After a reconnect, a truncated broadcast or a gap: the plies the page missed, animated quickly. */
function catchUp() {
    if (catching) return catching;
    catching = (async () => {
        for (let guard = 0; guard < 6; guard++) {
            const r = await NET.get(`${CFG.urls.events}?after=${sync.ply}`);
            if (!r.ok || !r.data) break;
            if (r.data.ply - sync.ply > CATCHUP_MAX) {
                const s = await NET.get(CFG.urls.snapshot);
                if (s.ok && s.data) { queue.length = 0; sync.reset(s.data.ply); latest = s.data; if (!running) settle(); }
                break;
            }
            const { batches, more } = sync.catchUp(r.data);
            batches.forEach((batch) => enqueue({ ...batch, fast: true }));
            if (!more) break;
        }
    })().finally(() => { catching = null; });

    return catching;
}
/** `hyper.updated`: the table's view of the plies `from_ply + 1 .. ply`. */
function onUpdated(p) {
    live.seat = p.seat; live.deadline = p.deadline_ms;
    if (p.truncated) { catchUp(); return; }
    const res = sync.offer({ from: p.from_ply, to: p.ply, events: p.events });
    if (res.type === 'take') enqueue(res.batch);
    else if (res.type === 'gap') catchUp();
    // No new ply but the table changed (a player left and a bot took the seat): the snapshot says how.
    else if (p.ply === sync.ply && p.from_ply === p.ply) { clearTimeout(settleTimer); settleTimer = setTimeout(() => settle(true), 150); }
}
/** `hyper.hand`: this seat's cards; the next snapshot carries them too, this only shows a drawn card sooner. */
function onHand(p) {
    if (ME === null || p.seat !== ME || !G) return;
    if (p.ply <= G.ply && Array.isArray(p.hand)) { G.seats[ME].hand = [...p.hand]; G.seats[ME].handCount = p.hand.length; renderHand(); }
}

/** The source of the batch on show (`bot` for a turn the server played for a seat): no soundboard clips then (sounds.js). */
let shownSource = null;
async function animateBatch(batch) {
    const events = batch.events ?? [];
    const ctxB = { seat: batch.seat ?? events.find((e) => e.seat !== undefined)?.seat ?? G.cur, speed: 1, watched: 0, source: batch.source };
    shownSource = batch.source ?? null;
    for (let i = 0; i < events.length; i++) {
        const e = events[i];
        ctxB.seat = e.type === 'turn_started' ? e.seat : (e.seat ?? ctxB.seat);
        // Another seat's events: at the chosen pace, faster while catching up, and at least 3× once the server
        // already gave this player the turn (their 90 s run while the page still shows the bots).
        rushing = !isMe(ctxB.seat) && (myClockRuns() || lagging(batch.at, Date.now(), !!CFG?.replay));
        ctxB.speed = isMe(ctxB.seat) ? 1 : rushing ? 20 : Math.max(UI.speed, batch.fast ? 3 : 1);
        if (e.type === 'dice_rolled') {
            const run = [e];
            while (events[i + 1]?.type === 'dice_rolled' && events[i + 1].from === e.from && events[i + 1].to === e.to) run.push(events[++i]);
            await showDice(run, events[i + 1], ctxB);
            continue;
        }
        await showEvent(e, ctxB, events[i + 1]);
    }
    shownSource = null;
    rushing = false;
}
const wait = (ctxB, ms) => (ctxB.speed >= 20 ? Promise.resolve() : sleep(ms / ctxB.speed));
async function showDice(run, next, ctxB) {
    const first = run[0]; const attacker = first.seat;
    const conquers = next?.type === 'territory_conquered' && next.territory === first.to;
    if (BT.open && !BT.spectate && BT.from === first.from && BT.to === first.to) {
        for (const r of run) await animateDice(r, run.length > 1);
        if (!conquers) {
            if (G.over) return;
            const last = run[run.length - 1];
            if (last.defender_losses === 0 && last.attacker_losses > 0) cue(BY[first.to].zone === 'swiss' ? 'battle.repelled.swiss' : G.terr[first.to].maxi ? 'battle.repelled.maxi' : 'battle.repelled');
            updateOdds();
            if (units(G, first.from) <= 1) { await sleep(600); closeBattle(); render(); }
        }

        return;
    }
    const defender = G.terr[first.to]?.owner ?? null;
    if (isMe(defender) && !isMe(attacker) && UI.scenes && ctxB.speed < 20 && ctxB.watched < 2 && window.Arena) {
        ctxB.watched++;
        await openBattle(first.from, first.to, true);
        $('#skip-btn').onclick = () => { BT.skip = true; };
        await sleep(ctxB.speed > 1 ? 400 : 900);
        for (const r of run) { if (BT.skip) { applyEvent(G, r); continue; } await animateDice(r, ctxB.speed > 1); }
        if (!BT.skip && BT.three) { if (conquers) await window.Arena.finale(colorOf(attacker), porOf(attacker), t(fac(attacker).tag)); else { cue('battle.held'); await sleep(900); } }
        closeBattle(); render();

        return;
    }
    // No human fights on either side (pace.js, user 2026-10-09): the battle and its conquest run without animation.
    if (quietBattle(G.seats, attacker, defender)) {
        for (const r of run) applyEvent(G, r);
        if (conquers) ctxB.quiet = next;
        render();

        return;
    }
    const fade = ctxB.speed < 20 ? arrow(first.from, first.to, colorOf(attacker), ctxB.speed) : () => {};
    await wait(ctxB, 360);
    let lost = 0;
    for (const r of run) { applyEvent(G, r); lost += r.attacker_losses; }
    if (ctxB.speed < 20) { sfx.shake(); if (lost) floatNum(first.from, '−' + lost); }
    render();
    if (!conquers && isMe(defender) && ctxB.speed < 20) cue('battle.held');
    fade(); await wait(ctxB, 280);
}
async function showEvent(e, ctxB, next) {
    const sp = ctxB.speed;
    switch (e.type) {
        case 'turn_started': {
            applyEvent(G, e); UI.from = null; UI.card = null;
            log(t(':name: +:fiat fiat, +:sats M sats', { name: nameOf(e.seat), fiat: fmt(e.fiat), sats: fmt(e.sats) }), false, e.seat);
            render();
            if (isMe(e.seat) && playing()) { cue('turn.mine'); await banner(t('Your turn'), t('+:fiat fiat · +:sats M sats', { fiat: fmt(e.fiat), sats: fmt(e.sats) }), 650, porOf(e.seat), colorOf(e.seat)); } else if (sp === 1) await banner(nameOf(e.seat), t('is on the move'), 320, porOf(e.seat), colorOf(e.seat), true);
            break;
        }
        case 'placed': {
            applyEvent(G, e);
            if (sp < 20) { floatNum(e.territory, '+' + (e.count + (e.bonus ?? 0)), '#7fe0a6'); territoryPulse(e.territory); sfx.place(); }
            if (isMe(e.seat) && (e.unit === 'maxi' || e.unit === 'asic')) cue('unit.' + e.unit);
            render(); await wait(ctxB, isMe(e.seat) ? 0 : 160);
            break;
        }
        case 'undone':
            applyEvent(G, e); floatNum(e.territory, '−' + e.count, '#ffb54d'); render();
            break;
        case 'phase_changed':
            applyEvent(G, e); UI.from = null; render();
            break;
        case 'territory_conquered': {
            if (BT.open && !BT.spectate && BT.to === e.territory) {
                await sleep(300);
                if (BT.three && window.Arena) await window.Arena.finale(colorOf(e.seat), porOf(e.seat), t(fac(e.seat).tag));
                if (!REDUCED) await gsap().to('#battle', { opacity: 0, duration: 0.25 }).then();
                closeBattle(); gsap().set('#battle', { opacity: 1 });
            }
            applyEvent(G, e);
            const x = BY[e.territory];
            const quiet = ctxB.quiet === e;
            if (quiet) ctxB.quiet = null;
            if (sp < 20 && !quiet) { territoryPulse(e.territory); burst(e.territory, colorOf(e.seat), x.bank ? 28 : 16, x.bank); shake(x.bank ? 10 : 5); sfx.boom(); }
            log(t(':name conquers :territory.', { name: nameOf(e.seat), territory: tn(e.territory) }), x.bank, e.seat);
            if (isMe(e.previous_owner) && !isMe(e.seat)) cue('territory.lost');
            else if (!isBot(e.seat)) cue('territory.conquered');
            if (ME !== null && e.previous_owner === ME && territoriesOf(G, ME).length === 3) cue('territory.low');
            if (isMe(e.seat)) UI.from = units(G, e.territory) > 1 ? e.territory : null;
            render(); if (!quiet) await wait(ctxB, 200);
            break;
        }
        case 'bank_fallen': {
            const z = ZONES[e.zone];
            floatNum(e.territory, '+1 ₿', '#ffb54d');
            cue(e.territory === 'frankfurt' ? 'bank.ezb' : e.territory === 'ny' ? 'bank.fed' : 'bank.fallen');
            log(t(':name topples the :bank.', { name: nameOf(e.seat), bank: t(z.bank) }), true, e.seat);
            if (sp < 20 || isMe(e.seat)) { flash(); sfx.sting(); await banner(t('Central bank toppled'), t(':bank · +1 M sats', { bank: t(z.bank) }), isBot(e.seat) ? 600 / sp : 900, porOf(e.seat), colorOf(e.seat), !isMe(e.seat), true); }
            break;
        }
        case 'zone_completed': {
            const z = ZONES[e.zone];
            cue('zone.completed');
            log(t(':name holds all of :zone.', { name: nameOf(e.seat), zone: t(z.name) }), true, e.seat);
            if (sp < 20 || isMe(e.seat)) { flash(); sfx.sting(); await banner(t('Orange pill'), t(':zone complete · +:sats sats per turn', { zone: t(z.name), sats: fmt(z.sats) }), isBot(e.seat) ? 600 / sp : 900, porOf(e.seat), colorOf(e.seat), !isMe(e.seat), true); }
            break;
        }
        case 'loot_gained':
            applyEvent(G, e); render();
            break;
        case 'moved': {
            applyEvent(G, e);
            if (sp < 20) { floatNum(e.to, '+' + e.count, '#7fe0a6'); sfx.place(); territoryPulse(e.to); if (e.kind === 'fortify') arrow(e.from, e.to, colorOf(e.seat), sp)(); }
            if (e.kind === 'fortify') log(t(':name moves :count units to :territory.', { name: nameOf(e.seat), count: e.count, territory: tn(e.to) }), false, e.seat);
            render(); await wait(ctxB, 280);
            break;
        }
        case 'card_played':
            await showCard(e, ctxB, next);
            break;
        case 'card_drawn':
            applyEvent(G, e);
            if (isMe(e.seat)) { sfx.flip(); render(); dealAnim(); await sleep(500); } else render();
            break;
        case 'fiat_interest':
            applyEvent(G, e);
            if (isMe(e.seat)) log(t(':name collects interest in London.', { name: nameOf(e.seat) }), false, e.seat);
            render();
            break;
        case 'fiat_decayed':
            applyEvent(G, e); render();
            if (isMe(e.seat) && e.amount >= 0.3) { cue('inflation.mine'); await banner(t('Inflation'), t('−:fiat fiat gone up in smoke', { fiat: fmt(e.amount) }), 650, 'pleb', '#7fe0a6'); }
            break;
        case 'player_eliminated':
            applyEvent(G, e); cue('player.out');
            log(t(':name is out.', { name: nameOf(e.seat) }), true, e.seat);
            render();
            if (!G.over || isMe(e.seat)) await banner(isMe(e.seat) ? t('You are out') : t(':name is out', { name: nameOf(e.seat) }), t('One fiat faction fewer'), 900, porOf(e.seat), colorOf(e.seat), !isMe(e.seat), true);
            break;
        case 'round_started':
            applyEvent(G, e); render();
            if (e.inflation) { cue('price.up'); log(t('The money printer kicks in: plebs cost 50 % more.'), true); }
            break;
        case 'turn_ended':
            if (isMe(e.seat)) {
                sfx.gong();
                if (ctxB.source === 'timer') { toast(t('Your turn ran out.')); if (BT.open) closeBattle(); closeMove(); }
            }
            break;
        case 'game_won':
            applyEvent(G, e); UI.from = null; UI.card = null;
            if (BT.open) closeBattle();
            closeMove(); render();
            await showEnd(e.seat, e.by_limit, e.round, e.loot ?? [], false);
            break;
        default:
            applyEvent(G, e);
            break;
    }
}
async function showCard(e, ctxB, next) {
    const c = CARDS[e.card]; if (!c) { applyEvent(G, e); return; }
    const human = !isBot(e.seat);
    cue('card.' + e.card);
    const zone = e.target ? BY[e.target].zone : null;
    if (human && UI.scenes && WEBGL && window.Arena && ctxB.speed < 20) await cinematic(e.card, { up: !!e.effect?.up, zone });
    else if (human || ctxB.speed < 20) await banner(t(c.name), t(c.text), isBot(e.seat) ? 450 / ctxB.speed : 700, '', 'var(--btc)', !isMe(e.seat), true);
    applyEvent(G, e);
    const effect = e.effect ?? {};
    let sub = t(c.joke);
    switch (e.card) {
        case 'brrrr': sub = t('+8 free plebs · inflation next round'); break;
        case 'attack51': sub = t(':territory taken over', { territory: tn(e.target) }); if (next?.type !== 'territory_conquered') territoryPulse(e.target); break;
        case 'keys': if (effect.victim !== undefined) sub = t(':name loses :sats M sats', { name: nameOf(effect.victim), sats: fmt(effect.sats_lost ?? 0) }); break;
        case 'diamond': territoryPulse(e.target); sub = t(':territory holds with +1', { territory: tn(e.target) }); break;
        case 'salvador': sub = effect.up ? t('Price +25 %. To the moon!') : t('Price −25 %. Ouch.'); cue(effect.up ? 'card.salvador.up' : 'card.salvador.down'); break;
        case 'scam': floatNum(e.target, '−' + (effect.units_lost ?? 2)); burst(e.target, '#ff5d73', 12); sub = t(':territory plundered', { territory: tn(e.target) }); break;
        case 'lagarde': if (effect.next_card && CARDS[effect.next_card]) sub = t('Next card: :card', { card: t(CARDS[effect.next_card].name) }); break;
        case 'nokeys': territoryPulse(e.target); sub = t(':territory turns neutral', { territory: tn(e.target) }); break;
        default: break;
    }
    log(t(':name plays :card: :effect', { name: nameOf(e.seat), card: t(c.name), effect: sub }), true, e.seat);
    render();
}

/* ================= End of the match ================= */
async function showEnd(winner, byLimit, round, loot, quiet) {
    if (ended) return;
    ended = true;
    // Voided by the league (P5c): no winner, nothing rated; the end screen says so and nothing else follows.
    if (G.voided) {
        $('#end-art').style.backgroundImage = '';
        $('#end-team').hidden = true;
        $('#end-h').textContent = t('Match voided');
        $('#end-sub').textContent = t('The league voided this tournament match. It counts nowhere and is not rated.');
        $('#end-loot').innerHTML = '';
        $('#end').dataset.result = 'voided';
        ['#rematch-btn', '#replay-link', '#stats-btn', '#share-link'].forEach((sel) => $(sel)?.setAttribute('hidden', ''));
        $('#end').hidden = false;
        hooks.onEnd?.(quiet, { voided: true });

        return;
    }
    // A team match (P4): the team wins together, every player of it is a winner.
    const team = sides() ? teamOf(winner) : null;
    const teamWon = team !== null && mySeat()?.team === team;
    const f = fac(winner); const iWon = team !== null ? teamWon : isMe(winner); const iLost = ME !== null && !iWon;
    const terr = territoriesOf(G, winner).length; const banks = banksOf(G, winner);
    // A defeat shows the viewer's own faction beaten (P3b art), a win or a spectator's view the winner's finale.
    const mine = porOf(ME);
    $('#end-art').style.backgroundImage = `url(${A}art/${iLost ? (mine === 'you' ? 'lose' : 'lose-' + mine) : f.por === 'you' ? 'win' : 'win-' + f.por}.jpg?v=1)`;
    $('#end-h').innerHTML = iWon
        ? (f.por === 'you' ? t('You made the :name happen!', { name: '<b>Hyperbitcoinization</b>' }) : t('You win as :side!', { side: b(t(f.side)) }))
        : t(':name wins', { name: b(nameOf(winner)) });
    // Under the winning clan's logo, every player of the team named.
    $('#end-team').hidden = team === null;
    if (team !== null) {
        $('#end-h').innerHTML = teamWon ? t('Your team wins!') : t(':clan wins', { clan: b(clanName(team)) });
        $('#end-team').innerHTML = `${clanLogo(team, 72)}<div><b>${esc(clanName(team))}</b><span>${esc(G.seats.filter((x) => x.team === team).map((x) => nameOf(x.seat)).join(' · '))}</span></div>`;
        $('#end').dataset.result = teamWon ? 'win' : ME !== null ? 'defeat' : 'watch';
    }
    $('#end-sub').textContent = `${t(f.win[1])} ${byLimit ? t('Round limit: :banks central banks, :territories territories', { banks, territories: terr }) : t('Everyone else is out')}, ${t('round :round', { round })}.`;
    const myLoot = ME !== null ? loot[ME] ?? G.seats[ME]?.loot ?? 0 : null;
    $('#end-loot').innerHTML = myLoot === null ? '' : `<img src="${A}art/ico-sats.webp?v=1" alt=""> ${t(':sats M sats loot, credited to you', { sats: b('+' + fmt(myLoot)) })}`;
    if (team === null) $('#end').dataset.result = iWon ? 'win' : iLost ? 'defeat' : 'watch';
    // A win or loot is a moment to share (P6, App\Support\Hyper\HyperMoments): the link opens the lobby's moments.
    $('#share-link')?.toggleAttribute('hidden', !(iWon || (myLoot ?? 0) > 0));
    if (!quiet) {
        cue(iLost ? 'game.lost' : iWon ? 'game.won' : 'game.over');
        if (iWon) { territoriesOf(G, winner).forEach((id, i) => setTimeout(() => burst(id, '#ffb54d', 10, true), i * 60)); }
        await banner(t(f.win[0]), iWon ? (byLimit ? t('The most central banks') : t('Everyone else is out')) : t(':name wins', { name: nameOf(winner) }), 1400, f.por, f.color, false, true);
    }
    $('#end').hidden = false;
    if (!REDUCED) gsap().from('#end .panel', { scale: 0.8, opacity: 0, duration: 0.4, ease: 'back.out(2)' });
    // The end-of-match statistics (stats.js) follow the end screen.
    hooks.onEnd?.(quiet);
}

/* ================= Intents ================= */
async function act(action) {
    if (UI.busy || !myTurn()) return null;
    UI.busy = true; render();
    const r = await NET.post(CFG.urls.act, { action, ply: sync.ply + 1 });
    UI.busy = false;
    if (r.ok && r.data) {
        keepSnapshot(r.data.snapshot);
        const res = sync.offer({ from: r.data.ply - 1, to: r.data.ply, events: r.data.events });
        if (res.type === 'take') enqueue({ ...res.batch, seat: ME });
        else if (res.type === 'gap') catchUp();
        else if (!running && !queue.length) settle();

        return r.data;
    }
    refused(r);

    return null;
}
function refused(r) {
    sfx.error();
    const reason = r.data?.reason;
    toast(r.data?.message ?? (r.status === 419 ? t('The page is out of date. Please reload it.') : t('The server did not answer. Please try again.')));
    if (r.status === 409 || r.status === 403 || reason === 'not_your_turn' || reason === 'wrong_phase') { catchUp(); settleTimer = setTimeout(settle, 300); }
    render();
}
function onTerr(id) {
    if (!G || G.over) return;
    const L = legalNow();
    if (!L) { if (myTurn() && !idle()) return; if (playing() && !G.over) { sfx.error(); toast(t(':name is on the move.', { name: nameOf(G.cur) })); } return; }
    if (UI.card) {
        const c = UI.card;
        if (!(L.cards[c] ?? []).includes(id)) { sfx.error(); toast(t('That target does not fit the card.')); return; }
        UI.card = null; act({ type: 'play_card', card: c, target: id }); return;
    }
    if (G.phase === 'buy') {
        if (!L.deploy.includes(id)) { sfx.error(); toast(t('Place troops on your own glowing territories.')); return; }
        if (mySeat().freePlebs === 0 && !L.affordable[UI.unit]) { sfx.error(); toast(UI.unit === 'pleb' ? t('Not enough fiat. On to the attack.') : t('Not enough sats.')); return; }
        act({ type: 'deploy', territory: id, unit: UI.unit, qty: UI.qty === 'max' ? 'max' : Number(UI.qty) }); return;
    }
    if (G.phase === 'attack') {
        if (id in L.attack) { UI.from = UI.from === id ? null : id; sfx.select(); render(); return; }
        if (G.terr[id].owner === ME) { sfx.error(); toast(t('An attack needs at least 2 units next to an enemy.')); return; }
        if (!UI.from) { sfx.error(); toast(t('Pick your attacking territory first (glowing).')); return; }
        if (!(L.attack[UI.from] ?? []).includes(id)) { sfx.error(); toast(t('Attack neighbours only. Dashed lines are sea routes.')); return; }
        openBattle(UI.from, id); return;
    }
    if (G.phase === 'fortify') {
        if (Object.keys(L.fortify).length === 0) { sfx.error(); toast(t('Already moved this turn. End your turn.')); return; }
        if (G.terr[id].owner !== ME) { sfx.error(); return; }
        if (!UI.from) { if (!(id in L.fortify)) { sfx.error(); toast(t('Only 1 unit there.')); return; } UI.from = id; sfx.select(); render(); return; }
        if (id === UI.from) { UI.from = null; render(); return; }
        if (!(L.fortify[UI.from] ?? []).includes(id)) { if (id in L.fortify) { UI.from = id; sfx.select(); render(); return; } sfx.error(); toast(t('Only into a neighbouring territory of yours.')); return; }
        openMove(UI.from, id, 'fortify', 1, units(G, UI.from) - 1);
    }
}
async function roll(blitz) {
    if (UI.busy || $('#battle').classList.contains('loading')) return;
    $('#roll-btn').disabled = true; $('#blitz-btn').disabled = true;
    const r = await act({ type: 'attack', from: BT.from, to: BT.to, mode: blitz ? 'blitz' : 'roll' });
    if (!r && BT.open) updateOdds();
}

/* ================= HUD ================= */
function renderInstruct() {
    if (!G || Date.now() < toastUntil) return;
    let h;
    const cur = nameOf(G.cur);
    if (G.over) h = esc(t('Match over.'));
    else if (!playing()) h = t('You are watching. :name is on the move.', { name: b(cur) });
    else if (!myTurn()) h = t(':name is on the move …', { name: b(cur) });
    else if (UI.card) h = t(':card: tap a glowing target. Tap the card again to cancel.', { card: b(t(CARDS[UI.card].name)) });
    else if (G.phase === 'buy') h = mySeat().freePlebs > 0 ? t('Place :count free plebs on your glowing territories.', { count: b(mySeat().freePlebs) }) : esc(t('Pick a unit, then tap your glowing territories.'));
    else if (G.phase === 'attack') h = UI.from ? t('Attack from :name: tap a pulsing target.', { name: b(tn(UI.from)) }) : esc(t('Tap a glowing territory as the attacker.'));
    else h = S?.legal && Object.keys(S.legal.fortify ?? {}).length === 0 ? esc(t('Moved. Now end your turn.')) : UI.from ? esc(t('Tap a pulsing neighbouring territory of yours.')) : esc(t('Move once: tap the start, then the target.'));
    $('#instruct-t').innerHTML = h;
}
function renderTicker() {
    if (!G) return;
    $('#ticker').innerHTML = `<h5>${esc(t('Chronicle'))}</h5>` + TICKER.slice(0, 7).map((l) => `<div class="ln ${l.hot ? 'hot' : ''}">${l.who !== null && l.who !== undefined ? POR(porOf(l.who), colorOf(l.who)) : '<span></span>'}<span>${esc(l.text)}</span></div>`).join('');
}
const bankSeg = (n) => Array.from({ length: 10 }, (_, i) => `<i class="${i < n ? 'on' : ''}"></i>`).join('');
let lastPhase = '';
export function render() {
    if (!G) return;
    const L = legalNow();
    renderBase(); renderPins();
    $('#round-lbl').textContent = t('Round :round', { round: G.round }) + (G.inflation ? ' · ' + t('Inflation!') : '') + (G.limit ? ' / ' + G.limit : '');
    const order = ['buy', 'attack', 'fortify']; const j = order.indexOf(G.phase);
    $$('.step').forEach((s) => { const i = order.indexOf(s.dataset.ph); s.classList.toggle('on', i === j); s.classList.toggle('done', i < j); });
    if (lastPhase !== G.phase + G.cur) { if (lastPhase && myTurn()) sfx.sting(); lastPhase = G.phase + G.cur; if (!REDUCED) gsap().fromTo('.step.on', { scale: 1.12 }, { scale: 1, duration: 0.4, ease: 'back.out(3)' }); }
    const me = mySeat();
    if (me) {
        tickNum($('#fiat'), me.fiat); tickNum($('#sats'), me.sats);
        $('#goal-seg').innerHTML = bankSeg(banksOf(G, ME));
        $('#goal-num').textContent = `${banksOf(G, ME)}/10`;
        if (L) $('#pleb-cost').textContent = t(':cost fiat', { cost: fmt(L.costs.pleb) });
    }
    const buying = !!L && G.phase === 'buy';
    $('#recruit-ui').style.display = buying ? '' : 'none';
    $('#free-lbl').hidden = !(buying && me.freePlebs > 0); $('#free-lbl').textContent = me ? t(':count free plebs to place', { count: me.freePlebs }) : '';
    $$('.unit').forEach((u) => { u.classList.toggle('on', u.dataset.unit === UI.unit); u.disabled = !L || (!L.affordable[u.dataset.unit] && me.freePlebs === 0); });
    $$('.qty button').forEach((x) => x.classList.toggle('on', x.dataset.q === UI.qty));
    $('#undo-btn').hidden = !(L && L.can_undo);
    $('#leave-btn')?.toggleAttribute('hidden', G.over || !playing());
    const mainOk = !!L && L.can_end_phase;
    $('#main-btn').disabled = !mainOk;
    $('#main-t').textContent = G.over ? t('Match over') : !myTurn() ? t(':name moves', { name: nameOf(G.cur) }) : G.phase === 'buy' ? t('To the attack') : G.phase === 'attack' ? t('End attack') : t('End turn');
    $('#main-i').innerHTML = `<use href="#${G.phase === 'fortify' ? 'i-check' : G.phase === 'buy' ? 'i-sword' : 'i-move'}"/>`;
    const seatCard = (x) => {
        const n = territoriesOf(G, x.seat).length; const bk = banksOf(G, x.seat);
        const dot = x.userId !== null && !x.bot ? `<i class="dot ${connected.has(x.seat) ? 'on' : ''}" title="${esc(connected.has(x.seat) ? t('at the table') : t('away'))}"></i>` : '';

        return `<div class="pl frame shadowed ${x.seat === G.cur && !G.over ? 'cur' : ''} ${x.out ? 'out' : ''} ${isMe(x.seat) ? 'me' : ''}" data-seat="${x.seat}" style="--pc:${colorOf(x.seat)}"><div class="shield">${POR(porOf(x.seat), colorOf(x.seat))}${dot}</div>
        <div class="nm"><span>${esc(nameOf(x.seat))}</span><em>${esc(t(':count terr.', { count: n }))}</em></div><div class="seg">${bankSeg(bk)}</div>
        <div class="meta"><span>${esc(bk === 1 ? t('1 bank') : t(':count banks', { count: bk }))} · ${fmt(x.sats)} ₿</span>${openHand(x)}</div></div>`;
    };
    // A team match (P4): the seats grouped under their clan, its logo and name first.
    $('#roster').innerHTML = sides()
        ? [0, 1].map((team) => `<div class="team-group" data-team="${team}" data-test="hyper-roster-team"><div class="team-head frame shadowed ${G.over && teamOf(G.winner) === team ? 'won' : ''} ${mySeat()?.team === team ? 'mine' : ''}">${clanLogo(team)}<b>${esc(clanName(team))}</b>${G.over && teamOf(G.winner) === team ? '<i aria-hidden="true">★</i>' : ''}</div>${G.seats.filter((x) => x.team === team).map(seatCard).join('')}</div>`).join('')
        : G.seats.map(seatCard).join('');
    $('#legend').innerHTML = `<div class="lg-head"><h5>${esc(t('Currency spaces'))}</h5><div class="lg-seg" role="group" aria-label="${esc(t('Map view (key M)'))}"><button type="button" data-mode="zone" class="${UI.mode === 'zone' ? 'on' : ''}">${esc(t('Spaces'))}</button><button type="button" data-mode="owner" class="${UI.mode === 'owner' ? 'on' : ''}">${esc(t('Owners'))}</button></div></div>` + Object.entries(ZONES).map(([z, v]) => {
        const o = zoneOwner(G, z);
        const bar = ZT[z].map((id) => { const w = G.terr[id].owner; return w === null ? '<i></i>' : `<i class="${isMe(w) ? 'me' : ''}" style="background:${colorOf(w)}"></i>`; }).join('');

        return `<div class="zr ${o !== null ? 'held' : ''}" style="--hc:${o !== null ? colorOf(o) : 'transparent'}" title="${esc(t(':zone: taking it all brings :sats M sats per turn', { zone: t(v.name), sats: fmt(v.sats) }))}"><div class="em"><img src="${A}art/zone-${z}.webp?v=1" alt="">${o !== null ? POR(porOf(o), colorOf(o)) : ''}</div><b>${esc(t(v.name))}</b><div class="rw">+${fmt(v.sats)}<img src="${A}art/ico-sats.webp?v=1" alt="${esc(t('M sats'))}"></div><div class="bar">${bar}</div><div class="bn">${esc(t(v.bonus))}</div></div>`;
    }).join('');
    // The chronicle starts right below the region list and ends above the buttons, whatever their height (the undo comes and goes).
    const tkTop = $('#legend').offsetTop + $('#legend').offsetHeight + 10; $('#ticker').style.top = tkTop + 'px'; $('#ticker').style.setProperty('--tk-top', tkTop + 'px');
    $('#ticker').style.maxHeight = Math.max(0, $('#cta').getBoundingClientRect().top - tkTop - 12) + 'px';
    $$('#legend .lg-seg button').forEach((btn) => { btn.onclick = () => { if (UI.mode !== btn.dataset.mode) $('#mode-btn').click(); }; });
    renderHand(); renderInstruct(); renderTicker();
    document.body.dataset.ply = String(G.ply);
    document.body.dataset.phase = G.phase;
    document.body.dataset.turn = myTurn() ? 'mine' : 'other';
    document.body.dataset.idle = idle() ? '1' : '0';
}
/** A seat's cards in the roster: their names in a replay (every hand is open there), else how many. */
function openHand(x) {
    if (CFG?.replay && Array.isArray(x.hand)) {
        const names = x.hand.map((c) => t(CARDS[c]?.name ?? c));

        return `<span class="cards" data-test="hyper-replay-hand" data-seat="${x.seat}" title="${esc(names.join(', '))}">${esc(names.length ? names.join(', ') : t(':count cards', { count: 0 }))}</span>`;
    }

    return `<span>${esc(x.handCount === 1 ? t('1 card') : t(':count cards', { count: x.handCount }))}</span>`;
}
let handKey = '';
function renderHand() {
    const me = mySeat(); const hand = $('#hand');
    const cards = me && Array.isArray(me.hand) && playing() ? me.hand : [];
    const key = cards.join(',') + '|' + UI.card + '|' + innerWidth;
    if (key === handKey) return; handKey = key;
    hand.innerHTML = '';
    const n = cards.length;
    cards.forEach((c, i) => {
        const card = CARDS[c]; if (!card) return;
        const el = document.createElement('button');
        el.type = 'button'; el.className = 'card' + (UI.card === c ? ' arm' : ''); el.dataset.card = c;
        el.innerHTML = `<div class="ct">${esc(t(card.name))}</div><div class="art pic" style="background-image:url(${A}art/card-${c}.jpg?v=1)">${esc(card.art)}</div><div class="ce">${esc(t(card.text))}</div><div class="cj">${esc(t(card.joke))}</div>`;
        const mid = (n - 1) / 2; const rot = (i - mid) * 7; const x = (i - mid) * (innerWidth < 820 ? 62 : 84); const y = Math.abs(i - mid) * 8;
        gsap().set(el, { x, y, rotation: rot });
        el.onmouseenter = () => { gsap().to(el, { y: y - 118, rotation: 0, scale: 1.12, zIndex: 10, duration: 0.22 }); sfx.flip(); };
        el.onmouseleave = () => gsap().to(el, { y, rotation: rot, scale: 1, zIndex: 1, duration: 0.25 });
        el.onclick = () => playCardClick(c);
        el.setAttribute('aria-label', `${t(card.name)}: ${t(card.text)}`);
        hand.appendChild(el);
    });
}
function playCardClick(c) {
    const L = legalNow();
    if (!L || !(c in L.cards)) { sfx.error(); toast(myTurn() && L?.pending_move ? t('Move troops into the conquered territory first.') : t('You play cards in your own turn.')); return; }
    if (L.cards[c] === null) { UI.card = null; act({ type: 'play_card', card: c }); return; }
    UI.card = UI.card === c ? null : c; sfx.select(); render();
}

/* ================= Turn clock ================= */
function updateClock() {
    const el = $('#turn-clock'); if (!el || !G) return;
    if (G.over || live.deadline === null || live.deadline === undefined) { el.hidden = true; return; }
    el.hidden = false;
    const total = (S?.turn_seconds ?? 90) * 1000;
    const left = Math.max(0, live.deadline - (Date.now() + clockOffset));
    const share = Math.max(0, Math.min(1, left / total));
    const ring = el.querySelector('.ring-on');
    ring.style.strokeDashoffset = String(100 - share * 100);
    ring.style.stroke = colorOf(live.seat);
    // A correspondence turn has hours: "23h", then minutes, the last 100 seconds in seconds.
    const secs = Math.ceil(left / 1000);
    el.querySelector('b').textContent = secs >= 3600 ? `${Math.floor(secs / 3600)}h` : secs > 100 ? `${Math.ceil(secs / 60)}m` : String(secs);
    el.classList.toggle('low', left < 15000);
    el.dataset.left = String(Math.ceil(left / 1000));
    el.title = t('Time left for :name', { name: nameOf(live.seat) });
}

/* ================= Settings and controls ================= */
function storage() { try { return window.localStorage; } catch (e) { return null; } }
function saveSettings() { writeSettings(storage(), { vol: AUD.vol, music: AUD.music, fx: AUD.fx, board: AUD.board, speed: UI.speed, mode: UI.mode, scenes: UI.scenes }, SETTINGS_KEY); }
function loadSettings() {
    const st = readSettings(storage(), SETTINGS_KEY);
    AUD.vol = st.vol; $('#vol').value = Math.round(st.vol * 100);
    AUD.music = st.music; $('#music-btn').setAttribute('aria-pressed', st.music);
    AUD.fx = st.fx; $('#sound-btn').setAttribute('aria-pressed', st.fx);
    AUD.board = st.board; $('#board-btn').setAttribute('aria-pressed', st.board);
    if ([1, 3, 20].includes(st.speed)) { UI.speed = st.speed; $$('#speed button').forEach((x) => x.classList.toggle('on', +x.dataset.s === st.speed)); }
    if (['owner', 'zone'].includes(st.mode)) UI.mode = st.mode;
    if (typeof st.scenes === 'boolean') { UI.scenes = st.scenes; $('#scenes-btn').setAttribute('aria-pressed', st.scenes); }
}
function bindControls() {
    $('#zoom-in').onclick = () => svg.transition().duration(350).call(zoom.scaleBy, 1.6);
    $('#zoom-out').onclick = () => svg.transition().duration(350).call(zoom.scaleBy, 1 / 1.6);
    $('#roll-btn').onclick = () => roll(false);
    $('#blitz-btn').onclick = () => roll(true);
    $('#retreat-btn').onclick = () => { closeBattle(); UI.from = null; render(); };
    $('#move-range').oninput = (e) => { $('#move-n').textContent = e.target.value; sfx.hover(); };
    $('#move-ok').onclick = async () => {
        const n = +$('#move-range').value; const { from, to, kind } = MV;
        closeMove();
        if (kind === 'conquest') await act({ type: 'move_in', count: n });
        else { UI.from = null; await act({ type: 'fortify', from, to, count: n }); }
    };
    $('#move-cancel').onclick = () => { if (MV.kind === 'fortify') { closeMove(); UI.from = null; render(); } };
    $('#main-btn').onclick = () => {
        const L = legalNow(); if (!L) return;
        if (G.phase === 'buy' && mySeat().freePlebs > 0) { sfx.error(); toast(t('Place the free plebs first.')); return; }
        UI.from = null; UI.card = null; act({ type: 'end_phase' });
    };
    $('#undo-btn').onclick = () => { if (legalNow()?.can_undo) act({ type: 'undo' }); };
    $$('.unit').forEach((u) => { u.onclick = () => { UI.unit = u.dataset.unit; sfx.select(); render(); }; });
    $$('.qty button').forEach((x) => { x.onclick = () => { UI.qty = x.dataset.q; render(); }; });
    $$('#speed button').forEach((x) => { x.onclick = () => { UI.speed = +x.dataset.s; $$('#speed button').forEach((y) => y.classList.toggle('on', y === x)); saveSettings(); }; });
    $('#mode-btn').onclick = () => { UI.mode = UI.mode === 'zone' ? 'owner' : 'zone'; render(); saveSettings(); };
    $('#scenes-btn').onclick = (e) => { UI.scenes = !UI.scenes; e.currentTarget.setAttribute('aria-pressed', UI.scenes); toast(UI.scenes ? t('Cinematics on') : t('Cinematics off: battles and cards run fast')); saveSettings(); };
    $('#sound-btn').onclick = (e) => { AUD.fx = !AUD.fx; e.currentTarget.setAttribute('aria-pressed', AUD.fx); if (AUD.fx) sfx.coin(); toast(AUD.fx ? t('Effects on') : t('Effects off (dice, clicks, explosions)')); saveSettings(); };
    $('#board-btn').onclick = (e) => { AUD.board = !AUD.board; e.currentTarget.setAttribute('aria-pressed', AUD.board); if (!AUD.board && AUD.cur) AUD.cur.pause(); toast(AUD.board ? t('Soundboard clips on') : t('Soundboard clips off, effects stay')); saveSettings(); };
    $('#music-btn').onclick = (e) => { AUD.music = !AUD.music; e.currentTarget.setAttribute('aria-pressed', AUD.music); if (AUD.musicBus) AUD.musicBus.gain.setTargetAtTime(AUD.music ? 0.42 : 0, AUD.ctx.currentTime, 0.3); if (AUD.music) startMusic(); saveSettings(); };
    $('#vol').oninput = (e) => { AUD.vol = e.target.value / 100; if (AUD.master) AUD.master.gain.setTargetAtTime(AUD.vol, AUD.ctx.currentTime, 0.05); saveSettings(); };
    $('#help-btn').onclick = () => { $('#help').hidden = false; gsap().from('#help .panel', { y: 20, opacity: 0, duration: 0.25 }); };
    $('#help-close').onclick = () => { $('#help').hidden = true; };
    $('#end-map').onclick = () => { $('#end').hidden = true; };
    const rematch = $('#rematch-btn');
    if (rematch) {
        rematch.onclick = async () => {
            rematch.disabled = true;
            const r = await NET.post(CFG.urls.rematch);
            if (r.ok && r.data) onRematch(r.data);
            else if (r.data?.reason === 'rematch_expired') onRematch({ closed: 'expired' });
            else { rematch.disabled = false; refused(r); }
        };
        const decline = $('#rematch-decline');
        if (decline) {
            decline.onclick = async () => {
                decline.disabled = true;
                const r = await NET.post(CFG.urls.rematchDecline);
                decline.disabled = false;
                if (r.ok && r.data) onRematch(r.data); else refused(r);
            };
        }
        // The rematch as it stood when the page loaded (P6): never a jump to a rematch that started meanwhile.
        if (CFG.rematch) onRematch(CFG.rematch, true);
    }
    const leave = $('#leave-btn');
    if (leave) {
        leave.onclick = async () => {
            // The first click asks (for 6 s), the second leaves.
            if (leave.dataset.armed !== '1') {
                leave.dataset.armed = '1'; leave.classList.add('armed'); toast(t('Really leave? Click again: a bot takes over.'));
                setTimeout(() => { leave.dataset.armed = '0'; leave.classList.remove('armed'); }, 6000);
                return;
            }
            const r = await NET.post(CFG.urls.leave);
            if (r.ok && r.data?.snapshot) { keepSnapshot(r.data.snapshot); await catchUp(); settle(); toast(t('You left. A bot plays your seat now.')); } else refused(r);
        };
    }
    // Keyboard: every frequent action has a key; the help screen lists them.
    const visible = (sel) => !$(sel).hidden;
    addEventListener('keydown', (e) => {
        if (e.ctrlKey || e.metaKey || e.altKey || e.target.closest('input, textarea, #chat, #emotes')) return;
        const k = e.key.toLowerCase(); const press = (sel) => { const x = $(sel); if (x && !x.disabled && x.offsetParent !== null) { e.preventDefault(); x.click(); return true; } return false; };
        if (visible('#battle') && $('#battle').classList.contains('cine')) { e.preventDefault(); return; }
        // A caption on screen: its keys are its own (tapToContinue), nothing behind it moves.
        if (visible('#banner')) return;
        if (visible('#battle')) { if (k === 'w' || k === ' ' || k === 'enter') press('#roll-btn'); else if (k === 'e') press('#blitz-btn'); else if (k === 'escape') press('#retreat-btn') || press('#skip-btn'); else if (k === 's') press('#skip-btn'); return; }
        if (visible('#move')) { const r = $('#move-range'); if (k === 'enter' || k === ' ') press('#move-ok'); else if (k === 'arrowleft' || k === 'arrowright') { e.preventDefault(); r.value = +r.value + (k === 'arrowright' ? 1 : -1); r.dispatchEvent(new Event('input')); } return; }
        if (visible('#help')) { if (k === 'escape' || k === 'enter' || k === 'h') press('#help-close'); return; }
        if (visible('#end')) return;
        if (k === 'm') $('#mode-btn').click();
        else if (k === 'k') $('#scenes-btn').click();
        else if (k === 'h') $('#help-btn').click();
        else if (k === ' ' || k === 'enter') press('#main-btn');
        else if (k === 'z' || k === 'backspace') press('#undo-btn');
        else if (['1', '3', '5', '0'].includes(k)) press(`.qty [data-q="${k === '0' ? 'max' : k}"]`);
        else if (k === 'escape' && G) { UI.card = null; UI.from = null; render(); }
    });
    addEventListener('resize', () => { fitMap(); handKey = ''; render(); });
    // Music starts with the first touch: browsers allow sound only after a gesture.
    addEventListener('pointerdown', () => { if (ctx()) startMusic(); }, { once: true, capture: true });
}

/**
 * `hyper.rematch` and the rematch endpoint's answer: who said yes, who is still asked, and once everybody
 * did, the new match, where this page goes.
 */
let rematchTimer = 0;
function onRematch(p, onLoad = false) {
    if (p.url && !onLoad) { window.location.assign(p.url); return; }
    const btn = $('#rematch-btn'); const note = $('#rematch-note'); const no = $('#rematch-decline');
    if (!btn || !note) return;
    clearTimeout(rematchTimer);
    // Closed (P6): declined by a player, run out, or started before this page loaded. The end screen says so and stays.
    if (p.closed || p.url) {
        btn.disabled = true; btn.hidden = true;
        if (no) no.hidden = true;
        note.textContent = p.url ? t('The rematch started.') : p.closed === 'declined' ? t(':name declined the rematch.', { name: p.by ?? '' }) : t('The rematch offer has run out.');
        return;
    }
    const mine = ME !== null && (p.ready ?? []).includes(ME);
    btn.hidden = false;
    btn.disabled = mine;
    // Something to decline once somebody asked.
    if (no) no.hidden = !p.table;
    $('#rematch-t').textContent = mine ? t('Rematch asked') : p.table ? t('Accept the rematch') : t('Rematch');
    const waiting = (p.waiting ?? []).length ? t('Waiting for :names', { names: p.waiting.map((s) => nameOf(s)).join(', ') }) : '';
    let until = '';
    if (p.ends_ms) {
        const far = p.ends_ms - Date.now() > 20 * 3600 * 1000;
        until = t('Open until :time.', { time: new Date(p.ends_ms).toLocaleString(CFG.locale, far ? { weekday: 'short', hour: '2-digit', minute: '2-digit' } : { hour: '2-digit', minute: '2-digit' }) });
        // The offer runs out on this page's clock too; the sweep closes an asked table besides and says so.
        rematchTimer = setTimeout(() => onRematch({ ...p, closed: 'expired' }), Math.max(0, p.ends_ms - Date.now()));
    }
    note.textContent = [waiting, until].filter(Boolean).join(' ');
}
/** The replay jumps: the table as at this snapshot's ply, nothing queued, no end screen until it ends again. */
function jump(s) {
    queue.length = 0;
    clearTimeout(settleTimer);
    sync.reset(s.ply);
    latest = s;
    ended = false;
    $('#end').hidden = true;
    hooks.onJump?.();
    applySnapshot(s);
}

/* ================= The page's surface for match.js, chat and emotes ================= */
export function seatRect(index) {
    const el = document.querySelector(`#roster .pl[data-seat="${index}"] .shield`);

    return el ? el.getBoundingClientRect() : null;
}
export const game = {
    onUpdated, onHand, catchUp, toast, render, seatRect, act, onRematch, jump,
    setSpeed(n) { UI.speed = n; },
    setConnected(seats) { connected.clear(); seats.forEach((s) => connected.add(s)); render(); },
    seatName: (i) => (G ? nameOf(i) : ''),
    seatColor: (i) => (G ? colorOf(i) : '#f7931a'),
    isBot: (i) => (G ? isBot(i) : false),
    /** stats.js: called when the end screen is up (`quiet` when the page opened on a finished match), and when the replay jumps. */
    onEnd(fn) { hooks.onEnd = fn; },
    onEndedAt(fn) { hooks.onEndedAt = fn; },
    onJump(fn) { hooks.onJump = fn; },
    me: () => ME,
    playing: () => !!G && playing(),
    /** Where the page stands, for the browser tests and a look in the console. */
    state: () => ({ ply: G?.ply ?? null, syncPly: sync?.ply ?? null, snapshotPly: S?.ply ?? null, round: G?.round, phase: G?.phase, cur: G?.cur, me: ME, over: G?.over, idle: G ? idle() : false, running, queued: queue.length, legal: G ? legalNow() : null, battle: BT.open, moveOpen: !$('#move').hidden, shown: [...SHOWN] }),
};

export function startGame(config, net) {
    CFG = config; NET = net; A = config.assets ?? '/hyper/';
    buildWorld();
    sync = new PlySync(config.snapshot.ply);
    setAudioHooks({
        assets: A,
        botTurn: () => !!G && !G.over && (isBot(G.cur) || shownSource === 'bot'),
        musicMood: () => {
            if (!G || G.over || ME === null) return { tense: false, standing: 'even' };
            const score = (x) => x.sats + territoriesOf(G, x.seat).length * 0.3;
            const rivals = G.seats.filter((x) => x.seat !== ME && !x.out); const best = rivals.length ? Math.max(...rivals.map(score)) : 0;
            const mine = score(G.seats[ME]);

            return { tense: myTurn() && G.phase === 'attack', standing: G.seats[ME].out ? 'behind' : mine >= best * 1.1 ? 'ahead' : mine < best * 0.75 ? 'behind' : 'even' };
        },
    });
    $$('.up[data-por]').forEach((el) => { el.innerHTML = POR(el.dataset.por, el.dataset.por === 'pleb' ? '#7fe0a6' : el.dataset.por === 'maxi' ? '#f7931a' : '#6f8fc4'); });
    $('#help-how').innerHTML = HOW.map(([n, h, x]) => `<div class="frame"><b><span>${esc(n)}</span>${esc(t(h))}</b><p>${esc(t(x))}</p></div>`).join('');
    loadSettings();
    initBoard();
    buildOverlay();
    bindControls();
    applySnapshot(config.snapshot);
    fitMap();
    window.d3.select('#map').call(zoom.transform, window.d3.zoomIdentity);
    baseKey = ''; render(); scalePins();
    log(t('The Hyperbitcoinization begins. Fiat rots, sats stay.'), true);
    if (!REDUCED) {
        gsap().from('#board', { scale: 1.2, opacity: 0, duration: 0.9, ease: 'power3.out' });
        gsap().from('.pin', { scale: 0, transformOrigin: 'center center', duration: 0.4, ease: 'back.out(3)', stagger: { each: 0.012, from: 'random' }, delay: 0.3 });
    }
    const muted = [!AUD.fx && t('Effects'), !AUD.board && t('Soundboard'), !AUD.music && t('Music')].filter(Boolean);
    if (muted.length) setTimeout(() => toast(t('Muted: :what. Switch them on again at the top right.', { what: muted.join(', ') })), 2500);
    setInterval(updateClock, 250); updateClock();
    // Fetch every battle stage in the background, so the first battle opens without a loader.
    setTimeout(() => { if (window.Arena && WEBGL && UI.scenes) window.Arena.preloadAll(); }, 2500);
    window.hyperGame = game;

    return game;
}
