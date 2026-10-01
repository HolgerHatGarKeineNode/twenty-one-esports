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
 * the end and the page asks for the verdict until it has one. Leaving the tab
 * during a ranked run aborts it. A mined block (a cleared row) flashes; the
 * flash is drawn over the running game and never holds it up.
 *
 * Test environment only: window.__stacker plays a recorded log at once and
 * reports the state hash, so a browser test can compare it with Node.
 */

import { ENGINE_VERSION, GOAL_LINES, nextPieces, run, stateHash } from './engine.js';
import { keyLabel, keyMap, normalizeControls } from './keys.js';
import { drawPreview, drawWell, SHOWN_ROWS } from './renderer.js';
import { encodeReplay, REPLAY_VERSION } from './replay.js';
import { createSession } from './session.js';
import { createTicker, formatTicks } from './ticker.js';

const COUNTDOWN_MS = 3000;
const FLASH_MS = 420;
const POLL_MS = 1000;
const POLL_TRIES = 40;
const STORE_CONTROLS = 'blockfill.controls';
const STORE_BEST = 'blockfill.practice.best';

function csrfHeaders() {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;

    return token ? { 'X-CSRF-TOKEN': token } : {};
}

async function request(method, url, body) {
    const response = await fetch(url, {
        method,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...csrfHeaders() },
        body: body === undefined ? undefined : JSON.stringify(body),
        credentials: 'same-origin',
    });
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

document.addEventListener('alpine:init', () => {
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
            release: null,
        };

        return {
            t: config.t,
            signedIn: config.signedIn,
            mode: 'idle',
            kind: 'practice',
            controls: normalizeControls(config.signedIn ? config.controls : (readStored(STORE_CONTROLS) ?? config.controls)),
            lines: 0,
            countdown: 0,
            minedTag: 0,
            reducedMotion: false,
            result: null,
            error: '',
            practiceBest: readStored(STORE_BEST),
            rankedBest: config.best,

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
                };
                this.reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
                rt.keys = keyMap(this.controls.keys);
                rt.onKeyDown = (event) => this.key(event, true);
                rt.onKeyUp = (event) => this.key(event, false);
                rt.onVisibility = () => this.visibility();
                rt.onBlur = () => rt.session?.releaseAll();
                rt.onResize = () => this.layout();
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
            },

            /** Cell size from the room the well has: its slot's width and the window's height. */
            layout() {
                const slot = rt.el.wellSlot;
                const width = slot ? slot.clientWidth : 240;
                const byWidth = Math.floor(width / 10);
                const byHeight = Math.floor((window.innerHeight - 200) / SHOWN_ROWS);
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

            newSession(seed, settings = this.controls) {
                trace(config, 'newSession');
                rt.seed = seed;
                rt.session = createSession({ seed, settings: { das: settings.das, arr: settings.arr, sdf: settings.sdf } });
                rt.flashUntil = 0;
                this.lines = 0;
                this.minedTag = 0;
                this.updateHud();
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
                this.newSession(issued.data.seed);
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
                rt.countdownUntil = performance.now() + COUNTDOWN_MS;
                const step = () => {
                    if (id !== rt.runId || this.mode !== 'countdown') {
                        return;
                    }
                    const left = rt.countdownUntil - performance.now();
                    this.countdown = Math.max(1, Math.ceil(left / 1000));
                    if (left <= 0) {
                        then();

                        return;
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
                    if (rt.session.over()) {
                        this.finish();
                    }
                }
                this.updateHud();
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
                const base = { ticks: outcome.ticks, hash: outcome.stateHash, kind: this.kind, lines: outcome.lines };
                if (!outcome.finished) {
                    this.result = { ...base, status: 'toppedOut' };

                    return;
                }
                if (this.kind === 'practice') {
                    const previous = this.practiceBest;
                    if (previous === null || outcome.ticks < previous) {
                        this.practiceBest = outcome.ticks;
                        writeStored(STORE_BEST, outcome.ticks);
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
                const replay = encodeReplay({ v: REPLAY_VERSION, engine: ENGINE_VERSION, seed: rt.seed, settings: rt.session.game.settings }, rt.session.log);
                const submitted = await request('POST', this.tokenUrl(config.urls.submit), { replay, ticks: outcome.ticks, hash: outcome.stateHash });
                if (id !== rt.runId) {
                    return;
                }
                if (submitted.status !== 202 || !submitted.data) {
                    this.result = { ...this.result, status: 'pending', reason: 'submit' };

                    return;
                }
                this.result = { ...this.result, status: submitted.data.status, reason: submitted.data.reason };
                for (let i = 0; i < POLL_TRIES && this.result.status === 'verifying'; i++) {
                    await sleep(POLL_MS);
                    if (id !== rt.runId) {
                        return;
                    }
                    const state = await request('GET', this.tokenUrl(config.urls.show));
                    if (state.status === 200 && state.data) {
                        this.result = { ...this.result, status: state.data.status, reason: state.data.reason };
                        if (state.data.best !== undefined) {
                            this.rankedBest = state.data.best;
                        }
                    }
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
                    rt.session.press(action, down);
                }
            },

            visibility() {
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
            },

            draw(now) {
                const game = rt.session?.game;
                const well = rt.el.well;
                if (!game || !well) {
                    return;
                }
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
                return Array.from({ length: GOAL_LINES }, (_, i) => (i < this.lines ? 'done' : 'open'));
            },

            statusText() {
                if (!this.result) {
                    return '';
                }

                const status = this.result.kind === 'ranked' && this.result.status === 'practice' ? 'practice_rank' : this.result.status;

                return this.t.status[status] ?? status;
            },

            bestLine() {
                const r = this.result;
                if (!r || r.status === 'toppedOut' || r.status === 'aborted') {
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
                    state() {
                        const game = rt.session?.game;

                        return {
                            mode: component.mode,
                            kind: component.kind,
                            result: component.result,
                            ticks: game?.tick ?? 0,
                            lines: game?.lines ?? 0,
                            hash: game ? stateHash(game) : null,
                            trace: window.__stackerTrace ?? [],
                        };
                    },
                    run,
                };
            },
        };
    });
});
