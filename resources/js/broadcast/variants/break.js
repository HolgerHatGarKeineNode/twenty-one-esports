/**
 * The break scene (plan P5): full screen and opaque, for the minutes before a stream starts, a break between rounds
 * and the end. It runs on its own from the preset and the snapshot; the state (starting soon, short break, thanks for
 * watching) comes from the server (OverlaySnapshot::breakScene(): the preset's pin, its tournament, the clock).
 *
 * Hierarchy: 1. the hero on the left (the state, the clock to the start or the round up next, the winner at the end)
 * under the league's mark as a lit block; 2. the column on the right, one section at a time behind a panel wipe: the
 * next matches, the top of every ladder, what comes up, the prize pot (only above zero); 3. the join card with its QR
 * code under the column, the ticker along the foot, the music credit. The room behind it all is the arena plate with
 * its light rig, darkened where words stand.
 *
 * Timing: a stinger opens the scene and covers every change of state (the old hero leaves behind it, the new one
 * builds in as it clears). Sections hold TIMING.breakSectionMs at least (a list of names read twice); a section
 * takes new data only when it next comes round. Music (module `music`): the league's free MIDI tracks
 * (resources/js/midi/player.js) on their own audio context; an OBS browser source may play it without a click, a
 * normal browser tab waits for one. The credit line names the track while it really plays.
 */

import { createMidiPlayer } from '../../midi/player.js';
import { createArena } from '../elements/arena.js';
import { createHero } from '../elements/hero.js';
import { createPanelPage } from '../elements/panel.js';
import { createWipe } from '../elements/wipe.js';
import { createLine, disposeTree } from '../text.js';
import { COLOR, TYPE } from '../tokens.js';
import { TIMING } from '../timing.js';
import { tickerItems } from './shared.js';

const DAY = 86_400_000;
export const PANEL = Object.freeze({ x: 1064, y: 54, w: 760, h: 740 });
const JOIN = Object.freeze({ x: 1064, y: 850, w: 760, h: 104 });
const NAME = { family: 'display', weight: 600, size: 26, leading: 1.2, tracking: 0 };
const SEED = { family: 'mono', weight: 500, size: 22, leading: 1.2, tracking: 0 };
const SMALL = { family: 'mono', weight: 500, size: 22, leading: 1.3, tracking: 0 };
const BIG = { family: 'display', weight: 800, size: 96, leading: 1.0, tracking: -0.01 };

const chunk = (list, n) => {
    const out = [];
    for (let i = 0; i < list.length; i += n) out.push(list.slice(i, i + n));

    return out;
};

/** What the hero says for this snapshot: the state, and what it counts down to or names. */
export function heroFor(ctx, s = ctx.snapshot()) {
    const t = ctx.texts;
    const B = s.break || { state: 'break', target: null };
    const T = s.tournament;
    const target = B.target;
    const headline = { soon: t.stateSoon, break: t.stateBreak, end: t.stateEnd }[B.state] || t.stateBreak;
    const hero = { state: B.state, headline, line: null, label: null, startsAt: null, big: null, when: null, winner: false };
    const toTarget = () => {
        if (!target) {
            hero.line = t.joinName;
            hero.big = s.site.host;

            return;
        }
        hero.line = target.gameName ? `${target.name}, ${target.gameName}` : target.name;
        const left = Date.parse(target.startsAt) - Date.now();
        if (left > 0 && left < DAY) {
            hero.label = t.startsIn;
            hero.startsAt = target.startsAt;
            hero.when = `${t.starts} ${target.starts}`;
        } else {
            hero.label = t.starts;
            hero.big = target.starts;
        }
    };
    if (B.state === 'end') {
        if (B.champion && T) {
            hero.line = T.name;
            hero.label = t.tournamentWinner;
            hero.big = B.champion;
            hero.winner = true;
        } else if (s.nextCup) {
            hero.line = s.nextCup.gameName ? `${s.nextCup.name}, ${s.nextCup.gameName}` : s.nextCup.name;
            hero.label = t.starts;
            hero.big = s.nextCup.starts;
        } else {
            hero.line = t.joinName;
            hero.big = s.site.host;
        }
    } else if (B.state === 'break' && T && T.status === 'running') {
        hero.line = T.name;
        hero.label = t.nextUp;
        hero.big = T.board?.round || T.statusLine;
    } else {
        toTarget();
    }

    return hero;
}

/** The column's sections for this snapshot, in turn: next matches, the ladders' leaders, what comes up, the pot. */
export function sectionsFor(ctx, s = ctx.snapshot()) {
    const t = ctx.texts;
    const B = s.break || {};
    const W = PANEL.w;
    const modules = s.preset.modules;
    const list = [];

    if ((B.matches || []).length > 0) {
        list.push({
            key: 'matches',
            title: t.nextMatches,
            rows: B.matches.slice(0, 5).map((m) => {
                const items = [];
                const sides = m.sides.slice(0, 2);
                if (m.live) items.push({ chip: t.live, right: 22, top: 12 });
                else items.push({ text: m.round, style: SMALL, color: COLOR.ink3, right: 24, top: 14, silent: true });
                sides.forEach((side, i) => {
                    const top = 10 + i * 44;
                    const seedW = side.seed ? 40 : 0;
                    if (side.seed) items.push({ text: String(side.seed), style: SEED, color: COLOR.ink3, left: 28, top: top + 8, silent: true });
                    items.push({ text: side.name, style: NAME, color: side.known ? COLOR.ink : COLOR.ink3, left: 28 + seedW, top: top + 6, maxWidth: W - 28 - seedW - 170 });
                });

                return { h: 102, heat: !!m.live, items };
            }),
        });
    }

    // Three ladders to a page at most, spread evenly (four make two and two, not three and one).
    const ladders = (B.leaders || []).filter((l) => l.rows.length > 0);
    chunk(ladders, Math.ceil(ladders.length / Math.ceil(ladders.length / 3))).forEach((ladders, page) => {
        list.push({
            key: `leaders-${page}`,
            title: t.topPlayers,
            rows: ladders.map((ladder) => {
                const art = ctx.art(ladder.emblem);
                const items = [];
                if (art !== 'mark') items.push({ image: art, w: 44, h: 44, x: 50, y: 30, rim: 0.6 });
                items.push({ text: ladder.name, style: TYPE.head, color: COLOR.ink2, left: art !== 'mark' ? 84 : 28, top: 16, maxWidth: W - 120 });
                ladder.rows.slice(0, 3).forEach((row, i) => {
                    const top = 58 + i * 40;
                    if (row.place === 1) items.push({ bar: true, top: top + 4, h: 32 });
                    items.push({ text: String(row.place), style: SEED, color: row.place === 1 ? COLOR.btcHi : COLOR.ink3, left: 28, top: top + 6, silent: true });
                    items.push({ text: row.rating, style: SEED, color: COLOR.ink2, right: 28, top: top + 6, silent: true });
                    items.push({ text: row.name, style: NAME, color: COLOR.ink, left: 68, top: top + 2, maxWidth: W - 68 - 150 });
                });

                return { h: 66 + 3 * 40, items, corner: null };
            }),
        });
    });

    const coming = (s.upcoming || []).filter((u) => u.id !== B.target?.id).slice(0, 4);
    if (coming.length > 0) {
        list.push({
            key: 'coming',
            title: t.comingUp,
            rows: coming.map((u) => {
                const art = ctx.art(u.emblem);
                const items = [];
                if (art !== 'mark') items.push({ image: art, w: 64, h: 64, x: 60, y: 48, rim: 0.8 });
                const left = art !== 'mark' ? 112 : 28;
                items.push({ text: u.name, style: NAME, color: COLOR.ink, left, top: 16, maxWidth: W - left - 28 });
                const pot = modules.pots && u.pot > 0 ? `, ${ctx.group(u.pot)} sats` : '';
                items.push({ text: `${u.gameName}, ${u.starts}${pot}`, style: SMALL, color: COLOR.ink2, left, top: 56, maxWidth: W - left - 28 });

                return { h: 96, items };
            }),
        });
    }

    if (B.pot && modules.pots) {
        list.push({
            key: 'pot',
            title: t.prizePot,
            rows: [{
                h: 300, heat: true,
                items: [
                    { image: ctx.art('trophy'), w: 220, h: 250, x: 150, y: 150, rim: 1.1 },
                    { text: ctx.group(B.pot), style: BIG, color: COLOR.ink, left: 300, top: 64, maxWidth: W - 330 },
                    { text: 'sats', style: TYPE.line, color: COLOR.ink2, left: 304, top: 172 },
                    { text: ctx.fill(t.potFor, { tournament: B.target?.name || '' }), style: SMALL, color: COLOR.ink2, left: 304, top: 222, maxWidth: W - 334 },
                ],
            }],
        });
    }

    if (list.length === 0) {
        list.push({
            key: 'join',
            title: t.joinName,
            rows: [{ h: 140, heat: true, items: [{ text: ctx.fill(t.spotLine, { host: s.site.host }), style: TYPE.line, color: COLOR.ink, left: 28, top: 30, maxWidth: W - 56 }, { text: t.joinScan, style: SMALL, color: COLOR.ink2, left: 28, top: 84 }] }],
        });
    }

    return list;
}

export function runBreak(ctx) {
    const { b, texts: t, sound, director } = ctx;
    const modules = () => ctx.snapshot().preset.modules;
    b.fullScreen();
    const arena = createArena(b.stage, {
        url: '/broadcast/art/plate-arena.webp',
        exposure: 0.62,
        shade: [{ x: 60, y: 400, w: 940, h: 500 }, { x: PANEL.x - 30, y: 40, w: PANEL.w + 60, h: 930 }],
    });
    b.stage.onFrame((now) => arena.update(now));

    const t0 = b.stage.now();
    b.stinger({ start: t0 + 300, onPeak: () => sound.play('hit') });
    director.later(t0 + 300, () => sound.play('whoosh'));
    const after = t0 + 300 + TIMING.stingerMs;

    if (modules().ticker && ctx.snapshot().ticker.length > 0) {
        b.ticker({ start: after + 200, label: t.live, items: tickerItems(ctx, []), source: () => tickerItems(ctx, []) });
    }

    // The hero, and a new one behind a stinger when what it says changes (never before it has been read).
    let hero = null;
    let heroKey = '';
    const keyOf = (h) => JSON.stringify({ ...h, startsAt: h.startsAt ? 'clock' : null });
    const heroAt = (start) => {
        const spec = heroFor(ctx);
        heroKey = keyOf(spec);
        hero = b.element(createHero(b.stage, b.timeline, b.particles, { start, hero: spec, art: { crown: ctx.art('crown') } }));
    };
    heroAt(t0 + 300 + TIMING.stingerPeakMs - 200);
    setInterval(() => {
        if (!hero) return;
        const next = keyOf(heroFor(ctx));
        const now = b.stage.now();
        if (next === heroKey || now < hero.seg.start + hero.seg.introMs + hero.seg.holdRuleMs) return;
        heroKey = next;
        const at = now + 400;
        b.stinger({ start: at, onPeak: () => sound.play('hit') });
        director.later(at, () => sound.play('whoosh'));
        hero.retire(at + TIMING.stingerPeakMs - TIMING.heroOutroMs - 300);
        heroAt(at + TIMING.stingerPeakMs - 200);
    }, 1000);

    // The join card under the column, for good.
    const site = ctx.snapshot().site;
    if (modules().qr && site.qr) {
        b.lowerThird({ slot: JOIN, kind: 'join', start: after + 900, name: t.joinScan, line: site.host, qr: site.qr, holdMs: 3_600_000 });
    }

    // The column: one section at a time; the wipe covers the change.
    let page = null;
    let turn = 0;
    let turning = false;
    const pageAt = (start) => {
        const list = sectionsFor(ctx);
        const spec = list[turn++ % list.length];
        const holdMs = list.length === 1 ? 60000 : TIMING.breakSectionMs;
        page = b.element(createPanelPage(b.stage, b.timeline, { start, slot: PANEL, title: spec.title, rows: spec.rows, holdMs, extra: { section: spec.key } }));
    };
    pageAt(after + 400);
    setInterval(() => {
        if (!page || turning) return;
        const now = b.stage.now();
        const outroAt = page.seg.start + page.seg.introMs + page.seg.holdMs;
        // The wipe arrives as the page leaves: it covers the column the moment the old rows are gone.
        if (now < outroAt - 300) return;
        turning = true;
        const wipeStart = outroAt + TIMING.panelOutroMs - TIMING.wipeCoverMs + 60;
        b.element(createWipe(b.stage, b.timeline, { start: wipeStart, rect: { x: PANEL.x - 16, y: PANEL.y - 8, w: PANEL.w + 40, h: PANEL.h + 24 } }));
        director.later(wipeStart, () => {
            pageAt(wipeStart + TIMING.wipeCoverMs + 200);
            turning = false;
        });
    }, 100);

    // Music and its credit.
    let credit = null;
    let audio = null;
    let lastTrack = null;
    const showCredit = (track) => {
        if (credit) {
            credit.retire(b.stage.now());
            credit = null;
        }
        if (!track || !audio || audio.state !== 'running') return;
        credit = b.element(createCredit(b.stage, b.timeline, { start: b.stage.now() + 200, text: ctx.fill(t.music, { title: track.title, author: track.author, license: track.license }) }));
    };
    if (modules().music) {
        try {
            audio = new AudioContext();
            const gain = audio.createGain();
            gain.gain.value = 0.7;
            gain.connect(audio.destination);
            const player = createMidiPlayer({ ctx: audio, output: gain, level: 0.5 });
            window.addEventListener('midi-track', (e) => { lastTrack = e.detail; showCredit(e.detail); });
            audio.resume().catch(() => {});
            player.start();
            // A tab waits for a click before it plays; OBS does not.
            window.addEventListener('pointerdown', () => audio.resume().then(() => showCredit(lastTrack)), { once: true });
        } catch {
            audio = null;
        }
    }

    return () => ({ hero: hero?.seg.state ?? null, section: page?.seg.section ?? null, sections: sectionsFor(ctx).map((s) => s.key), music: audio ? audio.state : 'off' });
}

/** The music credit: one line in the foot of the hero column, while the track plays. */
function createCredit(stage, timeline, { start, text }) {
    const { THREE } = stage;
    const root = new THREE.Group();
    stage.scene.add(root);
    const line = createLine(stage, text, TYPE.crawl, { color: COLOR.ink3, maxWidth: 904 });
    const p = stage.toWorld(96, 912);
    line.mesh.position.set(p.x - line.pad + line.width / 2, p.y + line.pad - line.height / 2, 0.6);
    root.add(line.mesh);
    const seg = timeline.add({ kind: 'credit', slot: 'credit', texts: [text], start, introMs: 800, holdMs: 3_600_000, outroMs: 500 });

    return {
        seg,
        update(t) {
            const ph = timeline.phase(seg, t);
            root.visible = ph.name !== 'before' && ph.name !== 'after';
            line.material.uniforms.reveal.value = ph.name === 'intro' ? ph.p : ph.name === 'outro' ? 1 - ph.p : 1;
            timeline.observe(seg, ph, t, [line.mesh]);
        },
        retire(t) { timeline.endAt(seg, t); },
        dispose() {
            stage.scene.remove(root);
            disposeTree(root);
        },
    };
}

