/**
 * Nostr profiles of the players on a page (P10a).
 *
 * 1. Loader (Alpine store `profiles`): collects the pubkeys the server marked
 *    stale (`data-profile-stale`, ProfileCache::isStale()), asks the profile
 *    relays for their kind 0 in one REQ per 50 authors, and hands the SIGNED
 *    events to POST /profiles. The server checks signature, kind, size and
 *    age and caches them; it never talks to a relay itself. Pictures that
 *    arrive are swapped into the page's avatars. Everything fails silently:
 *    no relay, no profile, a refused event, all leave the page as it was.
 *    A pubkey asked for within LOCAL_TTL_MS is not asked again from this
 *    browser (covers players who have no profile at all).
 *
 * 2. Card host (Alpine component `profileCardHost`, one per page in the
 *    layout): a name marked `data-player-card="<npub>"` opens the player card
 *    (ProfileHovercard.dc.html) after 400 ms of hover or on Enter while
 *    focused; a click still follows the link. On touch screens a tap opens
 *    the same card as a bottom sheet (MobileProfileHovercard.dc.html).
 *    The card is an HTML fragment from GET /players/{npub}/card.
 */
const LOCAL_TTL_MS = 30 * 60 * 1000;
const STORAGE_KEY = 'twentyone.profiles.asked';
const BATCH = 50;
const OPEN_DELAY_MS = 400;
const CLOSE_DELAY_MS = 200;
const GUTTER = 16;

function config() {
    try {
        return JSON.parse(document.querySelector('meta[name="profile-loader"]')?.content || '{}');
    } catch {
        return {};
    }
}

function xsrfHeaders() {
    const cookie = document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='));
    if (cookie) {
        return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) };
    }

    const meta = document.querySelector('meta[name="csrf-token"]')?.content;

    return meta ? { 'X-CSRF-TOKEN': meta } : {};
}

function askedRecently() {
    try {
        const stored = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
        const now = Date.now();

        return Object.fromEntries(Object.entries(stored).filter(([, at]) => now - at < LOCAL_TTL_MS));
    } catch {
        return {};
    }
}

function rememberAsked(pubkeys) {
    try {
        const stored = askedRecently();
        const now = Date.now();
        pubkeys.forEach((pubkey) => {
            stored[pubkey] = now;
        });
        localStorage.setItem(STORAGE_KEY, JSON.stringify(stored));
    } catch {
        // private mode: ask again next time
    }
}

/**
 * Newest kind 0 per author from the relays. `reached` is false when no relay
 * could be connected at all (the card then says "Profile didn't load").
 */
async function readProfiles(relays, authors, waitMs) {
    const { SimplePool } = await import('nostr-tools/pool');
    const pool = new SimplePool();
    let reached = false;
    pool.onRelayConnectionSuccess = () => {
        reached = true;
    };

    try {
        const events = await Promise.race([
            pool.querySync(relays, { kinds: [0], authors }, { maxWait: waitMs }),
            new Promise((resolve) => setTimeout(() => resolve([]), waitMs + 500)),
        ]);

        const newest = new Map();
        for (const event of events) {
            if (event.kind !== 0 || ! authors.includes(event.pubkey)) {
                continue;
            }
            const known = newest.get(event.pubkey);
            if (! known || event.created_at > known.created_at) {
                newest.set(event.pubkey, event);
            }
        }

        return { reached, events: [...newest.values()] };
    } finally {
        try {
            pool.close(relays);
        } catch {
            // already closed
        }
    }
}

function swapAvatars(pubkey, profile) {
    if (! profile?.avatar) {
        return;
    }

    document.querySelectorAll(`img[data-avatar="${pubkey}"]`).forEach((img) => {
        if (img.getAttribute('src') === profile.avatar) {
            return;
        }
        img.onerror = () => {
            img.onerror = null;
            img.src = img.dataset.fallback;
            img.alt = img.dataset.fallbackAlt;
        };
        img.alt = img.alt.replace(/, generated$/, '');
        img.src = profile.avatar;
    });
}

export function profileStore() {
    return {
        statuses: {},
        busy: null,

        statusOf(pubkey) {
            // idle: never asked from this page; loading; done (relays answered); failed (none reachable).
            return this.statuses[pubkey] ?? 'idle';
        },

        /** Stale pubkeys on the page, not asked for from this browser lately. */
        scan() {
            const recent = askedRecently();
            const pubkeys = [...new Set([...document.querySelectorAll('[data-profile-stale]')]
                .map((el) => el.dataset.avatar || el.dataset.pubkey)
                .filter((pubkey) => /^[0-9a-f]{64}$/.test(pubkey || '') && ! recent[pubkey] && ! this.statuses[pubkey]))];

            if (pubkeys.length > 0) {
                this.load(pubkeys);
            }
        },

        retry(pubkey) {
            this.load([pubkey]);
        },

        async load(pubkeys) {
            const { relays = [], url, waitMs = 2500 } = config();

            if (relays.length === 0 || ! url) {
                return;
            }

            pubkeys.forEach((pubkey) => {
                this.statuses[pubkey] = 'loading';
            });

            for (let i = 0; i < pubkeys.length; i += BATCH) {
                const chunk = pubkeys.slice(i, i + BATCH);
                let result = { reached: false, events: [] };

                try {
                    result = await readProfiles(relays, chunk, waitMs);
                } catch {
                    // treated as unreachable below
                }

                let updated = {};
                if (result.events.length > 0) {
                    try {
                        const response = await fetch(url, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...xsrfHeaders() },
                            body: JSON.stringify({ events: result.events }),
                        });
                        updated = response.ok ? ((await response.json()).updated ?? {}) : {};
                    } catch {
                        updated = {};
                    }
                }

                if (result.reached) {
                    rememberAsked(chunk);
                }

                chunk.forEach((pubkey) => {
                    this.statuses[pubkey] = result.reached ? 'done' : 'failed';
                    if (updated[pubkey]) {
                        swapAvatars(pubkey, updated[pubkey]);
                    }
                });

                if (Object.keys(updated).length > 0) {
                    window.dispatchEvent(new CustomEvent('profiles-updated', { detail: { pubkeys: Object.keys(updated) } }));
                }
            }
        },
    };
}

export function profileCardHost() {
    return {
        open: false,
        sheet: false,
        npub: null,
        trigger: null,
        html: '',
        label: '',
        x: GUTTER,
        y: GUTTER,
        dragY: 0,
        openTimer: null,
        closeTimer: null,
        cache: {},

        init() {
            const target = (event) => event.target instanceof Element ? event.target.closest('[data-player-card]') : null;
            const touchOnly = () => window.matchMedia('(hover: none)').matches;

            document.addEventListener('mouseover', (event) => {
                const el = target(event);
                if (! el || touchOnly()) {
                    return;
                }
                clearTimeout(this.closeTimer);
                if (this.open && this.trigger === el) {
                    return;
                }
                clearTimeout(this.openTimer);
                this.openTimer = setTimeout(() => this.show(el, false), OPEN_DELAY_MS);
            });

            document.addEventListener('mouseout', (event) => {
                const el = target(event);
                if (! el || (event.relatedTarget instanceof Node && el.contains(event.relatedTarget))) {
                    return;
                }
                clearTimeout(this.openTimer);
                this.leaveSoon();
            });

            document.addEventListener('keydown', (event) => {
                const el = target(event);
                if (el && event.key === 'Enter' && ! event.metaKey && ! event.ctrlKey && ! event.shiftKey) {
                    event.preventDefault();
                    this.show(el, touchOnly(), true);
                }
            });

            document.addEventListener('click', (event) => {
                const el = target(event);
                if (el && touchOnly()) {
                    event.preventDefault();
                    this.show(el, true, true);
                }
            });

            window.addEventListener('profiles-updated', (event) => {
                const card = this.trigger?.dataset.pubkey;
                (event.detail?.pubkeys ?? []).forEach((pubkey) => {
                    Object.keys(this.cache).forEach((npub) => {
                        if (this.cache[npub].pubkey === pubkey) {
                            delete this.cache[npub];
                        }
                    });
                });
                if (this.open && card && event.detail?.pubkeys?.includes(card)) {
                    this.fetchCard(this.npub).then((html) => {
                        this.html = html;
                    });
                }
            });

            window.addEventListener('scroll', () => {
                if (this.open && ! this.sheet) {
                    this.close(false);
                }
            }, { passive: true });

            this.$store.profiles.scan();
        },

        async fetchCard(npub) {
            if (this.cache[npub]) {
                return this.cache[npub].html;
            }
            const response = await fetch(`/players/${encodeURIComponent(npub)}/card`, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
            if (! response.ok) {
                throw new Error(`card ${response.status}`);
            }
            const html = await response.text();
            const pubkey = /data-pubkey="([0-9a-f]{64})"/.exec(html)?.[1];
            this.cache[npub] = { html, pubkey };

            return html;
        },

        async show(el, asSheet, focus = false) {
            const npub = el.dataset.playerCard;
            const pubkey = el.dataset.pubkey;
            let html;

            try {
                html = await this.fetchCard(npub);
            } catch {
                return;
            }

            this.trigger = el;
            this.npub = npub;
            this.sheet = asSheet;
            this.html = html;
            this.dragY = 0;
            this.open = true;

            // A player the page did not ask for yet (fresh on the server, but
            // the card is opened): nothing to do. A stale one: ask now.
            if (pubkey && el.hasAttribute('data-profile-stale') && this.$store.profiles.statusOf(pubkey) === 'idle' && ! askedRecently()[pubkey]) {
                this.$store.profiles.load([pubkey]);
            }

            await this.$nextTick();

            // The card knows the current name; the link may still show the old one.
            const name = (asSheet ? this.$refs.sheet : this.$refs.pop)?.querySelector('[data-test=profile-card]')?.dataset.name || el.dataset.playerName || el.textContent.trim();
            this.label = (config().labels?.profile ?? ':name').replace(':name', name);

            if (! asSheet) {
                this.place();
            }
            if (focus) {
                (asSheet ? this.$refs.sheet : this.$refs.pop)?.querySelector('a[href], button')?.focus();
            }
        },

        place() {
            const pop = this.$refs.pop;
            const rect = this.trigger.getBoundingClientRect();
            const width = pop.offsetWidth;
            const height = pop.offsetHeight;
            const vw = document.documentElement.clientWidth;
            const vh = window.innerHeight;

            this.x = Math.max(GUTTER, Math.min(rect.left, vw - width - GUTTER));
            const below = rect.bottom + 8;
            this.y = below + height <= vh - GUTTER || rect.top - 8 - height < GUTTER ? below : rect.top - 8 - height;
        },

        keep() {
            clearTimeout(this.closeTimer);
        },

        leaveSoon() {
            if (! this.open || this.sheet) {
                return;
            }
            clearTimeout(this.closeTimer);
            this.closeTimer = setTimeout(() => this.close(false), CLOSE_DELAY_MS);
        },

        focusOut(event) {
            if (this.sheet || ! (event.relatedTarget instanceof Node) || this.$refs.pop.contains(event.relatedTarget)) {
                return;
            }
            this.close(false);
        },

        close(returnFocus = true) {
            if (! this.open) {
                return;
            }
            clearTimeout(this.openTimer);
            clearTimeout(this.closeTimer);
            this.open = false;
            if (returnFocus) {
                this.trigger?.focus();
            }
        },

        dragStart(event) {
            // Only a sheet scrolled to its top is dragged; otherwise it scrolls.
            this.dragFrom = this.$refs.sheet.scrollTop > 0 ? null : event.touches[0].clientY;
        },

        dragMove(event) {
            if (this.dragFrom !== null) {
                this.dragY = Math.max(0, event.touches[0].clientY - this.dragFrom);
            }
        },

        dragEnd() {
            if (this.dragY > 80) {
                this.close();
            }
            this.dragY = 0;
        },
    };
}
