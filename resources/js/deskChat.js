/**
 * The tournament desk (App\Support\Tournaments\TournamentDesk; user,
 * 2026-10-03: "damit alle Spieler mit der Turnierleitung chatten können, für
 * Probleme oder Bugs oder sonstiges"): one private NIP-17 group per
 * tournament, the active entrants and the tournament direction. It is the
 * match room chat (resources/js/roomChat.js) with three differences:
 *
 *   - its rumors carry `desk` (the league's tournament id) instead of
 *     `match`, and its messages are read with deskMessages() (nostrChat.js):
 *     a message stays readable after a member it named withdrew;
 *   - a message of the direction (`manager` in the member list) is marked;
 *   - it counts what arrived since the player last read the desk, for the
 *     unread badge on every desk button: Alpine.store('desk') holds the count
 *     and the read marker per tournament, the marker also in localStorage.
 *
 * The page's own desk (the always open panel on the tournament page; user,
 * 2026-10-04: no chat hidden behind a click) draws the chat and counts what
 * it shows as read while it is in view; elsewhere a desk button starts one
 * without a view (`headless`) just for the count (resources/js/deskButton.js). Both read the wraps the way the
 * room does: on their own only with a NIP-44 capable extension; without
 * one, the count comes from the messages opened before (the desk's cache).
 * Mute is not offered here: the direction's notices reach every member.
 */
import { loadCache } from './chatCache.js';
import { deskMessages } from './nostrChat.js';
import { roomChat } from './roomChat.js';

const readKey = (me, desk) => `esports.desk.read.${me}.${desk}`;

/** The desks' shared state: unread count, read marker (unix seconds) and which instance runs, per tournament. */
export function deskStore() {
    const Alpine = window.Alpine;
    if (!Alpine.store('desk')) Alpine.store('desk', { unread: {}, read: {}, running: {} });

    return Alpine.store('desk');
}

function readMarker(me, desk) {
    try {
        const value = Number(localStorage.getItem(readKey(me, desk)));

        return Number.isSafeInteger(value) && value > 0 ? value : 0;
    } catch {
        return 0;
    }
}

export function deskChat(config) {
    const chat = roomChat({ ...config, match: null, casual: null, cacheName: 'desk', tags: [['desk', String(config.desk)]] });
    const baseInit = chat.init;
    const baseDestroy = chat.destroy;
    const managers = new Set(config.members.filter((m) => m.manager).map((m) => m.pubkey));

    const desk = {
        // The panel is on screen (IntersectionObserver) and the tab is in front: only then is a message read.
        inView: false,
        pageVisible: typeof document === 'undefined' || document.visibilityState === 'visible',
        headless: config.headless ?? false,

        init() {
            const store = deskStore();
            store.read[config.desk] ??= readMarker(config.me, config.desk);
            // The page's panel wins: a desk button on this page brings it into view instead of following its link.
            if (!this.headless) store.running[config.desk] = 'view';
            else store.running[config.desk] ??= 'badge';

            baseInit.call(this);

            // Not live (no extension, or not opened yet): what the desk opened before still counts and shows.
            if (this.status !== 'live' && config.me) {
                const { cache, rumors } = loadCache('desk', config.me);
                this.cache = cache;
                this.rumors.push(...rumors);
            }

            if (this.headless) return;

            // The history, not the whole panel: a message counts as read once the list it sits in is on screen.
            this.inViewObserver = new IntersectionObserver(([entry]) => { this.inView = entry.isIntersecting; }, { threshold: 0.25 });
            this.inViewObserver.observe(this.$root.querySelector('[data-test=desk-messages]'));
            // Removed in destroy(): a wire:navigate away must not leave a listener holding this desk.
            this.teardown = new AbortController();
            document.addEventListener('visibilitychange', () => { this.pageVisible = document.visibilityState === 'visible'; }, { signal: this.teardown.signal });

            if (window.location.hash === '#desk') this.$nextTick(() => this.focusDesk());
        },

        /** Alpine calls it when the panel leaves the page (wire:navigate included): the room's sockets, then the desk's own hooks. */
        destroy() {
            baseDestroy?.call(this);
            this.teardown?.abort();
            this.inViewObserver?.disconnect();
        },

        get thread() {
            return {
                rumors: deskMessages(this.rumors, { me: config.me, members: config.members.map((m) => m.pubkey), desk: config.desk, muted: this.muted.filter((p) => !managers.has(p)) }),
                entries: [],
            };
        },

        get messages() {
            return this.thread.rumors.map((rumor) => ({
                id: rumor.id,
                at: rumor.created_at * 1000,
                pubkey: rumor.pubkey,
                from: rumor.pubkey === config.me ? 'me' : 'them',
                name: this.memberName(rumor.pubkey),
                text: rumor.content,
                manager: managers.has(rumor.pubkey),
                viaDm: false,
                card: null,
                mutedCard: false,
            }));
        },

        /** Messages of the others since the desk was last read. */
        get unread() {
            const read = (deskStore().read[config.desk] ?? 0) * 1000;

            return this.messages.filter((m) => m.from === 'them' && m.at > read).length;
        },

        /**
         * The panel's one effect: first what is on screen counts as read, then the count goes to the buttons. One
         * effect, in this order: as two, the count's effect could run before the read marker moved and not run
         * again (Alpine drops a job that is still in the queue it is flushing), and a badge kept counting what was
         * on screen (measured 2026-10-04, tests/Browser/TournamentDeskTest.php: unread 0, badge "1").
         */
        sync() {
            this.markRead();
            this.publish();
        },

        /** Hands the count to every desk button (sync() on the panel, an effect for a headless desk). */
        publish() {
            deskStore().unread[config.desk] = this.unread;
        },

        /** The panel is in view in the front tab: everything on screen is read. */
        markRead() {
            if (this.headless || !this.inView || !this.pageVisible) return;
            const newest = Math.max(0, ...this.messages.map((m) => Math.floor(m.at / 1000)));
            const store = deskStore();
            if (newest <= (store.read[config.desk] ?? 0)) return;
            store.read[config.desk] = newest;

            try {
                localStorage.setItem(readKey(config.me, config.desk), String(newest));
            } catch {
                // private mode or full storage: the badge just forgets what was read
            }
        },

        /** A desk button or #desk: the panel into view (unless it already is, as the side column is), the newest message, the field. */
        focusDesk() {
            const box = this.$root.getBoundingClientRect();
            if (box.top < 0 || box.bottom > window.innerHeight) {
                this.$root.scrollIntoView({ block: 'start', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
            }
            this.$nextTick(() => {
                this.toBottom();
                // The field takes the focus on a wide screen; a phone's keyboard opens only on a tap into it.
                if (matchMedia('(min-width: 64rem)').matches) this.$refs.input?.focus({ preventScroll: true });
            });
        },
    };

    Object.defineProperties(chat, Object.getOwnPropertyDescriptors(desk));

    return chat;
}

/** A desk without a view, for a button's unread badge: started once per tournament and page. */
export function startHeadlessDesk(config) {
    const Alpine = window.Alpine;
    const chat = Alpine.reactive(deskChat({ ...config, headless: true }));
    chat.init();
    Alpine.effect(() => chat.publish());

    return chat;
}
