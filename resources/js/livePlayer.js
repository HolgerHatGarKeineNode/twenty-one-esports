import { StreamPlayer } from './streamPlayer.js';

/**
 * The live stream around the site (P20).
 *
 * `livePlayer`: the floating player at the bottom left of every page of the
 * shell while the stream is on air (<x-live-player>). A tab ("Watch live")
 * opens a muted mini player; minimise folds it back into the tab (the sound
 * keeps running if it was switched on, a muted picture stops loading), close
 * stops it and hides the tab. What the viewer chose stays in localStorage
 * (`open`, `tab` or `closed`); the /live page can switch a closed one back on.
 *
 * It never covers a bar at the bottom edge: every visible element marked
 * `data-live-floor` (the match dock) or `data-page-bar` (a page's own bottom
 * bar) that shares its columns lifts it above that bar, re-measured once a
 * second, on resize and after a click. It steps aside while a sheet of the
 * shell or the dock is open (a button with aria-haspopup="dialog" expanded)
 * or the on-screen keyboard is up.
 *
 * `liveStage`: the big player of /live, with the browser's own controls; it
 * starts when the page's live feed says the stream came on air.
 */

export const STORAGE_KEY = 'twentyone.live-player';

const VIEWS = ['open', 'tab', 'closed'];

/** Room between the player and a bar under it, and from the screen edge. */
const GAP = 8;

export function storedView() {
    try {
        const value = window.localStorage.getItem(STORAGE_KEY);

        return VIEWS.includes(value) ? value : null;
    } catch {
        return null;
    }
}

export function storeView(view) {
    try {
        if (view === null) {
            window.localStorage.removeItem(STORAGE_KEY);
        } else {
            window.localStorage.setItem(STORAGE_KEY, view);
        }
    } catch {
        // Storage refused (private mode, quota): the choice lasts for this page.
    }
}

function statusText(labels, status) {
    return labels[status] ?? '';
}

export function livePlayer(config) {
    // Outside Alpine's reactive state: hls.js must never sit behind a Proxy.
    let player = null;

    return {
        view: 'tab',
        status: 'idle',
        muted: true,
        bottom: null,
        stepAside: false,
        timer: null,

        init() {
            this.view = storedView() ?? 'tab';

            if (this.view === 'open') {
                this.$nextTick(() => this.play());
            }

            this.measure = this.measure.bind(this);
            this.onClick = () => requestAnimationFrame(this.measure);
            this.onNavigated = () => {
                // wire:navigate onto a page with its own player (/live): this one stops.
                if (document.querySelector('[data-live-stage]')) this.halt();
                this.measure();
            };

            this.timer = setInterval(() => {
                if (!document.hidden) this.measure();
            }, 1000);
            window.addEventListener('resize', this.measure);
            window.visualViewport?.addEventListener('resize', this.measure);
            document.addEventListener('click', this.onClick, true);
            document.addEventListener('livewire:navigated', this.onNavigated);
            this.$nextTick(this.measure);
        },

        destroy() {
            clearInterval(this.timer);
            window.removeEventListener('resize', this.measure);
            window.visualViewport?.removeEventListener('resize', this.measure);
            document.removeEventListener('click', this.onClick, true);
            document.removeEventListener('livewire:navigated', this.onNavigated);
            player?.destroy();
        },

        get statusText() {
            return statusText(config.labels, this.status);
        },

        /** On air (the feed), or still busy with a stream that just went off. */
        get shown() {
            return this.view !== 'closed' && (this.$store.live.live || this.status !== 'idle');
        },

        /** "N watching", or the tab's plain line while the stream shares no count. */
        get watching() {
            const count = this.$store.live.viewers;
            if (count === null) return config.watching.none;

            return config.watching[count === 1 ? 'one' : 'many'].replace('#', count);
        },

        get playing() {
            return player !== null && player.running;
        },

        open() {
            this.view = 'open';
            storeView('open');
            this.$nextTick(() => {
                if (!this.playing) this.play();
                this.$refs.minimise?.focus();
                this.measure();
            });
        },

        play() {
            const video = this.$refs.video;
            if (!video) return;

            video.muted = this.muted;
            player ??= new StreamPlayer(video, config.url, (status) => {
                this.status = status;
            });
            player.start();
        },

        retry() {
            this.play();
        },

        /** Fold into the tab. A muted picture stops loading; sound the viewer switched on keeps playing. */
        minimise() {
            if (this.muted) this.halt();
            this.view = 'tab';
            storeView('tab');
            this.$nextTick(() => {
                this.$refs.tab?.focus();
                this.measure();
            });
        },

        close() {
            this.halt();
            this.view = 'closed';
            storeView('closed');
        },

        /** Stop loading, keep the view. */
        halt() {
            player?.stop();
            this.status = 'idle';
        },

        toggleSound() {
            const video = this.$refs.video;
            this.muted = !this.muted;
            video.muted = this.muted;

            if (!this.muted && video.paused && this.playing) video.play().catch(() => {});
        },

        measure() {
            const box = this.$refs.box;
            if (!box) return;

            const rect = box.getBoundingClientRect();
            // Folded to nothing (closed): measure the columns the tab would take.
            const left = rect.width > 0 ? rect.left : GAP * 2;
            const right = rect.width > 0 ? rect.right : left + 240;
            let floor = 0;

            document.querySelectorAll('[data-live-floor], [data-page-bar]').forEach((bar) => {
                if (bar.getClientRects().length === 0) return;
                const r = bar.getBoundingClientRect();
                if (r.width === 0 || r.height === 0 || r.right <= left || r.left >= right) return;
                floor = Math.max(floor, window.innerHeight - r.top);
            });

            this.bottom = floor > 0 ? Math.round(floor + GAP) : null;

            const viewport = window.visualViewport;
            const keyboard = !!viewport && viewport.height < window.innerHeight * 0.7;
            // An open sheet or panel of the shell or the dock (the More sheet, the game hub, the dock's list).
            const sheet = document.querySelector('[aria-haspopup="dialog"][aria-expanded="true"]') !== null;
            this.stepAside = keyboard || sheet;
        },

        /**
         * An object, not a string: Alpine writes a style string over the whole
         * attribute, which wiped x-show's display:none whenever the floor moved
         * (a hidden or closed player came back over the page, off air at 375 px).
         */
        get position() {
            return { bottom: this.bottom === null ? 'calc(16px + env(safe-area-inset-bottom, 0px))' : `${this.bottom}px` };
        },
    };
}

export function liveStage(config) {
    let player = null;

    return {
        status: 'idle',
        miniClosed: false,

        init() {
            this.miniClosed = storedView() === 'closed';

            if (this.$store.live.live) this.start();

            // The stream comes on air while the page is open (P20b): the stage starts playing.
            this.$watch('$store.live.live', (live) => {
                if (live && (player === null || !player.running)) this.start();
            });
        },

        /** On air, or still showing a stream that just went off (its own "off air" message ends it). */
        get onScreen() {
            return this.$store.live.live || this.status !== 'idle';
        },

        start() {
            player ??= new StreamPlayer(this.$refs.video, config.url, (status) => {
                this.status = status;
            });
            this.$refs.video.muted = true;
            player.start();
        },

        destroy() {
            player?.destroy();
        },

        get statusText() {
            return statusText(config.labels, this.status);
        },

        retry() {
            player?.start();
        },

        showMiniPlayer() {
            storeView('tab');
            this.miniClosed = false;
        },
    };
}
