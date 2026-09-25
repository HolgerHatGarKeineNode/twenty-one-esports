/**
 * The match dock (P5f) on the client: open and close, fold, the numbers that
 * run down, and live updates. The tabs and their order come from the server
 * (resources/views/components/⚡match-dock.blade.php); this module only asks
 * for a new render.
 *
 * Live: the player's private channel (a notification, a game that started,
 * an invite that changed) and the public watch channel of every chess game
 * on the dock ask for `$refresh`. Without a websocket the dock polls, and it
 * polls slowly with one too, since series changes are not broadcast. A
 * refresh waits while the pointer or the keyboard focus is inside the dock,
 * so a tab never moves under the player's hand (MatchDock.dc.html "Order").
 *
 * The panels live in the `panel` island: loaded when a tab is first opened,
 * reloaded on the next opening after the dock changed.
 */

const FOLD_KEY = 'esports.dock-folded';
const REFRESH_DEBOUNCE_MS = 250;

function storedFold() {
    try {
        return sessionStorage.getItem(FOLD_KEY);
    } catch {
        return null;
    }
}

function storeFold(folded) {
    try {
        sessionStorage.setItem(FOLD_KEY, folded ? '1' : '0');
    } catch {
        // Private mode: the fold lasts for this page only.
    }
}

/** Same as App\Support\Dock\OpenMatches::format(). */
export function formatLeft(ms, format, labels) {
    const left = Math.max(0, ms);
    if (format === 'clock') {
        const seconds = Math.ceil(left / 1000);

        return Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
    }
    const minutes = Math.floor(left / 60000);
    const hours = Math.floor(minutes / 60);

    return hours > 0
        ? labels.hm.replace(':h', hours).replace(':m', String(minutes % 60).padStart(2, '0'))
        : labels.min.replace(':m', minutes);
}

export default function matchDock(config) {
    return {
        open: null,
        folded: false,
        autoFolded: false,
        panelLeft: 0,
        pageBar: null,
        keyboard: false,
        chatOpen: false,
        inside: false,
        pending: false,
        panelsStale: false,
        announcement: '',
        offset: config.now - Date.now(),
        timers: [],
        listened: new Map(),
        connected: false,
        busy: false,
        poller: null,
        debounce: null,

        init() {
            const stored = storedFold();
            // Nothing on the player: the dock starts folded (MatchDock.dc.html "Only waiting").
            this.folded = stored === null ? config.need === 0 : stored === '1';
            // Folded by that default, not by the player: unfold once something is on them.
            this.autoFolded = stored === null && this.folded;

            this.measure();
            this.tick();
            this.timers.push(setInterval(() => {
                if (!document.hidden) {
                    this.tick();
                    this.measure();
                }
            }, 1000));
            window.addEventListener('resize', () => this.measure());
            window.visualViewport?.addEventListener('resize', () => this.measure());

            this.connect();
            if (!window.Echo && document.readyState !== 'complete') {
                window.addEventListener('load', () => this.connect(), { once: true });
            }
            this.schedulePoll();
        },

        destroy() {
            this.timers.forEach((timer) => clearInterval(timer));
        },

        /* ---------- open, close, fold ------------------------------------------------------------------- */

        toggle(what, el) {
            if (this.open === what) {
                this.close(false);

                return;
            }
            this.open = what;
            window.dispatchEvent(new CustomEvent('dock-toggle', { detail: true }));

            if (what.startsWith('item:')) {
                this.placePanel(el);
                const key = what.slice(5);
                if (this.panelsStale || !this.$root.querySelector(`[data-panel="${key}"]`)) {
                    this.panelsStale = false;
                    this.$wire.$island('panel').$refresh().then(() => this.tick());
                }
            }
            if (what === 'sheet') {
                this.$nextTick(() => this.$refs.sheetClose?.focus());
            }
        },

        close(returnFocus) {
            if (this.open === null) return;
            const was = this.open;
            this.open = null;
            window.dispatchEvent(new CustomEvent('dock-toggle', { detail: false }));
            if (returnFocus) {
                const selector = was.startsWith('item:') ? `[data-dock-tab="${was.slice(5)}"]` : was === 'more' ? '[data-test=dock-more-button]' : was === 'slot' ? '[data-test=dock-slot-button]' : null;
                const target = selector && [...document.querySelectorAll(selector)].find((el) => el.getClientRects().length > 0);
                target?.focus();
            }
        },

        fold() {
            this.folded = !this.folded;
            this.autoFolded = false;
            storeFold(this.folded);
            if (this.folded) this.close(false);
        },

        /** The panel rises from its tab, kept inside the viewport. */
        placePanel(tab) {
            const bar = this.$refs.bar?.getBoundingClientRect();
            const box = tab.getBoundingClientRect();
            if (!bar) return;
            const width = 360;
            const left = Math.min(box.left, window.innerWidth - 16 - width);
            this.panelLeft = Math.round(Math.max(16, left) - bar.left);
        },

        /** Left and right arrows move between tabs (a tab list without roving tabindex). */
        step(direction, event) {
            const tabs = [...this.$root.querySelectorAll('[data-dock-tab]')].filter((el) => el.getClientRects().length > 0);
            const index = tabs.indexOf(document.activeElement);
            if (index === -1) return;
            event.preventDefault();
            tabs[(index + direction + tabs.length) % tabs.length].focus();
        },

        leaveFocus(event) {
            if (!event.currentTarget.contains(event.relatedTarget)) {
                this.inside = false;
                this.flush();
            }
        },

        /* ---------- where the dock sits ------------------------------------------------------------------- */

        /**
         * A page that owns the bottom edge marks its bar with `data-page-bar`
         * (the daily move bar, the chat sheet, the match room's score bar);
         * the dock then folds into a 44 px tab on the top edge of the highest
         * visible one. The keyboard open on a phone hides the dock.
         */
        measure() {
            let top = null;
            document.querySelectorAll('[data-page-bar]').forEach((bar) => {
                if (bar.getClientRects().length === 0) return;
                const rect = bar.getBoundingClientRect();
                if (rect.height > 0 && (top === null || rect.top < top)) top = rect.top;
            });
            this.pageBar = top === null ? null : Math.max(0, Math.round(window.innerHeight - top));

            const viewport = window.visualViewport;
            this.keyboard = !!viewport && viewport.height < window.innerHeight * 0.7;
        },

        /* ---------- numbers that run down ----------------------------------------------------------------- */

        tick() {
            const now = Date.now() + this.offset;
            this.$root.querySelectorAll('[data-tick]').forEach((el) => {
                const tick = JSON.parse(el.dataset.tick);
                const left = tick.endsAt - now;
                const text = formatLeft(left, tick.format, config.labels);
                el.textContent = el.dataset.suffix ? el.dataset.suffix.replace(':left', text) : text;
                // Inline, so it wins over whichever text colour the server rendered.
                el.style.color = left < tick.redUnder ? 'var(--color-loss)' : '';
            });
            this.$root.querySelectorAll('[data-fuse]').forEach((el) => {
                const tick = JSON.parse(el.dataset.fuse);
                const share = Math.max(0, Math.min(1, (tick.endsAt - now) / tick.total));
                el.style.width = el.classList.contains('dk-fuse') ? `calc((100% - 3px) * ${share})` : `${share * 100}%`;
                el.style.background = tick.endsAt - now < tick.redUnder ? 'var(--color-loss)' : '';
            });
        },

        /* ---------- live ---------------------------------------------------------------------------------- */

        connect() {
            if (!window.Echo || this.connected) return;
            this.connected = true;
            const refresh = () => this.requestRefresh();
            window.Echo.private('App.Models.User.' + document.querySelector('meta[name="presence-user"]')?.content)
                .listen('.user.notified', refresh)
                .listen('.chess.game-started', refresh)
                .listen('.chess.invite', refresh);
            this.watchGames();
            this.schedulePoll();
        },

        /** The watch channel of every chess game on the dock: a move there turns its tab. */
        watchGames() {
            if (!window.Echo) return;
            const ids = new Set([...this.$root.querySelectorAll('[data-dock-game]')].map((el) => el.dataset.dockGame));
            ids.forEach((id) => {
                if (this.listened.has(id)) return;
                const handler = () => this.requestRefresh();
                window.Echo.channel('game.' + id + '.watch').listen('.game.updated', handler);
                this.listened.set(id, handler);
            });
            this.listened.forEach((handler, id) => {
                if (ids.has(id)) return;
                // Stop listening, never leave: the page may watch the same game.
                window.Echo.channel('game.' + id + '.watch').stopListening('.game.updated', handler);
                this.listened.delete(id);
            });
        },

        schedulePoll() {
            clearInterval(this.poller);
            const seconds = window.Echo ? config.pollWithSocket : config.poll;
            if (seconds > 0) {
                this.poller = setInterval(() => {
                    if (!document.hidden) this.requestRefresh();
                }, seconds * 1000);
            }
        },

        requestRefresh() {
            this.pending = true;
            clearTimeout(this.debounce);
            this.debounce = setTimeout(() => this.flush(), REFRESH_DEBOUNCE_MS);
        },

        async flush() {
            if (!this.pending || this.inside || this.busy) return;
            this.pending = false;
            this.busy = true;
            const before = this.needKeys();
            try {
                await this.$wire.$refresh();
            } finally {
                this.busy = false;
            }
            this.panelsStale = true;
            await this.$nextTick();
            this.tick();
            this.watchGames();
            if (this.autoFolded && this.needKeys().size > 0) {
                this.folded = false;
                this.autoFolded = false;
            }
            this.ring(before);
            if (this.open?.startsWith('item:')) {
                this.panelsStale = false;
                this.$wire.$island('panel').$refresh().then(() => this.tick());
            }
            if (this.pending) this.flush();
        },

        needKeys() {
            return new Set([...this.$root.querySelectorAll('[data-dock-item][data-need="1"]')].map((el) => el.dataset.dockItem));
        },

        /**
         * A tab that turned to "on you" rings three times and screen readers
         * hear one polite sentence; not on the player's own live blitz board.
         */
        ring(before) {
            const turned = [...this.$root.querySelectorAll('[data-dock-item][data-need="1"]')].filter((el) => !before.has(el.dataset.dockItem));
            if (turned.length === 0 || config.quiet) return;
            turned.forEach((item) => {
                this.$root.querySelectorAll(`[data-dock-tab="${item.dataset.dockItem}"]`).forEach((el) => {
                    el.classList.remove('dk-ping');
                    void el.offsetWidth;
                    el.classList.add('dk-ping');
                });
            });
            this.announcement = config.labels.turned.replace(':sentence', turned[0].dataset.sentence);
        },
    };
}
