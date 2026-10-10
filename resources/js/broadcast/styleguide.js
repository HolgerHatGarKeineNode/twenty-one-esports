/**
 * /broadcast/styleguide (admin): the broadcast design system on one page, and its demo program running live on the
 * engine. `?stage=1` shows the stage alone on a transparent page, as an OBS browser source would (screenshots,
 * video, the transparency test); `?sound=1` enables the stinger sounds there (OBS may autoplay).
 *
 * The documentation is written from the same modules the overlays use (tokens, timing, curves), so it cannot drift.
 * Without WebGL the stage says so and the documentation still renders.
 */

import { BEZIER } from './curves.js';
import { createBroadcast, webglAvailable } from './engine.js';
import { createSound } from './sound.js';
import { COLOR, SLOTS, TYPE } from './tokens.js';
import { RULES, TIMING, holdForTexts } from './timing.js';

const config = JSON.parse(document.getElementById('broadcast-config').textContent);
const params = new URLSearchParams(location.search);
const PROGRAM_MS = 30000;
const stageOnly = params.get('stage') === '1';
if (stageOnly) document.documentElement.classList.add('stage-only');

function el(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);
    Object.entries(attrs).forEach(([k, v]) => (k === 'text' ? (node.textContent = v) : node.setAttribute(k, v)));
    children.forEach((c) => node.append(c));

    return node;
}

function renderDocs() {
    const t = config.texts;
    const timing = document.querySelector('[data-doc=timing]');
    if (!timing) return;
    const rows = [
        [t.ruleHold, `max(${RULES.holdMinMs / 1000} s, ${RULES.holdBaseMs / 1000} s + ${RULES.holdPerWordMs / 1000} s × ${t.words})`],
        [t.ruleIntro, `${RULES.introMinMs}–${RULES.introMaxMs} ms`],
        [t.ruleOutro, `${RULES.outroMinMs}–${RULES.outroMaxMs} ms`],
        [t.rulePride, `${RULES.prideMinMs / 1000}–${RULES.prideMaxMs / 1000} s`],
        [t.ruleTicker, `≤ ${RULES.tickerMaxPxPerS} px/s`],
        [t.ruleRotation, `≥ ${RULES.rotationMinMs / 1000} s`],
    ];
    timing.append(...rows.map(([k, v]) => el('div', { class: 'row' }, [el('dt', { text: k }), el('dd', { text: v })])));
    const used = document.querySelector('[data-doc=timing-used]');
    Object.entries(TIMING).forEach(([k, v]) => used.append(el('div', { class: 'row' }, [el('dt', { text: k }), el('dd', { text: k.endsWith('PxPerS') ? `${v} px/s` : `${v} ms` })])));

    const type = document.querySelector('[data-doc=type]');
    Object.entries(TYPE).forEach(([name, s]) => {
        const sample = el('p', { class: `spec spec-${s.family}`, text: config.texts.typeSample[name] || name });
        sample.style.cssText = `font-weight:${s.weight};font-size:calc(${s.size} * var(--u));line-height:${s.leading};letter-spacing:${s.tracking}em`;
        type.append(el('div', { class: 'type-row' }, [sample, el('p', { class: 'meta', text: `${name}, ${s.family === 'mono' ? 'JetBrains Mono' : 'Unbounded'} ${s.weight}, ${s.size} px / ${s.leading}` })]));
    });

    const colors = document.querySelector('[data-doc=colors]');
    Object.entries(COLOR).forEach(([name, hex]) => {
        const chip = el('span', { class: 'swatch-chip' });
        chip.style.background = hex;
        colors.append(el('li', { class: 'swatch' }, [chip, el('span', { class: 'swatch-name', text: name }), el('code', { text: hex })]));
    });

    const curves = document.querySelector('[data-doc=curves]');
    Object.entries(BEZIER).forEach(([name, [x1, y1, x2, y2]]) => {
        const svg = `<svg viewBox="-10 -30 120 160" aria-hidden="true"><path class="axis" d="M0 100H100M0 100V0"/><path class="curve" d="M0 100 C${x1 * 100} ${100 - y1 * 100} ${x2 * 100} ${100 - y2 * 100} 100 0"/></svg>`;
        const fig = el('figure', { class: 'curve-card' });
        fig.innerHTML = svg;
        fig.append(el('figcaption', {}, [el('strong', { text: name }), el('span', { text: config.texts.curves[name] }), el('code', { text: `cubic-bezier(${[x1, y1, x2, y2].join(', ')})` })]));
        curves.append(fig);
    });

    const slots = document.querySelector('[data-doc=slots]');
    const rects = Object.entries(SLOTS).map(([name, r]) => `<g class="slot slot-${name}"><rect x="${r.x}" y="${r.y}" width="${r.w}" height="${r.h}"/><text x="${r.x + 16}" y="${r.y + 36}">${config.texts.slots[name]}</text></g>`).join('');
    slots.innerHTML = `<svg viewBox="0 0 1920 1080" role="img" aria-label="${config.texts.slotsLabel}"><rect class="frame" x="0" y="0" width="1920" height="1080"/><rect class="safe" x="96" y="54" width="1728" height="972"/>${rects}</svg>`;
}

/** The demo program: one 30 s cycle, repeated, scheduled ahead so window.broadcast.timeline() shows what is planned. */
function program(b, sound, t0) {
    const d = config.demo;
    const at = (ms) => t0 + ms;
    b.lowerThird({ start: at(1200), name: d.lower[0].name, line: d.lower[0].line, emblem: config.art['emblem-rocket-league'] });
    b.lowerThird({ start: at(1200 + TIMING.rotationMs), name: d.lower[1].name, line: d.lower[1].line, emblem: config.art['emblem-chess'] });
    b.pride({ start: at(3000), side: 'right', ...d.prideRight, trophy: config.art['rank-up'], energy: config.art['energy-wide'] });
    b.pride({ start: at(16000), side: 'left', ...d.prideLeft, trophy: config.art.trophy, energy: config.art['energy-wide'] });
    b.stinger({ start: at(27500), onPeak: () => sound.play('hit') });
    setTimeout(() => sound.play('riser'), Math.max(0, at(3000) - 1200 - b.stage.now()));
    setTimeout(() => sound.play('whoosh'), Math.max(0, at(27500) - b.stage.now()));
}

async function start() {
    renderDocs();
    const canvas = document.getElementById('broadcast-canvas');
    const status = document.querySelector('[data-broadcast-status]');
    if (!webglAvailable() || !window.THREE) {
        document.body.dataset.broadcast = 'no-webgl';
        if (status) status.hidden = false;
        window.broadcast = { webgl: false };

        return;
    }
    const b = await createBroadcast(canvas, { tier: params.get('tier') });
    const sound = createSound();
    let cycle = 0;
    const plan = () => {
        program(b, sound, b.stage.now() + 200);
        cycle++;
        setTimeout(plan, PROGRAM_MS);
    };
    const run = () => {
        b.ticker({ start: b.stage.now() + 300, label: config.demo.tickerLabel, items: config.demo.ticker });
        plan();
        document.body.dataset.broadcast = 'running';
    };
    b.start();
    // Reduced motion: the documentation page waits for a press before it plays the program; the stage alone (OBS) always plays.
    const play = document.querySelector('[data-action=play]');
    if (!stageOnly && matchMedia('(prefers-reduced-motion: reduce)').matches && play) {
        play.hidden = false;
        document.body.dataset.broadcast = 'waiting';
        play.addEventListener('click', () => { play.hidden = true; run(); }, { once: true });
    } else {
        run();
    }

    if (params.get('sound') === '1') sound.enable();
    document.querySelector('[data-action=sound]')?.addEventListener('click', async (e) => {
        const on = await sound.enable();
        e.currentTarget.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    document.querySelector('[data-action=stinger]')?.addEventListener('click', () => {
        const s = b.stinger({ start: b.stage.now() + 50, onPeak: () => sound.play('hit') });
        sound.play('whoosh');
        void s;
    });
    document.querySelectorAll('[data-backdrop]').forEach((btn) => btn.addEventListener('click', () => {
        document.querySelector('.stage').dataset.backdrop = btn.dataset.backdrop;
        document.querySelectorAll('[data-backdrop]').forEach((o) => o.setAttribute('aria-pressed', o === btn ? 'true' : 'false'));
    }));

    // Live timeline readout under the stage: what is on air, in which phase, against its rule.
    const readout = document.querySelector('[data-doc=live]');
    if (readout) {
        setInterval(() => {
            const snap = window.broadcast.timeline();
            const now = snap.now;
            const rows = snap.segments.filter((s) => s.end > now - 2000 && s.start < now + 6000).slice(-6);
            readout.replaceChildren(...rows.map((s) => {
                const phase = now < s.start ? config.texts.phases.before : now < s.start + s.introMs ? config.texts.phases.intro : now < s.start + s.introMs + s.holdMs ? config.texts.phases.hold : now < s.end ? config.texts.phases.outro : config.texts.phases.after;
                const hold = s.kind === 'ticker' ? `${s.pxPerS} px/s` : s.kind === 'stinger' ? '–' : `${(s.holdMs / 1000).toFixed(1)} s ≥ ${(holdForTexts(s.texts) / 1000).toFixed(1)} s`;

                return el('tr', {}, [el('td', { text: config.texts.kinds[s.kind] }), el('td', { text: phase }), el('td', { text: `${s.introMs} ms` }), el('td', { text: hold }), el('td', { text: s.kind === 'ticker' ? '–' : `${s.outroMs} ms` })]);
            }));
        }, 250);
    }
    void cycle;
}

start();
