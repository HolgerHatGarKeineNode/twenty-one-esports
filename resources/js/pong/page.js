/**
 * What both Proof of Pong pages share (plan "Proof of Pong", P1/P2): the game against a bot (game.js) and the live
 * match (live.js). The page's config, its texts, the meme events' names and lines, the field fitted to the window and
 * the player's input.
 *
 * The field is fitted to the window once and again only when the window's WIDTH changes: a phone shows and hides its
 * address bar (and keyboard) as height-only resizes, and a field that followed them jumped (Blockli, 2026-10-09).
 * The player's paddle follows the pointer (mouse, or a thumb dragged anywhere on the page) or the keys: W/S and the
 * arrows (left/right as well on an upright field).
 */
import { autoQuality, createArena, fieldYAt } from './arena.js';
import { HEIGHT } from './physics.js';

export const KEY_STEP = 1800;

/**
 * The page's saved settings (localStorage `pong-settings`, the browser test's handle): `speed` runs the clock of a game
 * against a bot faster (1 = real time), `autoplay` lets a bot of that level play the player's paddle, `quality` is the
 * arena's tier (auto, high, medium, low), `motion` false turns the moving effects off as the system's reduced motion
 * does, `eventEvery` sets the event block of a game against a bot (1: every rally a meme event) (the P7 browser test's seam: a game
 * against a bot is the browser's own and stored nowhere).
 */
export function readSettings() {
    const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
    try {
        const stored = JSON.parse(localStorage.getItem('pong-settings') || '{}');
        const speed = Number(stored.speed);
        const autoplay = Number(stored.autoplay);
        const eventEvery = Number(stored.eventEvery);

        return {
            speed: Number.isFinite(speed) && speed > 0 ? Math.min(speed, 200) : 1,
            autoplay: [1, 2, 3, 4].includes(autoplay) ? autoplay : null,
            quality: ['high', 'medium', 'low'].includes(stored.quality) ? stored.quality : 'auto',
            motion: typeof stored.motion === 'boolean' ? stored.motion : !reduced,
            force2d: stored.force2d === true,
            eventEvery: Number.isInteger(eventEvery) && eventEvery >= 1 && eventEvery <= 100 ? eventEvery : null,
        };
    } catch {
        return { speed: 1, autoplay: null, quality: 'auto', motion: !reduced, force2d: false, eventEvery: null };
    }
}

/** Saves one of the settings above, keeping the others. */
export function writeSetting(key, value) {
    try {
        const stored = JSON.parse(localStorage.getItem('pong-settings') || '{}');
        stored[key] = value;
        localStorage.setItem('pong-settings', JSON.stringify(stored));
    } catch {
        // Held for this visit only.
    }
}

export function readConfig() {
    const config = JSON.parse(document.getElementById('pong-config').textContent);
    const t = (key, replace = {}) => Object.entries(replace).reduce((text, [k, v]) => text.replace(`:${k}`, v), config.texts[key] ?? key);

    return { config, t };
}

/** Each meme event's name and line (literal keys, so the page's text test finds them). */
export const eventText = (t, event) => ({
    halving: [t('Halving'), t('The ball is half the size, its point counts double.')],
    brrr: [t('Brrr'), t('The ball flies faster.')],
    pizza: [t('Pizza Day'), t('Two balls at once.')],
    difficulty: [t('Difficulty Adjustment'), t('Both paddles are shorter.')],
    tax: [t('Taxation is Theft'), t('A tax office patrols the centre line; the ball bounces off it.')],
    controls: [t('Capital Controls'), t('A border wall with a moving gap; hit the wall and the ball comes back.')],
    few: [t('Few understand'), t('The ball is invisible in the middle of the field.')],
    pow: [t('Proof of Work'), t('Each of your hits makes your paddle longer.')],
    arbeitsamt: [t('Job Centre – Please wait'), t('At the centre line the ball draws a number and waits a second.')],
})[event];

/**
 * The stage: the arena fitted into the window, and the player's wanted paddle position. `input.target(current)` is
 * where the player wants their paddle, given where it is (the keys move it from there).
 */
export function createStage() {
    const $ = (id) => document.getElementById(id);
    const field = $('field');
    const stage = $('stage');
    const settings = readSettings();
    const arena = createArena($('arena'), $('arena3d'), { motion: settings.motion, force2d: settings.force2d });
    // A software renderer (SwiftShader, llvmpipe) draws on the CPU: `auto` starts it on the lowest tier.
    const software = /swiftshader|llvmpipe|software/i.test(arena.gpu ?? '');
    arena.setQuality(settings.quality === 'auto' ? (software ? 'low' : autoQuality()) : settings.quality);
    document.body.classList.toggle('gl', arena.kind === 'webgl');
    if (arena.kind !== 'webgl') $('arena3d')?.remove();
    const keys = { up: false, down: false };
    let target = HEIGHT >> 1;
    let portrait = false;
    let box = { w: 0, h: 0 };

    // Fit the field into the stage: 16:9 lying, 9:16 upright. Called at the start and when the width changes.
    let fittedWidth = -1;
    const fit = () => {
        fittedWidth = innerWidth;
        const top = 56;
        // The strip under the stage keeps the music's credit line (#now-playing, P8) off the field.
        const credit = 20;
        const availW = innerWidth - 16;
        const availH = innerHeight - top - credit - 12;
        portrait = innerWidth < innerHeight;
        const ratio = portrait ? 9 / 16 : 16 / 9;
        let w = Math.min(availW, availH * ratio);
        let h = w / ratio;
        if (h > availH) {
            h = availH;
            w = h * ratio;
        }
        w = Math.floor(w);
        h = Math.floor(h);
        stage.style.height = `${innerHeight - top - credit}px`;
        field.style.width = `${w}px`;
        field.style.height = `${h}px`;
        box = { w, h };
        const r = field.getBoundingClientRect();
        arena.resize({ W: innerWidth, H: innerHeight, field: { x: r.left, y: r.top, w, h }, portrait });
        document.body.dataset.portrait = portrait ? '1' : '0';
    };
    fit();
    addEventListener('resize', () => {
        if (innerWidth !== fittedWidth) fit();
    });

    const follow = (event) => {
        const r = field.getBoundingClientRect();
        target = fieldYAt(event.clientX - r.left, event.clientY - r.top, box.w, box.h, portrait);
    };
    addEventListener('pointermove', follow);
    addEventListener('pointerdown', follow);
    const keyOf = (event) => ({ KeyW: 'up', ArrowUp: 'up', ArrowLeft: 'up', KeyS: 'down', ArrowDown: 'down', ArrowRight: 'down' })[event.code];
    addEventListener('keydown', (event) => {
        const key = keyOf(event);
        if (!key) return;
        keys[key] = true;
        event.preventDefault();
    });
    addEventListener('keyup', (event) => {
        const key = keyOf(event);
        if (key) keys[key] = false;
    });

    return {
        arena,
        settings,
        portrait: () => portrait,
        input: {
            target(current) {
                if (keys.up || keys.down) {
                    target = Math.max(0, Math.min(HEIGHT, current + (keys.down ? KEY_STEP : -KEY_STEP)));
                }

                return target;
            },
        },
    };
}
