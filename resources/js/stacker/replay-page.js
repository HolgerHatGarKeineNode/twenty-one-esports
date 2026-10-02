/*
 * The Blockfill replay viewer (plan "Blockfill", P5; resources/views/pages/
 * stacker/⚡replay): an Alpine component around the replay player
 * (replay-player.js), drawn by the game's own renderer. It owns no rules.
 *
 * The chain of mined blocks (40, or the lines of the week's rules) is the seek bar: a cube column stands where
 * its rows were cleared, a click or a drag jumps to that moment, the arrow
 * keys on it go from block to block. Play at 0.5x to 4x; one tick back or on
 * with the buttons or with comma and full stop; Space plays and pauses.
 * Nothing moves before the viewer presses Play.
 *
 * Test environment only: window.__stackerReplay reports what is shown.
 */

import { nextPieces, stateHash } from './engine.js';
import { drawPreview, drawWell, SHOWN_ROWS } from './renderer.js';
import { createReplayPlayer } from './replay-player.js';
import { TICK_MS, formatTicks } from './ticker.js';

export const SPEEDS = Object.freeze([0.5, 1, 2, 4]);
const FLASH_MS = 420;
/** A click this close to a cube column (CSS px) jumps to its moment exactly. */
const SNAP_PX = 10;
/** A frame after a stall plays at most this much time. */
const MAX_FRAME_MS = 250;

/**
 * The tick for a point on the track: the moment of a cube column within SNAP_PX,
 * else the share of the run's time.
 *
 * @param {number} x CSS px from the track's left edge
 * @param {number} width the track's width
 * @param {number} total ticks of the run
 * @param {number[]} columnTicks ticks of the cube columns
 */
export function tickAt(x, width, total, columnTicks) {
    if (width <= 0 || total <= 0) {
        return 0;
    }
    let nearest = null;
    for (const tick of columnTicks) {
        const distance = Math.abs((tick / total) * width - x);
        if (distance <= SNAP_PX && (nearest === null || distance < nearest.distance)) {
            nearest = { tick, distance };
        }
    }
    if (nearest !== null) {
        return nearest.tick;
    }

    return Math.max(0, Math.min(total, Math.round((x / width) * total)));
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('stackerReplay', (config) => {
        // Not reactive: the game changes sixty times a second and is drawn, not bound.
        const rt = { player: null, el: null, frame: 0, last: 0, carry: 0, flashUntil: 0, flashRows: 0, cell: 24, dragging: false, onKey: null, onResize: null };

        return {
            t: config.t,
            total: 0,
            tick: 0,
            lines: 0,
            pieces: '–',
            pps: '–',
            held: [0, 0, 0, 0, 0, 0, 0, 0],
            playing: false,
            speed: 1,
            columns: [],
            ended: null,
            error: '',
            reducedMotion: false,

            init() {
                rt.el = {
                    well: this.$refs.well,
                    wellSlot: this.$refs.wellSlot,
                    hold: this.$refs.hold,
                    track: this.$refs.track,
                    next: [...this.$el.querySelectorAll('[data-next]')],
                };
                this.reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
                try {
                    rt.player = createReplayPlayer(config.replay);
                } catch {
                    this.error = this.t.broken;

                    return;
                }
                this.total = rt.player.total;
                this.columns = rt.player.clears.map(({ tick, count }) => ({ tick, count: Math.min(4, count), left: (tick / this.total) * 100 }));
                this.ended = rt.player.result.stateHash === config.hash && rt.player.total === config.ticks;
                // the run as a whole: the moment shown has its own clock and block line
                this.pieces = String(rt.player.result.pieces);
                this.pps = ((rt.player.result.pieces * 60) / Math.max(1, this.total)).toFixed(2);
                rt.onKey = (event) => this.key(event);
                rt.onResize = () => this.layout();
                window.addEventListener('keydown', rt.onKey);
                window.addEventListener('resize', rt.onResize);
                this.layout();
                this.seek(0);
                if (config.testing) {
                    this.installTestHook();
                }
            },

            destroy() {
                cancelAnimationFrame(rt.frame);
                window.removeEventListener('keydown', rt.onKey);
                window.removeEventListener('resize', rt.onResize);
            },

            // ---- layout ------------------------------------------------------

            layout() {
                const slot = rt.el.wellSlot;
                const byWidth = Math.floor((slot ? slot.clientWidth : 240) / 10);
                // from lg on the well, the chain and its controls share one screen; on a phone the width decides
                const reserved = window.innerWidth >= 1024 ? 420 : 220;
                const byHeight = Math.floor((window.innerHeight - reserved) / SHOWN_ROWS);
                rt.cell = Math.max(12, Math.min(28, byWidth, byHeight));
                this.sizeCanvas(rt.el.well, rt.cell * 10, rt.cell * SHOWN_ROWS);
                for (const canvas of [rt.el.hold, ...rt.el.next]) {
                    if (canvas) {
                        this.sizeCanvas(canvas, canvas.clientWidth, canvas.clientHeight);
                    }
                }
                this.draw(performance.now());
            },

            sizeCanvas(canvas, width, height) {
                const ratio = window.devicePixelRatio || 1;
                canvas.width = Math.max(1, Math.round(width * ratio));
                canvas.height = Math.max(1, Math.round(height * ratio));
                canvas.style.width = `${width}px`;
                canvas.style.height = `${height}px`;
                canvas.getContext('2d').setTransform(ratio, 0, 0, ratio, 0, 0);
                canvas._css = { width, height };
            },

            // ---- moving through the run ------------------------------------

            seek(tick) {
                if (!rt.player) {
                    return;
                }
                rt.player.seek(tick);
                rt.flashUntil = 0;
                this.sync();
                this.draw(performance.now());
            },

            step(delta) {
                this.pause();
                this.seek(this.tick + delta);
            },

            toggle() {
                this.playing ? this.pause() : this.play();
            },

            play() {
                if (!rt.player || this.playing) {
                    return;
                }
                if (this.tick >= this.total) {
                    this.seek(0);
                }
                this.playing = true;
                rt.last = performance.now();
                rt.carry = 0;
                rt.frame = requestAnimationFrame((now) => this.frame(now));
            },

            pause() {
                this.playing = false;
                cancelAnimationFrame(rt.frame);
                // a flash fades on the frames of playing only: a pause shows the well without it
                if (rt.flashUntil > 0) {
                    rt.flashUntil = 0;
                    this.draw(performance.now());
                }
            },

            setSpeed(speed) {
                if (SPEEDS.includes(speed)) {
                    this.speed = speed;
                }
            },

            frame(now) {
                if (!this.playing) {
                    return;
                }
                rt.carry += Math.min(MAX_FRAME_MS, Math.max(0, now - rt.last)) * this.speed;
                rt.last = now;
                const due = Math.floor(rt.carry / TICK_MS);
                if (due > 0) {
                    rt.carry -= due * TICK_MS;
                    const cleared = rt.player.advance(due);
                    if (cleared > 0 && !this.reducedMotion) {
                        rt.flashUntil = now + FLASH_MS;
                        rt.flashRows = Math.min(4, cleared);
                    }
                    this.sync();
                }
                this.draw(now);
                if (this.tick >= this.total) {
                    this.playing = false;

                    return;
                }
                rt.frame = requestAnimationFrame((next) => this.frame(next));
            },

            sync() {
                const game = rt.player.game;
                this.tick = game.tick;
                this.lines = game.lines;
                this.held = Array.from(game.held);
            },

            draw(now) {
                const game = rt.player?.game;
                if (!game || !rt.el.well._css) {
                    return;
                }
                const flash = rt.flashUntil > now ? (rt.flashUntil - now) / FLASH_MS : 0;
                drawWell(rt.el.well.getContext('2d'), game, { cell: rt.cell, mined: rt.flashRows, flash });
                const cell = ({ width, height }) => Math.max(6, Math.floor(Math.min(width / 4.6, height / 2.6)));
                if (rt.el.hold?._css) {
                    drawPreview(rt.el.hold.getContext('2d'), game.hold, { ...rt.el.hold._css, cell: cell(rt.el.hold._css) });
                }
                const next = nextPieces(game);
                rt.el.next.forEach((canvas, index) => {
                    if (canvas._css) {
                        drawPreview(canvas.getContext('2d'), next[index] ?? -1, { ...canvas._css, cell: cell(canvas._css) });
                    }
                });
            },

            // ---- the chain as the seek bar ---------------------------------

            trackTick(event) {
                const rect = rt.el.track.getBoundingClientRect();

                return tickAt(event.clientX - rect.left, rect.width, this.total, this.columns.map((column) => column.tick));
            },

            trackDown(event) {
                if (!rt.player || event.button > 0) {
                    return;
                }
                this.pause();
                rt.dragging = true;
                rt.el.track.setPointerCapture?.(event.pointerId);
                rt.el.track.focus({ preventScroll: true });
                this.seek(this.trackTick(event));
            },

            trackMove(event) {
                if (rt.dragging) {
                    this.seek(this.trackTick(event));
                }
            },

            trackUp(event) {
                rt.dragging = false;
                rt.el.track.releasePointerCapture?.(event.pointerId);
            },

            trackKey(event) {
                const ticks = this.columns.map((column) => column.tick);
                const target = {
                    ArrowRight: ticks.find((tick) => tick > this.tick) ?? this.total,
                    ArrowUp: ticks.find((tick) => tick > this.tick) ?? this.total,
                    ArrowLeft: [...ticks].reverse().find((tick) => tick < this.tick) ?? 0,
                    ArrowDown: [...ticks].reverse().find((tick) => tick < this.tick) ?? 0,
                    PageUp: this.tick + 60,
                    PageDown: this.tick - 60,
                    Home: 0,
                    End: this.total,
                }[event.key];
                if (target === undefined) {
                    return;
                }
                event.preventDefault();
                this.pause();
                this.seek(target);
            },

            key(event) {
                if (event.altKey || event.ctrlKey || event.metaKey) {
                    return;
                }
                const tag = event.target?.tagName;
                if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || event.target?.isContentEditable) {
                    return;
                }
                if (event.key === ',' || event.key === '.') {
                    event.preventDefault();
                    this.step(event.key === ',' ? -1 : 1);
                } else if (event.key === ' ' && tag !== 'BUTTON' && tag !== 'A') {
                    // a focused button plays or pauses with Space itself
                    event.preventDefault();
                    this.toggle();
                }
            },

            // ---- text --------------------------------------------------------

            time(ticks) {
                return formatTicks(ticks ?? 0);
            },

            blockLine() {
                return this.lines === 0 ? this.t.noBlock : this.t.block.replace(':n', String(Math.min(rt.player?.game.goal ?? 40, this.lines)));
            },

            minedLine() {
                if (!rt.player || this.lines === 0) {
                    return '';
                }

                return this.t.minedAt.replace(':time', formatTicks(rt.player.blockTicks[Math.min(rt.player?.game.goal ?? 40, this.lines) - 1] ?? 0));
            },

            sliderText() {
                return this.t.slider.replace(':time', formatTicks(this.tick)).replace(':n', String(Math.min(rt.player?.game.goal ?? 40, this.lines)));
            },

            // ---- test hook ---------------------------------------------------

            installTestHook() {
                const component = this;
                window.__stackerReplay = {
                    /** Stands at `tick` as the chain would, and reports the state. */
                    seek(tick) {
                        component.pause();
                        component.seek(tick);

                        return this.state();
                    },
                    state() {
                        const game = rt.player?.game;

                        return {
                            tick: component.tick,
                            total: component.total,
                            lines: component.lines,
                            playing: component.playing,
                            speed: component.speed,
                            hash: game ? stateHash(game) : null,
                            blockTicks: rt.player?.blockTicks ?? [],
                            ended: component.ended,
                        };
                    },
                };
            },
        };
    });
});
