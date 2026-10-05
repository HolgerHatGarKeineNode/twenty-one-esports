/**
 * Keys an admin muted for everyone or banned from the site
 * (App\Support\Moderation\SiteModeration). The chats read relays straight
 * from the browser, so the league cannot delete anything there: every chat
 * on this site leaves these keys' messages, polls and zaps out instead,
 * old and new ones, with no fold and no "hidden" line.
 *
 * - Each chat's config brings the list (`hidden`, without the viewer's own
 *   key: a muted player still sees what they wrote). All chats of a page
 *   share one hub (window.esportsSiteHidden), so a key hidden by the
 *   moderation menu of one chat leaves every chat of the page at once.
 * - A change reaches every open page on the public `moderation` channel
 *   (App\Events\SiteModerationChanged: the key and whether it is hidden
 *   now, never mute or ban). A page without a websocket (a guest on a page
 *   that opens none) gets the list with its next load.
 * - siteModeration: the admin's menu on a message (components/chat-moderation):
 *   "Mute for everyone" / "Ban from the site", a reason, confirm; then the
 *   hub hides the key right away and the push tells everybody else.
 *
 * The pure parts (hiddenSet, applyChange, visible) run in Node:
 * tests/js/siteHidden.test.mjs.
 */

const HEX64 = /^[0-9a-f]{64}$/;
/** Bounded like the chats' other moderation lists. */
export const MAX_HIDDEN = 5000;

/** A clean set out of whatever the config carried: hex keys only, bounded, never the viewer's own. */
export function hiddenSet(list, me = null) {
    const set = new Set();
    for (const pubkey of Array.isArray(list) ? list : []) {
        if (set.size >= MAX_HIDDEN) break;
        if (typeof pubkey === 'string' && HEX64.test(pubkey) && pubkey !== me) set.add(pubkey);
    }

    return set;
}

/** One push or menu action applied to the set; true when it changed anything. */
export function applyChange(set, change, me = null) {
    const pubkey = change?.pubkey;
    if (typeof pubkey !== 'string' || !HEX64.test(pubkey) || pubkey === me) return false;
    if (change.hidden === true) {
        if (set.has(pubkey) || set.size >= MAX_HIDDEN) return false;
        set.add(pubkey);

        return true;
    }
    if (change.hidden === false) return set.delete(pubkey);

    return false;
}

/** The items whose author (`pubkey`, for a zap its sender) is not hidden. */
export function visible(items, hidden) {
    return hidden.size === 0 ? items : items.filter((item) => !hidden.has(item?.pubkey));
}

function hub() {
    window.esportsSiteHidden ??= { keys: new Set(), listeners: new Set(), listening: false };

    return window.esportsSiteHidden;
}

function notify(state) {
    state.listeners.forEach((listener) => listener());
}

function listen(state) {
    if (state.listening || !window.Echo) return;
    state.listening = true;
    window.Echo.channel('moderation').listen('.moderation.changed', (change) => {
        if (applyChange(state.keys, change)) notify(state);
    });
}

/**
 * For a chat: seed the page's hub with the config's list and call
 * `onChange(set)` now and on every change, with the viewer's own key left
 * out. Returns the function that stops it (the chat's destroy()).
 */
export function watchSiteHidden(list, me, onChange) {
    const state = hub();
    for (const pubkey of hiddenSet(list)) state.keys.add(pubkey);
    listen(state);
    const listener = () => onChange(hiddenSet([...state.keys], me));
    state.listeners.add(listener);
    listener();

    return () => state.listeners.delete(listener);
}

/** The menu hid a key: every chat of this page drops it now (the push may come a moment later). */
export function hideOnPage(pubkey) {
    const state = hub();
    if (applyChange(state.keys, { pubkey, hidden: true })) notify(state);
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

/**
 * The admin's moderation menu on one chat message (components/chat-moderation).
 * `pubkey()` reads the message's author from the chat around it.
 */
export function siteModeration() {
    return {
        action: null,
        reason: '',
        busy: false,
        error: '',

        start(action) {
            this.action = action;
            this.error = '';
            this.$nextTick(() => this.$refs.reason?.focus());
        },

        cancel() {
            this.action = null;
            this.reason = '';
            this.error = '';
        },

        async confirm(pubkey) {
            if (this.busy || this.reason.trim().length < 3 || !HEX64.test(pubkey ?? '')) return;
            this.busy = true;
            this.error = '';

            try {
                const response = await fetch(this.$root.dataset.url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                    body: JSON.stringify({ pubkey, action: this.action, reason: this.reason.trim() }),
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    this.error = typeof data?.message === 'string' && data.message !== '' ? data.message : this.$root.dataset.failed;

                    return;
                }
                this.cancel();
                hideOnPage(pubkey);
            } catch {
                this.error = this.$root.dataset.failed;
            } finally {
                this.busy = false;
            }
        },
    };
}
