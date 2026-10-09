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
import { createArena, fieldYAt } from './arena.js';
import { HEIGHT } from './physics.js';

export const KEY_STEP = 1800;

/**
 * The page's saved settings (localStorage `pong-settings`, the browser test's handle): `speed` runs the clock of a game
 * against a bot faster (1 = real time), `autoplay` lets a bot of that level play the player's paddle.
 */
export function readSettings() {
    try {
        const stored = JSON.parse(localStorage.getItem('pong-settings') || '{}');
        const speed = Number(stored.speed);
        const autoplay = Number(stored.autoplay);

        return { speed: Number.isFinite(speed) && speed > 0 ? Math.min(speed, 200) : 1, autoplay: [1, 2, 3, 4].includes(autoplay) ? autoplay : null };
    } catch {
        return { speed: 1, autoplay: null };
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
})[event];

/**
 * The stage: the arena fitted into the window, and the player's wanted paddle position. `input.target(current)` is
 * where the player wants their paddle, given where it is (the keys move it from there).
 */
export function createStage() {
    const $ = (id) => document.getElementById(id);
    const field = $('field');
    const stage = $('stage');
    const arena = createArena($('arena'));
    const keys = { up: false, down: false };
    let target = HEIGHT >> 1;
    let portrait = false;
    let box = { w: 0, h: 0 };

    // Fit the field into the stage: 16:9 lying, 9:16 upright. Called at the start and when the width changes.
    let fittedWidth = -1;
    const fit = () => {
        fittedWidth = innerWidth;
        const top = 56;
        const availW = innerWidth - 16;
        const availH = innerHeight - top - 12;
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
        stage.style.height = `${innerHeight - top}px`;
        field.style.width = `${w}px`;
        field.style.height = `${h}px`;
        box = { w, h };
        arena.resize(w, h, portrait);
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
