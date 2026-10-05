/*
 * The Blockfill page (plan "Blockfill", P3; resources/views/pages/stacker/⚡play):
 * an Alpine component around the engine. It owns no rules: keys become
 * inputs (session.js), a fixed 60 Hz ticker decides when a tick happens
 * (ticker.js), requestAnimationFrame only draws (renderer.js).
 *
 * Practice: a local random seed, for guests too; the best practice time stays
 * in this browser. Ranked (logged in): the run is asked for right before the
 * countdown (one-time token and seed), its start is announced and the game
 * clock starts only once the server has answered, the replay is submitted at
 * the end with how it was played (P7: only a keyboard run counts, a press on
 * the on-screen buttons makes it a touch run) and the page asks for the
 * verdict until it has one. Leaving the tab
 * during a ranked run aborts it. A mined block (a cleared row) flashes; the
 * flash is drawn over the running game and never holds it up.
 *
 * Sound (P8, sound.js): effects for what the player does and music that
 * follows the run, silent until the first pointer or key press. The control
 * in the page header sets effects and music on/off and their volumes; a
 * player's choice is saved on the server (saveSound), a guest's in this
 * browser.
 *
 * Test environment only: window.__stacker plays a recorded log at once and
 * reports the state hash, so a browser test can compare it with Node.
 */

import { ENGINE_VERSION, isEngine, nextPieces, rulesOf, run, stateHash } from './engine.js';
import { keyLabel, keyMap, normalizeControls } from './keys.js';
import { drawPreview, drawWell, SHOWN_ROWS } from './renderer.js';
import { encodeReplay, REPLAY_VERSION } from './replay.js';
import { createSession } from './session.js';
import { createSound, cuesFor, normalizeSound } from './sound.js';
import { createTicker, formatTicks } from './ticker.js';
// P5: the replay viewer shares this entry (and its engine and renderer) instead of a bundle of its own
import './replay-page.js';
import { registerAlpine } from '../registerAlpine.js';

const COUNTDOWN_MS = 3000;
/** Height of the touch panel above the tab bar (the spacer in the page matches it). */
const TOUCH_PANEL = 136;
const FLASH_MS = 420;
const POLL_MS = 1000;
const POLL_TRIES = 40;
const STORE_CONTROLS = 'blockfill.controls';
const STORE_BEST = 'blockfill.practice.best';

/** The practice best of a rule set: today's rules (bf1) keep the key they always had, a week's own rules get their own. */
function bestKey(engine) {
    return engine === ENGINE_VERSION || !isEngine(engine) ? STORE_BEST : `${STORE_BEST}.${engine}`;
}
const STORE_SOUND = 'blockfill.sound';
/** A player's sound setting is sent this long after the last change. */
const SAVE_SOUND_MS = 400;

function csrfHeaders() {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;

    return token ? { 'X-CSRF-TOKEN': token } : {};
}

/**
 * A JSON call to the run endpoints. A network failure answers status 0 instead of
 * throwing, so every caller handles it as a failed call (a run that cannot start,
 * a submission left for the verdict poll) and no promise rejects unhandled.
 */
async function request(method, url, body) {
    let response;
    try {
        response = await fetch(url, {
            method,
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...csrfHeaders() },
            body: body === undefined ? undefined : JSON.stringify(body),
            credentials: 'same-origin',
        });
    } catch (error) {
        (window.__stackerNetwork ??= []).push(`${method} ${url}: ${error}`);

        return { status: 0, data: null };
    }
    let data = null;
    if (response.status !== 204) {
        try {
            data = await response.json();
        } catch {
            data = null;
        }
    }

    return { status: response.status, data };
}

function randomSeed() {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);

    return [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
}

function readStored(key) {
    try {
        return JSON.parse(localStorage.getItem(key) ?? 'null');
    } catch {
        return null;
    }
}

function writeStored(key, value) {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // private mode: the setting lasts for this page only
    }
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** Test environment only: every init, destroy and new session, with where it came from. */
function trace(config, event) {
    if (config.testing) {
        (window.__stackerTrace ??= []).push({ event, at: Math.round(performance.now()), stack: (new Error().stack ?? '').split('\n').slice(2, 7).join(' | ') });
    }
}

registerAlpine(() => {
    window.Alpine.data('stackerGame', (config) => {
        // Not reactive: the engine state changes sixty times a second and is drawn, not bound.
        const rt = {
            el: null,
            session: null,
            ticker: createTicker(),
            frame: 0,
            flashUntil: 0,
            flashRows: 0,
            countdownUntil: 0,
            // the countdown's length; the browser tests set it (test hook countdown()) instead of racing the clock
            countdownMs: COUNTDOWN_MS,
            token: null,
            seed: null,
            keys: new Map(),
            onKeyDown: null,
            onKeyUp: null,
            onVisibility: null,
            onBlur: null,
            onResize: null,
            runId: 0,
            cell: 24,
            recorded: null,
            holdSubmit: false,
            // P7: a ranked run counts only played with the keyboard; one on-screen press makes it a touch run
            touched: false,
            release: null,
            sound: null,
            wire: null,
            onGesture: null,
            saveSound: 0,
        };
        const initialSound = normalizeSound(config.signedIn ? config.sound : (readStored(STORE_SOUND) ?? config.sound));

        return {
            t: config.t,
            signedIn: config.signedIn,
            mode: 'idle',
            kind: 'practice',
            controls: normalizeControls(config.signedIn ? config.controls : (readStored(STORE_CONTROLS) ?? config.controls)),
            lines: 0,
            // the week's rules (engine.js rulesOf()): the lines that finish a run, and the level it is on
            goal: rulesOf(isEngine(config.engine) ? config.engine : ENGINE_VERSION).goal,
            level: 1,
            leveled: rulesOf(isEngine(config.engine) ? config.engine : ENGINE_VERSION).every > 0,
            countdown: 0,
            minedTag: 0,
            reducedMotion: false,
            result: null,
            error: '',
            practiceBest: readStored(bestKey(config.engine)),
            // the best of this week (the one a ranked run has to beat) and of all time (shown beside it)
            rankedBest: config.best,
            allTimeBest: config.allTimeBest,
            sound: initialSound,

            init() {
                trace(config, 'init');
                // The elements, pinned here: a method called from a button inside an x-if
                // template runs with that clone's $el and $refs, which are gone once the
                // template is removed (the start buttons vanish with the countdown).
                rt.el = {
                    root: this.$el,
                    well: this.$refs.well,
                    wellSlot: this.$refs.wellSlot,
                    hold: this.$refs.hold,
                    time: this.$refs.time,
                    pps: this.$refs.pps,
                    next: [...this.$el.querySelectorAll('[data-next]')],
                    result: this.$refs.result,
                };
                this.reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
                rt.keys = keyMap(this.controls.keys);
                rt.onKeyDown = (event) => this.key(event, true);
                rt.onKeyUp = (event) => this.key(event, false);
                rt.onVisibility = () => this.visibility();
                rt.onBlur = () => rt.session?.releaseAll();
                rt.onResize = () => this.layout();
                // the browser lets a page sound only after a gesture: the first press unlocks it
                rt.sound = createSound({ settings: this.sound });
                rt.onGesture = () => rt.sound.unlock();
                window.addEventListener('pointerdown', rt.onGesture, true);
                window.addEventListener('keydown', rt.onGesture, true);
                this.$watch('mode', () => this.scene());
                this.$watch('lines', () => this.scene());
                this.$watch('kind', () => this.scene());
                // pinned like the elements above: $wire from this component, whatever button calls
                rt.wire = this.$wire;
                window.addEventListener('keydown', rt.onKeyDown);
                window.addEventListener('keyup', rt.onKeyUp);
                window.addEventListener('blur', rt.onBlur);
                window.addEventListener('resize', rt.onResize);
                document.addEventListener('visibilitychange', rt.onVisibility);
                this.layout();
                this.newSession(randomSeed());
                this.draw(performance.now());
                if (config.testing) {
                    this.installTestHook();
                }
            },

            destroy() {
                trace(config, 'destroy');
                cancelAnimationFrame(rt.frame);
                this.unlisten(rt);
                clearTimeout(rt.saveSound);
                rt.sound?.destroy();
                if (window.__stacker?.owner === this) {
                    delete window.__stacker;
                }
            },

            unlisten(runtime) {
                window.removeEventListener('keydown', runtime.onKeyDown);
                window.removeEventListener('keyup', runtime.onKeyUp);
                window.removeEventListener('blur', runtime.onBlur);
                window.removeEventListener('resize', runtime.onResize);
                document.removeEventListener('visibilitychange', runtime.onVisibility);
                window.removeEventListener('pointerdown', runtime.onGesture, true);
                window.removeEventListener('keydown', runtime.onGesture, true);
            },

            coarse() {
                return window.matchMedia?.('(pointer: coarse)').matches ?? false;
            },

            tabBarHeight() {
                const bar = document.querySelector('[data-test=tab-bar]');

                // not offsetParent: the bar is position: fixed, and a fixed element has none
                return bar && bar.getClientRects().length > 0 ? bar.getBoundingClientRect().height : 0;
            },

            /**
             * The room the sticky header takes at the top of the viewport: the root's
             * scroll-padding-top (header plus a gap, resources/css/app.css). A scroll
             * to the well stops there, so the well starts below it.
             */
            topInset() {
                return parseFloat(getComputedStyle(document.documentElement).scrollPaddingTop) || 0;
            },

            /**
             * A new run starts with the well in view: after a result the page may have
             * scrolled down to it. On a touch screen the well goes to the top, below the
             * sticky header and above the touch panel; with a keyboard just far enough to
             * be seen.
             */
            revealWell() {
                const slot = rt.el?.wellSlot;
                if (!slot) {
                    return;
                }
                // after Alpine has shown the touch panel and its spacer (nextTick), so the page can scroll that far
                this.$nextTick(() => requestAnimationFrame(() => {
                    const rect = slot.getBoundingClientRect();
                    // the touch panel's own top once it is shown; before that the room layout() reserves for it
                    const panel = document.querySelector('[data-test=touch]');
                    const bottom = !this.coarse() ? window.innerHeight
                        : panel && panel.getClientRects().length > 0 ? panel.getBoundingClientRect().top
                            : window.innerHeight - (this.tabBarHeight() + TOUCH_PANEL);
                    // in view means below the sticky header, not under it
                    const header = document.querySelector('body > header');
                    if (rect.top >= (header ? header.getBoundingClientRect().bottom : 0) && rect.bottom <= bottom) {
                        return;
                    }
                    slot.scrollIntoView({ block: this.coarse() ? 'start' : 'nearest', behavior: this.reducedMotion ? 'auto' : 'smooth' });
                }));
            },

            /** Cell size from the room the well has: its slot's width and the window's height. */
            layout() {
                const slot = rt.el.wellSlot;
                const width = slot ? slot.clientWidth : 240;
                const byWidth = Math.floor(width / 10);
                // on a touch screen the well has to fit between the sticky header and the touch
                // panel above the tab bar (the panel is fixed there while a practice run is on)
                const reserved = this.coarse() ? this.topInset() + this.tabBarHeight() + TOUCH_PANEL + 16 : 200;
                const byHeight = Math.floor((window.innerHeight - reserved) / SHOWN_ROWS);
                rt.cell = Math.max(12, Math.min(30, byWidth, byHeight));
                this.sizeCanvas(rt.el.well, rt.cell * 10, rt.cell * SHOWN_ROWS);
                for (const canvas of [rt.el.hold, ...rt.el.next]) {
                    if (canvas) {
                        this.sizeCanvas(canvas, canvas.clientWidth, canvas.clientHeight);
                    }
                }
                if (rt.session) {
                    this.draw(performance.now());
                }
            },

            sizeCanvas(canvas, width, height) {
                const ratio = window.devicePixelRatio || 1;
                canvas.width = Math.max(1, Math.round(width * ratio));
                canvas.height = Math.max(1, Math.round(height * ratio));
                canvas.style.width = `${width}px`;
                canvas.style.height = `${height}px`;
                const ctx = canvas.getContext('2d');
                ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
                canvas._css = { width, height };
            },

            /** A new game; `engine` is the difficulty: the issued run's for a ranked run, else this week's (config.engine). */
            newSession(seed, settings = this.controls, engine = config.engine) {
                trace(config, 'newSession');
                rt.seed = seed;
                rt.touched = false;
                rt.session = createSession({ seed, settings: { das: settings.das, arr: settings.arr, sdf: settings.sdf }, engine: engine ?? ENGINE_VERSION });
                rt.flashUntil = 0;
                this.lines = 0;
                this.goal = rt.session.game.goal;
                this.level = rt.session.game.level;
                this.leveled = rt.session.game.rules.every > 0;
                this.minedTag = 0;
                this.updateHud();
                // the new game at once: during the countdown the well is empty and Next shows its own pieces
                this.draw(performance.now());
            },

            // ---- flow --------------------------------------------------------

            startPractice() {
                this.stop();
                rt.runId++;
                this.kind = 'practice';
                this.result = null;
                this.error = '';
                this.newSession(randomSeed());
                this.beginCountdown(rt.runId, () => this.play());
            },

            async startRanked() {
                trace(config, 'startRanked');
                if (!this.signedIn) {
                    window.location.href = config.urls.login;

                    return;
                }
                this.stop();
                const id = ++rt.runId;
                this.kind = 'ranked';
                this.result = null;
                this.error = '';
                this.mode = 'countdown';
                this.countdown = 3;
                const issued = await request('POST', config.urls.issue);
                if (id !== rt.runId) {
                    return;
                }
                if (issued.status !== 201 || !issued.data) {
                    this.fail(issued.status === 429 ? this.t.tooFast : this.t.noRun);

                    return;
                }
                rt.token = issued.data.token;
                // a page left open over Monday 00:00 learns the new week's best here
                if (issued.data.best !== undefined) {
                    this.rankedBest = issued.data.best;
                    this.allTimeBest = issued.data.best_all_time ?? this.allTimeBest;
                }
                this.newSession(issued.data.seed, this.controls, issued.data.engine);
                this.beginCountdown(id, async () => {
                    const started = await request('POST', this.tokenUrl(config.urls.start));
                    if (id !== rt.runId) {
                        return;
                    }
                    if (started.status !== 204) {
                        this.fail(this.t.noRun);

                        return;
                    }
                    this.play();
                });
            },

            restart() {
                if (this.kind === 'ranked') {
                    this.startRanked();
                } else {
                    this.startPractice();
                }
            },

            beginCountdown(id, then) {
                this.mode = 'countdown';
                this.revealWell();
                rt.countdownUntil = performance.now() + rt.countdownMs;
                let beeped = 0;
                const step = () => {
                    if (id !== rt.runId || this.mode !== 'countdown') {
                        return;
                    }
                    const left = rt.countdownUntil - performance.now();
                    this.countdown = Math.max(1, Math.ceil(left / 1000));
                    if (left <= 0) {
                        rt.sound.effect('count', 0);
                        then();

                        return;
                    }
                    if (this.countdown !== beeped) {
                        beeped = this.countdown;
                        rt.sound.effect('count', beeped);
                    }
                    setTimeout(step, Math.min(100, left));
                };
                step();
            },

            play() {
                this.mode = 'playing';
                if (rt.recorded) {
                    // test hook: a recorded log instead of the keyboard
                    const inputs = rt.recorded;
                    rt.recorded = null;
                    rt.session.play(inputs);
                    this.finish();

                    return;
                }
                rt.ticker.reset(performance.now());
                const frame = (now) => {
                    if (this.mode !== 'playing') {
                        return;
                    }
                    this.advance(rt.ticker.due(now), now);
                    this.draw(now);
                    if (this.mode === 'playing') {
                        rt.frame = requestAnimationFrame(frame);
                    }
                };
                rt.frame = requestAnimationFrame(frame);
            },

            advance(ticks, now) {
                for (let i = 0; i < ticks && this.mode === 'playing'; i++) {
                    const outcome = rt.session.tick();
                    if (outcome && outcome.cleared > 0) {
                        this.mined(outcome.cleared, now);
                    }
                    if (outcome) {
                        this.sounds(outcome);
                    }
                    if (rt.session.over()) {
                        this.finish();
                    }
                }
                this.updateHud();
            },

            /** The effects for one tick, several where they belong together; the run's end has its own. */
            sounds(outcome) {
                if (rt.session.over()) {
                    return;
                }
                for (const [name, arg] of cuesFor(outcome)) {
                    rt.sound.effect(name, arg);
                }
            },

            mined(count, now) {
                this.lines = rt.session.game.lines;
                this.minedTag = count;
                rt.flashRows = count;
                rt.flashUntil = this.reducedMotion ? 0 : now + FLASH_MS;
            },

            stop() {
                cancelAnimationFrame(rt.frame);
                rt.session?.releaseAll();
            },

            fail(message) {
                this.stop();
                this.mode = 'idle';
                this.error = message;
            },

            abort(reason) {
                this.stop();
                rt.runId++;
                this.mode = 'result';
                this.result = { ticks: rt.session?.game.tick ?? 0, status: 'aborted', reason, kind: this.kind };
            },

            async finish() {
                this.stop();
                this.mode = 'result';
                const id = rt.runId;
                const outcome = rt.session.result();
                this.lines = outcome.lines;
                this.updateHud();
                this.draw(performance.now());
                // where the result sits below the well (a narrow screen, or beside the game chat's column from xl,
                // 2026-10-03): bring it into view (it is set below, before any await). Asked of the layout, not the
                // window width, after Alpine has shown the result (x-show) and the browser has laid it out.
                this.$nextTick(() => requestAnimationFrame(() => requestAnimationFrame(() => {
                    if (rt.el.result.getBoundingClientRect().top < rt.el.well.getBoundingClientRect().bottom) {
                        return;
                    }
                    rt.el.result.scrollIntoView({ block: 'start', behavior: this.reducedMotion ? 'auto' : 'smooth' });
                })));
                const base = { ticks: outcome.ticks, hash: outcome.stateHash, kind: this.kind, lines: outcome.lines };
                rt.sound.effect(outcome.finished ? 'fanfare' : 'topout');
                if (!outcome.finished) {
                    this.result = { ...base, status: 'toppedOut' };

                    return;
                }
                if (this.kind === 'practice') {
                    const previous = this.practiceBest;
                    if (previous === null || outcome.ticks < previous) {
                        this.practiceBest = outcome.ticks;
                        writeStored(bestKey(rt.session.game.engine), outcome.ticks);
                    }
                    if (previous !== null && outcome.ticks < previous) {
                        // beat a time of its own: a flourish after the fanfare
                        rt.sound.effect('best');
                    }
                    this.result = { ...base, status: 'practice', previous };

                    return;
                }
                if (rt.holdSubmit) {
                    // test hook: the browser test moves the server's clock before the submission
                    this.result = { ...base, status: 'held', previous: this.rankedBest };
                    await new Promise((resolve) => {
                        rt.release = resolve;
                    });
                    rt.holdSubmit = false;
                }
                this.result = { ...base, status: 'submitting', previous: this.rankedBest };
                const replay = encodeReplay({ v: REPLAY_VERSION, engine: rt.session.game.engine, seed: rt.seed, settings: rt.session.game.settings }, rt.session.log);
                const submitted = await request('POST', this.tokenUrl(config.urls.submit), { replay, ticks: outcome.ticks, hash: outcome.stateHash, input: rt.touched ? 'touch' : 'keyboard' });
                if (id !== rt.runId) {
                    return;
                }
                if (submitted.status === 503) {
                    // the verifier's queue is full: the run was not taken, nothing is saved
                    this.result = { ...this.result, status: 'busy', reason: 'busy' };

                    return;
                }
                if (submitted.status !== 202 || !submitted.data) {
                    // no answer from the league about this run: never claim it arrived
                    this.result = { ...this.result, status: 'unsent', reason: 'submit' };

                    return;
                }
                this.result = { ...this.result, status: submitted.data.status, reason: submitted.data.reason, moment: submitted.data.moment ?? null };
                for (let i = 0; i < POLL_TRIES && this.result.status === 'verifying'; i++) {
                    await sleep(POLL_MS);
                    if (id !== rt.runId) {
                        return;
                    }
                    const state = await request('GET', this.tokenUrl(config.urls.show));
                    if (state.status === 200 && state.data) {
                        // P5: `replay` is the run's replay page once the league keeps one; `moment` a run worth sharing
                        this.result = { ...this.result, status: state.data.status, reason: state.data.reason, replay: state.data.replay ?? null, moment: state.data.moment ?? null };
                        if (state.data.best !== undefined) {
                            this.rankedBest = state.data.best;
                            this.allTimeBest = state.data.best_all_time ?? this.allTimeBest;
                        }
                    }
                }
                if (this.result.status === 'verified') {
                    if (this.result.previous !== null && this.result.previous !== undefined && this.result.ticks < this.result.previous) {
                        rt.sound.effect('best');
                    }
                    // the weekly leaderboard below the game reads itself again (P4). Sent on window itself:
                    // `$dispatch` starts at the element that called this method (the start button, gone by now)
                    window.dispatchEvent(new CustomEvent('stacker-verified'));
                    if (!this.result.moment) {
                        // the verdict came with the submission: one read for the moment to share
                        const state = await request('GET', this.tokenUrl(config.urls.show));
                        if (id === rt.runId && state.status === 200 && state.data?.moment) {
                            this.result = { ...this.result, moment: state.data.moment };
                        }
                    }
                }
            },

            /** The share sheet of the run just verified (components/⚡blockfill-share). */
            shareMoment() {
                if (this.result?.moment) {
                    window.dispatchEvent(new CustomEvent('blockfill-share', { detail: { moment: this.result.moment } }));
                }
            },

            tokenUrl(template) {
                return template.replace('__TOKEN__', rt.token);
            },

            // ---- input -------------------------------------------------------

            key(event, down) {
                const target = event.target;
                if (event.ctrlKey || event.metaKey || event.altKey || (target && /^(INPUT|TEXTAREA|SELECT)$/.test(target.tagName)) || target?.isContentEditable) {
                    return;
                }
                const action = rt.keys.get(event.code);
                if (action === undefined) {
                    return;
                }
                if (this.mode === 'playing' || this.mode === 'countdown') {
                    event.preventDefault();
                }
                if (event.repeat) {
                    return;
                }
                if (action === 'restart') {
                    if (down && this.mode !== 'idle') {
                        this.restart();
                    }

                    return;
                }
                if (this.mode === 'playing' || this.mode === 'countdown') {
                    rt.session.press(action, down);
                }
            },

            /** On-screen buttons (touch, practice): a press and a release. */
            touch(action, down) {
                if (this.mode === 'playing' || this.mode === 'countdown') {
                    rt.touched = true;
                    rt.session.press(action, down);
                }
            },

            visibility() {
                rt.sound.visibility();
                if (!document.hidden) {
                    rt.ticker.reset(performance.now());

                    return;
                }
                if (this.kind === 'ranked' && (this.mode === 'playing' || this.mode === 'countdown')) {
                    this.abort('hidden');
                }
            },

            // ---- settings (guests: this browser only) ----------------------

            saveControls(next) {
                this.controls = normalizeControls(next);
                rt.keys = keyMap(this.controls.keys);
                if (!this.signedIn) {
                    writeStored(STORE_CONTROLS, this.controls);
                }
            },

            // ---- sound ---------------------------------------------------------

            /** The music follows the page: menu, run, last ten rows. */
            scene() {
                rt.sound?.setScene({ mode: this.mode, kind: this.kind, remaining: this.goal - this.lines, goal: this.goal });
            },

            /** Effects or music on/off (a click, so it may start the sound at once). */
            toggleSound(channel) {
                const on = `${channel}On`;
                this.changeSound({ [on]: !this.sound[on] });
            },

            /** A volume from its slider; moving it up from silence switches the channel on. */
            setVolume(channel, value) {
                const volume = Math.round(Number(value));
                this.changeSound(volume > 0 ? { [channel]: volume, [`${channel}On`]: true } : { [channel]: volume });
            },

            changeSound(change) {
                this.sound = rt.sound.configure({ ...this.sound, ...change });
                rt.sound.unlock();
                if (!this.signedIn) {
                    writeStored(STORE_SOUND, this.sound);

                    return;
                }
                clearTimeout(rt.saveSound);
                const sound = { ...this.sound };
                rt.saveSound = setTimeout(() => rt.wire.saveSound(sound), SAVE_SOUND_MS);
            },

            keyText(action) {
                return this.controls.keys[action].map(keyLabel).join(' / ');
            },

            // ---- drawing -----------------------------------------------------

            updateHud() {
                const game = rt.session?.game;
                if (!game) {
                    return;
                }
                if (rt.el.time) {
                    rt.el.time.textContent = formatTicks(game.tick);
                }
                if (rt.el.pps) {
                    rt.el.pps.textContent = game.tick > 0 ? ((game.pieces * 60) / game.tick).toFixed(2) : '0.00';
                }
                if (game.lines !== this.lines) {
                    this.lines = game.lines;
                }
                if (game.level !== this.level) {
                    this.level = game.level;
                }
            },

            draw(now) {
                const game = rt.session?.game;
                const well = rt.el?.well;
                if (!game || !well) {
                    return;
                }
                rt.drawn = { seed: rt.seed, tick: game.tick };
                const flash = rt.flashUntil > now ? (rt.flashUntil - now) / FLASH_MS : 0;
                drawWell(well.getContext('2d'), game, { cell: rt.cell, mined: rt.flashRows, flash });
                const hold = rt.el.hold;
                if (hold?._css) {
                    drawPreview(hold.getContext('2d'), game.hold, { ...hold._css, cell: this.previewCell(hold._css) });
                }
                const next = nextPieces(game);
                rt.el.next.forEach((canvas, index) => {
                    if (canvas._css) {
                        drawPreview(canvas.getContext('2d'), next[index] ?? -1, { ...canvas._css, cell: this.previewCell(canvas._css) });
                    }
                });
            },

            previewCell({ width, height }) {
                return Math.max(6, Math.floor(Math.min(width / 4.6, height / 2.6)));
            },

            // ---- text --------------------------------------------------------

            time(ticks) {
                return ticks === null || ticks === undefined ? '–' : formatTicks(ticks, 3);
            },

            chain() {
                return Array.from({ length: this.goal }, (_, i) => (i < this.lines ? 'done' : 'open'));
            },

            statusText() {
                if (!this.result) {
                    return '';
                }

                // every ranked run is verified: one that did not beat the week's best says so, with that best
                if (this.slowerVerified()) {
                    return this.t.status.verifiedSlower.replace(':best', formatTicks(this.result.previous, 3));
                }
                // P7: a ranked run without a keyboard is refused before the verifier, with its own text
                if (this.result.status === 'rejected' && this.result.reason === 'input') {
                    return this.t.status.rejected_input;
                }

                return this.t.status[this.result.status] ?? this.result.status;
            },

            /** A verified ranked run that is not faster than the week's best it had to beat. */
            slowerVerified() {
                const r = this.result;

                return r?.kind === 'ranked' && r.status === 'verified' && r.previous !== null && r.previous !== undefined && r.ticks >= r.previous;
            },

            bestLine() {
                const r = this.result;
                // a slower verified run names the best in its status line already
                if (!r || ['toppedOut', 'aborted', 'busy', 'unsent'].includes(r.status) || this.slowerVerified()) {
                    return '';
                }
                if (r.previous === null || r.previous === undefined) {
                    return this.t.firstTime;
                }
                const delta = r.previous - r.ticks;

                return delta > 0
                    ? this.t.newBest.replace(':delta', formatTicks(delta, 3).replace(/^0:0?/, '')).replace(':previous', formatTicks(r.previous, 3))
                    : this.t.yourBest.replace(':best', formatTicks(r.previous, 3));
            },

            // ---- test hook ---------------------------------------------------

            installTestHook() {
                const component = this;
                window.__stacker = {
                    owner: component,
                    /**
                     * Plays a recorded log at once. During a ranked countdown the log
                     * replaces the keyboard once the run starts (`hold`: submit only
                     * after release()); otherwise as a practice run on `options.seed`.
                     */
                    feed(inputs, options = {}) {
                        if (component.kind === 'ranked' && component.mode === 'countdown') {
                            rt.recorded = inputs;
                            rt.holdSubmit = options.hold === true;

                            return 'queued';
                        }
                        if (component.mode !== 'playing') {
                            component.stop();
                            rt.runId++;
                            component.kind = 'practice';
                            component.result = null;
                            component.newSession(options.seed ?? randomSeed(), options.settings ?? component.controls);
                            component.mode = 'playing';
                        }
                        cancelAnimationFrame(rt.frame);
                        const outcome = rt.session.play(inputs);
                        component.finish();

                        return outcome;
                    },
                    release() {
                        rt.release?.();
                    },
                    /**
                     * Sets the length of every countdown from now on, in ms: 0 starts a
                     * run at once, so a test can pin either order of the countdown's end
                     * and its own checks instead of hoping the wall clock picks one.
                     * A countdown already running ends `ms` from now, so a test can hold
                     * one open while it looks and then let the run start.
                     */
                    countdown(ms) {
                        rt.countdownMs = Math.max(0, Number(ms) || 0);
                        if (component.mode === 'countdown') {
                            rt.countdownUntil = performance.now() + rt.countdownMs;
                        }
                    },
                    state() {
                        const game = rt.session?.game;

                        return {
                            mode: component.mode,
                            kind: component.kind,
                            result: component.result,
                            ticks: game?.tick ?? 0,
                            lines: game?.lines ?? 0,
                            hash: game ? stateHash(game) : null,
                            pieces: game?.pieces ?? 0,
                            piece: game?.current ? { x: game.current.x, y: game.current.y, rot: game.current.rot } : null,
                            boardEmpty: game ? game.board.every((cell) => cell === 0) : null,
                            seed: rt.seed,
                            drawn: rt.drawn ?? null,
                            trace: window.__stackerTrace ?? [],
                        };
                    },
                    run,
                    sound() {
                        return rt.sound?.debug() ?? null;
                    },
                };
            },
        };
    });
});
