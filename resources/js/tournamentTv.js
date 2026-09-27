/**
 * The tournament TV (P19, pages::tournaments.tv).
 *
 *   tournamentTv({ id, hold }) — on the Livewire root. Rotates the scenes
 *   (each `[data-scene-id]` stays `data-dwell` seconds), keeps the TV live
 *   (TournamentChanged on the public `tournament.{id}` channel, and a poll
 *   every POLL_MS that works without a websocket), and after every update
 *   compares each match box's `data-sig` with the one before: a box that got
 *   its result slams its score, lights the winner line into the next box and
 *   dims the loser; a side that moved on arrives in its new box. Rotation
 *   waits on the bracket for `hold` seconds after such a moment.
 *
 * The scene state lives on `.tv-stage` (wire:ignore.self), so a Livewire
 * morph never resets it; everything else is the server's markup. Nothing
 * here grows with time: one interval per job, one Map of box signatures
 * (as many entries as the bracket has matches), the SVG lines redrawn in
 * place. With reduced motion the scenes still change and results still
 * arrive, without slams, lines drawing or confetti.
 *
 * Renewal: every server render costs the page about 50 bytes per node that
 * no collection gives back (measured with a forced GC, P19: ~14–21 KB per
 * render of the TV, ~33–43 KB on the tournament page, gone when nothing is
 * rendered). It sits in the render-and-morph path, not in this script. The
 * TV therefore renders only when the tournament moved, and after
 * `data-renew-after` renders it loads itself again once the last result
 * moment has played out: a few MB at most, for as long as it runs.
 */

const POLL_MS = 20000;
const IDLE_MS = 4000;
const SVG = 'http://www.w3.org/2000/svg';

export default function tournamentTv({ id, hold = 8 } = {}) {
    return {
        sigs: new Map(),
        scene: null,
        elapsed: 0,
        paused: false,
        holdUntil: 0,
        clock: null,
        poll: null,
        idleTimer: null,
        refreshTimer: null,
        offMorphed: null,
        channel: null,
        listeners: [],
        cycle: 'a',
        renders: 0,

        init() {
            const stage = this.$refs.stage;
            this.scene = stage.dataset.scene;
            this.sigs = this.readSigs();
            this.drawLines(false);

            this.clock = setInterval(() => this.tick(), 1000);
            this.poll = setInterval(() => this.refresh(), POLL_MS);

            this.offMorphed = window.Livewire?.hook('morphed', ({ component }) => {
                if (component.id === this.$wire.$id) {
                    this.updated();
                }
            });

            if (window.Echo) {
                this.channel = window.Echo.channel('tournament.' + id);
                this.channel.listen('.tournament.changed', () => this.refresh(300));
            }

            this.on(window, 'resize', () => this.drawLines(false));
            this.on(document, 'keydown', (event) => this.key(event));
            this.on(document, 'mousemove', () => this.wake());
            this.on(document, 'fullscreenchange', () => {
                stage.toggleAttribute('data-fullscreen', document.fullscreenElement !== null);
            });
            this.wake();
            this.show(this.scene, false);
        },

        destroy() {
            clearInterval(this.clock);
            clearInterval(this.poll);
            clearTimeout(this.idleTimer);
            clearTimeout(this.refreshTimer);
            this.listeners.forEach(([target, type, handler]) => target.removeEventListener(type, handler));
            this.listeners = [];

            if (typeof this.offMorphed === 'function') {
                this.offMorphed();
            }

            if (this.channel) {
                window.Echo.leaveChannel('tournament.' + id);
            }
        },

        on(target, type, handler) {
            target.addEventListener(type, handler);
            this.listeners.push([target, type, handler]);
        },

        /* ---------- Live ------------------------------------------------------------------------------------------ */

        refresh(delay = 0) {
            clearTimeout(this.refreshTimer);
            this.refreshTimer = setTimeout(() => this.$wire.check(), delay);
        },

        readSigs() {
            const sigs = new Map();
            this.$root.querySelectorAll('.tv-scene[data-scene-id="bracket"] [data-key][data-sig]').forEach((box) => sigs.set(box.dataset.key, box.dataset.sig));

            return sigs;
        },

        updated() {
            this.renders += 1;
            const before = this.sigs;
            this.sigs = this.readSigs();
            const results = [];
            const arrivals = [];

            this.sigs.forEach((sig, key) => {
                const old = before.get(key);

                if (old === undefined || old === sig) {
                    return;
                }

                const [oldStatus, , oldIds] = old.split('|');
                const [status, , ids] = sig.split('|');

                if (status === 'done' && (oldStatus !== 'done' || old !== sig)) {
                    results.push(key);
                }

                if (ids !== oldIds) {
                    const previous = oldIds.split(',');
                    ids.split(',').forEach((pid, index) => {
                        if (pid !== '' && pid !== previous[index]) {
                            arrivals.push([key, index]);
                        }
                    });
                }
            });

            this.drawLines(false);

            if (results.length === 0 && arrivals.length === 0) {
                return;
            }

            // A new result is the moment: show it on the bracket and stay there a while.
            this.show('bracket', false);
            this.holdUntil = Date.now() + hold * 1000;
            this.$refs.stage.dataset.moment = String(Date.now());

            if (this.reduced()) {
                results.forEach((key) => this.flag(key, 'is-new'));

                return;
            }

            results.forEach((key) => this.flag(key, 'is-slam'));
            this.drawLines(true, results);
            arrivals.forEach(([key, index]) => {
                const side = this.box(key)?.querySelector(`[data-side="${index}"]`);

                if (side) {
                    side.classList.remove('is-arriving');
                    void side.offsetWidth;
                    side.classList.add('is-arriving');
                }
            });
        },

        flag(key, name) {
            const box = this.box(key);

            if (box) {
                box.classList.remove(name);
                void box.offsetWidth;
                box.classList.add(name);
            }
        },

        box(key) {
            return this.$root.querySelector(`.tv-scene[data-scene-id="bracket"] [data-key="${CSS.escape(key)}"]`);
        },

        /* ---------- Scenes ---------------------------------------------------------------------------------------- */

        scenes() {
            return [...this.$root.querySelectorAll('[data-scene-id]')].map((el) => el.dataset.sceneId);
        },

        tick() {
            // Renew once the last result moment has played out (its animations take under 2 s).
            if (this.renders >= Number(this.$refs.stage.dataset.renewAfter ?? 400) && Date.now() - (this.holdUntil - hold * 1000) > 2000) {
                clearInterval(this.clock);
                window.location.reload();

                return;
            }

            const scenes = this.scenes();

            if (!scenes.includes(this.scene)) {
                this.show(scenes[0], false);

                return;
            }

            if (this.paused || Date.now() < this.holdUntil || scenes.length < 2) {
                return;
            }

            this.elapsed += 1;

            if (this.elapsed >= this.dwell()) {
                this.next(1);
            }
        },

        dwell() {
            return Number(this.$root.querySelector(`[data-scene-id="${this.scene}"]`)?.dataset.dwell ?? 20);
        },

        next(step) {
            const scenes = this.scenes();
            const index = scenes.indexOf(this.scene);
            this.show(scenes[(index + step + scenes.length) % scenes.length], false);
        },

        show(scene, byHand) {
            const stage = this.$refs.stage;

            if (!scene) {
                return;
            }

            if (byHand) {
                this.holdUntil = 0;
            }

            const changed = scene !== this.scene;
            this.scene = scene;
            this.elapsed = 0;
            this.cycle = this.cycle === 'a' ? 'b' : 'a';
            stage.dataset.scene = scene;
            stage.dataset.cycle = this.cycle;
            stage.style.setProperty('--dwell', this.dwell() + 's');

            if (changed && scene === 'bracket') {
                requestAnimationFrame(() => this.drawLines(false));
            }
        },

        togglePause() {
            this.paused = !this.paused;
            this.$refs.stage.toggleAttribute('data-paused', this.paused);
        },

        key(event) {
            if (event.target.closest('input, textarea, select')) {
                return;
            }

            if (event.key === 'f' || event.key === 'F') {
                this.fullscreen();
            } else if (event.key === 'ArrowRight') {
                this.next(1);
            } else if (event.key === 'ArrowLeft') {
                this.next(-1);
            } else if (event.key === ' ') {
                event.preventDefault();
                this.togglePause();
            } else {
                return;
            }

            this.wake();
        },

        fullscreen() {
            if (document.fullscreenElement) {
                document.exitFullscreen?.();
            } else {
                document.documentElement.requestFullscreen?.().catch(() => {});
            }
        },

        /** The pointer and the hint show while someone is at the screen, then get out of the way. */
        wake() {
            const stage = this.$refs.stage;
            stage.removeAttribute('data-idle');
            clearTimeout(this.idleTimer);
            this.idleTimer = setTimeout(() => stage.setAttribute('data-idle', ''), IDLE_MS);
        },

        reduced() {
            return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        },

        /* ---------- Winner lines ---------------------------------------------------------------------------------- */

        /**
         * One elbow line from each box to the box its winner moves on to,
         * lit once that result is in. `animate` draws the lines of `keys` in.
         */
        drawLines(animate, keys = []) {
            this.$root.querySelectorAll('[data-lines-host]').forEach((host) => {
                const svg = host.querySelector('[data-lines]');
                const origin = host.getBoundingClientRect();

                if (!svg || origin.width === 0) {
                    return;
                }

                svg.setAttribute('viewBox', `0 0 ${Math.round(origin.width)} ${Math.round(origin.height)}`);
                const paths = [];

                host.querySelectorAll('[data-key][data-from]').forEach((box) => {
                    box.dataset.from.split(',').forEach((from, slot) => {
                        const source = from === '' ? null : host.querySelector(`[data-key="${CSS.escape(from)}"]`);

                        if (!source) {
                            return;
                        }

                        const a = source.getBoundingClientRect();
                        const b = box.getBoundingClientRect();
                        const side = box.querySelector(`[data-side="${slot}"]`)?.getBoundingClientRect() ?? b;
                        const x1 = a.right - origin.left;
                        const y1 = a.top + a.height / 2 - origin.top;
                        const x2 = b.left - origin.left;
                        const y2 = side.top + side.height / 2 - origin.top;
                        const mid = (x1 + x2) / 2;
                        const lit = source.dataset.status === 'done';
                        paths.push([`M${x1.toFixed(1)} ${y1.toFixed(1)}H${mid.toFixed(1)}V${y2.toFixed(1)}H${x2.toFixed(1)}`, lit, keys.includes(from)]);
                    });
                });

                // Reuse the path elements: the count only changes with the bracket.
                while (svg.childElementCount > paths.length) {
                    svg.lastElementChild.remove();
                }

                while (svg.childElementCount < paths.length) {
                    svg.appendChild(document.createElementNS(SVG, 'path'));
                }

                paths.forEach(([d, lit, fresh], index) => {
                    const path = svg.children[index];
                    path.setAttribute('d', d);
                    path.setAttribute('class', 'tv-line' + (lit ? ' is-lit' : '') + (animate && fresh ? ' is-drawing' : ''));

                    if (animate && fresh) {
                        const length = path.getTotalLength();
                        path.style.setProperty('--len', length.toFixed(1));
                    }
                });
            });
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('tournamentTv', tournamentTv);
});
