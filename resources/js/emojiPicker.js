/**
 * The emoji picker and its popover (P24), shared by the stream chat on /live
 * (resources/js/liveChat.js) and the game channels (P21,
 * resources/js/gameChannel.js). Moved here unchanged from liveChat.js.
 */
import { loadEmojiGroups, loadRecentEmojis, loadUserCustomEmojis, searchEmojis } from './emoji.js';

/**
 * The emoji picker (ported from einundzwanzig-group's `emojiPicker`): recently
 * used, search, one tab per emojibase group and a first tab with the viewer's
 * own NIP-30 emoji, which join the grid as their images finish loading.
 * Picking calls insertEmoji() of the chat around it.
 */
const loadedImages = new Set();

export function emojiPicker(options = {}) {
    let groups = [];
    let custom = [];

    return {
        ready: false,
        search: '',
        activeTab: '',
        tabs: [],
        recent: loadRecentEmojis().slice(0, 8),
        customReady: [],
        customTotal: 0,

        async init() {
            try {
                groups = await loadEmojiGroups(options.locale);
            } catch (error) {
                console.warn('[emoji] loading the emoji set failed', error);
                groups = [];
            }
            this.rebuildTabs();
            this.ready = true;

            loadUserCustomEmojis(options.me, options.relays ?? []).then((list) => {
                custom = list;
                this.customTotal = list.length;
                this.rebuildTabs();
                this.preloadCustom();
            }).catch(() => {});
        },

        // Only images that loaded join the grid; one that loaded before shows at once.
        preloadCustom() {
            this.customReady = custom.filter((emoji) => loadedImages.has(emoji.url));
            for (const emoji of custom) {
                if (loadedImages.has(emoji.url)) continue;
                const image = new Image();
                image.referrerPolicy = 'no-referrer';
                image.onload = () => {
                    loadedImages.add(emoji.url);
                    this.customReady.push(emoji);
                };
                image.src = emoji.url;
            }
        },

        rebuildTabs() {
            this.tabs = [
                ...(custom.length ? [{ key: 'custom', name: options.labels?.yourEmoji ?? 'Your emoji', icon: '⚡' }] : []),
                ...groups.map((group) => ({ key: group.key, name: group.name, icon: group.icon })),
            ];
            if (!this.activeTab || !this.tabs.some((tab) => tab.key === this.activeTab)) {
                this.activeTab = this.tabs[0]?.key ?? '';
            }
        },

        get results() {
            if (!this.ready) return [];
            if (this.search.trim()) return searchEmojis(this.search, groups, this.customReady);
            if (this.activeTab === 'custom') return this.customReady.map((emoji) => ({ ...emoji, custom: true }));

            return groups.find((group) => group.key === this.activeTab)?.emojis ?? [];
        },

        // Two flat loops (image or character) instead of an x-if per tile: the original measured 148 ms for 171 tiles with two templates each.
        get customResults() {
            return this.results.filter((emoji) => emoji.custom);
        },

        get standardResults() {
            return this.results.filter((emoji) => !emoji.custom);
        },

        pickLabel(emoji) {
            return (options.labels?.insert ?? 'Insert :emoji').split(':emoji').join(emoji.custom ? ':' + emoji.shortcode + ':' : emoji.label);
        },
    };
}

/**
 * The picker's panel: teleported to <body> and `fixed`, so the chat's scroll
 * box does not clip it. Opening upwards it is anchored at its BOTTOM edge, so
 * it grows away from the button while the set loads (the original's lesson:
 * a height measured during "loading" put the panel 98 px below the window).
 * `max-height` keeps it inside the window; it scrolls instead.
 */
const HIDDEN = 'visibility:hidden';

export function emojiPopover() {
    return {
        open: false,
        panelStyle: HIDDEN,

        toggle() {
            if (!this.open) this.panelStyle = HIDDEN;
            this.open = !this.open;
            if (this.open) this.$nextTick(() => this.reposition());
        },

        close(focusTrigger = false) {
            if (!this.open) return;
            this.open = false;
            if (focusTrigger) this.$refs.trigger?.focus();
        },

        reposition() {
            const trigger = this.$refs.trigger;
            const panel = document.querySelector('[data-emoji-panel]');
            if (!trigger || !panel) return;

            const box = trigger.getBoundingClientRect();
            const width = panel.offsetWidth;
            const pad = 8;
            const gap = 8;
            const left = Math.min(Math.max(pad, box.right - width), window.innerWidth - width - pad);
            const above = box.top - gap - pad;
            const below = window.innerHeight - box.bottom - gap - pad;
            const edge = above >= below
                ? `top:auto;bottom:${Math.round(window.innerHeight - box.top + gap)}px;max-height:${Math.round(above)}px`
                : `bottom:auto;top:${Math.round(box.bottom + gap)}px;max-height:${Math.round(below)}px`;
            this.panelStyle = `left:${Math.round(left)}px;${edge};overflow-y:auto`;
        },

        closeUnless(event) {
            if (!this.$refs.trigger?.contains(event.target)) this.open = false;
        },
    };
}
