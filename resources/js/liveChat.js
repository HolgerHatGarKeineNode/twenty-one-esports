/**
 * Entry for /live (P24): the stream's NIP-53 live chat next to the stage,
 * read and written straight from the browser; the league server never sees a
 * message. Loaded only on that page (layout `scripts`).
 *
 * - liveChat: one subscription (kind 1311 and zap receipts 9735 with the
 *   stream's `a`) on the chat relays, newest at the bottom. It follows new
 *   messages while the reader is at the bottom; scrolled up, it counts them
 *   in an "N new" button instead. Names and pictures come from kind 0 on the
 *   profile relays, cached on this device. Posting signs a kind 1311 with the
 *   viewer's signer (signing.js) and publishes it; guests only read.
 *   Mutes are the viewer's own, as in the game chat: localStorage plus the
 *   account (Livewire `setMuted`); a run of muted messages folds into one line.
 * - emojiPicker / emojiPopover: the emoji picker of einundzwanzig-group
 *   (bridge.ts, emoji-picker.blade.php), ported to nostr-tools. Only pointer
 *   devices get it; on touch the keyboard has emoji.
 */
import { SimplePool } from 'nostr-tools/pool';
import { npubEncode } from 'nostr-tools/nip19';
import { knownCustomEmojis, loadEmojiGroups, loadRecentEmojis, loadUserCustomEmojis, pushRecentEmoji, searchEmojis } from './emoji.js';
import { ensureSigner } from './nostrSign.js';
import { newest, readRelays } from './relayRead.js';
import { signerMessage, signTemplate } from './signing.js';
import { botMark, boundProfiles, displayRows, formatSats, insertSorted, isHttps, isStreamMessage, length, messageTemplate, parseZap, profileOf, sendBlocker, tokenize } from './streamChat.js';

const MUTES_KEY = 'esports.chat.mutes';
const PROFILES_KEY = 'esports.livechat.profiles';
const PROFILE_TTL_MS = 6 * 60 * 60 * 1000;
const KEEP = 200;
const BOTTOM_SLACK = 48;
const AVATAR_PLACEHOLDER = '0'.repeat(64);

function readJson(key, fallback) {
    try {
        return JSON.parse(localStorage.getItem(key) ?? 'null') ?? fallback;
    } catch {
        return fallback;
    }
}

function writeJson(key, value) {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // private mode or full storage: it only forgets
    }
}

/** The cached profiles still fresh, each checked again: the stored copy is only as good as whoever wrote it. */
function cachedProfiles() {
    const now = Date.now();
    const stored = readJson(PROFILES_KEY, {});

    return Object.fromEntries(Object.entries(stored && typeof stored === 'object' ? stored : {}).filter(([pubkey, profile]) => /^[0-9a-f]{64}$/.test(pubkey)
        && profile && now - (profile.seen ?? 0) < PROFILE_TTL_MS
        && (profile.picture === null || isHttps(profile.picture))
        && (profile.name === null || (typeof profile.name === 'string' && profile.name.length <= 96))));
}

export function liveChat(config) {
    const t = config.labels;

    return {
        t,
        items: [],
        profiles: {},
        status: (config.relays ?? []).length === 0 ? 'off' : 'connecting',
        input: '',
        sending: false,
        error: '',
        lastSentAt: null,
        atBottom: true,
        unseen: 0,
        muted: [],
        revealed: [],
        menuFor: null,
        me: config.me ?? null,
        maxLength: config.maxLength ?? 280,
        pointerFine: typeof window.matchMedia === 'function' && window.matchMedia('(hover: hover) and (pointer: fine)').matches,

        init() {
            const local = readJson(MUTES_KEY, []);
            // The account's list is the truth for a logged-in viewer; a guest keeps hers on this device.
            this.muted = [...new Set(this.me ? (config.muted ?? []) : (Array.isArray(local) ? local : []))];
            if (this.me) writeJson(MUTES_KEY, this.muted);

            this.profiles = cachedProfiles();
            this.asked = new Set(Object.keys(this.profiles));
            this.pending = new Set();

            this.fit = () => this.fitColumn();
            this.fitObserver = new ResizeObserver(this.fit);
            this.fitObserver.observe(document.body);
            window.addEventListener('resize', this.fit);
            this.fit();

            if (this.status === 'off') return;

            this.pool = new SimplePool();
            this.sub = this.pool.subscribe(
                config.relays,
                { kinds: [1311, 9735], '#a': [config.address], limit: config.history ?? 80 },
                {
                    onevent: (event) => this.receive(event),
                    oneose: () => this.caughtUp(),
                    maxWait: 6000,
                },
            );
            // A relay that never answers must not leave the list saying "connecting".
            this.eoseTimer = setTimeout(() => this.caughtUp(), 6500);

            if (this.me && this.pointerFine) {
                // Warm the viewer's own emoji so the picker's tab is there when it opens.
                loadUserCustomEmojis(this.me, config.emojiRelays ?? []).catch(() => {});
            }
        },

        destroy() {
            this.fitObserver?.disconnect();
            window.removeEventListener('resize', this.fit);
            clearTimeout(this.eoseTimer);
            clearTimeout(this.profileTimer);
            this.sub?.close();
            this.pool?.destroy();
        },

        caughtUp() {
            clearTimeout(this.eoseTimer);
            if (this.status !== 'connecting') return;
            this.status = 'live';
            this.$nextTick(() => this.scrollToBottom());
        },

        receive(event) {
            let item = null;
            if (isStreamMessage(event, config.address)) {
                if (event.content.trim() === '') return;
                item = {
                    type: 'message',
                    id: event.id,
                    pubkey: event.pubkey,
                    created_at: event.created_at,
                    tokens: tokenize(event.content, event.tags),
                };
            } else {
                const zap = parseZap(event, { address: config.address, signers: config.zapSigners ?? [], recipient: config.zapRecipient ?? null, lnurl: config.zapLnurl ?? null });
                if (zap) item = { type: 'zap', ...zap, tokens: tokenize(zap.comment, []) };
            }
            if (!item) return;

            const list = this.$refs.list;
            const follow = this.atBottom;
            if (!insertSorted(this.items, item, KEEP)) return;
            this.wantProfile(item.pubkey);

            if (this.status !== 'live') return;
            if (item.pubkey === this.me || (follow && list?.checkVisibility())) {
                this.$nextTick(() => this.scrollToBottom());
            } else if (!this.muted.includes(item.pubkey)) {
                this.unseen += 1;
            }
        },

        /** The list's rows; `cont` marks a message that continues its author's previous one (within 2 min): no name line again. */
        get rows() {
            const rows = displayRows(this.items, { muted: this.muted, revealed: this.revealed });
            rows.forEach((row, index) => {
                const previous = rows[index - 1];
                row.cont = row.type === 'message' && previous?.type === 'message' && previous.item.pubkey === row.item.pubkey && row.item.created_at - previous.item.created_at < 120;
            });

            return rows;
        },

        /**
         * From lg the column reaches from its own top to the window's bottom
         * edge, ending above the match dock when one floats there. Its top is
         * where the header leaves it, and the header is 112 px for a player
         * but 181 px for a guest (measured at 1440): no fixed offset fits
         * both. So the column measures itself and sets one CSS variable, read
         * by its `lg:h-[…]` class; the body's ResizeObserver catches a header
         * that changes and a dock that comes or goes (it adds a spacer).
         */
        fitColumn() {
            const column = this.$root;
            if (!column) return;

            if (window.innerWidth < 1024) {
                // Phones: the tab bar and the dock bar float over the page's bottom edge (`data-live-floor`).
                // The chat stays shorter than the band above them, and scrolls into view above them.
                const floors = [...document.querySelectorAll('[data-live-floor]')]
                    .filter((el) => el.checkVisibility())
                    .map((el) => el.getBoundingClientRect().top)
                    .filter((top) => top > window.innerHeight / 2);
                const value = Math.round(window.innerHeight - Math.min(window.innerHeight, ...floors) + 8) + 'px';
                if (column.style.getPropertyValue('--live-chat-floor') !== value) {
                    column.style.setProperty('--live-chat-floor', value);
                }

                return;
            }

            // The grid it starts in, not the column itself: stuck, the column reports its 16 px.
            const top = column.parentElement.getBoundingClientRect().top + window.scrollY;
            const dock = document.querySelector('[data-test=match-dock]');
            const dockTop = dock?.checkVisibility() ? dock.getBoundingClientRect().top : null;
            const reserve = dockTop === null ? 16 : Math.max(16, window.innerHeight - dockTop + 12);
            const value = Math.round(top + reserve) + 'px';
            if (column.style.getPropertyValue('--live-chat-top') !== value) {
                column.style.setProperty('--live-chat-top', value);
            }
        },

        /* ---------- Scrolling ---------------------------------------------------------------- */

        onScroll() {
            const list = this.$refs.list;
            if (!list) return;
            this.atBottom = list.scrollHeight - list.scrollTop - list.clientHeight < BOTTOM_SLACK;
            if (this.atBottom) this.unseen = 0;
        },

        scrollToBottom(smooth = false) {
            const list = this.$refs.list;
            if (!list) return;
            const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
            list.scrollTo({ top: list.scrollHeight, behavior: smooth && !reduce ? 'smooth' : 'auto' });
            this.atBottom = true;
            this.unseen = 0;
        },

        /** The chat became visible again (the phone's tab): catch up to the bottom. */
        shown() {
            this.$nextTick(() => this.scrollToBottom());
        },

        get unseenLabel() {
            return this.unseen === 1 ? t.newMessage : t.newMessages.replace(':count', this.unseen);
        },

        /* ---------- People ------------------------------------------------------------------- */

        wantProfile(pubkey) {
            if (this.asked.has(pubkey) || (config.profileRelays ?? []).length === 0) return;
            this.asked.add(pubkey);
            this.pending.add(pubkey);
            clearTimeout(this.profileTimer);
            this.profileTimer = setTimeout(() => this.loadProfiles(), 250);
        },

        async loadProfiles() {
            const authors = [...this.pending].slice(0, 50);
            authors.forEach((pubkey) => this.pending.delete(pubkey));
            if (this.pending.size > 0) this.profileTimer = setTimeout(() => this.loadProfiles(), 250);
            if (authors.length === 0) return;

            const results = await readRelays(config.profileRelays, [{ kinds: [0], authors }]);
            const events = results.flatMap((result) => result.events);
            const store = cachedProfiles();
            for (const pubkey of authors) {
                const profile = profileOf(newest(events, pubkey, 0));
                if (!profile) continue;
                this.profiles[pubkey] = { ...profile, seen: Date.now() };
                store[pubkey] = this.profiles[pubkey];
            }
            // Newest first, at most 400 people and 256 KB: localStorage is shared with the rest of the site.
            writeJson(PROFILES_KEY, boundProfiles(store));
        },

        nameOf(pubkey) {
            if (pubkey === this.me && config.meName) return config.meName;
            const name = this.profiles[pubkey]?.name;
            if (name) return name;
            try {
                return npubEncode(pubkey).slice(0, 12) + '…';
            } catch {
                return t.someone;
            }
        },

        avatarOf(pubkey) {
            return this.profiles[pubkey]?.picture ?? this.generatedAvatar(pubkey);
        },

        generatedAvatar(pubkey) {
            return (config.avatarUrl ?? '').replace(AVATAR_PLACEHOLDER, pubkey);
        },

        /** The bot badge: the configured bot key only. */
        isBot(pubkey) {
            return botMark(pubkey, { bot: config.bot, profile: this.profiles[pubkey] }) === 'bot';
        },

        /** A profile that calls itself a bot shows its npub next to the name, not a badge: anyone can say so. */
        selfBot(pubkey) {
            return botMark(pubkey, { bot: config.bot, profile: this.profiles[pubkey] }) === 'self';
        },

        shortNpub(pubkey) {
            try {
                const npub = npubEncode(pubkey);

                return npub.slice(0, 10) + '…' + npub.slice(-4);
            } catch {
                return '';
            }
        },

        time(seconds) {
            return new Date(seconds * 1000).toLocaleTimeString(config.locale ?? undefined, { hour: '2-digit', minute: '2-digit' });
        },

        sats(amount) {
            return t.zapped.replace(':amount', formatSats(amount, config.locale ?? 'en'));
        },

        /* ---------- Mutes -------------------------------------------------------------------- */

        isMuted(pubkey) {
            return this.muted.includes(pubkey);
        },

        toggleMenu(id) {
            this.menuFor = this.menuFor === id ? null : id;
        },

        async setMuted(pubkey, mute) {
            if (!pubkey || pubkey === this.me) return;
            this.muted = mute ? [...new Set([...this.muted, pubkey])] : this.muted.filter((known) => known !== pubkey);
            this.menuFor = null;
            writeJson(MUTES_KEY, this.muted);
            if (this.me) {
                try {
                    await this.$wire.setMuted(pubkey, mute);
                } catch (error) {
                    console.warn('[live chat] saving the mute failed', error);
                }
            }
        },

        muteLabel(pubkey) {
            return (this.isMuted(pubkey) ? t.unmute : t.mute).replace(':name', this.nameOf(pubkey));
        },

        mutedLabel(row) {
            return row.ids.length === 1 ? t.mutedOne : t.mutedMany.replace(':count', row.ids.length);
        },

        toggleReveal(row) {
            const first = row.ids[0];
            this.revealed = this.revealed.includes(first) ? this.revealed.filter((id) => id !== first) : [...this.revealed, first];
        },

        isRevealed(row) {
            return this.revealed.includes(row.ids[0]);
        },

        /* ---------- Writing ------------------------------------------------------------------ */

        get remaining() {
            return this.maxLength - length(this.input.trim());
        },

        /**
         * Put an emoji where the caret is (a selection is replaced) and remember
         * it as recently used. The `emoji` tag is not made here: send() derives
         * the tags from the final text.
         */
        insertEmoji(text, emojiTag, label) {
            const field = this.$refs.composer;
            const start = field?.selectionStart ?? this.input.length;
            const end = field?.selectionEnd ?? this.input.length;
            this.input = this.input.slice(0, start) + text + this.input.slice(end);
            pushRecentEmoji(emojiTag ? { custom: true, shortcode: String(emojiTag[1]), url: String(emojiTag[2]) } : { u: text, label: label ?? text });
            this.$nextTick(() => {
                const position = start + text.length;
                field?.focus();
                field?.setSelectionRange(position, position);
            });
        },

        async send() {
            if (!this.me || this.sending || this.status !== 'live') return;

            const blocker = sendBlocker(this.input, { maxLength: this.maxLength, cooldownMs: config.cooldownMs ?? 2000, lastSentAt: this.lastSentAt });
            if (blocker === 'empty') return;
            if (blocker !== null) {
                this.error = blocker === 'tooLong' ? t.tooLong.replace(':max', this.maxLength) : t.wait;

                return;
            }

            const content = this.input;
            this.sending = true;
            this.error = '';

            try {
                if (!(await ensureSigner())) {
                    this.error = t.noSigner;

                    return;
                }

                const template = messageTemplate(content, { address: config.address, relayHint: config.relayHint, custom: knownCustomEmojis(this.me) });
                let signed;
                try {
                    signed = await signTemplate(template, { pubkey: this.me });
                } catch (error) {
                    this.error = signerMessage(t, error);

                    return;
                }

                // The field empties once it is signed; the text comes back if no relay takes it.
                this.lastSentAt = Date.now();
                this.input = this.input === content ? '' : this.input;
                try {
                    await Promise.any(this.pool.publish(config.relays, signed));
                } catch {
                    this.error = t.notSent;
                    this.input ||= content;

                    return;
                }

                this.receive(signed);
            } catch (error) {
                console.warn('[live chat] sending failed', error);
                this.error = t.failed;
                this.input ||= content;
            } finally {
                this.sending = false;
            }
        },
    };
}

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

document.addEventListener('alpine:init', () => {
    window.Alpine.data('liveChat', liveChat);
    window.Alpine.data('emojiPicker', emojiPicker);
    window.Alpine.data('emojiPopover', emojiPopover);
});
